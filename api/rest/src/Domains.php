<?php
namespace OnaRest;

/** DNS domains (domain_* modules, `advanced` permission). */
class Domains
{
    private const BASE_SQL = 'SELECT d.*, (SELECT COUNT(*) FROM dns r WHERE r.domain_id = d.id) AS record_count
        FROM domains d';

    /** GET /domains: every domain, unpaginated and sorted by full name (forms need them all). */
    public static function list(): array
    {
        $domains = array_map([self::class, 'format'], Ona::select(self::BASE_SQL));
        usort($domains, fn($a, $b) => strcmp($a['name'], $b['name']));
        return [200, ['data' => $domains]];
    }

    public static function load(int $id): array
    {
        $rows = Ona::select(self::BASE_SQL . ' WHERE d.id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('Domain');
        return $rows[0];
    }

    public static function get(int $id): array
    {
        return [200, self::format(self::load($id))];
    }

    /** API field => module option (domain_add; domain_modify adds "set_"). */
    private const SOA = [
        'primaryMaster' => 'primary_master',
        'adminEmail' => 'admin',
        'refresh' => 'refresh',
        'retry' => 'retry',
        'expiry' => 'expiry',
        'minimum' => 'minimum',
        'defaultTtl' => 'ttl',
    ];

    private static function soa(Input $in): array
    {
        $out = [];
        foreach (self::SOA as $field => $opt) {
            $out[$opt] = in_array($field, ['primaryMaster', 'adminEmail'], true)
                ? $in->string($field, false, 191)
                : $in->int($field, false, 1, 2147483647);
        }
        return $out;
    }

    public static function create(Request $req): array
    {
        Auth::requirePerm('advanced');
        $in = new Input($req->body());
        $name = self::name($in, true);
        $parentId = $in->int('parentId', false, 1);
        $opts = ['name' => $name] + self::soa($in);
        $in->check();
        if ($parentId !== null) {
            self::load($parentId);
            $opts['parent'] = Ona::domainName($parentId);
        }
        $before = Ona::maxId('domains');
        Ona::run('domain_add', array_filter($opts, fn($v) => $v !== null && $v !== ''), ['advanced']);
        return [201, self::get(Ona::newId('domains', $before))[1]];
    }

    public static function update(Request $req, int $id): array
    {
        Auth::requirePerm('advanced');
        $d = self::load($id);
        $in = new Input($req->body());
        $name = self::name($in, false);
        $parentId = $in->int('parentId', false, 0);
        $soa = self::soa($in);
        $in->check();
        if ($name === null && $parentId === null && !array_filter($soa, fn($v) => $v !== null)) {
            throw ApiError::badRequest('Nothing to update');
        }
        if ($parentId === $id) throw ApiError::validation('Invalid input', ['parentId' => 'a domain cannot be its own parent']);
        if ($parentId) self::load($parentId);

        $set = [];
        foreach ($soa as $opt => $v) if ($v !== null && $v !== '') $set["set_{$opt}"] = $v;
        if ($name !== null && $name !== '') $set['set_name'] = $name;
        // domain_modify clears the parent unless set_parent is sent, so always
        // send the parent we want (by ID; 0 means none).
        $parent = $parentId ?? (int)$d['parent_id'];
        if ($parent) $set['set_parent'] = $parent;
        // It also prints its usage unless some set_* option is truthy;
        // resending the current TTL is a no-op.
        if (!$set) $set['set_ttl'] = (int)$d['default_ttl'];
        Ona::run('domain_modify', ['domain' => $id] + $set, ['advanced']);
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        Auth::requirePerm('advanced');
        self::load($id);
        Ona::run('domain_del', ['domain' => $id, 'commit' => 'Y'], ['advanced']);
        return [204, null];
    }

    /** The domain's own label(s), e.g. "lab" under parent "example.com", or "example.com" at the top. */
    private static function name(Input $in, bool $required): ?string
    {
        $v = $in->string('name', $required, 191);
        if ($v !== null && $v !== '') {
            $v = strtolower(rtrim($v, '.'));
            if (!preg_match('/^(?!-)[a-z0-9_-]+(?<!-)(\.(?!-)[a-z0-9_-]+(?<!-))*$/', $v)) {
                $in->error('name', 'must be a DNS name (letters, digits, - and _, separated by dots)');
            }
        }
        return $v;
    }

    public static function format(array $d): array
    {
        $out = [
            'id' => (int)$d['id'],
            'name' => Ona::domainName($d['id']),
            'shortName' => $d['name'],
            'parent' => $d['parent_id'] ? ['id' => (int)$d['parent_id'], 'name' => Ona::domainName($d['parent_id'])] : null,
            'primaryMaster' => (string)$d['primary_master'],
            'adminEmail' => (string)$d['admin_email'],
            'serial' => (int)$d['serial'],
            'refresh' => (int)$d['refresh'],
            'retry' => (int)$d['retry'],
            'expiry' => (int)$d['expiry'],
            'minimum' => (int)$d['minimum'],
            'defaultTtl' => (int)$d['default_ttl'],
        ];
        if (isset($d['record_count'])) $out['recordCount'] = (int)$d['record_count'];
        return $out;
    }
}
