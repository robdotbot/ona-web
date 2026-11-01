<?php
namespace OnaRest;

/**
 * Thin helpers around ONA's own code: DCM modules for every write, and
 * functions_db / ip_mangle for reads.
 */
class Ona
{
    /**
     * Run an ONA DCM module (in its own DB transaction, via run_module()).
     *
     * The module name is always a literal from our code, never request input.
     * $requiredPerms are checked up front for a clean 403; the module does its
     * own auth() check as well.
     *
     * Options are passed as an escaped key=value string rather than an array:
     * run_module() inserts backslashes into array values containing "=" or "&",
     * while the string form is unescaped again by parse_options().
     */
    public static function run(string $module, array $options, array $requiredPerms, array $okStatuses = [0], bool $trim = true): string
    {
        global $self;
        Auth::requirePerm(...$requiredPerms);
        self::$lastStatus = 0;

        $parts = [];
        foreach ($options as $key => $value) {
            if ($value === null) continue;
            if (is_bool($value)) $value = $value ? 'Y' : 'N';
            $value = str_replace(['=', '&'], ['\\=', '\\&'], (string)$value);
            $parts[] = "{$key}={$value}";
        }
        $self['error'] = '';
        [$status, $output] = run_module($module, implode('&', $parts));
        $output = (string)$output;
        self::$lastStatus = (int)$status;
        if (in_array((int)$status, $okStatuses, true)) return $trim ? trim($output) : $output;

        $text = $output . "\n" . ($self['error'] ?? '');
        if (stripos($text, 'Permission denied') !== false) {
            throw new ApiError(403, 'forbidden', 'Permission denied', ['module' => $module]);
        }
        $message = self::firstError($text);
        $conflict = preg_match('/conflict|already|exists with the values|in use|can.t be deleted/i', $message);
        throw new ApiError($conflict ? 409 : 422, $conflict ? 'conflict' : 'module_error', $message, [
            'module' => $module,
            'moduleStatus' => (int)$status,
            'output' => self::cleanLines($output),
        ]);
    }

    /** Exit status of the last run() that returned (0 unless $okStatuses allowed another). */
    public static int $lastStatus = 0;

    public static function cleanLines(string $text): array
    {
        $lines = array_map('trim', preg_split('/\r?\n|\\\\n/', $text));
        return array_values(array_filter($lines, fn($l) => $l !== ''));
    }

    private static function firstError(string $text): string
    {
        $lines = self::cleanLines($text);
        foreach ($lines as $line) {
            if (preg_match('/^ERROR\s*=>\s*(.+)$/', $line, $m)) return $m[1];
        }
        return $lines[0] ?? 'The operation failed';
    }

    /**
     * For the few admin tables ONA has no DCM module for (users, groups,
     * permissions, sys_config, custom attribute types): run direct writes in
     * one transaction, after the same permission check the legacy UI makes.
     * $fn uses db_insert_record()/db_update_record()/db_delete_records(),
     * which take values as bound parameters.
     */
    public static function write(array $requiredPerms, string $logMessage, callable $fn)
    {
        global $onadb;
        Auth::requirePerm(...$requiredPerms);
        $onadb->StartTrans();
        try {
            $result = $fn($onadb);
        } catch (\Throwable $e) {
            $onadb->FailTrans();
            $onadb->CompleteTrans();
            throw $e;
        }
        if (!$onadb->CompleteTrans()) throw new ApiError(500, 'internal_error', 'Database update failed');
        printmsg('INFO => [REST API] ' . $logMessage, 0);
        return $result;
    }

    /** Throw unless a db_*_record() call succeeded. */
    public static function dbOk(array $result): array
    {
        if ($result[0]) {
            error_log('ona-rest: db write failed: ' . ($GLOBALS['self']['error'] ?? ''));
            throw new ApiError(500, 'internal_error', 'Database update failed');
        }
        return $result;
    }

    // ---- IP helpers -------------------------------------------------------

    /** ONA stores IPs as decimal numbers (IPv6 as big decimals). */
    public static function ipText($numeric): string
    {
        $t = ip_mangle((string)$numeric, 'dotted');
        return $t === -1 ? (string)$numeric : strtolower((string)$t);
    }

    public static function isIpv4($numeric): bool
    {
        return gmp_cmp(gmp_init((string)$numeric), gmp_init('4294967295')) <= 0;
    }

    /** Returns the numeric form of an address, or null if it isn't one. */
    public static function ipNumeric(string $text): ?string
    {
        if ($text === '' || ctype_digit($text)) return null; // don't treat bare numbers as IPs
        $n = ip_mangle($text, 'numeric');
        return $n === -1 ? null : (string)$n;
    }

    public static function cidr($maskNumeric): int
    {
        return (int)ip_mangle((string)$maskNumeric, 'cidr');
    }

    // ---- Formatting -------------------------------------------------------

    public static function mac(?string $mac): ?string
    {
        if ($mac === null || $mac === '') return null;
        $m = mac_mangle($mac, 2);
        return $m === -1 ? $mac : strtolower($m);
    }

    private static array $domainCache = [];

    public static function domainName($id): string
    {
        $id = (int)$id;
        if (!isset(self::$domainCache[$id])) self::$domainCache[$id] = (string)ona_build_domain_name($id);
        return self::$domainCache[$id];
    }

    /** Fetch one row by primary key using functions_db. */
    public static function byId(string $table, int $id): ?array
    {
        global $onadb;
        [$status, $rows, $rec] = db_get_record($onadb, $table, ['id' => $id]);
        if ($status) throw new ApiError(500, 'internal_error', 'Database query failed');
        return $rows ? $rec : null;
    }

    /** Run a parameterised SELECT and return all rows. */
    public static function select(string $sql, array $params = [], int $limit = -1, int $offset = -1): array
    {
        global $onadb;
        $rs = $onadb->SelectLimit($sql, $limit, $offset, $params ?: false);
        if ($rs === false) {
            error_log('ona-rest: SQL failed: ' . $onadb->ErrorMsg());
            throw new ApiError(500, 'internal_error', 'Database query failed');
        }
        $rows = [];
        while (!$rs->EOF) $rows[] = $rs->FetchRow();
        $rs->Close();
        return $rows;
    }

    /** Highest id in a table (the table name is always a literal from our code). */
    public static function maxId(string $table): int
    {
        return (int)(self::select("SELECT MAX(id) AS id FROM {$table}")[0]['id'] ?? 0);
    }

    /**
     * The id of the row a module just added: the modules don't report it, so
     * take the newest row after $before that matches $where.
     */
    public static function newId(string $table, int $before, string $where = '1=1', array $params = []): int
    {
        $rows = self::select("SELECT id FROM {$table} WHERE id > ? AND ({$where}) ORDER BY id DESC", array_merge([$before], $params), 1);
        if (!$rows) throw new ApiError(500, 'internal_error', 'The record was added but could not be read back');
        return (int)$rows[0]['id'];
    }

    public static function count(string $sql, array $params = []): int
    {
        $rows = self::select("SELECT COUNT(*) AS n FROM ({$sql}) AS counted", $params);
        return (int)($rows[0]['n'] ?? 0);
    }
}

/** ?page=&perPage= handling and the standard list envelope. */
class Paging
{
    public int $page;
    public int $perPage;

    public function __construct(Request $req, int $default = 25, int $max = 200)
    {
        $this->page = max(1, $req->queryInt('page') ?? 1);
        $this->perPage = $req->queryInt('perPage') ?? $default;
        if ($this->perPage < 1 || $this->perPage > $max) {
            throw ApiError::badRequest("perPage must be between 1 and {$max}");
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** Count, fetch one page and map rows into the response envelope. */
    public function run(string $sql, array $params, string $orderBy, callable $map): array
    {
        $total = Ona::count($sql, $params);
        $rows = Ona::select("{$sql} ORDER BY {$orderBy}", $params, $this->perPage, $this->offset());
        return [200, [
            'data' => array_map($map, $rows),
            'meta' => [
                'page' => $this->page,
                'perPage' => $this->perPage,
                'total' => $total,
                'totalPages' => (int)ceil($total / $this->perPage),
            ],
        ]];
    }

    /**
     * Resolve ?sort=field or ?sort=-field against a whitelist mapping API
     * field names to SQL expressions.
     */
    public static function orderBy(Request $req, array $allowed, string $default): string
    {
        $sort = $req->queryString('sort', $default);
        $desc = str_starts_with($sort, '-');
        $field = ltrim($sort, '-');
        if (!isset($allowed[$field])) {
            throw ApiError::badRequest('sort must be one of: ' . implode(', ', array_keys($allowed)) . ' (prefix with - for descending)');
        }
        return $allowed[$field] . ($desc ? ' DESC' : ' ASC');
    }
}

/** Small input validation helpers for JSON bodies. */
class Input
{
    private array $data;
    private array $errors = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /** The undecoded value, for arrays and fields the typed helpers don't cover. */
    public function raw(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function string(string $key, bool $required = false, int $max = 255): ?string
    {
        if (!$this->has($key) || $this->data[$key] === null || $this->data[$key] === '') {
            if ($required) $this->errors[$key] = 'is required';
            return $this->has($key) && $this->data[$key] === '' ? '' : null;
        }
        $v = $this->data[$key];
        if (is_int($v)) $v = (string)$v;
        if (!is_string($v)) {
            $this->errors[$key] = 'must be a string';
            return null;
        }
        $v = trim($v);
        if (strlen($v) > $max) $this->errors[$key] = "must be at most {$max} characters";
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $v)) $this->errors[$key] = 'contains control characters';
        return $v;
    }

    /**
     * A short name (VLAN, campus, block, group...). Restricted to a safe
     * character set: some ONA modules (vlan_modify, vlan_campus_modify,
     * block_modify) put the new name into SQL without escaping it.
     */
    public function label(string $key, bool $required = false, int $max = 63): ?string
    {
        $v = $this->string($key, $required, $max);
        if ($v !== null && $v !== '' && !preg_match('#^[A-Za-z0-9][A-Za-z0-9 ._:/\#()+-]*$#', $v)) {
            $this->errors[$key] = 'may only contain letters, digits, spaces and . _ : / # ( ) + -';
        }
        return $v;
    }

    public function int(string $key, bool $required = false, int $min = 0, int $max = PHP_INT_MAX): ?int
    {
        if (!$this->has($key) || $this->data[$key] === null || $this->data[$key] === '') {
            if ($required) $this->errors[$key] = 'is required';
            return null;
        }
        $v = $this->data[$key];
        if (is_string($v) && ctype_digit($v)) $v = (int)$v;
        if (!is_int($v) || $v < $min || $v > $max) {
            $this->errors[$key] = "must be an integer between {$min} and {$max}";
            return null;
        }
        return $v;
    }

    public function bool(string $key, bool $default): bool
    {
        if (!$this->has($key) || $this->data[$key] === null) return $default;
        if (!is_bool($this->data[$key])) {
            $this->errors[$key] = 'must be a boolean';
            return $default;
        }
        return $this->data[$key];
    }

    public function ip(string $key, bool $required = false): ?string
    {
        $v = $this->string($key, $required, 64);
        if ($v === null || $v === '') return null;
        if (Ona::ipNumeric($v) === null) $this->errors[$key] = 'must be an IPv4 or IPv6 address';
        return $v;
    }

    public function error(string $key, string $message): void
    {
        $this->errors[$key] = $message;
    }

    /** Throw a 422 listing every invalid field, if any. */
    public function check(): void
    {
        if ($this->errors) throw ApiError::validation('Invalid input', $this->errors);
    }
}
