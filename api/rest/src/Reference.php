<?php
namespace OnaRest;

/**
 * Small lookup tables the legacy "admin" windows edit directly (ONA has no
 * DCM modules for them): manufacturers, models, roles, device types, subnet
 * types, DHCP option definitions, config types and DNS views. All writes
 * need `advanced`, IDs come from ONA's sequences (ona_get_next_id), and a
 * row can't be deleted while something still uses it.
 *
 * Each resource is described in RESOURCES:
 *   table    the ONA table
 *   label    used in messages
 *   fields   API field => [column, kind, required, max] (kind: str|int|ref:<resource>|enum:a,b)
 *   unique   fields whose combination must be unique
 *   usedBy   [table, column] pairs that block deletes
 *   order    ORDER BY for lists
 *   protect  IDs that can't be changed or deleted
 */
class Reference
{
    public const RESOURCES = [
        'manufacturers' => [
            'table' => 'manufacturers', 'label' => 'Manufacturer',
            'fields' => ['name' => ['name', 'str', true, 191]],
            'unique' => ['name'], 'usedBy' => [['models', 'manufacturer_id']], 'order' => 'name',
        ],
        'models' => [
            'table' => 'models', 'label' => 'Model',
            'fields' => [
                'name' => ['name', 'str', true, 191],
                'manufacturerId' => ['manufacturer_id', 'ref:manufacturers', true, 0],
                'snmpSysObjectId' => ['snmp_sysobjectid', 'str', false, 191],
            ],
            'unique' => ['manufacturerId', 'name'], 'usedBy' => [['device_types', 'model_id']], 'order' => 'name',
        ],
        'roles' => [
            'table' => 'roles', 'label' => 'Role',
            'fields' => ['name' => ['name', 'str', true, 127]],
            'unique' => ['name'], 'usedBy' => [['device_types', 'role_id']], 'order' => 'name',
        ],
        'device-types' => [
            'table' => 'device_types', 'label' => 'Device type',
            'fields' => [
                'modelId' => ['model_id', 'ref:models', true, 0],
                'roleId' => ['role_id', 'ref:roles', true, 0],
            ],
            'unique' => ['modelId', 'roleId'], 'usedBy' => [['devices', 'device_type_id']], 'order' => 'id',
        ],
        'subnet-types' => [
            'table' => 'subnet_types', 'label' => 'Subnet type',
            'fields' => [
                'name' => ['display_name', 'str', true, 63],
                'shortName' => ['short_name', 'str', false, 31],
                'notes' => ['notes', 'str', false, 191],
            ],
            'unique' => ['name'], 'usedBy' => [['subnets', 'subnet_type_id']], 'order' => 'display_name',
        ],
        'dhcp-options' => [
            'table' => 'dhcp_options', 'label' => 'DHCP option',
            'fields' => [
                'name' => ['name', 'str', true, 31],
                'displayName' => ['display_name', 'str', true, 31],
                'number' => ['number', 'int', true, 254],
                'type' => ['type', 'enum:L,S,N,I,B', true, 0],
            ],
            'unique' => ['name'], 'usedBy' => [['dhcp_option_entries', 'dhcp_option_id']], 'order' => 'display_name',
            'readonlyWhen' => 'sys_default',
        ],
        'config-types' => [
            'table' => 'configuration_types', 'label' => 'Config type',
            'fields' => ['name' => ['name', 'str', true, 63]],
            'unique' => ['name'], 'usedBy' => [['configurations', 'configuration_type_id']], 'order' => 'name',
        ],
        'dns-views' => [
            'table' => 'dns_views', 'label' => 'DNS view',
            'fields' => [
                'name' => ['name', 'str', true, 191],
                'description' => ['description', 'str', false, 191],
            ],
            'unique' => ['name'], 'usedBy' => [['dns', 'dns_view_id']], 'order' => 'id', 'protect' => [0],
        ],
    ];

    private static function spec(string $resource): array
    {
        return self::RESOURCES[$resource];
    }

    public static function list(string $resource): array
    {
        $s = self::spec($resource);
        $rows = Ona::select("SELECT * FROM {$s['table']} ORDER BY {$s['order']}");
        return [200, ['data' => array_map(fn($r) => self::format($resource, $r), $rows)]];
    }

    public static function load(string $resource, int $id): array
    {
        $s = self::spec($resource);
        $rows = Ona::select("SELECT * FROM {$s['table']} WHERE id = ?", [$id]);
        if (!$rows) throw ApiError::notFound($s['label']);
        return $rows[0];
    }

    public static function get(string $resource, int $id): array
    {
        return [200, self::format($resource, self::load($resource, $id))];
    }

    /** Validate the body into [column => value]; only fields present unless $create. */
    private static function values(string $resource, Input $in, bool $create): array
    {
        $out = [];
        foreach (self::spec($resource)['fields'] as $field => [$col, $kind, $required, $max]) {
            if (!$create && !$in->has($field)) continue;
            $req = $create && $required;
            if ($kind === 'str') {
                $v = $in->string($field, $req, $max);
                if (!$create && $required && $v === '') $in->error($field, 'is required');
                $out[$col] = $v ?? '';
            } elseif ($kind === 'int') {
                $out[$col] = $in->int($field, $req, 0, $max);
            } elseif (str_starts_with($kind, 'enum:')) {
                $v = $in->string($field, $req, 10);
                $allowed = explode(',', substr($kind, 5));
                if ($v !== null && $v !== '' && !in_array($v, $allowed, true)) $in->error($field, 'must be one of ' . implode(', ', $allowed));
                $out[$col] = $v;
            } else { // ref:<resource>
                $v = $in->int($field, $req, 1);
                if ($v !== null && !Ona::select('SELECT id FROM ' . self::spec(substr($kind, 4))['table'] . ' WHERE id = ?', [$v])) {
                    $in->error($field, 'no such ' . strtolower(self::spec(substr($kind, 4))['label']));
                }
                $out[$col] = $v;
            }
        }
        return $out;
    }

    /** Resource-specific normalisation, matching the legacy windows. */
    private static function normalise(string $resource, array $v, Input $in): array
    {
        if ($resource === 'dns-views' && isset($v['name'])) $v['name'] = strtoupper(preg_replace('/\s+/', '-', $v['name']));
        if ($resource === 'subnet-types' && isset($v['short_name']) && $v['short_name'] !== '') {
            $v['short_name'] = strtolower($v['short_name']);
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $v['short_name'])) $in->error('shortName', 'may only contain letters, digits, - and _');
        }
        if ($resource === 'dhcp-options' && isset($v['name']) && !preg_match('/^[a-z0-9-]+$/i', $v['name'])) {
            $in->error('name', 'may only contain letters, digits and -');
        }
        return $v;
    }

    private static function checkUnique(string $resource, array $row, ?int $exceptId): void
    {
        $s = self::spec($resource);
        $where = [];
        $params = [];
        foreach ($s['unique'] as $field) {
            $col = $s['fields'][$field][0];
            $where[] = "{$col} = ?";
            $params[] = $row[$col];
        }
        if ($exceptId !== null) {
            $where[] = 'id != ?';
            $params[] = $exceptId;
        }
        if (Ona::select("SELECT id FROM {$s['table']} WHERE " . implode(' AND ', $where), $params)) {
            throw new ApiError(409, 'conflict', "{$s['label']} already exists");
        }
    }

    public static function create(string $resource, Request $req): array
    {
        Auth::requirePerm('advanced');
        $s = self::spec($resource);
        $in = new Input($req->body());
        $v = self::normalise($resource, self::values($resource, $in, true), $in);
        $in->check();
        self::checkUnique($resource, $v, null);
        if ($resource === 'dhcp-options') $v['sys_default'] = 0;
        $id = Ona::write(['advanced'], "{$s['label']} ADDED", function ($db) use ($s, $v) {
            // Some sequences in ONA's install data start behind the rows it
            // seeds (configuration_types), so skip IDs already taken.
            for ($i = 0; $i < 1000; $i++) {
                $id = ona_get_next_id($s['table']);
                if (!$id) throw new ApiError(500, 'internal_error', 'Could not allocate an ID');
                if (!Ona::select("SELECT id FROM {$s['table']} WHERE id = ?", [$id])) break;
            }
            Ona::dbOk(db_insert_record($db, $s['table'], ['id' => $id] + $v));
            return $id;
        });
        return [201, self::get($resource, $id)[1]];
    }

    public static function update(string $resource, Request $req, int $id): array
    {
        Auth::requirePerm('advanced');
        $s = self::spec($resource);
        $row = self::load($resource, $id);
        self::checkEditable($resource, $row);
        $in = new Input($req->body());
        $v = self::normalise($resource, self::values($resource, $in, false), $in);
        $in->check();
        $v = array_filter($v, fn($x) => $x !== null);
        if (!$v) throw ApiError::badRequest('Nothing to update');
        self::checkUnique($resource, array_merge($row, $v), $id);
        $changed = array_filter($v, fn($x, $col) => (string)$x !== (string)$row[$col], ARRAY_FILTER_USE_BOTH);
        if ($changed) {
            Ona::write(['advanced'], "{$s['label']} UPDATED: {$id}", function ($db) use ($s, $id, $changed) {
                Ona::dbOk(db_update_record($db, $s['table'], ['id' => $id], $changed));
            });
        }
        return self::get($resource, $id);
    }

    public static function delete(string $resource, int $id): array
    {
        Auth::requirePerm('advanced');
        $s = self::spec($resource);
        $row = self::load($resource, $id);
        self::checkEditable($resource, $row);
        foreach ($s['usedBy'] as [$table, $col]) {
            $n = Ona::count("SELECT id FROM {$table} WHERE {$col} = ?", [$id]);
            if ($n) throw new ApiError(409, 'conflict', "{$s['label']} is in use by {$n} record(s) in {$table}");
        }
        Ona::write(['advanced'], "{$s['label']} DELETED: {$id}", function ($db) use ($s, $id) {
            Ona::dbOk(db_delete_records($db, $s['table'], ['id' => $id]));
        });
        return [204, null];
    }

    private static function checkEditable(string $resource, array $row): void
    {
        $s = self::spec($resource);
        if (in_array((int)$row['id'], $s['protect'] ?? [], true)) {
            throw new ApiError(409, 'conflict', "This {$s['label']} is built in and can't be changed");
        }
        if (isset($s['readonlyWhen']) && !empty($row[$s['readonlyWhen']])) {
            throw new ApiError(409, 'conflict', "This {$s['label']} is built in and can't be changed");
        }
    }

    public static function format(string $resource, array $r): array
    {
        $s = self::spec($resource);
        $out = ['id' => (int)$r['id']];
        foreach ($s['fields'] as $field => [$col, $kind]) {
            $out[$field] = $kind === 'int' || str_starts_with($kind, 'ref:') ? ($r[$col] === null ? null : (int)$r[$col]) : (string)$r[$col];
        }
        $out['inUse'] = 0;
        foreach ($s['usedBy'] as [$table, $col]) $out['inUse'] += Ona::count("SELECT id FROM {$table} WHERE {$col} = ?", [$r['id']]);
        if ($resource === 'device-types') $out += Lookups::deviceType((int)$r['id']);
        if ($resource === 'dhcp-options') {
            $out['system'] = (bool)$r['sys_default'];
            $out['typeName'] = DhcpOptions::TYPES[$r['type']] ?? $r['type'];
        }
        if (in_array((int)$r['id'], $s['protect'] ?? [], true)) $out['system'] = true;
        return $out;
    }
}
