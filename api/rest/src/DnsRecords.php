<?php
namespace OnaRest;

class DnsRecords
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'PTR', 'SRV', 'TXT'];

    public const BASE_SQL = 'SELECT r.*, i.ip_addr, i.host_id,
            t.name AS target_name, t.domain_id AS target_domain_id
        FROM dns r
        LEFT JOIN interfaces i ON i.id = r.interface_id
        LEFT JOIN dns t ON t.id = r.dns_id';

    public static function list(Request $req): array
    {
        $paging = new Paging($req);
        $where = [];
        $params = [];
        $q = $req->queryString('q');
        if ($q !== '') {
            if (($ip = Ona::ipNumeric($q)) !== null) {
                $where[] = 'i.ip_addr = ?';
                $params[] = $ip;
            } else {
                [$name] = explode('.', $q, 2);
                $where[] = '(r.name LIKE ? OR r.txt LIKE ? OR r.notes LIKE ?)';
                $params[] = '%' . Sql::likeEscape($name) . '%';
                $params[] = '%' . Sql::likeEscape($q) . '%';
                $params[] = '%' . Sql::likeEscape($q) . '%';
            }
        }
        $type = strtoupper($req->queryString('type'));
        if ($type !== '') {
            if (!in_array($type, self::TYPES, true)) throw ApiError::badRequest('type must be one of ' . implode(', ', self::TYPES));
            $where[] = 'r.type = ?';
            $params[] = $type;
        }
        if (($domain = $req->queryInt('domainId')) !== null) {
            $where[] = 'r.domain_id = ?';
            $params[] = $domain;
        }
        if (($host = $req->queryInt('hostId')) !== null) {
            $where[] = '(i.host_id = ? OR r.dns_id IN (SELECT x.id FROM dns x JOIN interfaces xi ON xi.id = x.interface_id WHERE xi.host_id = ?))';
            array_push($params, $host, $host);
        }
        $sql = self::BASE_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $order = Paging::orderBy($req, ['name' => 'r.name', 'type' => 'r.type', 'domain' => 'r.domain_id', 'id' => 'r.id'], 'name');
        return $paging->run($sql, $params, $order . ', r.type, r.id', [self::class, 'format']);
    }

    public static function load(int $id): array
    {
        $rows = Ona::select(self::BASE_SQL . ' WHERE r.id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('DNS record');
        return $rows[0];
    }

    public static function get(int $id): array
    {
        return [200, self::format(self::load($id))];
    }

    public static function create(Request $req): array
    {
        $in = new Input($req->body());
        $type = strtoupper((string)$in->string('type', true, 10));
        if ($type !== '' && !in_array($type, self::TYPES, true)) $in->error('type', 'must be one of ' . implode(', ', self::TYPES));
        $opts = self::fields($in, '') + ['type' => $type];
        $opts['name'] = $in->string('name', true);
        $opts['addptr'] = $in->bool('addPtr', false);
        $in->check();
        // ONA stores AAAA records as type A with an IPv6 address.
        if ($opts['type'] === 'AAAA') $opts['type'] = 'A';

        $before = (int)(Ona::select('SELECT MAX(id) AS id FROM dns')[0]['id'] ?? 0);
        Ona::run('dns_record_add', array_filter($opts, fn($v) => $v !== null && $v !== ''), ['host_add']);

        // The module doesn't report the new ID; it is the newest record of
        // that type (an A record's automatic PTR gets a higher ID).
        $rows = Ona::select('SELECT id FROM dns WHERE id > ? AND type = ? ORDER BY id', [$before, $opts['type']], 1);
        if (!$rows) throw new ApiError(500, 'internal_error', 'Record was added but could not be read back');
        return [201, self::format(self::load((int)$rows[0]['id']))];
    }

    public static function update(Request $req, int $id): array
    {
        $rec = self::load($id);
        $in = new Input($req->body());
        $set = self::fields($in, 'set_');
        $set['set_name'] = $in->string('name');
        $in->check();
        $set = array_filter($set, fn($v) => $v !== null);
        if (!$set) throw ApiError::badRequest('Nothing to update');
        // dns_record_modify prints its usage text unless a set_* option other
        // than set_txt is truthy, so a txt-only change (or ttl 0 / empty notes)
        // needs a companion; " <ttl>" is truthy but compares equal to the ttl.
        if (!array_filter(array_diff_key($set, ['set_txt' => 1]))) $set['set_ttl'] = ' ' . ($set['set_ttl'] ?? $rec['ttl']);
        Ona::run('dns_record_modify', ['name' => $id] + $set, ['host_modify']);
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        $rec = self::load($id);
        Ona::run('dns_record_del', ['name' => $id, 'type' => $rec['type'], 'commit' => 'Y'], ['host_del']);
        return [204, null];
    }

    /** Optional fields shared by create and update, keyed by module option. */
    private static function fields(Input $in, string $prefix): array
    {
        return [
            "{$prefix}ip" => $in->ip('ip'),
            "{$prefix}pointsto" => $in->string('pointsTo'),
            "{$prefix}ttl" => $in->int('ttl', false, 0, 2147483647),
            "{$prefix}notes" => $in->string('notes', false, 128),
            "{$prefix}mx_preference" => $in->int('mxPreference', false, 0, 65535),
            "{$prefix}txt" => $in->string('txt'),
            "{$prefix}srv_pri" => $in->int('srvPriority', false, 0, 65535),
            "{$prefix}srv_weight" => $in->int('srvWeight', false, 0, 65535),
            "{$prefix}srv_port" => $in->int('srvPort', false, 0, 65535),
        ];
    }

    public static function format(array $r): array
    {
        $domain = Ona::domainName($r['domain_id']);
        $type = $r['type'];
        if ($type === 'A' && $r['ip_addr'] !== null && !Ona::isIpv4($r['ip_addr'])) $type = 'AAAA';
        $out = [
            'id' => (int)$r['id'],
            'type' => $type,
            'name' => $r['name'],
            'fqdn' => ($r['name'] !== '' ? $r['name'] . '.' : '') . $domain,
            'domain' => ['id' => (int)$r['domain_id'], 'name' => $domain],
            'ttl' => (int)$r['ttl'],
            'ip' => $r['ip_addr'] !== null ? Ona::ipText($r['ip_addr']) : null,
            'interfaceId' => $r['interface_id'] ? (int)$r['interface_id'] : null,
            'hostId' => $r['host_id'] ? (int)$r['host_id'] : null,
            'pointsTo' => null,
            'notes' => $r['notes'],
            'enabled' => !str_starts_with((string)$r['ebegin'], '0000'),
            'mxPreference' => null,
            'txt' => null,
            'srv' => null,
        ];
        if ($r['dns_id'] && $r['target_domain_id'] !== null) {
            $out['pointsTo'] = [
                'id' => (int)$r['dns_id'],
                'fqdn' => ($r['target_name'] !== '' ? $r['target_name'] . '.' : '') . Ona::domainName($r['target_domain_id']),
            ];
        }
        if ($r['type'] === 'MX') $out['mxPreference'] = (int)$r['mx_preference'];
        if ($r['type'] === 'TXT') $out['txt'] = $r['txt'];
        if ($r['type'] === 'SRV') {
            $out['srv'] = ['priority' => (int)$r['srv_pri'], 'weight' => (int)$r['srv_weight'], 'port' => (int)$r['srv_port']];
        }
        return $out;
    }
}
