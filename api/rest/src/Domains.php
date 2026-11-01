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
        $out['servers'] = array_map(fn($a) => ['id' => $a['id'], 'server' => $a['server'], 'role' => $a['role']], DomainServers::forDomain((int)$d['id']));
        return $out;
    }
}

/**
 * Which DNS servers serve which domains (dns_server_domains), through the
 * domain_server_add / domain_server_del modules (`advanced`).
 */
class DomainServers
{
    public const ROLES = ['master', 'slave', 'forward'];

    private const BASE_SQL = 'SELECT sd.*, d.name AS host_name, d.domain_id AS host_domain_id
        FROM dns_server_domains sd
        LEFT JOIN hosts h ON h.id = sd.host_id
        LEFT JOIN dns d ON d.id = h.primary_dns_id';

    /** GET /domain-servers?domainId=&hostId= (unpaginated). */
    public static function list(Request $req): array
    {
        $where = [];
        $params = [];
        if (($d = $req->queryInt('domainId')) !== null) { $where[] = 'sd.domain_id = ?'; $params[] = $d; }
        if (($h = $req->queryInt('hostId')) !== null) { $where[] = 'sd.host_id = ?'; $params[] = $h; }
        $rows = Ona::select(self::BASE_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY sd.domain_id, d.name', $params);
        return [200, ['data' => array_map([self::class, 'format'], $rows)]];
    }

    /** Assignments for one domain, for embedding in Domains::format(). */
    public static function forDomain(int $domainId): array
    {
        return array_map([self::class, 'format'], Ona::select(self::BASE_SQL . ' WHERE sd.domain_id = ? ORDER BY d.name', [$domainId]));
    }

    public static function load(int $id): array
    {
        $rows = Ona::select(self::BASE_SQL . ' WHERE sd.id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('DNS server assignment');
        return $rows[0];
    }

    public static function get(int $id): array
    {
        return [200, self::format(self::load($id))];
    }

    /** POST /domain-servers {domainId, hostId, role}: also adds the NS record if missing. */
    public static function create(Request $req): array
    {
        Auth::requirePerm('advanced');
        $in = new Input($req->body());
        $domainId = $in->int('domainId', true, 1);
        $hostId = $in->int('hostId', true, 1);
        $role = strtolower((string)$in->string('role', false, 10)) ?: 'master';
        if (!in_array($role, self::ROLES, true)) $in->error('role', 'must be one of ' . implode(', ', self::ROLES));
        $in->check();
        Domains::load($domainId);
        $host = Hosts::load($hostId);
        if (Ona::select('SELECT id FROM dns_server_domains WHERE domain_id = ? AND host_id = ?', [$domainId, $hostId])) {
            throw new ApiError(409, 'conflict', 'This server is already assigned to the domain');
        }
        // The module finds the server by DNS name and uses it as the NS
        // record's target, so pass the host's FQDN (not an ID).
        $fqdn = ($host['name'] ? $host['name'] . '.' : '') . Ona::domainName($host['domain_id']);
        // ona_find_domain() in the module doesn't take IDs; send the name.
        Ona::run('domain_server_add', ['domain' => Ona::domainName($domainId), 'server' => $fqdn, 'role' => $role], ['advanced']);
        $rows = Ona::select('SELECT id FROM dns_server_domains WHERE domain_id = ? AND host_id = ?', [$domainId, $hostId]);
        if (!$rows) throw new ApiError(500, 'internal_error', 'The assignment was added but could not be read back');
        return [201, self::get((int)$rows[0]['id'])[1]];
    }

    /** DELETE /domain-servers/{id}: also deletes the server's NS record in the domain. */
    public static function delete(int $id): array
    {
        Auth::requirePerm('advanced');
        $a = self::load($id);
        Ona::run('domain_server_del', ['domain' => (int)$a['domain_id'], 'server' => (int)$a['host_id'], 'commit' => 'Y'], ['advanced']);
        return [204, null];
    }

    public static function format(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'domain' => ['id' => (int)$r['domain_id'], 'name' => Ona::domainName($r['domain_id'])],
            'server' => [
                'id' => (int)$r['host_id'],
                'fqdn' => ($r['host_name'] ? $r['host_name'] . '.' : '') . Ona::domainName($r['host_domain_id']),
            ],
            'role' => (string)$r['role'],
            'rebuildPending' => (bool)$r['rebuild_flag'],
        ];
    }
}
