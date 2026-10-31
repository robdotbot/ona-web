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
        return [200, self::format(self::load($id))];
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
        return [201, self::format(self::load((int)$iface['id']))];
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
