<?php
namespace OnaRest;

class Subnets
{
    private const BASE_SQL = 'SELECT s.*, t.display_name AS type_name
        FROM subnets s LEFT JOIN subnet_types t ON t.id = s.subnet_type_id';

    public static function list(Request $req): array
    {
        $paging = new Paging($req);
        $where = [];
        $params = [];

        $q = $req->queryString('q');
        if ($q !== '') {
            $cond = ['s.name LIKE ?'];
            $params[] = '%' . Sql::likeEscape(strtoupper($q)) . '%';
            if (($ip = Ona::ipNumeric($q)) !== null) {
                // An address matches the subnet that contains it.
                [, $rows, $hit] = ona_find_subnet($q);
                if ($rows) {
                    $cond[] = 's.id = ?';
                    $params[] = $hit['id'];
                }
            }
            $where[] = '(' . implode(' OR ', $cond) . ')';
        }
        if (($type = $req->queryInt('typeId')) !== null) {
            $where[] = 's.subnet_type_id = ?';
            $params[] = $type;
        }

        $sql = self::BASE_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $order = Paging::orderBy($req, ['name' => 's.name', 'ip' => 's.ip_addr', 'type' => 't.display_name'], 'ip');
        return $paging->run($sql, $params, $order . ', s.id', [self::class, 'format']);
    }

    public static function get(int $id): array
    {
        return [200, self::format(self::load($id), true)];
    }

    public static function load(int $id): array
    {
        $rows = Ona::select(self::BASE_SQL . ' WHERE s.id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('Subnet');
        return $rows[0];
    }

    public static function create(Request $req): array
    {
        $in = new Input($req->body());
        $name = $in->string('name', true, 127);
        $ip = $in->ip('ip', true);
        $mask = self::netmask($in, 'netmask', true);
        $type = $in->int('typeId', true, 1);
        $in->check();

        Ona::run('subnet_add', ['name' => $name, 'ip' => $ip, 'netmask' => $mask, 'type' => $type], ['subnet_add']);

        $rows = Ona::select(self::BASE_SQL . ' WHERE s.ip_addr = ?', [Ona::ipNumeric($ip)]);
        return [201, self::format($rows[0], true)];
    }

    public static function update(Request $req, int $id): array
    {
        self::load($id);
        $in = new Input($req->body());
        $set = [
            'set_name' => $in->string('name', false, 127),
            'set_ip' => $in->ip('ip'),
            'set_netmask' => self::netmask($in, 'netmask', false),
            'set_type' => $in->int('typeId', false, 1),
        ];
        $in->check();
        $set = array_filter($set, fn($v) => $v !== null && $v !== '');
        if (!$set) throw ApiError::badRequest('Nothing to update; send at least one of name, ip, netmask, typeId');

        Ona::run('subnet_modify', ['subnet' => $id] + $set, ['subnet_modify']);
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        self::load($id);
        Ona::run('subnet_del', ['subnet' => $id, 'commit' => 'Y'], ['subnet_del']);
        return [204, null];
    }

    /** GET /subnets/{id}/next-ip: first unused address, via ONA's subnet_nextip. */
    public static function nextIp(int $id): array
    {
        self::load($id);
        $out = Ona::run('subnet_nextip', ['subnet' => $id, 'output' => 'dotted'], []);
        return [200, ['ip' => strtolower(trim($out))]];
    }

    /** Accept "24", "/24" or "255.255.255.0". */
    private static function netmask(Input $in, string $key, bool $required): ?string
    {
        $v = $in->string($key, $required, 64);
        if ($v === null || $v === '') return null;
        if (preg_match('#^/?(\d{1,3})$#', $v, $m)) {
            if ((int)$m[1] > 128) $in->error($key, 'CIDR prefix must be 0-128');
            return '/' . $m[1];
        }
        if (ip_mangle($v, 'cidr') === -1) $in->error($key, 'must be a prefix length (24) or dotted mask (255.255.255.0)');
        return $v;
    }

    public static function format(array $s, bool $detail = false): array
    {
        $cidr = Ona::cidr($s['ip_mask']);
        $ip = Ona::ipText($s['ip_addr']);
        $v4 = Ona::isIpv4($s['ip_addr']);
        $usage = get_subnet_usage($s['id']);
        [$percent, $used, $total] = is_array($usage) ? $usage : [0, 0, 0];

        $out = [
            'id' => (int)$s['id'],
            'name' => $s['name'],
            'ip' => $ip,
            'cidr' => $cidr,
            'netmask' => $v4 ? Ona::ipText($s['ip_mask']) : "/{$cidr}",
            'network' => "{$ip}/{$cidr}",
            'ipVersion' => $v4 ? 4 : 6,
            'type' => $s['subnet_type_id'] ? ['id' => (int)$s['subnet_type_id'], 'name' => $s['type_name']] : null,
            'usage' => ['used' => (int)$used, 'total' => (string)$total, 'percent' => (int)$percent],
        ];
        if ($detail) {
            $out['vlan'] = null;
            if ($s['vlan_id']) {
                [, $rows, $vlan] = ona_get_vlan_record(['id' => $s['vlan_id']]);
                if ($rows) $out['vlan'] = ['id' => (int)$vlan['id'], 'name' => $vlan['name'], 'number' => (int)$vlan['number'], 'campus' => $vlan['vlan_campus_name']];
            }
            if ($v4) {
                $last = (int)$s['ip_addr'] + (0xffffffff - (int)$s['ip_mask']);
                $out['firstIp'] = Ona::ipText($s['ip_addr']);
                $out['lastIp'] = Ona::ipText($last);
            }
            $out['interfaceCount'] = Ona::count('SELECT id FROM interfaces WHERE subnet_id = ?', [$s['id']]);
            $out['hostCount'] = Ona::count('SELECT DISTINCT host_id FROM interfaces WHERE subnet_id = ?', [$s['id']]);
        }
        return $out;
    }
}

class Sql
{
    public static function likeEscape(string $s): string
    {
        return addcslashes($s, '%_\\');
    }
}
