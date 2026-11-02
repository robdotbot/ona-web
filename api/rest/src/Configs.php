<?php
namespace OnaRest;

/**
 * Config archives: text snapshots of a device's configuration
 * (config_add / config_del / config_display / config_diff). Reads and
 * writes need `host_config_admin`, as in the legacy config window.
 */
class Configs
{
    private const BASE_SQL = 'SELECT c.id, c.host_id, c.configuration_type_id, c.md5_checksum, c.ctime,
            LENGTH(c.config_body) AS size, t.name AS type_name, d.name AS host_name, d.domain_id AS host_domain_id
        FROM configurations c
        LEFT JOIN configuration_types t ON t.id = c.configuration_type_id
        LEFT JOIN hosts h ON h.id = c.host_id
        LEFT JOIN dns d ON d.id = h.primary_dns_id';

    /** Largest config text accepted (the column is LONGTEXT; JSON bodies are held in memory). */
    private const MAX_TEXT = 4 * 1024 * 1024;

    /** GET /configs?hostId=&typeId=: newest first, without the text. */
    public static function list(Request $req): array
    {
        Auth::requirePerm('host_config_admin');
        $paging = new Paging($req, 50);
        $where = [];
        $params = [];
        if (($h = $req->queryInt('hostId')) !== null) { $where[] = 'c.host_id = ?'; $params[] = $h; }
        if (($t = $req->queryInt('typeId')) !== null) { $where[] = 'c.configuration_type_id = ?'; $params[] = $t; }
        $sql = self::BASE_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        return $paging->run($sql, $params, 'c.ctime DESC, c.id DESC', [self::class, 'format']);
    }

    public static function load(int $id): array
    {
        $rows = Ona::select(self::BASE_SQL . ' WHERE c.id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('Config');
        return $rows[0];
    }

    /** GET /configs/{id}: with the text, read through config_display. */
    public static function get(int $id): array
    {
        Auth::requirePerm('host_config_admin');
        $c = self::load($id);
        $text = Ona::run('config_display', ['config' => $id, 'verbose' => 'N'], ['host_config_admin'], [0], false);
        return [200, self::format($c) + ['text' => $text]];
    }

    /** POST /configs {hostId, typeId, text}: the text itself, never a file name. */
    public static function create(Request $req): array
    {
        Auth::requirePerm('host_config_admin');
        $body = $req->body();
        $in = new Input($body);
        $hostId = $in->int('hostId', true, 1);
        $typeId = $in->int('typeId', true, 1);
        $text = $body['text'] ?? null;
        if (!is_string($text) || trim($text) === '') $in->error('text', 'is required');
        elseif (strlen($text) > self::MAX_TEXT) $in->error('text', 'must be at most ' . self::MAX_TEXT . ' bytes');
        elseif (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $text)) $in->error('text', 'contains control characters');
        $in->check();
        Hosts::load($hostId);
        $type = Reference::load('config-types', $typeId);
        // config first: run_module() drops "config=...&" from its log line,
        // so the text (which may hold secrets) isn't logged.
        $out = Ona::run('config_add', ['config' => $text, 'host' => $hostId, 'type' => $type['name']], ['host_config_admin']);
        if (!preg_match('/ID:\s*(\d+)/', $out, $m)) throw new ApiError(500, 'internal_error', 'The config was added but could not be read back');
        return [201, self::get((int)$m[1])[1]];
    }

    public static function delete(int $id): array
    {
        Auth::requirePerm('host_config_admin');
        self::load($id);
        Ona::run('config_del', ['config' => $id, 'commit' => 'Y'], ['host_config_admin']);
        return [204, null];
    }

    /** DELETE /hosts/{id}/configs: delete every archived config of a host (config_del_all). */
    public static function deleteAll(int $hostId): array
    {
        Auth::requirePerm('host_config_admin');
        Hosts::load($hostId);
        $n = Ona::count('SELECT id FROM configurations WHERE host_id = ?', [$hostId]);
        // config_del_all fails when there is nothing to delete; that's not an error here.
        if ($n) Ona::run('config_del_all', ['host' => $hostId, 'commit' => 'Y'], ['host_config_admin']);
        return [200, ['deleted' => $n]];
    }

    /** GET /configs/diff?from=&to=: unified diff from the older to the newer of two configs (config_diff). */
    public static function diff(Request $req): array
    {
        Auth::requirePerm('host_config_admin');
        $a = $req->queryInt('from');
        $b = $req->queryInt('to');
        if (!$a || !$b) throw ApiError::badRequest('from and to (config IDs) are required');
        if ($a === $b) throw ApiError::badRequest('from and to must be different configs');
        $ca = self::load($a);
        $cb = self::load($b);
        $diff = Ona::run('config_diff', ['ida' => $a, 'idb' => $b], ['host_config_admin'], [0], false);
        // config_diff always compares older -> newer.
        [$old, $new] = strtotime($ca['ctime']) > strtotime($cb['ctime']) || (strtotime($ca['ctime']) === strtotime($cb['ctime']) && $a > $b) ? [$cb, $ca] : [$ca, $cb];
        return [200, ['from' => self::format($old), 'to' => self::format($new), 'diff' => $diff]];
    }

    public static function format(array $c): array
    {
        return [
            'id' => (int)$c['id'],
            'host' => ['id' => (int)$c['host_id'], 'fqdn' => ($c['host_name'] ? $c['host_name'] . '.' : '') . Ona::domainName($c['host_domain_id'])],
            'type' => ['id' => (int)$c['configuration_type_id'], 'name' => (string)$c['type_name']],
            'md5' => $c['md5_checksum'],
            'size' => (int)$c['size'],
            'createdAt' => $c['ctime'],
        ];
    }
}

/**
 * Reports from plugins (report_run). Only names from `report_run list=Y`
 * are accepted, output is text or CSV only (never a plugin's HTML), and
 * parameters are plain key/value strings.
 */
class Reports
{
    public const FORMATS = ['text', 'csv'];
    /** Options report_run / run_module read themselves. */
    private const RESERVED = ['name', 'list', 'format', 'rpt_usage', 'help', 'module_loglevel', 'js'];

    /** name => description, from report_run list=Y, limited to plugins that have a report. */
    private static function available(): array
    {
        static $list = null;
        if ($list !== null) return $list;
        $runnable = array_column(plugin_list('report'), 'name');
        $out = Ona::run('report_run', ['list' => 'Y'], []);
        $list = [];
        foreach (Ona::cleanLines($out) as $line) {
            if ($line === 'Report Name' || preg_match('/^-+$/', $line)) continue;
            if (preg_match('/^(\S+)\s*(.*)$/', $line, $m) && in_array($m[1], $runnable, true)) $list[$m[1]] = $m[2];
        }
        ksort($list);
        return $list;
    }

    private static function name(string $key): string
    {
        $list = self::available();
        if (!array_key_exists($key, $list)) throw ApiError::notFound('Report');
        return $key;
    }

    public static function list(): array
    {
        $data = [];
        foreach (self::available() as $name => $desc) $data[] = ['name' => $name, 'description' => $desc];
        return [200, ['data' => $data]];
    }

    /** GET /reports/{name}: the report's usage text (rpt_usage). */
    public static function get(string $key): array
    {
        $name = self::name($key);
        $usage = Ona::run('report_run', ['name' => $name, 'rpt_usage' => 'Y', 'format' => 'text'], [], [0], false);
        return [200, ['name' => $name, 'description' => self::available()[$name], 'usage' => rtrim($usage)]];
    }

    /** POST /reports/{name}/run {format?, params?}. Reports that change data rely on the modules they call to check permissions. */
    public static function run(Request $req, string $key): array
    {
        $name = self::name($key);
        $body = $req->body();
        $in = new Input($body);
        $format = $in->string('format', false, 10) ?: 'text';
        if (!in_array($format, self::FORMATS, true)) $in->error('format', 'must be one of ' . implode(', ', self::FORMATS));
        $params = $body['params'] ?? [];
        $opts = [];
        if (!is_array($params) || ($params && array_is_list($params))) {
            $in->error('params', 'must be an object of name: value pairs');
        } elseif (count($params) > 20) {
            $in->error('params', 'at most 20 parameters');
        } else {
            foreach ($params as $k => $v) {
                $k = (string)$k;
                if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $k) || in_array($k, self::RESERVED, true)) {
                    $in->error('params', "invalid parameter name: {$k}");
                    continue;
                }
                if (is_bool($v)) $v = $v ? 'Y' : null;
                elseif (is_int($v)) $v = (string)$v;
                if ($v !== null && (!is_string($v) || strlen($v) > 4096 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $v))) {
                    $in->error('params', "parameter {$k} must be a string, number or boolean (up to 4096 characters)");
                    continue;
                }
                if ($v !== null && $v !== '') $opts[$k] = $v;
            }
        }
        $in->check();
        $out = Ona::run('report_run', ['name' => $name, 'format' => $format] + $opts, [], [0], false);
        return [200, ['name' => $name, 'format' => $format, 'output' => rtrim($out, "\n")]];
    }
}
