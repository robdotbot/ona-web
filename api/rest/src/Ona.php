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
    public static function run(string $module, array $options, array $requiredPerms): string
    {
        global $self;
        Auth::requirePerm(...$requiredPerms);

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
        if ((int)$status === 0) return trim($output);

        $text = $output . "\n" . ($self['error'] ?? '');
        if (stripos($text, 'Permission denied') !== false) {
            throw new ApiError(403, 'forbidden', 'Permission denied', ['module' => $module]);
        }
        $message = self::firstError($text);
        $conflict = preg_match('/conflict|already|exists with the values/i', $message);
        throw new ApiError($conflict ? 409 : 422, $conflict ? 'conflict' : 'module_error', $message, [
            'module' => $module,
            'moduleStatus' => (int)$status,
            'output' => self::cleanLines($output),
        ]);
    }

    private static function cleanLines(string $text): array
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
