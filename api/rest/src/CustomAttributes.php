<?php
namespace OnaRest;

/**
 * Custom attribute types. ONA has no DCM module for these; like the legacy
 * "Custom attribute types" admin window, writes go straight to the table and
 * need the `advanced` permission.
 */
class CustomAttributeTypes
{
    private const BASE_SQL = 'SELECT t.*, (SELECT COUNT(*) FROM custom_attributes a WHERE a.custom_attribute_type_id = t.id) AS use_count
        FROM custom_attribute_types t';

    public static function list(): array
    {
        $rows = Ona::select(self::BASE_SQL . ' ORDER BY t.name');
        return [200, ['data' => array_map([self::class, 'format'], $rows)]];
    }

    public static function load(int $id): array
    {
        $rows = Ona::select(self::BASE_SQL . ' WHERE t.id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('Custom attribute type');
        return $rows[0];
    }

    public static function get(int $id): array
    {
        return [200, self::format(self::load($id))];
    }

    private static function fields(Input $in, bool $create): array
    {
        $f = [
            'name' => $in->label('name', $create),
            'notes' => $in->string('notes', false, 127),
            'field_validation_rule' => $in->string('validationRule', false, 1024),
            'failed_rule_text' => $in->string('failedRuleText', false, 1024),
        ];
        $rule = $f['field_validation_rule'];
        if ($rule !== null && $rule !== '' && @preg_match($rule, '') === false) {
            $in->error('validationRule', 'must be a PCRE regular expression with delimiters, e.g. /^[YN]$/');
        }
        return $f;
    }

    public static function create(Request $req): array
    {
        Auth::requirePerm('advanced');
        $in = new Input($req->body());
        $f = self::fields($in, true);
        $in->check();
        self::checkUnique($f['name']);
        $id = Ona::write(['advanced'], "Custom attribute type ADDED: {$f['name']}", function ($db) use ($f) {
            $id = ona_get_next_id('custom_attribute_types');
            if (!$id) throw new ApiError(500, 'internal_error', 'Could not allocate an ID');
            Ona::dbOk(db_insert_record($db, 'custom_attribute_types', ['id' => $id] + array_map(fn($v) => (string)$v, $f)));
            return $id;
        });
        return [201, self::get($id)[1]];
    }

    public static function update(Request $req, int $id): array
    {
        Auth::requirePerm('advanced');
        $type = self::load($id);
        $in = new Input($req->body());
        $f = array_filter(self::fields($in, false), fn($v) => $v !== null);
        $in->check();
        if (($f['name'] ?? null) === '') throw ApiError::validation('Invalid input', ['name' => 'is required']);
        if (!$f) throw ApiError::badRequest('Nothing to update');
        if (isset($f['name']) && $f['name'] !== $type['name']) self::checkUnique($f['name']);
        Ona::write(['advanced'], "Custom attribute type UPDATED: {$id}", function ($db) use ($f, $id) {
            Ona::dbOk(db_update_record($db, 'custom_attribute_types', ['id' => $id], $f));
        });
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        Auth::requirePerm('advanced');
        $type = self::load($id);
        if ((int)$type['use_count'] > 0) {
            throw new ApiError(409, 'conflict', "There are {$type['use_count']} custom attributes using this type");
        }
        Ona::write(['advanced'], "Custom attribute type DELETED: {$type['name']}", function ($db) use ($id) {
            Ona::dbOk(db_delete_records($db, 'custom_attribute_types', ['id' => $id]));
        });
        return [204, null];
    }

    private static function checkUnique(string $name): void
    {
        if (Ona::select('SELECT id FROM custom_attribute_types WHERE name = ?', [$name])) {
            throw new ApiError(409, 'conflict', "A custom attribute type named {$name} already exists");
        }
    }

    public static function format(array $t): array
    {
        return [
            'id' => (int)$t['id'],
            'name' => $t['name'],
            'notes' => (string)$t['notes'],
            'validationRule' => (string)$t['field_validation_rule'],
            'failedRuleText' => (string)$t['failed_rule_text'],
            'useCount' => (int)($t['use_count'] ?? 0),
        ];
    }
}

/**
 * Custom attribute values on hosts, subnets and VLANs
 * (custom_attribute_add / _modify / _del modules).
 */
class CustomAttributes
{
    private const BASE_SQL = 'SELECT a.*, t.name AS type_name FROM custom_attributes a
        LEFT JOIN custom_attribute_types t ON t.id = a.custom_attribute_type_id';

    /** API filter / body key => [table_name_ref, module option]. */
    private const TARGETS = [
        'hostId' => ['hosts', 'host'],
        'subnetId' => ['subnets', 'subnet'],
        'vlanId' => ['vlans', 'vlan'],
    ];

    public static function list(Request $req): array
    {
        $paging = new Paging($req, 100);
        $where = [];
        $params = [];
        foreach (self::TARGETS as $key => [$table]) {
            if (($id = $req->queryInt($key)) !== null) {
                $where[] = 'a.table_name_ref = ? AND a.table_id_ref = ?';
                array_push($params, $table, $id);
            }
        }
        if (($type = $req->queryInt('typeId')) !== null) {
            $where[] = 'a.custom_attribute_type_id = ?';
            $params[] = $type;
        }
        $q = $req->queryString('q');
        if ($q !== '') {
            $where[] = 'a.value LIKE ?';
            $params[] = '%' . Sql::likeEscape($q) . '%';
        }
        $sql = self::BASE_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $order = Paging::orderBy($req, ['type' => 't.name', 'value' => 'a.value', 'id' => 'a.id'], 'type');
        return $paging->run($sql, $params, $order . ', a.id', [self::class, 'format']);
    }

    public static function load(int $id): array
    {
        $rows = Ona::select(self::BASE_SQL . ' WHERE a.id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('Custom attribute');
        return $rows[0];
    }

    public static function get(int $id): array
    {
        return [200, self::format(self::load($id))];
    }

    /** POST {typeId, value, and one of hostId, subnetId, vlanId}. */
    public static function create(Request $req): array
    {
        Auth::requirePerm('custom_attribute_add');
        $in = new Input($req->body());
        $typeId = $in->int('typeId', true, 1);
        $value = $in->string('value', true, 65535);
        $targets = [];
        foreach (self::TARGETS as $key => $_) {
            if (($id = $in->int($key, false, 1)) !== null) $targets[$key] = $id;
        }
        if (count($targets) !== 1) $in->error('target', 'send exactly one of hostId, subnetId, vlanId');
        $in->check();
        CustomAttributeTypes::load($typeId);
        $key = array_key_first($targets);
        $id = $targets[$key];
        [$table, $opt] = self::TARGETS[$key];
        $before = Ona::maxId('custom_attributes');
        Ona::run('custom_attribute_add', [$opt => self::moduleRef($table, $id), 'type' => $typeId, 'value' => $value], ['custom_attribute_add']);
        return [201, self::get(Ona::newId('custom_attributes', $before))[1]];
    }

    public static function update(Request $req, int $id): array
    {
        Auth::requirePerm('custom_attribute_modify');
        $attr = self::load($id);
        $in = new Input($req->body());
        $typeId = $in->int('typeId', false, 1);
        $value = $in->string('value', false, 65535);
        $in->check();
        if ($typeId === null && $value === null) throw ApiError::badRequest('Nothing to update; send typeId and/or value');
        if ($typeId !== null) CustomAttributeTypes::load($typeId);
        // custom_attribute_modify needs a value whenever the type is sent.
        $set = ['set_value' => $value ?? $attr['value']];
        if ($typeId !== null && $typeId !== (int)$attr['custom_attribute_type_id']) $set['set_type'] = $typeId;
        Ona::run('custom_attribute_modify', ['id' => $id] + $set, ['custom_attribute_modify']);
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        Auth::requirePerm('custom_attribute_del');
        $attr = self::load($id);
        $opt = null;
        foreach (self::TARGETS as [$table, $o]) if ($table === $attr['table_name_ref']) $opt = $o;
        if ($opt === null) throw new ApiError(422, 'module_error', 'Attribute is attached to an unsupported record type');
        Ona::run('custom_attribute_del', [
            $opt => self::moduleRef($attr['table_name_ref'], (int)$attr['table_id_ref']),
            'type' => (int)$attr['custom_attribute_type_id'],
            'commit' => 'Y',
        ], ['custom_attribute_del']);
        return [204, null];
    }

    /**
     * How the modules find the record: hosts and subnets by ID; VLANs only
     * by name or number, so use the name (unique unless another campus
     * reuses it, in which case the module reports the ambiguity).
     */
    private static function moduleRef(string $table, int $id)
    {
        return match ($table) {
            'hosts' => Hosts::load($id)['id'],
            'subnets' => Subnets::load($id)['id'],
            'vlans' => Vlans::load($id)['name'],
        };
    }

    public static function format(array $a): array
    {
        $key = null;
        foreach (self::TARGETS as $k => [$table]) if ($table === $a['table_name_ref']) $key = $k;
        return [
            'id' => (int)$a['id'],
            'type' => ['id' => (int)$a['custom_attribute_type_id'], 'name' => $a['type_name']],
            'value' => (string)$a['value'],
            'target' => [
                'kind' => match ($key) { 'hostId' => 'host', 'subnetId' => 'subnet', 'vlanId' => 'vlan', default => (string)$a['table_name_ref'] },
                'id' => (int)$a['table_id_ref'],
            ],
        ];
    }
}
