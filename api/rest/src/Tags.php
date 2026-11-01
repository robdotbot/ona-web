<?php
namespace OnaRest;

/**
 * Tags on hosts and subnets (tag_add / tag_del). ONA lets either the
 * `host_add` or `subnet_add` permission add a tag (and `host_del` or
 * `subnet_del` remove one); the API asks for the one matching the record.
 */
class Tags
{
    private const TYPES = ['host' => ['hostId', 'host_add', 'host_del'], 'subnet' => ['subnetId', 'subnet_add', 'subnet_del']];

    /** GET /tags?hostId= | ?subnetId= | ?name=: tags on a record, or every use of a name. */
    public static function list(Request $req): array
    {
        $where = [];
        $params = [];
        foreach (self::TYPES as $type => [$field]) {
            if (($id = $req->queryInt($field)) !== null) {
                $where[] = 'type = ? AND reference = ?';
                array_push($params, $type, $id);
            }
        }
        $name = $req->queryString('name');
        if ($name !== '') {
            $where[] = 'name = ?';
            $params[] = $name;
        }
        if (!$where) throw ApiError::badRequest('Pass hostId, subnetId or name (GET /tags/names lists all tag names)');
        $rows = Ona::select('SELECT * FROM tags WHERE ' . implode(' AND ', $where) . ' ORDER BY name, type, reference', $params, 1000);
        return [200, ['data' => array_map([self::class, 'format'], $rows)]];
    }

    /** GET /tags/names: every tag name with how many hosts and subnets carry it. */
    public static function names(): array
    {
        $rows = Ona::select("SELECT name, SUM(type = 'host') AS hosts, SUM(type = 'subnet') AS subnets FROM tags GROUP BY name ORDER BY name");
        return [200, ['data' => array_map(fn($r) => ['name' => $r['name'], 'hostCount' => (int)$r['hosts'], 'subnetCount' => (int)$r['subnets']], $rows)]];
    }

    public static function load(int $id): array
    {
        $rows = Ona::select('SELECT * FROM tags WHERE id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('Tag');
        return $rows[0];
    }

    /** POST /tags {name, hostId | subnetId} */
    public static function create(Request $req): array
    {
        $in = new Input($req->body());
        $name = self::name($in);
        [$type, $ref] = self::target($in);
        $in->check();
        Auth::requirePerm(self::TYPES[$type][1]);
        $type === 'host' ? Hosts::load($ref) : Subnets::load($ref);
        if (Ona::select('SELECT id FROM tags WHERE type = ? AND reference = ? AND name = ?', [$type, $ref, $name])) {
            throw new ApiError(409, 'conflict', "The tag {$name} is already on this {$type}");
        }
        $before = Ona::maxId('tags');
        Ona::run('tag_add', ['type' => $type, 'reference' => $ref, 'name' => $name], [self::TYPES[$type][1]]);
        $id = Ona::newId('tags', $before, 'type = ? AND reference = ?', [$type, $ref]);
        return [201, self::format(self::load($id))];
    }

    /** DELETE /tags/{id} */
    public static function delete(int $id): array
    {
        $tag = self::load($id);
        $perm = self::TYPES[$tag['type']][2] ?? 'advanced';
        Ona::run('tag_del', ['tag' => $id, 'commit' => 'Y'], [$perm]);
        return [204, null];
    }

    /** Tag names on one record, for the host and subnet formats. */
    public static function namesFor(string $type, int $id): array
    {
        return array_column(Ona::select('SELECT name FROM tags WHERE type = ? AND reference = ? ORDER BY name', [$type, $id]), 'name');
    }

    /** SQL condition for "?tag=" list filters; $refColumn is the record's id column. */
    public static function filter(string $type, string $refColumn): string
    {
        return "EXISTS (SELECT 1 FROM tags tg WHERE tg.type = '{$type}' AND tg.reference = {$refColumn} AND tg.name = ?)";
    }

    /** Like tag_add: whitespace becomes "-"; we also limit names to a safe set. */
    private static function name(Input $in): ?string
    {
        $v = $in->string('name', true, 127);
        if ($v === null || $v === '') return $v;
        $v = preg_replace('/\s+/', '-', $v);
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/+-]*$#', $v)) $in->error('name', 'may only contain letters, digits and . _ : / + -');
        return $v;
    }

    private static function target(Input $in): array
    {
        $host = $in->int('hostId', false, 1);
        $subnet = $in->int('subnetId', false, 1);
        if (($host === null) === ($subnet === null)) $in->error('hostId', 'send exactly one of hostId, subnetId');
        return $host !== null ? ['host', $host] : ['subnet', (int)$subnet];
    }

    public static function format(array $t): array
    {
        return [
            'id' => (int)$t['id'],
            'name' => $t['name'],
            'target' => ['kind' => $t['type'], 'id' => (int)$t['reference']],
        ];
    }
}

/**
 * Messages shown on host and subnet pages (message_add, `advanced`). ONA
 * has no module to change or delete one; the legacy UI only lets them
 * expire, so PATCH/DELETE write the table directly under `advanced`.
 */
class Messages
{
    private const TABLES = ['hostId' => 'hosts', 'subnetId' => 'subnets'];
    public const PRIORITIES = [0 => 'info', 1 => 'high', 2 => 'medium', 3 => 'low'];

    /** GET /messages?hostId= | ?subnetId= [&includeExpired=true] */
    public static function list(Request $req): array
    {
        $where = [];
        $params = [];
        foreach (self::TABLES as $field => $table) {
            if (($id = $req->queryInt($field)) !== null) {
                $where[] = 'table_name_ref = ? AND table_id_ref = ?';
                array_push($params, $table, $id);
            }
        }
        if ($req->queryString('includeExpired') !== 'true') {
            $where[] = '(expiration IS NULL OR expiration > ?)';
            $params[] = date('Y-m-d H:i:s');
        }
        $sql = 'SELECT * FROM messages' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY priority, mtime DESC, id DESC';
        return [200, ['data' => array_map([self::class, 'format'], Ona::select($sql, $params, 500))]];
    }

    public static function load(int $id): array
    {
        $rows = Ona::select('SELECT * FROM messages WHERE id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('Message');
        return $rows[0];
    }

    public static function get(int $id): array
    {
        return [200, self::format(self::load($id))];
    }

    /** POST /messages {hostId | subnetId, message, priority?, expiration?} */
    public static function create(Request $req): array
    {
        Auth::requirePerm('advanced');
        $in = new Input($req->body());
        $host = $in->int('hostId', false, 1);
        $subnet = $in->int('subnetId', false, 1);
        if (($host === null) === ($subnet === null)) $in->error('hostId', 'send exactly one of hostId, subnetId');
        $text = $in->string('message', true, 4000);
        $priority = $in->int('priority', false, 0, 3);
        $expiration = self::date($in, 'expiration');
        $in->check();
        $host !== null ? Hosts::load($host) : Subnets::load($subnet);
        $before = Ona::maxId('messages');
        Ona::run('message_add', [
            $host !== null ? 'host' : 'subnet' => $host ?? $subnet,
            'message' => $text,
            'priority' => $priority ?? 3,
            'expiration' => $expiration,
        ], ['advanced']);
        return [201, self::get(Ona::newId('messages', $before))[1]];
    }

    /** PATCH /messages/{id} {message?, priority?, expiration?}; send expiration "now" to expire it. */
    public static function update(Request $req, int $id): array
    {
        Auth::requirePerm('advanced');
        $msg = self::load($id);
        $in = new Input($req->body());
        $set = [
            'message_text' => $in->string('message', false, 4000),
            'priority' => $in->int('priority', false, 0, 3),
            'expiration' => $in->raw('expiration') === 'now' ? date('Y-m-d H:i:s') : self::date($in, 'expiration'),
        ];
        if ($set['message_text'] === '') $in->error('message', 'is required');
        $in->check();
        $set = array_filter($set, fn($v) => $v !== null);
        if (!$set) throw ApiError::badRequest('Nothing to update; send message, priority or expiration');
        Ona::write(['advanced'], "Message UPDATED: {$id} on {$msg['table_name_ref']} {$msg['table_id_ref']}", function ($db) use ($id, $set) {
            Ona::dbOk(db_update_record($db, 'messages', ['id' => $id], $set));
        });
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        Auth::requirePerm('advanced');
        $msg = self::load($id);
        Ona::write(['advanced'], "Message DELETED: {$id} from {$msg['table_name_ref']} {$msg['table_id_ref']}", function ($db) use ($id) {
            Ona::dbOk(db_delete_records($db, 'messages', ['id' => $id]));
        });
        return [204, null];
    }

    /** A YYYY-MM-DD[ HH:MM[:SS]] date, returned as Y-m-d H:i:s. */
    private static function date(Input $in, string $key): ?string
    {
        $v = $in->string($key, false, 32);
        if ($v === null || $v === '') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $v) || ($t = strtotime(str_replace('T', ' ', $v))) === false) {
            $in->error($key, 'must be a date (YYYY-MM-DD or YYYY-MM-DD HH:MM[:SS])');
            return null;
        }
        return date('Y-m-d H:i:s', $t);
    }

    /** The host's FQDN or the subnet's name, for lists that mix targets. */
    private static function targetName(string $kind, int $id): ?string
    {
        if ($kind === 'host') {
            $r = Ona::select('SELECT d.name, d.domain_id FROM hosts h JOIN dns d ON d.id = h.primary_dns_id WHERE h.id = ?', [$id]);
            return $r ? ($r[0]['name'] ? $r[0]['name'] . '.' : '') . Ona::domainName($r[0]['domain_id']) : null;
        }
        if ($kind === 'subnet') {
            $r = Ona::select('SELECT name FROM subnets WHERE id = ?', [$id]);
            return $r ? $r[0]['name'] : null;
        }
        return null;
    }

    public static function format(array $m): array
    {
        $kind = $m['table_name_ref'] === 'hosts' ? 'host' : ($m['table_name_ref'] === 'subnets' ? 'subnet' : $m['table_name_ref']);
        return [
            'id' => (int)$m['id'],
            'target' => ['kind' => $kind, 'id' => (int)$m['table_id_ref'], 'name' => self::targetName($kind, (int)$m['table_id_ref'])],
            'priority' => (int)$m['priority'],
            'priorityName' => self::PRIORITIES[(int)$m['priority']] ?? 'low',
            'message' => $m['message_text'],
            'username' => $m['username'],
            'createdAt' => $m['mtime'],
            'expiration' => $m['expiration'],
            'expired' => $m['expiration'] !== null && strtotime($m['expiration']) <= time(),
        ];
    }
}

/** GET /change-log: the ona_logs table (`advanced`); filled only when sys_config log_to_db is 1. */
class ChangeLog
{
    public static function list(Request $req): array
    {
        Auth::requirePerm('advanced');
        global $conf;
        $paging = new Paging($req, 50);
        $where = [];
        $params = [];
        $q = $req->queryString('q');
        if ($q !== '') {
            $where[] = 'message LIKE ?';
            $params[] = '%' . Sql::likeEscape($q) . '%';
        }
        $user = $req->queryString('username');
        if ($user !== '') {
            $where[] = 'username = ?';
            $params[] = $user;
        }
        $sql = 'SELECT * FROM ona_logs' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $order = Paging::orderBy($req, ['timestamp' => 'timestamp', 'username' => 'username'], '-timestamp');
        [$status, $body] = $paging->run($sql, $params, $order . ', id DESC', fn($r) => [
            'id' => (int)$r['id'],
            'timestamp' => $r['timestamp'],
            'username' => $r['username'],
            'remoteAddr' => $r['remote_addr'],
            'context' => $r['context_name'],
            'message' => $r['message'],
        ]);
        $body['meta']['logToDb'] = !empty($conf['log_to_db']);
        return [$status, $body];
    }
}
