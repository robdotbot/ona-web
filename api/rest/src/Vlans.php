<?php
namespace OnaRest;

/** VLAN campuses: named groups of VLANs (vlan_campus_* modules, vlan_* permissions). */
class VlanCampuses
{
    private const BASE_SQL = 'SELECT c.id, c.name,
            (SELECT COUNT(*) FROM vlans v WHERE v.vlan_campus_id = c.id) AS vlan_count
        FROM vlan_campuses c';

    public static function list(Request $req): array
    {
        $paging = new Paging($req, 50);
        $where = [];
        $params = [];
        $q = $req->queryString('q');
        if ($q !== '') {
            $where[] = 'c.name LIKE ?';
            $params[] = '%' . Sql::likeEscape(strtoupper($q)) . '%';
        }
        $sql = self::BASE_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $order = Paging::orderBy($req, ['name' => 'c.name', 'id' => 'c.id'], 'name');
        return $paging->run($sql, $params, $order . ', c.id', [self::class, 'format']);
    }

    public static function load(int $id): array
    {
        $rows = Ona::select(self::BASE_SQL . ' WHERE c.id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('VLAN campus');
        return $rows[0];
    }

    public static function get(int $id): array
    {
        return [200, self::format(self::load($id))];
    }

    public static function create(Request $req): array
    {
        Auth::requirePerm('vlan_add');
        $in = new Input($req->body());
        $name = $in->label('name', true);
        $in->check();
        Ona::run('vlan_campus_add', ['name' => $name], ['vlan_add']);
        $rows = Ona::select('SELECT id FROM vlan_campuses WHERE name = ?', [strtoupper($name)]);
        if (!$rows) throw new ApiError(500, 'internal_error', 'Campus was added but could not be read back');
        return [201, self::format(self::load((int)$rows[0]['id']))];
    }

    public static function update(Request $req, int $id): array
    {
        Auth::requirePerm('vlan_modify');
        $campus = self::load($id);
        $in = new Input($req->body());
        $name = $in->label('name', true);
        $in->check();
        if (strtoupper($name) !== $campus['name']) {
            Ona::run('vlan_campus_modify', ['name' => $id, 'set_name' => $name], ['vlan_modify']);
        }
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        Auth::requirePerm('vlan_del');
        self::load($id);
        Ona::run('vlan_campus_del', ['name' => $id, 'commit' => 'Y'], ['vlan_del']);
        return [204, null];
    }

    public static function format(array $c): array
    {
        return ['id' => (int)$c['id'], 'name' => $c['name'], 'vlanCount' => (int)$c['vlan_count']];
    }
}

/** VLANs, each in one campus (vlan_* modules and permissions). */
class Vlans
{
    private const BASE_SQL = 'SELECT v.id, v.name, v.number, v.vlan_campus_id, c.name AS campus_name,
            (SELECT COUNT(*) FROM subnets s WHERE s.vlan_id = v.id) AS subnet_count
        FROM vlans v
        LEFT JOIN vlan_campuses c ON c.id = v.vlan_campus_id';

    public static function list(Request $req): array
    {
        $paging = new Paging($req, 50);
        $where = [];
        $params = [];
        $q = $req->queryString('q');
        if ($q !== '') {
            $cond = ['v.name LIKE ?'];
            $params[] = '%' . Sql::likeEscape(strtoupper($q)) . '%';
            if (ctype_digit($q)) {
                $cond[] = 'v.number = ?';
                $params[] = (int)$q;
            }
            $where[] = '(' . implode(' OR ', $cond) . ')';
        }
        if (($campus = $req->queryInt('campusId')) !== null) {
            $where[] = 'v.vlan_campus_id = ?';
            $params[] = $campus;
        }
        $sql = self::BASE_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $order = Paging::orderBy($req, ['number' => 'v.number', 'name' => 'v.name', 'campus' => 'c.name'], 'number');
        return $paging->run($sql, $params, $order . ', v.id', [self::class, 'format']);
    }

    public static function load(int $id): array
    {
        $rows = Ona::select(self::BASE_SQL . ' WHERE v.id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('VLAN');
        return $rows[0];
    }

    public static function get(int $id): array
    {
        $vlan = self::format(self::load($id));
        $subnets = Ona::select('SELECT s.*, t.display_name AS type_name FROM subnets s
            LEFT JOIN subnet_types t ON t.id = s.subnet_type_id WHERE s.vlan_id = ? ORDER BY s.ip_addr', [$id]);
        $vlan['subnets'] = array_map([Subnets::class, 'format'], $subnets);
        return [200, $vlan];
    }

    public static function create(Request $req): array
    {
        Auth::requirePerm('vlan_add');
        $in = new Input($req->body());
        $campusId = $in->int('campusId', true, 1);
        $name = $in->label('name', true);
        $number = $in->int('number', true, 1, 4094);
        $in->check();
        VlanCampuses::load($campusId);
        Ona::run('vlan_add', ['campus' => $campusId, 'name' => $name, 'number' => $number], ['vlan_add']);
        $rows = Ona::select('SELECT id FROM vlans WHERE vlan_campus_id = ? AND number = ?', [$campusId, $number]);
        if (!$rows) throw new ApiError(500, 'internal_error', 'VLAN was added but could not be read back');
        return [201, self::get((int)$rows[0]['id'])[1]];
    }

    public static function update(Request $req, int $id): array
    {
        Auth::requirePerm('vlan_modify');
        $vlan = self::load($id);
        $in = new Input($req->body());
        $name = $in->label('name');
        $number = $in->int('number', false, 1, 4094);
        $campusId = $in->int('campusId', false, 1);
        $in->check();
        if ($campusId !== null) VlanCampuses::load($campusId);
        // vlan_modify treats every set_* it receives as a change, so only send real changes.
        $set = [];
        if ($name !== null && $name !== '' && strtoupper($name) !== $vlan['name']) $set['set_name'] = $name;
        if ($number !== null && $number !== (int)$vlan['number']) $set['set_number'] = $number;
        if ($campusId !== null && $campusId !== (int)$vlan['vlan_campus_id']) $set['set_campus'] = $campusId;
        if ($name === null && $number === null && $campusId === null) {
            throw ApiError::badRequest('Nothing to update; send at least one of name, number, campusId');
        }
        if ($set) Ona::run('vlan_modify', ['vlan' => $id] + $set, ['vlan_modify']);
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        Auth::requirePerm('vlan_del');
        self::load($id);
        Ona::run('vlan_del', ['vlan' => $id, 'commit' => 'Y'], ['vlan_del']);
        return [204, null];
    }

    public static function format(array $v): array
    {
        return [
            'id' => (int)$v['id'],
            'name' => $v['name'],
            'number' => (int)$v['number'],
            'campus' => ['id' => (int)$v['vlan_campus_id'], 'name' => $v['campus_name']],
            'subnetCount' => (int)$v['subnet_count'],
        ];
    }
}
