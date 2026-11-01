<?php
namespace OnaRest;

class Interfaces
{
    public const BASE_SQL = 'SELECT i.*, d.name AS host_name, d.domain_id AS host_domain_id,
            s.name AS subnet_name, s.ip_addr AS subnet_ip, s.ip_mask AS subnet_mask
        FROM interfaces i
        LEFT JOIN hosts h ON h.id = i.host_id
        LEFT JOIN dns d ON d.id = h.primary_dns_id
        LEFT JOIN subnets s ON s.id = i.subnet_id';

    public static function list(Request $req): array
    {
        $paging = new Paging($req, 50);
        $where = [];
        $params = [];
        if (($host = $req->queryInt('hostId')) !== null) {
            $where[] = 'i.host_id = ?';
            $params[] = $host;
        }
        if (($subnet = $req->queryInt('subnetId')) !== null) {
            $where[] = 'i.subnet_id = ?';
            $params[] = $subnet;
        }
        $q = $req->queryString('q');
        if ($q !== '') {
            if (($ip = Ona::ipNumeric($q)) !== null) {
                $where[] = 'i.ip_addr = ?';
                $params[] = $ip;
            } elseif (($mac = mac_mangle($q, 1)) !== -1) {
                $where[] = 'i.mac_addr = ?';
                $params[] = $mac;
            } else {
                $where[] = '(i.name LIKE ? OR i.description LIKE ? OR d.name LIKE ?)';
                $like = '%' . Sql::likeEscape($q) . '%';
                array_push($params, $like, $like, $like);
            }
        }
        $sql = self::BASE_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $order = Paging::orderBy($req, ['ip' => 'i.ip_addr', 'name' => 'i.name', 'host' => 'd.name'], 'ip');
        return $paging->run($sql, $params, $order . ', i.id', [self::class, 'format']);
    }

    public static function load(int $id): array
    {
        $rows = Ona::select(self::BASE_SQL . ' WHERE i.id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('Interface');
        return $rows[0];
    }

    public static function get(int $id): array
    {
        return [200, self::format(self::load($id)) + ['sharedWith' => self::sharedWith($id)]];
    }

    public static function create(Request $req): array
    {
        $in = new Input($req->body());
        $hostId = $in->int('hostId', true, 1);
        $opts = [
            'ip' => $in->ip('ip', true),
            'mac' => $in->string('mac', false, 32),
            'name' => $in->string('name'),
            'description' => $in->string('description'),
        ];
        $addPtr = $in->bool('addPtr', false);
        $in->check();
        Hosts::load($hostId);
        $opts = ['host' => $hostId] + array_filter($opts, fn($v) => $v !== null && $v !== '');
        $opts['addptr'] = $addPtr;

        Ona::run('interface_add', $opts, ['host_add']);
        [, $rows, $iface] = ona_find_interface($opts['ip']);
        if (!$rows) throw new ApiError(500, 'internal_error', 'Interface was added but could not be read back');
        return [201, self::get((int)$iface['id'])[1]];
    }

    public static function update(Request $req, int $id): array
    {
        $current = self::load($id);
        $in = new Input($req->body());
        $set = [
            'set_ip' => $in->ip('ip'),
            'set_mac' => $in->string('mac', false, 32),
            'set_name' => $in->string('name'),
            'set_description' => $in->string('description'),
        ];
        $in->check();
        $set = array_filter($set, fn($v) => $v !== null);
        // Don't ask ONA to "change" the IP to the same value; it refuses.
        if (isset($set['set_ip']) && Ona::ipNumeric($set['set_ip']) === (string)$current['ip_addr']) unset($set['set_ip']);
        if (!$set) throw ApiError::badRequest('Nothing to update; send at least one of ip, mac, name, description');

        Ona::run('interface_modify', ['interface' => $id] + $set, ['interface_modify']);
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        self::load($id);
        Ona::run('interface_del', ['interface' => $id, 'commit' => 'Y'], ['interface_del', 'host_del']);
        return [204, null];
    }

    /** POST /interfaces/{id}/move {hostId}: give the interface to another host (interface_move_host, `advanced`). */
    public static function moveToHost(Request $req, int $id): array
    {
        Auth::requirePerm('advanced');
        $iface = self::load($id);
        $in = new Input($req->body());
        $hostId = $in->int('hostId', true, 1);
        $in->check();
        Hosts::load($hostId);
        if ((int)$iface['host_id'] === $hostId) throw ApiError::validation('Invalid input', ['hostId' => 'the interface is already on this host']);
        Ona::run('interface_move_host', ['ip' => $id, 'host' => $hostId], ['advanced']);
        return self::get($id);
    }

    /**
     * POST /interfaces/{id}/shares {hostId, name?}: share the address with
     * another host, e.g. a VRRP/HSRP/CARP address (interface_share; ONA
     * checks `interface_del` for it).
     */
    public static function share(Request $req, int $id): array
    {
        $iface = self::load($id);
        $in = new Input($req->body());
        $hostId = $in->int('hostId', true, 1);
        $name = $in->string('name', false, 191);
        $in->check();
        Auth::requirePerm('interface_del');
        Hosts::load($hostId);
        if ((int)$iface['host_id'] === $hostId) throw ApiError::validation('Invalid input', ['hostId' => 'the interface already belongs to this host']);
        if (Ona::select('SELECT host_id FROM interface_clusters WHERE interface_id = ? AND host_id = ?', [$id, $hostId])) {
            throw new ApiError(409, 'conflict', 'The interface is already shared with this host');
        }
        Ona::run('interface_share', ['ip' => $id, 'host' => $hostId, 'name' => $name ?: null], ['interface_del']);
        return [201, self::get($id)[1]];
    }

    /** DELETE /interfaces/{id}/shares/{hostId} (interface_share_del, `interface_del`). */
    public static function unshare(int $id, int $hostId): array
    {
        self::load($id);
        Auth::requirePerm('interface_del');
        if (!Ona::select('SELECT host_id FROM interface_clusters WHERE interface_id = ? AND host_id = ?', [$id, $hostId])) {
            throw ApiError::notFound('Interface share');
        }
        Ona::run('interface_share_del', ['ip' => $id, 'host' => $hostId], ['interface_del']);
        return [204, null];
    }

    /**
     * POST /interfaces/{id}/nat {ip}: add an external NAT address. nat_add
     * creates it as a new interface on the same host (via interface_add),
     * so it needs `host_add` too.
     */
    public static function addNat(Request $req, int $id): array
    {
        $iface = self::load($id);
        $in = new Input($req->body());
        $ip = $in->ip('ip', true);
        $in->check();
        Auth::requirePerm('interface_modify', 'host_add');
        if ($iface['nat_interface_id']) throw new ApiError(409, 'conflict', 'The interface already has a NAT address');
        Ona::run('nat_add', ['ip' => $id, 'natip' => $ip], ['interface_modify', 'host_add']);
        return [201, self::get($id)[1]];
    }

    /** DELETE /interfaces/{id}/nat: remove the NAT address and its interface (nat_del, which runs interface_del). */
    public static function deleteNat(int $id): array
    {
        $iface = self::load($id);
        Auth::requirePerm('interface_modify', 'interface_del', 'host_del');
        if (!$iface['nat_interface_id']) throw ApiError::notFound('NAT address');
        Ona::run('nat_del', ['ip' => $id, 'natip' => (int)$iface['nat_interface_id'], 'commit' => 'Y'], ['interface_modify', 'interface_del', 'host_del']);
        return [204, null];
    }

    /**
     * POST /interfaces/move {start, end?, newStart, newEnd?, commit}: move the
     * interfaces in an address range of one subnet to free addresses of
     * another (interface_move, `interface_modify`). Without commit it only
     * reports what would move. IPv4 only, as in ONA.
     */
    public static function bulkMove(Request $req): array
    {
        $in = new Input($req->body());
        $opts = [
            'start' => $in->ip('start', true),
            'end' => $in->ip('end'),
            'new_start' => $in->ip('newStart', true),
            'new_end' => $in->ip('newEnd'),
        ];
        $commit = $in->bool('commit', false);
        foreach (['start' => 'start', 'end' => 'end', 'new_start' => 'newStart', 'new_end' => 'newEnd'] as $opt => $field) {
            if ($opts[$opt] !== null && Ona::ipNumeric($opts[$opt]) !== null && !Ona::isIpv4(Ona::ipNumeric($opts[$opt]))) $in->error($field, 'must be an IPv4 address');
        }
        $in->check();
        Auth::requirePerm('interface_modify');
        $opts['commit'] = $commit;
        // Exit status 7 is the module's "not committed" preview; run_module()
        // adds 100 to any non-zero status (and rolls back, which is fine here).
        $out = Ona::run('interface_move', $opts, ['interface_modify'], $commit ? [0] : [7, 107]);
        $moves = [];
        foreach (Ona::cleanLines($out) as $line) {
            if (preg_match('/^(\S+)\s*->\s*(\S+)\s*\((.*)\)$/', $line, $m)) $moves[] = ['from' => $m[1], 'to' => $m[2], 'host' => $m[3]];
        }
        return [200, ['committed' => $commit, 'moves' => $moves]];
    }

    /** Hosts an interface is shared with (interface_clusters). */
    public static function sharedWith(int $id): array
    {
        $rows = Ona::select('SELECT c.host_id, c.name, d.name AS host_name, d.domain_id
            FROM interface_clusters c
            LEFT JOIN hosts h ON h.id = c.host_id
            LEFT JOIN dns d ON d.id = h.primary_dns_id
            WHERE c.interface_id = ? ORDER BY d.name', [$id]);
        return array_map(fn($r) => [
            'hostId' => (int)$r['host_id'],
            'fqdn' => ($r['host_name'] ? $r['host_name'] . '.' : '') . Ona::domainName($r['domain_id']),
            'name' => (string)$r['name'],
        ], $rows);
    }

    public static function format(array $i): array
    {
        $out = [
            'id' => (int)$i['id'],
            'ip' => Ona::ipText($i['ip_addr']),
            'ipVersion' => Ona::isIpv4($i['ip_addr']) ? 4 : 6,
            'mac' => Ona::mac($i['mac_addr']),
            'name' => $i['name'],
            'description' => $i['description'],
            'lastResponse' => $i['last_response'],
            'natInterfaceId' => $i['nat_interface_id'] ? (int)$i['nat_interface_id'] : null,
            'host' => null,
            'subnet' => null,
        ];
        if ($i['host_id']) {
            $fqdn = ($i['host_name'] ? $i['host_name'] . '.' : '') . Ona::domainName($i['host_domain_id']);
            $out['host'] = ['id' => (int)$i['host_id'], 'fqdn' => $fqdn];
        }
        if ($i['subnet_id']) {
            $out['subnet'] = [
                'id' => (int)$i['subnet_id'],
                'name' => $i['subnet_name'],
                'network' => Ona::ipText($i['subnet_ip']) . '/' . Ona::cidr($i['subnet_mask']),
            ];
        }
        return $out;
    }
}
