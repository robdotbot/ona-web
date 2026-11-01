<?php
namespace OnaRest;

/** IP address blocks: named ranges for planning (block_* modules, `advanced` permission). */
class Blocks
{
    private const BASE_SQL = 'SELECT b.* FROM blocks b';

    public static function list(Request $req): array
    {
        $paging = new Paging($req);
        $where = [];
        $params = [];
        $q = $req->queryString('q');
        if ($q !== '') {
            if (($ip = Ona::ipNumeric($q)) !== null) {
                // An address matches the blocks that contain it.
                $where[] = '? BETWEEN b.ip_addr_start AND b.ip_addr_end';
                $params[] = $ip;
            } else {
                $where[] = '(b.name LIKE ? OR b.notes LIKE ?)';
                $params[] = '%' . Sql::likeEscape(strtoupper($q)) . '%';
                $params[] = '%' . Sql::likeEscape($q) . '%';
            }
        }
        $sql = self::BASE_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $order = Paging::orderBy($req, ['name' => 'b.name', 'start' => 'b.ip_addr_start', 'end' => 'b.ip_addr_end'], 'start');
        return $paging->run($sql, $params, $order . ', b.id', [self::class, 'format']);
    }

    public static function load(int $id): array
    {
        $rows = Ona::select(self::BASE_SQL . ' WHERE b.id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('Block');
        return $rows[0];
    }

    /** A block with the subnets that fall (at least partly) inside it. */
    public static function get(int $id): array
    {
        $b = self::load($id);
        $out = self::format($b);
        $subnets = Ona::select('SELECT s.*, t.display_name AS type_name FROM subnets s
            LEFT JOIN subnet_types t ON t.id = s.subnet_type_id
            WHERE s.ip_addr BETWEEN ? AND ? ORDER BY s.ip_addr', [$b['ip_addr_start'], $b['ip_addr_end']], 500);
        $out['subnets'] = array_map([Subnets::class, 'format'], $subnets);
        return [200, $out];
    }

    public static function create(Request $req): array
    {
        Auth::requirePerm('advanced');
        $in = new Input($req->body());
        $opts = [
            'name' => $in->label('name', true),
            'start' => $in->ip('start', true),
            'end' => $in->ip('end', true),
            'notes' => $in->string('notes', false, 191),
        ];
        $in->check();
        self::checkRange($opts['start'], $opts['end']);
        $before = Ona::maxId('blocks');
        Ona::run('block_add', $opts, ['advanced']);
        return [201, self::get(Ona::newId('blocks', $before))[1]];
    }

    public static function update(Request $req, int $id): array
    {
        Auth::requirePerm('advanced');
        $b = self::load($id);
        $in = new Input($req->body());
        $set = [
            'set_name' => $in->label('name'),
            'set_start' => $in->ip('start'),
            'set_end' => $in->ip('end'),
            'set_notes' => $in->string('notes', false, 191),
        ];
        $in->check();
        $set = array_filter($set, fn($v) => $v !== null);
        if (!$set) throw ApiError::badRequest('Nothing to update; send at least one of name, start, end, notes');
        self::checkRange($set['set_start'] ?? Ona::ipText($b['ip_addr_start']), $set['set_end'] ?? Ona::ipText($b['ip_addr_end']));
        if (($set['set_name'] ?? null) === '') unset($set['set_name']);
        // block_modify prints its usage unless a set_* option is truthy, so
        // clearing the notes alone resends the current name (a no-op).
        if (!array_filter($set)) $set['set_name'] = $b['name'];
        Ona::run('block_modify', ['block' => $id] + $set, ['advanced']);
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        Auth::requirePerm('advanced');
        self::load($id);
        Ona::run('block_del', ['block' => $id, 'commit' => 'Y'], ['advanced']);
        return [204, null];
    }

    private static function checkRange(string $start, string $end): void
    {
        $s = Ona::ipNumeric($start);
        $e = Ona::ipNumeric($end);
        if (Ona::isIpv4($s) !== Ona::isIpv4($e)) {
            throw ApiError::validation('Invalid input', ['end' => 'must be the same IP version as start']);
        }
        if (gmp_cmp(gmp_init($s), gmp_init($e)) > 0) {
            throw ApiError::validation('Invalid input', ['end' => 'must not be before start']);
        }
    }

    public static function format(array $b): array
    {
        $start = gmp_init((string)$b['ip_addr_start']);
        $end = gmp_init((string)$b['ip_addr_end']);
        return [
            'id' => (int)$b['id'],
            'name' => $b['name'],
            'start' => Ona::ipText($b['ip_addr_start']),
            'end' => Ona::ipText($b['ip_addr_end']),
            'ipVersion' => Ona::isIpv4($b['ip_addr_start']) ? 4 : 6,
            // A string: IPv6 blocks can exceed what a JSON number holds exactly.
            'size' => gmp_strval(gmp_add(gmp_sub($end, $start), 1)),
            'notes' => (string)$b['notes'],
        ];
    }
}
