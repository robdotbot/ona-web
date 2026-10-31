<?php
namespace OnaRest;

/**
 * Session login against ONA's own users, groups and permissions.
 *
 * Login reuses ONA's get_authentication() (local or LDAP, per the
 * `authtype` sys_config setting) and get_perms(), so the session holds the
 * same $_SESSION['ona']['auth'] data that ONA's auth() checks read.
 *
 * Unlike the legacy desktop, the API never logs anyone in automatically:
 * a request is authenticated only if this session went through
 * POST /auth/login, and the marker we store then still matches the user ONA
 * thinks is logged in. A guest session created by the legacy UI is not
 * enough.
 */
class Auth
{
    private const KEY = 'ona_rest';

    public static function login(string $username, string $password): array
    {
        global $conf, $onadb;

        if ($username === '' || $password === '' || strlen($username) > 64 || strlen($password) > 1024) {
            throw ApiError::validation('Username and password are required');
        }

        // ONA validates the format and checks the password (local or LDAP).
        // Its messages distinguish unknown users from bad passwords; we don't.
        [$status] = get_authentication($username, $password);
        if ($status !== 0) {
            usleep(random_int(200000, 400000));
            throw ApiError::unauthorized('Invalid username or password');
        }

        // ONA's local auth class looks users up with SQL LIKE, so "adm_n"
        // would match "admin". Require an exact match for local users.
        if (($conf['authtype'] ?? 'local') === 'local') {
            [, $rows, $user] = db_get_record($onadb, 'users', ['username' => $username]);
            if ($rows !== 1 || strcasecmp($user['username'], $username) !== 0) {
                throw ApiError::unauthorized('Invalid username or password');
            }
            $username = $user['username'];
        }

        session_regenerate_id(true);
        get_perms($username);
        $_SESSION['ona']['auth']['user']['username'] = $username;
        $_SESSION[self::KEY] = [
            'username' => $username,
            'csrf' => bin2hex(random_bytes(32)),
            'login_time' => time(),
        ];
        if (($conf['authtype'] ?? 'local') === 'local') {
            db_update_record($onadb, 'users', ['username' => $username], ['atime' => date('Y-m-d H:i:s')]);
        }
        printmsg("INFO => [REST API] {$username} has logged in", 0);
        return self::session();
    }

    public static function logout(): void
    {
        if (self::user()) printmsg('INFO => [REST API] ' . self::user() . ' has logged out', 0);
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600, 'path' => $p['path'], 'domain' => $p['domain'],
            'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict',
        ]);
    }

    /** Username of the API-authenticated user, or null. */
    public static function user(): ?string
    {
        $api = $_SESSION[self::KEY] ?? null;
        $ona = $_SESSION['ona']['auth']['user']['username'] ?? null;
        if (!is_array($api) || !is_string($ona) || $api['username'] !== $ona) return null;
        return $ona;
    }

    public static function requireUser(): string
    {
        $user = self::user();
        if ($user === null) throw ApiError::unauthorized();
        return $user;
    }

    /**
     * Every state-changing request must echo the per-session token from
     * GET /auth/session in an X-CSRF-Token header.
     */
    public static function checkCsrf(Request $req): void
    {
        $sent = (string)$req->header('X-CSRF-Token');
        $expected = $_SESSION[self::KEY]['csrf'] ?? '';
        if ($expected === '' || !hash_equals($expected, $sent)) {
            throw new ApiError(403, 'csrf_failed', 'Missing or invalid X-CSRF-Token header');
        }
    }

    /** Throws 403 unless ONA's auth() grants the permission. */
    public static function requirePerm(string ...$perms): void
    {
        foreach ($perms as $perm) {
            if (!auth($perm)) throw ApiError::forbidden($perm);
        }
    }

    public static function session(): array
    {
        $perms = array_keys((array)($_SESSION['ona']['auth']['perms'] ?? []));
        sort($perms);
        return [
            'user' => [
                'username' => self::user(),
                'groups' => array_keys((array)($_SESSION['ona']['auth']['user']['grps'] ?? [])),
            ],
            'permissions' => $perms,
            'csrfToken' => $_SESSION[self::KEY]['csrf'] ?? null,
        ];
    }
}
