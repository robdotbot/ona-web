<?php
namespace OnaRest;

/** Locations that devices (hosts) live at (location_* modules). */
class Locations
{
    private const BASE_SQL = 'SELECT l.*, (SELECT COUNT(*) FROM devices d WHERE d.location_id = l.id) AS device_count
        FROM locations l';

    /** GET /locations: every location, unpaginated (the host form needs them all). */
    public static function list(): array
    {
        $rows = Ona::select(self::BASE_SQL . ' ORDER BY l.reference');
        return [200, ['data' => array_map([self::class, 'format'], $rows)]];
    }

    public static function load(int $id): array
    {
        $rows = Ona::select(self::BASE_SQL . ' WHERE l.id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('Location');
        return $rows[0];
    }

    public static function get(int $id): array
    {
        return [200, self::format(self::load($id))];
    }

    /** API field => [module option, max length]. */
    private const FIELDS = [
        'name' => ['name', 63],
        'address' => ['address', 63],
        'city' => ['city', 63],
        'state' => ['state', 31],
        'latitude' => ['latitude', 20],
        'longitude' => ['longitude', 20],
        'misc' => ['misc', 191],
    ];

    public static function create(Request $req): array
    {
        Auth::requirePerm('location_add');
        $in = new Input($req->body());
        $opts = ['reference' => self::reference($in, true)];
        foreach (self::FIELDS as $field => [$opt, $max]) $opts[$opt] = $in->string($field, $field === 'name', $max);
        $opts['zip_code'] = $in->int('zipCode', false, 0, 99999);
        $in->check();
        Ona::run('location_add', $opts, ['location_add']);
        $rows = Ona::select('SELECT id FROM locations WHERE reference = ?', [strtoupper($opts['reference'])]);
        if (!$rows) throw new ApiError(500, 'internal_error', 'Location was added but could not be read back');
        return [201, self::format(self::load((int)$rows[0]['id']))];
    }

    public static function update(Request $req, int $id): array
    {
        Auth::requirePerm('location_add');
        $loc = self::load($id);
        $in = new Input($req->body());
        $values = ['reference' => self::reference($in, false)];
        foreach (self::FIELDS as $field => [$opt]) $values[$opt] = $in->string($field, false, self::FIELDS[$field][1]);
        $values['zip_code'] = $in->int('zipCode', false, 0, 99999);
        $in->check();
        if (($values['name'] ?? null) === '') throw ApiError::validation('Invalid input', ['name' => 'is required']);
        if (isset($values['reference'])) $values['reference'] = strtoupper($values['reference']);
        $values = array_filter($values, fn($v) => $v !== null);
        if (!$values) throw ApiError::badRequest('Nothing to update');

        // location_modify fails with "You did not update anything" when
        // nothing changed, so only send real changes.
        $set = [];
        foreach ($values as $col => $v) {
            if ((string)$v !== (string)($loc[$col] ?? '')) $set["set_{$col}"] = $v;
        }
        // ONA reads the new longitude only if "set_logitude" is present (an
        // upstream typo), so send the value under both names.
        if (isset($set['set_longitude'])) $set['set_logitude'] = $set['set_longitude'];
        if ($set) Ona::run('location_modify', ['reference' => $id] + $set, ['location_add']);
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        Auth::requirePerm('location_del');
        self::load($id);
        Ona::run('location_del', ['reference' => $id, 'commit' => 'Y'], ['location_del']);
        return [204, null];
    }

    /** References are short codes, upper-cased by ONA; numbers would be taken as IDs. */
    private static function reference(Input $in, bool $required): ?string
    {
        $ref = $in->label('reference', $required, 10);
        if ($ref !== null && ctype_digit($ref)) $in->error('reference', 'must contain at least one non-digit');
        return $ref;
    }

    public static function format(array $l): array
    {
        $out = [
            'id' => (int)$l['id'],
            'reference' => $l['reference'],
            'name' => $l['name'],
            'address' => (string)($l['address'] ?? ''),
            'city' => (string)($l['city'] ?? ''),
            'state' => (string)($l['state'] ?? ''),
            'zipCode' => !empty($l['zip_code']) ? (int)$l['zip_code'] : null,
            'latitude' => (string)($l['latitude'] ?? ''),
            'longitude' => (string)($l['longitude'] ?? ''),
            'misc' => (string)($l['misc'] ?? ''),
        ];
        if (isset($l['device_count'])) $out['deviceCount'] = (int)$l['device_count'];
        return $out;
    }
}
