<?php
namespace OnaRest;

/**
 * An error that is turned into a JSON error response:
 *   {"error": {"code": "...", "message": "...", "details": {...}}}
 */
class ApiError extends \Exception
{
    public int $status;
    public string $errorCode;
    public array $details;

    public function __construct(int $status, string $code, string $message, array $details = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $code;
        $this->details = $details;
    }

    public static function badRequest(string $message, array $details = []): self
    {
        return new self(400, 'bad_request', $message, $details);
    }

    public static function validation(string $message, array $fields = []): self
    {
        return new self(422, 'validation_failed', $message, $fields ? ['fields' => $fields] : []);
    }

    public static function notFound(string $what): self
    {
        return new self(404, 'not_found', "{$what} not found");
    }

    public static function unauthorized(string $message = 'Authentication required'): self
    {
        return new self(401, 'unauthorized', $message);
    }

    public static function forbidden(string $permission): self
    {
        return new self(403, 'forbidden', 'Permission denied', ['permission' => $permission]);
    }
}

class Request
{
    public string $method;
    public string $path;
    public array $query;
    private ?array $body = null;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        // Everything after /api/v1, wherever the API is mounted.
        $pos = strpos($uri, '/api/v1');
        $path = $pos === false ? $uri : substr($uri, $pos + strlen('/api/v1'));
        $this->path = '/' . trim($path, '/');
        $this->query = $_GET;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if ($name === 'Content-Type') $key = 'CONTENT_TYPE';
        return $_SERVER[$key] ?? null;
    }

    /** Decoded JSON object body. Bodies must be sent as application/json. */
    public function body(): array
    {
        if ($this->body !== null) return $this->body;
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) return $this->body = [];
        $type = strtolower((string)$this->header('Content-Type'));
        if (strpos($type, 'application/json') !== 0) {
            throw new ApiError(415, 'unsupported_media_type', 'Request bodies must be application/json');
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw ApiError::badRequest('Request body must be a JSON object');
        }
        return $this->body = $data;
    }

    public function queryString(string $key, string $default = ''): string
    {
        $v = $this->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    public function queryInt(string $key): ?int
    {
        $v = $this->query[$key] ?? null;
        if ($v === null || $v === '') return null;
        if (!is_string($v) || !ctype_digit($v)) throw ApiError::badRequest("Query parameter '{$key}' must be a non-negative integer");
        return (int)$v;
    }
}

class Response
{
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /** Send a JSON response and stop. Anything ONA printed is discarded. */
    public static function json(int $status, $data): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        self::securityHeaders();
        if ($status !== 204) echo json_encode($data, self::JSON_FLAGS);
        exit;
    }

    public static function raw(int $status, string $contentType, string $body): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code($status);
        header("Content-Type: {$contentType}");
        self::securityHeaders();
        echo $body;
        exit;
    }

    public static function error(ApiError $e): void
    {
        $err = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        if ($e->details) $err['details'] = $e->details;
        if ($e->status === 405 && isset($e->details['allow'])) header('Allow: ' . implode(', ', $e->details['allow']));
        self::json($e->status, ['error' => $err]);
    }

    private static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        header('Referrer-Policy: no-referrer');
    }

    /** Last-resort handlers so PHP errors never leak HTML or stack traces. */
    public static function installErrorHandlers(): void
    {
        set_exception_handler(function (\Throwable $e) {
            if ($e instanceof ApiError) self::error($e);
            error_log('ona-rest: uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            self::error(new ApiError(500, 'internal_error', 'Internal server error'));
        });
        register_shutdown_function(function () {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                error_log("ona-rest: fatal: {$err['message']} at {$err['file']}:{$err['line']}");
                while (ob_get_level() > 0) ob_end_clean();
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo '{"error":{"code":"internal_error","message":"Internal server error"}}';
            }
        });
    }
}
