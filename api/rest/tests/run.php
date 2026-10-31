<?php
/**
 * Integration tests for the ONA REST API.
 *
 * Zero dependencies: PHP 8.3 with the curl extension (and mysqli for the
 * limited-user test). Run inside the api container:
 *
 *   docker compose exec -T api php /opt/ona/rest/tests/run.php
 *
 * Env:
 *   ONA_API_URL   API base URL (default http://localhost/api/v1)
 *   ONA_TEST_ONLY substring filter on test names (optional)
 *
 * Everything the tests create is named RESTTEST-<run id>-... and lives in a
 * random free 10.250.x.0/24, and is deleted again even when a test fails.
 */

declare(strict_types=1);
error_reporting(E_ALL);

const ADMIN_USER = 'admin';
const ADMIN_PASS = 'admin';
const GUEST_USER = 'guest';
const GUEST_PASS = 'guest';

$BASE = rtrim(getenv('ONA_API_URL') ?: 'http://localhost/api/v1', '/');
$RUN = substr(bin2hex(random_bytes(4)), 0, 6);   // unique per run, used in names

// ===========================================================================
// HTTP client: one cookie jar per simulated client.
// ===========================================================================

final class Res
{
    public function __construct(
        public int $status,
        /** @var array<string,string> lower-cased header names */
        public array $headers,
        public string $body,
        public mixed $json,
    ) {}

    public function code(): ?string
    {
        return is_array($this->json) ? ($this->json['error']['code'] ?? null) : null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function summary(): string
    {
        return "HTTP {$this->status} " . substr($this->body, 0, 300);
    }
}

final class Client
{
    private string $jar;
    public ?string $csrf = null;

    public function __construct(public string $base, public string $label = 'client')
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'onarest-jar-');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /**
     * @param array|string|null $body array => JSON-encoded; string => sent raw
     * @param array<string,string> $headers extra headers
     * @param bool|null $csrf send X-CSRF-Token (default: for non-GET when known)
     */
    public function req(string $method, string $path, array|string|null $body = null, array $headers = [], ?bool $csrf = null): Res
    {
        $url = preg_match('#^https?://#', $path) ? $path : $this->base . $path;
        $h = ['Accept' => 'application/json'];
        if (is_array($body)) {
            $h['Content-Type'] = 'application/json';
            $body = json_encode($body === [] ? new stdClass() : $body);
        }
        $csrf ??= $method !== 'GET' && $this->csrf !== null;
        if ($csrf && $this->csrf !== null) $h['X-CSRF-Token'] = $this->csrf;
        $h = array_merge($h, $headers);

        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "{$k}: {$v}", array_keys($h), $h),
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        if ($raw === false) throw new RuntimeException("curl {$method} {$url}: " . curl_error($ch));
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return new Res($status, $respHeaders, (string)$raw, json_decode((string)$raw, true));
    }

    public function get(string $path, array $headers = []): Res { return $this->req('GET', $path, null, $headers); }
    public function post(string $path, array|string|null $body = [], array $headers = []): Res { return $this->req('POST', $path, $body, $headers); }
    public function patch(string $path, array $body): Res { return $this->req('PATCH', $path, $body); }
    public function delete(string $path): Res { return $this->req('DELETE', $path); }

    public function login(string $user, string $pass): Res
    {
        $r = $this->post('/auth/login', ['username' => $user, 'password' => $pass]);
        if ($r->status === 200) $this->csrf = $r->json['csrfToken'] ?? null;
        return $r;
    }

    /** Log in or abort the test. */
    public function mustLogin(string $user, string $pass): self
    {
        $r = $this->login($user, $pass);
        if ($r->status !== 200) throw new AssertionFailed("login as {$user} failed: " . $r->summary());
        return $this;
    }

    /** Expect a status, return the decoded body. */
    public function ok(string $method, string $path, ?array $body, int $expect): mixed
    {
        $r = $this->req($method, $path, $body);
        if ($r->status !== $expect) throw new AssertionFailed("{$method} {$path}: expected {$expect}, got " . $r->summary());
        return $r->json;
    }
}

// ===========================================================================
// Tiny test framework.
// ===========================================================================

final class AssertionFailed extends Exception {}
final class Skipped extends Exception {}

/** @var array<int, array{string, callable}> */
$TESTS = [];
function test(string $name, callable $fn): void { $GLOBALS['TESTS'][] = [$name, $fn]; }

function fail(string $msg): never { throw new AssertionFailed($msg); }
function skip(string $msg): never { throw new Skipped($msg); }
function check(bool $cond, string $msg): void { if (!$cond) fail($msg); }
function eq(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) fail("{$what}: expected " . json_encode($expected) . ', got ' . json_encode($actual));
}

/** Assert status (and optionally the error code) of a response. */
function expectStatus(Res $r, int $status, ?string $code = null, string $what = ''): void
{
    $what = $what !== '' ? "{$what}: " : '';
    if ($r->status !== $status) fail("{$what}expected HTTP {$status}, got " . $r->summary());
    if ($code !== null && $r->code() !== $code) fail("{$what}expected error.code {$code}, got " . $r->summary());
    if ($status >= 400 && !isset($r->json['error']['message'])) fail("{$what}error envelope missing error.message: " . $r->summary());
}

// ---- Cleanup registry: undo actions run in reverse order after each test.

$CLEANUP = [];
function onCleanup(callable $fn): void { $GLOBALS['CLEANUP'][] = $fn; }
function runCleanup(): array
{
    $errors = [];
    while ($fn = array_pop($GLOBALS['CLEANUP'])) {
        try { $fn(); } catch (Throwable $e) { $errors[] = $e->getMessage(); }
    }
    return $errors;
}

// ===========================================================================
// Fixtures.
// ===========================================================================

function admin(): Client
{
    static $c = null;
    return $c ??= (new Client($GLOBALS['BASE'], 'admin'))->mustLogin(ADMIN_USER, ADMIN_PASS);
}

function name(string $suffix): string { return "resttest-{$GLOBALS['RUN']}-{$suffix}"; }

/** A random 10.250.x.0/24 that no existing subnet overlaps (prefix without the last octet). */
function freeNet(): string
{
    static $taken = [];
    for ($i = 0; $i < 50; $i++) {
        $x = random_int(0, 255);
        if (isset($taken[$x])) continue;
        $r = admin()->get("/subnets?q=10.250.{$x}.1&perPage=5");
        if ($r->status === 200 && $r->json['meta']['total'] === 0 && !overlaps("10.250.{$x}.")) {
            $taken[$x] = true;
            return "10.250.{$x}";
        }
    }
    fail('could not find a free 10.250.x.0/24');
}

/** True if any existing subnet contains a 10.250.x.0/24 address (catches supernets too). */
function overlaps(string $prefix): bool
{
    foreach ([0, 255] as $last) {
        $r = admin()->get('/search?q=' . $prefix . $last);
        if (!empty($r->json['subnets'])) return true;
    }
    return false;
}

/** Create a subnet as admin and register its deletion. */
function makeSubnet(string $suffix, ?string $net = null): array
{
    $net ??= freeNet();
    $s = admin()->ok('POST', '/subnets', [
        'name' => strtoupper(name($suffix)), 'ip' => "{$net}.0", 'netmask' => '24', 'typeId' => 13,
    ], 201);
    onCleanup(fn() => deleteIfExists("/subnets/{$s['id']}"));
    return $s;
}

/** Create a host as admin and register its deletion. */
function makeHost(string $suffix, string $ip, array $extra = []): array
{
    $h = admin()->ok('POST', '/hosts', ['name' => name($suffix) . '.example.com', 'deviceTypeId' => 1, 'ip' => $ip] + $extra, 201);
    onCleanup(fn() => deleteIfExists("/hosts/{$h['id']}"));
    return $h;
}

function deleteIfExists(string $path): void
{
    $r = admin()->delete($path);
    if (!in_array($r->status, [204, 404], true)) throw new RuntimeException("cleanup DELETE {$path}: " . $r->summary());
}

/** mysqli connection using ONA's own database_settings.inc.php. */
function db(): mysqli
{
    static $db = null;
    if ($db) return $db;
    if (!extension_loaded('mysqli')) skip('mysqli extension not loaded');
    $file = dirname(__DIR__, 2) . '/www/local/config/database_settings.inc.php';
    if (!is_readable($file)) skip("cannot read {$file} (run inside the api container)");
    include $file;
    $c = $ona_contexts['DEFAULT']['databases'][0] ?? null;
    if (!$c) skip('no DEFAULT database context in database_settings.inc.php');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    return $db = new mysqli($c['db_host'], $c['db_login'], $c['db_passwd'], $c['db_database']);
}

// ===========================================================================
// Route table (mirrors routes.php; kept by hand on purpose).
// ===========================================================================

const PUBLIC_ROUTES = [
    ['GET', '/health'],
    ['GET', '/openapi.yaml'],
    ['POST', '/auth/login'],
    ['POST', '/auth/logout'],
];

const PROTECTED_ROUTES = [
    ['GET', '/auth/session'],
    ['GET', '/search'],
    ['GET', '/subnet-types'],
    ['GET', '/device-types'],
    ['GET', '/subnets'], ['POST', '/subnets'],
    ['GET', '/subnets/{id}'], ['PATCH', '/subnets/{id}'], ['DELETE', '/subnets/{id}'],
    ['GET', '/subnets/{id}/next-ip'],
    ['GET', '/hosts'], ['POST', '/hosts'],
    ['GET', '/hosts/{id}'], ['PATCH', '/hosts/{id}'], ['DELETE', '/hosts/{id}'],
    ['GET', '/interfaces'], ['POST', '/interfaces'],
    ['GET', '/interfaces/{id}'], ['PATCH', '/interfaces/{id}'], ['DELETE', '/interfaces/{id}'],
    ['GET', '/dns-records'], ['POST', '/dns-records'],
    ['GET', '/dns-records/{id}'], ['PATCH', '/dns-records/{id}'], ['DELETE', '/dns-records/{id}'],
    ['GET', '/vlan-campuses'], ['POST', '/vlan-campuses'],
    ['GET', '/vlan-campuses/{id}'], ['PATCH', '/vlan-campuses/{id}'], ['DELETE', '/vlan-campuses/{id}'],
    ['GET', '/vlans'], ['POST', '/vlans'],
    ['GET', '/vlans/{id}'], ['PATCH', '/vlans/{id}'], ['DELETE', '/vlans/{id}'],
    ['GET', '/blocks'], ['POST', '/blocks'],
    ['GET', '/blocks/{id}'], ['PATCH', '/blocks/{id}'], ['DELETE', '/blocks/{id}'],
    ['GET', '/dhcp-servers'], ['POST', '/dhcp-servers'],
    ['GET', '/dhcp-servers/{id}'], ['DELETE', '/dhcp-servers/{id}/subnets/{subnetId}'],
    ['GET', '/dhcp-failover-groups'], ['POST', '/dhcp-failover-groups'],
    ['GET', '/dhcp-failover-groups/{id}'], ['PATCH', '/dhcp-failover-groups/{id}'], ['DELETE', '/dhcp-failover-groups/{id}'],
    ['GET', '/dhcp-pools'], ['POST', '/dhcp-pools'],
    ['GET', '/dhcp-pools/{id}'], ['PATCH', '/dhcp-pools/{id}'], ['DELETE', '/dhcp-pools/{id}'],
    ['GET', '/dhcp-options'],
    ['GET', '/dhcp-entries'], ['POST', '/dhcp-entries'],
    ['GET', '/dhcp-entries/{id}'], ['PATCH', '/dhcp-entries/{id}'], ['DELETE', '/dhcp-entries/{id}'],
    ['GET', '/custom-attribute-types'], ['POST', '/custom-attribute-types'],
    ['GET', '/custom-attribute-types/{id}'], ['PATCH', '/custom-attribute-types/{id}'], ['DELETE', '/custom-attribute-types/{id}'],
    ['GET', '/custom-attributes'], ['POST', '/custom-attributes'],
    ['GET', '/custom-attributes/{id}'], ['PATCH', '/custom-attributes/{id}'], ['DELETE', '/custom-attributes/{id}'],
    ['GET', '/locations'], ['POST', '/locations'],
    ['GET', '/locations/{id}'], ['PATCH', '/locations/{id}'], ['DELETE', '/locations/{id}'],
    ['GET', '/domains'], ['POST', '/domains'],
    ['GET', '/domains/{id}'], ['PATCH', '/domains/{id}'], ['DELETE', '/domains/{id}'],
    ['GET', '/users'], ['POST', '/users'],
    ['GET', '/users/{id}'], ['PATCH', '/users/{id}'], ['DELETE', '/users/{id}'],
    ['GET', '/groups'], ['POST', '/groups'],
    ['GET', '/groups/{id}'], ['PATCH', '/groups/{id}'], ['DELETE', '/groups/{id}'],
    ['GET', '/permissions'],
    ['GET', '/sys-config'], ['POST', '/sys-config'],
    ['GET', '/sys-config/{key}'], ['PATCH', '/sys-config/{key}'], ['DELETE', '/sys-config/{key}'],
    ['GET', '/plugins'], ['PATCH', '/plugins/{key}'],
];

/** Fill path placeholders: numeric ones with $id, {key} with a name. */
function concrete(string $path, int $id = 1): string
{
    return preg_replace('/\{\w+\}/', (string)$id, str_replace('{key}', 'resttest_key', $path));
}

// ===========================================================================
// 1. Unauthenticated access
// ===========================================================================

test('unauth: every protected route returns 401 unauthorized', function () {
    $c = new Client($GLOBALS['BASE'], 'anon');
    $routes = PROTECTED_ROUTES;
    $bad = [];
    foreach ($routes as [$m, $p]) {
        $r = $c->req($m, concrete($p), $m === 'GET' ? null : ['name' => 'x'], [], false);
        if ($r->status !== 401 || $r->code() !== 'unauthorized') $bad[] = "{$m} {$p} -> {$r->status} " . ($r->code() ?? '');
    }
    check(!$bad, implode('; ', $bad));
});

test('unauth: /health and /openapi.yaml are public', function () {
    $c = new Client($GLOBALS['BASE'], 'anon');
    $r = $c->get('/health');
    expectStatus($r, 200);
    eq('ok', $r->json['status'] ?? null, 'health status');
    $r = $c->get('/openapi.yaml');
    expectStatus($r, 200);
    check(str_starts_with((string)$r->header('content-type'), 'application/yaml'), 'spec content-type: ' . $r->header('content-type'));
});

// ===========================================================================
// 2. Login / logout / sessions
// ===========================================================================

test('login: wrong password -> 401', function () {
    $r = (new Client($GLOBALS['BASE']))->login(ADMIN_USER, 'definitely-wrong');
    expectStatus($r, 401, 'unauthorized');
});

test('login: LIKE wildcard username "adm_n" with admin password -> 401', function () {
    $r = (new Client($GLOBALS['BASE']))->login('adm_n', ADMIN_PASS);
    expectStatus($r, 401, 'unauthorized');
    $r = (new Client($GLOBALS['BASE']))->login('adm%', ADMIN_PASS);
    expectStatus($r, 401, 'unauthorized', 'adm%');
});

test('login: non-JSON body -> 415', function () {
    $c = new Client($GLOBALS['BASE']);
    $r = $c->post('/auth/login', 'username=admin&password=admin', ['Content-Type' => 'application/x-www-form-urlencoded']);
    expectStatus($r, 415, 'unsupported_media_type');
});

test('login: missing fields -> 422 with details.fields', function () {
    $c = new Client($GLOBALS['BASE']);
    $r = $c->post('/auth/login', ['username' => 'admin']);
    expectStatus($r, 422, 'validation_failed', 'no password');
    check(isset($r->json['error']['details']['fields']['password']), 'fields.password missing: ' . $r->body);
    $r = $c->post('/auth/login', ['password' => 'admin']);
    expectStatus($r, 422, 'validation_failed', 'no username');
    check(isset($r->json['error']['details']['fields']['username']), 'fields.username missing: ' . $r->body);
});

test('login: success returns user, csrfToken and permissions; /auth/session agrees', function () {
    $c = new Client($GLOBALS['BASE']);
    $r = $c->login(ADMIN_USER, ADMIN_PASS);
    expectStatus($r, 200);
    eq('admin', $r->json['user']['username'] ?? null, 'user.username');
    check(is_string($r->json['csrfToken'] ?? null) && strlen($r->json['csrfToken']) >= 32, 'csrfToken missing/short');
    foreach (['subnet_add', 'host_add', 'host_del'] as $p) {
        check(in_array($p, $r->json['permissions'] ?? [], true), "admin permissions lack {$p}");
    }
    check(str_contains((string)$r->header('set-cookie'), 'ONA_SESSION_ID='), 'no ONA_SESSION_ID cookie set');
    $s = $c->get('/auth/session');
    expectStatus($s, 200);
    eq($r->json['csrfToken'], $s->json['csrfToken'] ?? null, 'session csrfToken');
});

test('login: guest has no permissions', function () {
    $r = (new Client($GLOBALS['BASE']))->login(GUEST_USER, GUEST_PASS);
    expectStatus($r, 200);
    eq([], $r->json['permissions'] ?? null, 'guest permissions');
});

test('logout: ends the session', function () {
    $c = (new Client($GLOBALS['BASE']))->mustLogin(ADMIN_USER, ADMIN_PASS);
    expectStatus($c->get('/auth/session'), 200, null, 'before logout');
    expectStatus($c->post('/auth/logout', null), 204, null, 'logout');
    expectStatus($c->get('/auth/session'), 401, 'unauthorized', 'after logout');
    expectStatus($c->get('/subnets'), 401, 'unauthorized', 'after logout');
});

test('logout: requires CSRF token when logged in', function () {
    $c = (new Client($GLOBALS['BASE']))->mustLogin(ADMIN_USER, ADMIN_PASS);
    expectStatus($c->req('POST', '/auth/logout', null, [], false), 403, 'csrf_failed');
    expectStatus($c->get('/auth/session'), 200, null, 'still logged in');
});

test('legacy UI guest session does not authenticate API requests', function () {
    $c = new Client($GLOBALS['BASE'], 'legacy');
    $root = preg_replace('#/api/v1$#', '/', $GLOBALS['BASE']);
    $r = $c->get($root);
    check($r->status === 200, "legacy UI GET {$root}: HTTP {$r->status}");
    check(str_contains((string)$r->header('set-cookie'), 'ONA_SESSION_ID')
        || filesize((new ReflectionProperty($c, 'jar'))->getValue($c)) > 0, 'legacy UI set no session cookie');
    expectStatus($c->get('/auth/session'), 401, 'unauthorized');
    expectStatus($c->get('/subnets'), 401, 'unauthorized');
});

// ===========================================================================
// 3. Permissions
// ===========================================================================

test('guest: every write endpoint is 403 forbidden naming the permission, nothing created', function () {
    $net = freeNet();
    $s = makeSubnet('perm', $net);
    $h = makeHost('perm-h', "{$net}.10");
    $iface = admin()->ok('POST', '/interfaces', ['hostId' => $h['id'], 'ip' => "{$net}.11"], 201);
    onCleanup(fn() => deleteIfExists("/interfaces/{$iface['id']}"));
    $rec = admin()->ok('POST', '/dns-records', ['type' => 'CNAME', 'name' => name('perm-cn') . '.example.com', 'pointsTo' => $h['fqdn']], 201);
    onCleanup(fn() => deleteIfExists("/dns-records/{$rec['id']}"));

    $g = (new Client($GLOBALS['BASE'], 'guest'))->mustLogin(GUEST_USER, GUEST_PASS);
    $cases = [
        ['POST', '/subnets', ['name' => strtoupper(name('guest')), 'ip' => "{$net}.0", 'netmask' => '25', 'typeId' => 13], 'subnet_add'],
        ['PATCH', "/subnets/{$s['id']}", ['name' => strtoupper(name('guest-ren'))], 'subnet_modify'],
        ['DELETE', "/subnets/{$s['id']}", null, 'subnet_del'],
        ['POST', '/hosts', ['name' => name('guest-h') . '.example.com', 'deviceTypeId' => 1, 'ip' => "{$net}.20"], 'host_add'],
        ['PATCH', "/hosts/{$h['id']}", ['notes' => 'guest was here'], 'host_modify'],
        ['DELETE', "/hosts/{$h['id']}", null, 'host_del'],
        ['POST', '/interfaces', ['hostId' => $h['id'], 'ip' => "{$net}.21"], 'host_add'],
        ['PATCH', "/interfaces/{$iface['id']}", ['description' => 'guest'], 'interface_modify'],
        ['DELETE', "/interfaces/{$iface['id']}", null, 'interface_del'],
        ['POST', '/dns-records', ['type' => 'CNAME', 'name' => name('guest-cn') . '.example.com', 'pointsTo' => $h['fqdn']], 'host_add'],
        ['PATCH', "/dns-records/{$rec['id']}", ['ttl' => 60], 'host_modify'],
        ['DELETE', "/dns-records/{$rec['id']}", null, 'host_del'],
    ];
    $bad = [];
    foreach ($cases as [$m, $p, $body, $perm]) {
        $r = $g->req($m, $p, $body);
        if ($r->status !== 403 || $r->code() !== 'forbidden' || ($r->json['error']['details']['permission'] ?? null) !== $perm) {
            $bad[] = "{$m} {$p}: expected 403 forbidden/{$perm}, got " . $r->summary();
        }
    }
    check(!$bad, implode("\n      ", $bad));

    // Nothing was created or changed.
    $a = admin();
    eq(0, $a->get('/subnets?q=' . urlencode(strtoupper(name('guest'))))->json['meta']['total'], 'guest subnet count');
    eq(0, $a->get('/hosts?q=' . name('guest-h'))->json['meta']['total'], 'guest host count');
    eq(0, $a->get("/interfaces?q={$net}.21")->json['meta']['total'], 'guest interface count');
    eq(0, $a->get('/dns-records?q=' . name('guest-cn'))->json['meta']['total'], 'guest dns count');
    eq(strtoupper(name('perm')), $a->get("/subnets/{$s['id']}")->json['name'], 'subnet name unchanged');
    eq('', $a->get("/hosts/{$h['id']}")->json['notes'], 'host notes unchanged');
    expectStatus($a->get("/interfaces/{$iface['id']}"), 200, null, 'interface still exists');
    eq(admin()->get("/dns-records/{$rec['id']}")->json['ttl'], $rec['ttl'], 'record ttl unchanged');
});

test('limited user with only subnet_add can create but not delete a subnet', function () {
    $db = db();
    $user = 'resttest_' . $GLOBALS['RUN'];
    $pass = bin2hex(random_bytes(8));
    $md5 = md5($pass);
    $db->execute_query('INSERT INTO users (username, password, level, ctime) VALUES (?, ?, 0, NOW())', [$user, $md5]);
    $uid = (int)$db->insert_id;
    onCleanup(function () use ($db, $uid) {
        $db->execute_query('DELETE FROM permission_assignments WHERE user_id = ?', [$uid]);
        $db->execute_query('DELETE FROM group_assignments WHERE user_id = ?', [$uid]);
        $db->execute_query('DELETE FROM users WHERE id = ?', [$uid]);
    });
    $permId = (int)$db->execute_query("SELECT id FROM permissions WHERE name = 'subnet_add'")->fetch_row()[0];
    $db->execute_query('INSERT INTO permission_assignments (perm_id, user_id, group_id) VALUES (?, ?, 0)', [$permId, $uid]);

    $c = new Client($GLOBALS['BASE'], 'limited');
    $r = $c->login($user, $pass);
    expectStatus($r, 200, null, 'limited login');
    eq(['subnet_add'], $r->json['permissions'], 'limited permissions');

    $net = freeNet();
    $r = $c->post('/subnets', ['name' => strtoupper(name('lim')), 'ip' => "{$net}.0", 'netmask' => '24', 'typeId' => 13]);
    expectStatus($r, 201, null, 'limited create');
    $id = $r->json['id'];
    onCleanup(fn() => deleteIfExists("/subnets/{$id}"));

    $r = $c->delete("/subnets/{$id}");
    expectStatus($r, 403, 'forbidden', 'limited delete');
    eq('subnet_del', $r->json['error']['details']['permission'] ?? null, 'details.permission');
    expectStatus(admin()->get("/subnets/{$id}"), 200, null, 'subnet still exists');
    $r = $c->patch("/subnets/{$id}", ['name' => strtoupper(name('lim2'))]);
    expectStatus($r, 403, 'forbidden', 'limited patch');
});

// ===========================================================================
// 4. CSRF
// ===========================================================================

test('csrf: admin write without / with wrong X-CSRF-Token -> 403 csrf_failed', function () {
    $c = (new Client($GLOBALS['BASE'], 'admin2'))->mustLogin(ADMIN_USER, ADMIN_PASS);
    $body = ['name' => strtoupper(name('csrf')), 'ip' => '10.250.0.0', 'netmask' => '24', 'typeId' => 13];
    expectStatus($c->req('POST', '/subnets', $body, [], false), 403, 'csrf_failed', 'no token');
    expectStatus($c->req('POST', '/subnets', $body, ['X-CSRF-Token' => str_repeat('0', 64)], false), 403, 'csrf_failed', 'wrong token');
    expectStatus($c->req('DELETE', '/subnets/1', null, ['X-CSRF-Token' => ''], false), 403, 'csrf_failed', 'empty token');
    // Another session's token doesn't work either.
    $other = (new Client($GLOBALS['BASE']))->mustLogin(ADMIN_USER, ADMIN_PASS);
    expectStatus($c->req('POST', '/subnets', $body, ['X-CSRF-Token' => (string)$other->csrf], false), 403, 'csrf_failed', 'other session token');
    eq(0, admin()->get('/subnets?q=' . urlencode(strtoupper(name('csrf'))))->json['meta']['total'], 'nothing created');
});

// ===========================================================================
// 5. CRUD lifecycles
// ===========================================================================

test('subnet lifecycle: create, get, list, patch, next-ip, usage, delete', function () {
    $a = admin();
    $net = freeNet();
    $nm = strtoupper(name('sub'));
    $r = $a->post('/subnets', ['name' => $nm, 'ip' => "{$net}.0", 'netmask' => '255.255.255.0', 'typeId' => 13]);
    expectStatus($r, 201, null, 'create');
    $s = $r->json;
    onCleanup(fn() => deleteIfExists("/subnets/{$s['id']}"));
    eq($nm, $s['name'], 'name');
    eq("{$net}.0", $s['ip'], 'ip');
    eq(24, $s['cidr'], 'cidr');
    eq('255.255.255.0', $s['netmask'], 'netmask');
    eq("{$net}.0/24", $s['network'], 'network');
    eq(4, $s['ipVersion'], 'ipVersion');
    eq(13, $s['type']['id'] ?? null, 'type.id');
    eq("{$net}.255", $s['lastIp'] ?? null, 'lastIp');
    eq(0, $s['hostCount'], 'hostCount');

    $g = $a->ok('GET', "/subnets/{$s['id']}", null, 200);
    eq($s, $g, 'GET equals create response');

    // list with q filter
    $l = $a->ok('GET', '/subnets?q=' . urlencode(name('sub')), null, 200);
    eq(1, $l['meta']['total'], 'list total for q');
    eq($s['id'], $l['data'][0]['id'], 'list id');
    foreach (['page', 'perPage', 'total', 'totalPages'] as $k) check(isset($l['meta'][$k]), "meta.{$k} missing");
    // q by an address inside the subnet
    $l = $a->ok('GET', "/subnets?q={$net}.77", null, 200);
    eq([$s['id']], array_column($l['data'], 'id'), 'list by contained IP');

    // pagination: second subnet, perPage=1
    $s2 = makeSubnet('sub-b');
    $l = $a->ok('GET', '/subnets?q=' . urlencode(name('sub')) . '&perPage=1&sort=name', null, 200);
    eq(['page' => 1, 'perPage' => 1, 'total' => 2, 'totalPages' => 2], $l['meta'], 'meta page 1');
    eq($s['id'], $l['data'][0]['id'], 'page 1 item');
    $l = $a->ok('GET', '/subnets?q=' . urlencode(name('sub')) . '&perPage=1&page=2&sort=name', null, 200);
    eq(2, $l['meta']['page'], 'meta.page');
    eq($s2['id'], $l['data'][0]['id'], 'page 2 item');
    $l = $a->ok('GET', '/subnets?q=' . urlencode(name('sub')) . '&sort=-name', null, 200);
    eq([$s2['id'], $s['id']], array_column($l['data'], 'id'), 'sort=-name order');

    // patch name
    $p = $a->ok('PATCH', "/subnets/{$s['id']}", ['name' => $nm . '-REN'], 200);
    eq($nm . '-REN', $p['name'], 'patched name');
    eq($nm . '-REN', $a->ok('GET', "/subnets/{$s['id']}", null, 200)['name'], 'patched name persisted');

    // next-ip on an empty subnet, then usage after adding hosts
    $n = $a->ok('GET', "/subnets/{$s['id']}/next-ip", null, 200);
    eq("{$net}.1", $n['ip'], 'next-ip (empty)');
    makeHost('sub-h1', "{$net}.1");
    makeHost('sub-h2', "{$net}.2");
    $n = $a->ok('GET', "/subnets/{$s['id']}/next-ip", null, 200);
    eq("{$net}.3", $n['ip'], 'next-ip after two hosts');
    $g = $a->ok('GET', "/subnets/{$s['id']}", null, 200);
    eq(2, $g['usage']['used'], 'usage.used');
    eq(2, $g['interfaceCount'], 'interfaceCount');
    eq(2, $g['hostCount'], 'hostCount');
    check($g['usage']['total'] === '254' || $g['usage']['total'] === '256', 'usage.total: ' . $g['usage']['total']);

    // remove the hosts, then delete: 204 with empty body, then 404
    runCleanupFor(['sub-h1', 'sub-h2']);
    $r = $a->delete("/subnets/{$s['id']}");
    expectStatus($r, 204, null, 'delete');
    eq('', $r->body, '204 body');
    expectStatus($a->get("/subnets/{$s['id']}"), 404, 'not_found', 'after delete');
});

/** Delete the test's hosts named by suffix now (cleanup still tolerates 404). */
function runCleanupFor(array $suffixes): void
{
    foreach ($suffixes as $suf) {
        $l = admin()->get('/hosts?q=' . name($suf));
        foreach ($l->json['data'] ?? [] as $h) {
            if ($h['name'] === name($suf)) expectStatus(admin()->delete("/hosts/{$h['id']}"), 204, null, "delete host {$suf}");
        }
    }
}

test('host lifecycle: create with mac/interface name, get, patch, delete', function () {
    $a = admin();
    $net = freeNet();
    makeSubnet('host', $net);
    $fqdn = name('host') . '.example.com';
    $r = $a->post('/hosts', [
        'name' => $fqdn, 'deviceTypeId' => 1, 'ip' => "{$net}.5", 'mac' => '00:11:22:AA:BB:CC',
        'interfaceName' => 'eth0', 'interfaceDescription' => 'uplink', 'notes' => 'created by tests',
    ]);
    expectStatus($r, 201, null, 'create');
    $h = $r->json;
    onCleanup(fn() => deleteIfExists("/hosts/{$h['id']}"));
    eq(name('host'), $h['name'], 'name');
    eq($fqdn, $h['fqdn'], 'fqdn');
    eq("{$net}.5", $h['primaryIp'], 'primaryIp');
    eq('created by tests', $h['notes'], 'notes');
    eq(1, $h['deviceType']['id'] ?? null, 'deviceType.id');
    eq(1, $h['interfaceCount'], 'interfaceCount');
    eq(1, count($h['interfaces']), 'interfaces count');
    $i = $h['interfaces'][0];
    eq("{$net}.5", $i['ip'], 'interface ip');
    eq('00:11:22:aa:bb:cc', $i['mac'], 'interface mac');
    eq('eth0', $i['name'], 'interface name');
    eq('uplink', $i['description'], 'interface description');
    eq($h['id'], $i['host']['id'], 'interface host id');
    eq($h['primaryInterfaceId'], $i['id'], 'primaryInterfaceId');
    $types = array_column($h['dnsRecords'], 'type');
    eq(['A'], $types, 'dnsRecords types (no PTR by default)');
    eq($h['primaryDnsId'], $h['dnsRecords'][0]['id'], 'primaryDnsId');

    $g = $a->ok('GET', "/hosts/{$h['id']}", null, 200);
    eq($h, $g, 'GET equals create response');

    $l = $a->ok('GET', '/hosts?q=' . name('host'), null, 200);
    eq([$h['id']], array_column($l['data'], 'id'), 'list by name');
    $l = $a->ok('GET', "/hosts?q={$net}.5", null, 200);
    eq([$h['id']], array_column($l['data'], 'id'), 'list by ip');
    $l = $a->ok('GET', '/hosts?q=00:11:22:aa:bb:cc', null, 200);
    eq([$h['id']], array_column($l['data'], 'id'), 'list by mac');

    $p = $a->ok('PATCH', "/hosts/{$h['id']}", ['notes' => 'patched notes', 'deviceTypeId' => 2], 200);
    eq('patched notes', $p['notes'], 'patched notes');
    eq(2, $p['deviceType']['id'] ?? null, 'patched deviceTypeId');

    $new = name('host-ren');
    $p = $a->ok('PATCH', "/hosts/{$h['id']}", ['name' => "{$new}.example.com"], 200);
    eq($new, $p['name'], 'renamed');
    eq("{$new}.example.com", $p['fqdn'], 'renamed fqdn');
    eq('patched notes', $p['notes'], 'notes kept after rename');

    // clearing notes alone
    $p = $a->ok('PATCH', "/hosts/{$h['id']}", ['notes' => ''], 200);
    eq('', $p['notes'], 'cleared notes');

    expectStatus($a->delete("/hosts/{$h['id']}"), 204, null, 'delete');
    expectStatus($a->get("/hosts/{$h['id']}"), 404, 'not_found', 'after delete');
    expectStatus($a->get("/interfaces/{$i['id']}"), 404, 'not_found', 'interface gone with host');
    expectStatus($a->get("/dns-records/{$h['primaryDnsId']}"), 404, 'not_found', 'A record gone with host');
});

test('interface lifecycle: add second interface, patch ip/mac/description, delete', function () {
    $a = admin();
    $net = freeNet();
    $s = makeSubnet('if', $net);
    $h = makeHost('if-h', "{$net}.10");

    $r = $a->post('/interfaces', ['hostId' => $h['id'], 'ip' => "{$net}.11", 'mac' => '02:00:00:00:00:01', 'name' => 'eth1', 'description' => 'second']);
    expectStatus($r, 201, null, 'create');
    $i = $r->json;
    onCleanup(fn() => deleteIfExists("/interfaces/{$i['id']}"));
    eq("{$net}.11", $i['ip'], 'ip');
    eq('02:00:00:00:00:01', $i['mac'], 'mac');
    eq('eth1', $i['name'], 'name');
    eq('second', $i['description'], 'description');
    eq($h['id'], $i['host']['id'] ?? null, 'host.id');
    eq($s['id'], $i['subnet']['id'] ?? null, 'subnet.id');
    eq("{$net}.0/24", $i['subnet']['network'] ?? null, 'subnet.network');

    eq(2, $a->ok('GET', "/hosts/{$h['id']}", null, 200)['interfaceCount'], 'host interfaceCount');
    $l = $a->ok('GET', "/interfaces?hostId={$h['id']}", null, 200);
    eq(2, $l['meta']['total'], 'list by hostId');
    eq($i, $a->ok('GET', "/interfaces/{$i['id']}", null, 200), 'GET equals create');

    $p = $a->ok('PATCH', "/interfaces/{$i['id']}", ['ip' => "{$net}.12", 'mac' => '02:00:00:00:00:02', 'description' => 'moved'], 200);
    eq("{$net}.12", $p['ip'], 'patched ip');
    eq('02:00:00:00:00:02', $p['mac'], 'patched mac');
    eq('moved', $p['description'], 'patched description');
    eq('eth1', $p['name'], 'name unchanged');
    // Same IP again is a no-op, not an error.
    $p = $a->ok('PATCH', "/interfaces/{$i['id']}", ['ip' => "{$net}.12", 'name' => 'eth9'], 200);
    eq('eth9', $p['name'], 'patched name with unchanged ip');

    expectStatus($a->delete("/interfaces/{$i['id']}"), 204, null, 'delete');
    expectStatus($a->get("/interfaces/{$i['id']}"), 404, 'not_found', 'after delete');
    eq(1, $a->ok('GET', "/hosts/{$h['id']}", null, 200)['interfaceCount'], 'host interfaceCount after delete');
});

test('dns records: CNAME, MX, TXT with = and &, patch ttl, delete', function () {
    $a = admin();
    $net = freeNet();
    makeSubnet('dns', $net);
    $h = makeHost('dns-h', "{$net}.10");

    // CNAME
    $r = $a->post('/dns-records', ['type' => 'CNAME', 'name' => name('dns-alias') . '.example.com', 'pointsTo' => $h['fqdn']]);
    expectStatus($r, 201, null, 'CNAME create');
    $cn = $r->json;
    onCleanup(fn() => deleteIfExists("/dns-records/{$cn['id']}"));
    eq('CNAME', $cn['type'], 'CNAME type');
    eq(name('dns-alias'), $cn['name'], 'CNAME name');
    eq(name('dns-alias') . '.example.com', $cn['fqdn'], 'CNAME fqdn');
    eq($h['fqdn'], $cn['pointsTo']['fqdn'] ?? null, 'CNAME pointsTo');
    eq($h['primaryDnsId'], $cn['pointsTo']['id'] ?? null, 'CNAME pointsTo.id');

    // MX
    $r = $a->post('/dns-records', ['type' => 'MX', 'name' => name('dns-mx') . '.example.com', 'pointsTo' => $h['fqdn'], 'mxPreference' => 15]);
    expectStatus($r, 201, null, 'MX create');
    $mx = $r->json;
    onCleanup(fn() => deleteIfExists("/dns-records/{$mx['id']}"));
    eq('MX', $mx['type'], 'MX type');
    eq(15, $mx['mxPreference'], 'mxPreference');
    eq($h['fqdn'], $mx['pointsTo']['fqdn'] ?? null, 'MX pointsTo');

    // TXT with characters that ONA's option parser treats specially
    $txt = 'v=spf1 a=b&c=d ip4:10.0.0.0/8 ~all';
    $r = $a->post('/dns-records', ['type' => 'TXT', 'name' => name('dns-txt') . '.example.com', 'txt' => $txt]);
    expectStatus($r, 201, null, 'TXT create');
    $t = $r->json;
    onCleanup(fn() => deleteIfExists("/dns-records/{$t['id']}"));
    eq('TXT', $t['type'], 'TXT type');
    eq($txt, $t['txt'], 'TXT value round-trip (create)');
    eq($txt, $a->ok('GET', "/dns-records/{$t['id']}", null, 200)['txt'], 'TXT value round-trip (get)');
    $txt2 = 'k=v&x=y==&&';
    eq($txt2, $a->ok('PATCH', "/dns-records/{$t['id']}", ['txt' => $txt2], 200)['txt'], 'TXT value round-trip (patch)');

    // host detail lists records pointing at it
    $g = $a->ok('GET', "/hosts/{$h['id']}", null, 200);
    $ids = array_column($g['dnsRecords'], 'id');
    check(in_array($cn['id'], $ids, true), 'host dnsRecords lacks CNAME');
    check(in_array($mx['id'], $ids, true), 'host dnsRecords lacks MX');

    // list filters
    $l = $a->ok('GET', '/dns-records?type=CNAME&q=' . name('dns-alias'), null, 200);
    eq([$cn['id']], array_column($l['data'], 'id'), 'list by type+q');
    $l = $a->ok('GET', "/dns-records?hostId={$h['id']}&sort=type", null, 200);
    eq(['A', 'CNAME', 'MX'], array_column($l['data'], 'type'), 'list by hostId');

    // patch ttl
    $p = $a->ok('PATCH', "/dns-records/{$cn['id']}", ['ttl' => 3600], 200);
    eq(3600, $p['ttl'], 'patched ttl');
    eq(3600, $a->ok('GET', "/dns-records/{$cn['id']}", null, 200)['ttl'], 'ttl persisted');
    eq(0, $a->ok('PATCH', "/dns-records/{$cn['id']}", ['ttl' => 0], 200)['ttl'], 'patched ttl back to 0');
    $p = $a->ok('PATCH', "/dns-records/{$mx['id']}", ['mxPreference' => 20], 200);
    eq(20, $p['mxPreference'], 'patched mxPreference');

    foreach ([$cn, $mx, $t] as $rec) {
        expectStatus($a->delete("/dns-records/{$rec['id']}"), 204, null, "delete {$rec['type']}");
        expectStatus($a->get("/dns-records/{$rec['id']}"), 404, 'not_found', "{$rec['type']} after delete");
    }
    expectStatus($a->get("/hosts/{$h['id']}"), 200, null, 'host survives record deletes');
});

// ===========================================================================
// 6. Errors
// ===========================================================================

test('errors: 404 for missing ids and unknown endpoints', function () {
    $a = admin();
    foreach (['/subnets', '/hosts', '/interfaces', '/dns-records'] as $p) {
        expectStatus($a->get("{$p}/999999999"), 404, 'not_found', "GET {$p}/missing");
        expectStatus($a->patch("{$p}/999999999", ['name' => 'x']), 404, 'not_found', "PATCH {$p}/missing");
        expectStatus($a->delete("{$p}/999999999"), 404, 'not_found', "DELETE {$p}/missing");
    }
    expectStatus($a->get('/subnets/999999999/next-ip'), 404, 'not_found', 'next-ip missing');
    expectStatus($a->post('/interfaces', ['hostId' => 999999999, 'ip' => '10.250.0.1']), 404, 'not_found', 'interface for missing host');
    expectStatus($a->get('/no-such-thing'), 404, 'not_found', 'unknown endpoint');
    expectStatus($a->get('/subnets/abc'), 404, 'not_found', 'non-numeric id');
});

test('errors: 409 conflict for an overlapping subnet', function () {
    $net = freeNet();
    makeSubnet('ovl', $net);
    $r = admin()->post('/subnets', ['name' => strtoupper(name('ovl2')), 'ip' => "{$net}.128", 'netmask' => '25', 'typeId' => 13]);
    expectStatus($r, 409, 'conflict');
    eq(0, admin()->get('/subnets?q=' . urlencode(strtoupper(name('ovl2'))))->json['meta']['total'], 'nothing created');
});

test('errors: 409 conflict for a duplicate host IP', function () {
    $net = freeNet();
    makeSubnet('dup', $net);
    makeHost('dup-h1', "{$net}.10");
    $r = admin()->post('/hosts', ['name' => name('dup-h2') . '.example.com', 'deviceTypeId' => 1, 'ip' => "{$net}.10"]);
    check(in_array($r->status, [409, 422], true) && $r->status !== 201, 'duplicate IP accepted: ' . $r->summary());
    expectStatus($r, 409, 'conflict');
});

test('errors: 422 validation_failed with details.fields', function () {
    $a = admin();
    $r = $a->post('/subnets', ['name' => strtoupper(name('bad')), 'ip' => '10.250.999.0', 'netmask' => '24', 'typeId' => 13]);
    expectStatus($r, 422, 'validation_failed', 'bad ip');
    check(isset($r->json['error']['details']['fields']['ip']), 'fields.ip missing: ' . $r->body);

    $r = $a->post('/subnets', ['netmask' => '33x']);
    expectStatus($r, 422, 'validation_failed', 'missing fields');
    $f = $r->json['error']['details']['fields'] ?? [];
    foreach (['name', 'ip', 'typeId', 'netmask'] as $k) check(isset($f[$k]), "fields.{$k} missing: " . $r->body);

    $r = $a->post('/hosts', ['name' => name('bad') . '.example.com', 'ip' => 'not-an-ip', 'deviceTypeId' => 'x', 'addPtr' => 'yes']);
    expectStatus($r, 422, 'validation_failed', 'host');
    $f = $r->json['error']['details']['fields'] ?? [];
    foreach (['ip', 'deviceTypeId', 'addPtr'] as $k) check(isset($f[$k]), "host fields.{$k} missing: " . $r->body);

    $r = $a->post('/dns-records', ['type' => 'BOGUS']);
    expectStatus($r, 422, 'validation_failed', 'dns');
    $f = $r->json['error']['details']['fields'] ?? [];
    foreach (['type', 'name'] as $k) check(isset($f[$k]), "dns fields.{$k} missing: " . $r->body);

    $r = $a->post('/interfaces', []);
    expectStatus($r, 422, 'validation_failed', 'interface');
    $f = $r->json['error']['details']['fields'] ?? [];
    foreach (['hostId', 'ip'] as $k) check(isset($f[$k]), "interface fields.{$k} missing: " . $r->body);

    // A host IP outside every subnet is rejected by ONA itself.
    $r = $a->post('/hosts', ['name' => name('nosub') . '.example.com', 'deviceTypeId' => 1, 'ip' => '192.0.2.123']);
    check($r->status >= 400 && $r->status < 500, 'host outside any subnet: ' . $r->summary());
});

test('errors: 400 for bad perPage / sort / page / body', function () {
    $a = admin();
    foreach (['/subnets?perPage=0', '/subnets?perPage=201', '/hosts?perPage=abc', '/subnets?sort=bogus',
              '/hosts?sort=-nope', '/interfaces?page=-1', '/dns-records?type=XX'] as $p) {
        expectStatus($a->get($p), 400, 'bad_request', $p);
    }
    expectStatus($a->post('/subnets', '[1,2]', ['Content-Type' => 'application/json']), 400, 'bad_request', 'JSON array body');
    expectStatus($a->post('/subnets', '{not json', ['Content-Type' => 'application/json']), 400, 'bad_request', 'invalid JSON');
    expectStatus($a->post('/subnets', 'name=x', ['Content-Type' => 'text/plain']), 415, 'unsupported_media_type', 'text/plain body');
    $r = $a->ok('GET', '/subnets?perPage=200', null, 200);
    eq(200, $r['meta']['perPage'], 'perPage=200 allowed');
});

test('errors: 405 for wrong method (with Allow header)', function () {
    $a = admin();
    $r = $a->req('PUT', '/subnets/1', ['name' => 'x']);
    expectStatus($r, 405, 'method_not_allowed', 'PUT /subnets/1');
    $allow = array_map('trim', explode(',', (string)$r->header('allow')));
    sort($allow);
    eq(['DELETE', 'GET', 'PATCH'], $allow, 'Allow header');
    expectStatus($a->delete('/subnets'), 405, 'method_not_allowed', 'DELETE /subnets');
    expectStatus($a->post('/health'), 405, 'method_not_allowed', 'POST /health');
    expectStatus($a->get('/auth/login'), 405, 'method_not_allowed', 'GET /auth/login');
});

// ===========================================================================
// 7. Search
// ===========================================================================

test('search: by IP, host name and subnet name', function () {
    $a = admin();
    $net = freeNet();
    $s = makeSubnet('srch', $net);
    $h = makeHost('srch-h', "{$net}.42");

    $r = $a->ok('GET', "/search?q={$net}.42", null, 200);
    eq(['type' => 'host', 'id' => $h['id']], $r['bestMatch'], 'IP bestMatch');
    eq([$h['id']], array_column($r['hosts'], 'id'), 'IP hosts');
    eq([$s['id']], array_column($r['subnets'], 'id'), 'IP subnets');
    eq(1, count($r['interfaces']), 'IP interfaces');

    $r = $a->ok('GET', "/search?q={$net}.43", null, 200);
    eq(['type' => 'subnet', 'id' => $s['id']], $r['bestMatch'], 'unused IP bestMatch is subnet');
    eq([], $r['hosts'], 'unused IP hosts');

    $r = $a->ok('GET', '/search?q=' . urlencode($h['fqdn']), null, 200);
    eq(['type' => 'host', 'id' => $h['id']], $r['bestMatch'], 'fqdn bestMatch');
    $r = $a->ok('GET', '/search?q=' . name('srch-h'), null, 200);
    eq(['type' => 'host', 'id' => $h['id']], $r['bestMatch'], 'short name bestMatch');

    $r = $a->ok('GET', '/search?q=' . urlencode(strtolower($s['name'])), null, 200);
    eq([$s['id']], array_column($r['subnets'], 'id'), 'subnet name (case-insensitive)');
    eq(['type' => 'subnet', 'id' => $s['id']], $r['bestMatch'], 'subnet bestMatch');

    $r = $a->ok('GET', '/search?q=' . name('nothing-like-this'), null, 200);
    eq(null, $r['bestMatch'], 'no match');
    eq([], $r['hosts'], 'no hosts');

    expectStatus($a->get('/search'), 422, 'validation_failed', 'empty q');
    // LIKE wildcards are escaped: "_" must not match arbitrary characters.
    $r = $a->ok('GET', '/search?q=' . str_replace('-', '_', name('srch-h')), null, 200);
    eq([], $r['hosts'], 'underscore treated literally');
});

// ===========================================================================
// 7b. VLANs, blocks, DHCP, custom attributes, locations, domains, admin
// ===========================================================================

/** Create a VLAN campus as admin and register its deletion. */
function makeCampus(string $suffix): array
{
    $c = admin()->ok('POST', '/vlan-campuses', ['name' => name($suffix)], 201);
    onCleanup(fn() => deleteIfExists("/vlan-campuses/{$c['id']}"));
    return $c;
}

test('guest: new write endpoints are 403 forbidden naming the permission', function () {
    $g = (new Client($GLOBALS['BASE'], 'guest'))->mustLogin(GUEST_USER, GUEST_PASS);
    $cases = [
        ['POST', '/vlan-campuses', ['name' => name('g-camp')], 'vlan_add'],
        ['PATCH', '/vlan-campuses/1', ['name' => name('g-camp')], 'vlan_modify'],
        ['DELETE', '/vlan-campuses/1', null, 'vlan_del'],
        ['POST', '/vlans', ['campusId' => 1, 'name' => 'x', 'number' => 5], 'vlan_add'],
        ['POST', '/blocks', ['name' => name('g-blk'), 'start' => '10.251.0.0', 'end' => '10.251.0.255'], 'advanced'],
        ['POST', '/dhcp-servers', ['hostId' => 1, 'subnetId' => 1], 'advanced'],
        ['POST', '/dhcp-pools', ['start' => '10.251.0.10', 'end' => '10.251.0.20'], 'advanced'],
        ['POST', '/dhcp-failover-groups', ['primaryServerId' => 1, 'secondaryServerId' => 2], 'advanced'],
        ['POST', '/dhcp-entries', ['optionId' => 4, 'value' => 'x', 'global' => true], 'advanced'],
        ['POST', '/custom-attribute-types', ['name' => name('g-cat')], 'advanced'],
        ['POST', '/custom-attributes', ['typeId' => 1, 'value' => 'Y', 'subnetId' => 1], 'custom_attribute_add'],
        ['POST', '/locations', ['reference' => 'GLOC', 'name' => 'x'], 'location_add'],
        ['POST', '/domains', ['name' => name('g') . '.test'], 'advanced'],
        ['POST', '/users', ['username' => 'resttest_g', 'password' => 'x'], 'user_admin'],
        ['POST', '/groups', ['name' => name('g-grp')], 'user_admin'],
        ['POST', '/sys-config', ['name' => 'resttest_g', 'value' => 'x'], 'advanced'],
        ['PATCH', '/sys-config/debug', ['value' => '1'], 'advanced'],
        ['PATCH', '/plugins/ona_nmap_scans', ['enabled' => false], 'advanced'],
        // Admin data is not readable without the admin permission either.
        ['GET', '/users', null, 'user_admin'],
        ['GET', '/groups', null, 'user_admin'],
        ['GET', '/permissions', null, 'user_admin'],
        ['GET', '/sys-config', null, 'advanced'],
        ['GET', '/plugins', null, 'advanced'],
    ];
    $bad = [];
    foreach ($cases as [$m, $p, $body, $perm]) {
        $r = $g->req($m, $p, $body);
        if ($r->status !== 403 || $r->code() !== 'forbidden' || ($r->json['error']['details']['permission'] ?? null) !== $perm) {
            $bad[] = "{$m} {$p}: expected 403 forbidden/{$perm}, got " . $r->summary();
        }
    }
    check(!$bad, implode("\n      ", $bad));
    $a = admin();
    eq(0, $a->get('/vlan-campuses?q=' . name('g-camp'))->json['meta']['total'], 'guest campus count');
    eq(0, $a->get('/blocks?q=' . name('g-blk'))->json['meta']['total'], 'guest block count');
});

test('vlans: campuses and VLANs CRUD, subnet assignment, in-use deletes are 409', function () {
    $a = admin();
    $c = makeCampus('camp');
    eq(strtoupper(name('camp')), $c['name'], 'campus name upper-cased');
    eq(0, $c['vlanCount'], 'vlanCount');
    $c2 = makeCampus('camp2');
    $r = $a->post('/vlan-campuses', ['name' => name('camp')]);
    expectStatus($r, 409, 'conflict', 'duplicate campus');
    $r = $a->post('/vlan-campuses', ['name' => "x'); DROP TABLE vlans; --"]);
    expectStatus($r, 422, 'validation_failed', 'unsafe name');

    $v = $a->ok('POST', '/vlans', ['campusId' => $c['id'], 'name' => name('v'), 'number' => 101], 201);
    onCleanup(fn() => deleteIfExists("/vlans/{$v['id']}"));
    eq(101, $v['number'], 'number');
    eq($c['id'], $v['campus']['id'], 'campus id');
    eq([], $v['subnets'], 'no subnets yet');
    expectStatus($a->post('/vlans', ['campusId' => $c['id'], 'name' => name('v-dup'), 'number' => 101]), 409, 'conflict', 'duplicate number');
    expectStatus($a->post('/vlans', ['campusId' => $c['id'], 'name' => 'x', 'number' => 5000]), 422, 'validation_failed', 'number range');

    $v = $a->ok('PATCH', "/vlans/{$v['id']}", ['name' => name('v-ren'), 'number' => 102, 'campusId' => $c2['id']], 200);
    eq(strtoupper(name('v-ren')), $v['name'], 'renamed');
    eq(102, $v['number'], 'renumbered');
    eq($c2['id'], $v['campus']['id'], 'moved campus');
    $list = $a->get("/vlans?campusId={$c2['id']}")->json;
    eq(1, $list['meta']['total'], 'list by campus');
    eq(1, $a->get('/vlans?q=102')->json['meta']['total'] >= 1 ? 1 : 0, 'list q by number');
    eq(1, $a->get("/vlan-campuses/{$c2['id']}")->json['vlanCount'], 'campus vlanCount');

    // Assign a subnet, then the VLAN and campus can't be deleted.
    $s = makeSubnet('vl');
    $s = $a->ok('PATCH', "/subnets/{$s['id']}", ['vlanId' => $v['id']], 200);
    eq($v['id'], $s['vlan']['id'] ?? null, 'subnet vlan');
    eq([$s['id']], array_column($a->get("/vlans/{$v['id']}")->json['subnets'], 'id'), 'vlan subnets');
    expectStatus($a->delete("/vlans/{$v['id']}"), 409, 'conflict', 'delete vlan in use');
    expectStatus($a->delete("/vlan-campuses/{$c2['id']}"), 409, 'conflict', 'delete campus in use');
    $s = $a->ok('PATCH', "/subnets/{$s['id']}", ['vlanId' => 0], 200);
    eq(null, $s['vlan'], 'subnet detached');

    expectStatus($a->delete("/vlans/{$v['id']}"), 204, null, 'delete vlan');
    expectStatus($a->get("/vlans/{$v['id']}"), 404, 'not_found', 'vlan gone');
    $c = $a->ok('PATCH', "/vlan-campuses/{$c['id']}", ['name' => name('camp-ren')], 200);
    eq(strtoupper(name('camp-ren')), $c['name'], 'campus renamed');
    expectStatus($a->delete("/vlan-campuses/{$c['id']}"), 204, null, 'delete campus');
});

test('blocks: create, list by address, subnets inside, patch, clear notes, delete', function () {
    $a = admin();
    $net = freeNet();
    $s = makeSubnet('blk', $net);
    $r = $a->post('/blocks', ['name' => name('blk'), 'start' => "{$net}.0", 'end' => "{$net}.255", 'notes' => 'a & b = c']);
    expectStatus($r, 201, null, 'create');
    $b = $r->json;
    onCleanup(fn() => deleteIfExists("/blocks/{$b['id']}"));
    eq(strtoupper(name('blk')), $b['name'], 'name');
    eq('256', $b['size'], 'size');
    eq('a & b = c', $b['notes'], 'notes with = and &');
    eq([$s['id']], array_column($b['subnets'], 'id'), 'subnets in block');
    eq(1, $a->get("/blocks?q={$net}.77")->json['meta']['total'], 'list by contained address');
    expectStatus($a->post('/blocks', ['name' => name('blk-bad'), 'start' => "{$net}.9", 'end' => "{$net}.1"]), 422, 'validation_failed', 'end before start');

    $b = $a->ok('PATCH', "/blocks/{$b['id']}", ['end' => "{$net}.127", 'name' => name('blk-ren')], 200);
    eq("{$net}.127", $b['end'], 'end');
    eq('128', $b['size'], 'new size');
    $b = $a->ok('PATCH', "/blocks/{$b['id']}", ['notes' => ''], 200);
    eq('', $b['notes'], 'notes cleared');
    eq(strtoupper(name('blk-ren')), $b['name'], 'name kept');
    expectStatus($a->delete("/blocks/{$b['id']}"), 204, null, 'delete');
    expectStatus($a->get("/blocks/{$b['id']}"), 404, 'not_found', 'gone');
});

test('dhcp: servers, option entries, pools and failover groups', function () {
    $a = admin();
    $net = freeNet();
    $s = makeSubnet('dhcp', $net);
    $h1 = makeHost('dhcp1', "{$net}.2");
    $h2 = makeHost('dhcp2', "{$net}.3");

    // Server <-> subnet.
    $srv = $a->ok('POST', '/dhcp-servers', ['hostId' => $h1['id'], 'subnetId' => $s['id']], 201);
    onCleanup(fn() => admin()->delete("/dhcp-servers/{$h1['id']}/subnets/{$s['id']}"));
    eq($h1['id'], $srv['id'], 'server id is the host id');
    eq([$s['id']], array_column($srv['subnets'], 'id'), 'server subnets');
    check(in_array($h1['id'], array_column($a->get('/dhcp-servers')->json['data'], 'id'), true), 'server listed');
    expectStatus($a->post('/dhcp-servers', ['hostId' => $h1['id'], 'subnetId' => $s['id']]), 409, 'conflict', 'already assigned');
    expectStatus($a->get("/dhcp-servers/{$h2['id']}"), 404, 'not_found', 'non-server host');

    // Option entries on the subnet and the server.
    $gw = $a->ok('POST', '/dhcp-entries', ['optionId' => 2, 'value' => "{$net}.1", 'subnetId' => $s['id']], 201);
    onCleanup(fn() => deleteIfExists("/dhcp-entries/{$gw['id']}"));
    eq('subnet', $gw['scope'], 'scope');
    eq($s['id'], $gw['target']['id'], 'target');
    eq('routers', $gw['option']['name'], 'option name');
    expectStatus($a->post('/dhcp-entries', ['optionId' => 2, 'value' => 'x', 'subnetId' => $s['id'], 'hostId' => $h1['id']]), 422, 'validation_failed', 'two scopes');
    $gw = $a->ok('PATCH', "/dhcp-entries/{$gw['id']}", ['value' => "{$net}.1,{$net}.4"], 200);
    eq("{$net}.1,{$net}.4", $gw['value'], 'patched value');
    eq([$gw['id']], array_column($a->get("/dhcp-entries?subnetId={$s['id']}")->json['data'], 'id'), 'list by subnet');
    $so = $a->ok('POST', '/dhcp-entries', ['optionId' => 4, 'value' => 'example.com', 'serverId' => $h1['id']], 201);
    onCleanup(fn() => deleteIfExists("/dhcp-entries/{$so['id']}"));
    eq('server', $so['scope'], 'server scope');
    eq([$so['id']], array_column($a->get("/dhcp-servers/{$h1['id']}")->json['options'], 'id'), 'server options');

    // Pools.
    $p = $a->ok('POST', '/dhcp-pools', ['start' => "{$net}.100", 'end' => "{$net}.150", 'leaseLength' => 3600, 'leaseGrace' => 120], 201);
    onCleanup(fn() => deleteIfExists("/dhcp-pools/{$p['id']}"));
    eq($s['id'], $p['subnet']['id'], 'pool subnet');
    eq(51, $p['size'], 'pool size');
    eq(3600, $p['leaseLength'], 'lease length');
    eq(120, $p['leaseGrace'], 'lease grace');
    expectStatus($a->post('/dhcp-pools', ['start' => "{$net}.140", 'end' => "{$net}.160"]), 409, 'conflict', 'overlapping pool');
    expectStatus($a->post('/dhcp-pools', ['start' => "{$net}.1", 'end' => "{$net}.5"]), 409, 'conflict', 'pool over a host');

    // Failover group, then put the pool in it.
    $fg = $a->ok('POST', '/dhcp-failover-groups', ['primaryServerId' => $h1['id'], 'secondaryServerId' => $h2['id'], 'split' => 128], 201);
    onCleanup(fn() => deleteIfExists("/dhcp-failover-groups/{$fg['id']}"));
    eq($h1['id'], $fg['primaryServer']['id'], 'primary');
    eq(128, $fg['split'], 'split');
    $fg = $a->ok('PATCH', "/dhcp-failover-groups/{$fg['id']}", ['mclt' => 2400, 'split' => 128], 200);
    eq(2400, $fg['mclt'], 'mclt');
    $fg = $a->ok('PATCH', "/dhcp-failover-groups/{$fg['id']}", ['mclt' => 2400], 200);   // no change: no error
    expectStatus($a->patch("/dhcp-failover-groups/{$fg['id']}", ['primaryServerId' => $h2['id']]), 422, 'validation_failed', 'servers are fixed');
    $p = $a->ok('PATCH', "/dhcp-pools/{$p['id']}", ['failoverGroupId' => $fg['id'], 'end' => "{$net}.160"], 200);
    eq($fg['id'], $p['failoverGroupId'], 'pool group');
    eq("{$net}.160", $p['end'], 'pool end');
    $p = $a->ok('PATCH', "/dhcp-pools/{$p['id']}", ['leaseRebind' => 900], 200);
    eq($fg['id'], $p['failoverGroupId'], 'group kept when not sent');
    eq(900, $p['leaseRebind'], 'rebind');
    eq([$p['id']], array_column($a->get("/dhcp-failover-groups/{$fg['id']}")->json['pools'], 'id'), 'group pools');
    eq([$p['id']], array_column($a->get("/dhcp-pools?subnetId={$s['id']}")->json['data'], 'id'), 'pools by subnet');

    expectStatus($a->delete("/dhcp-pools/{$p['id']}"), 204, null, 'delete pool');
    expectStatus($a->delete("/dhcp-failover-groups/{$fg['id']}"), 204, null, 'delete group');
    expectStatus($a->delete("/dhcp-entries/{$so['id']}"), 204, null, 'delete server option');
    expectStatus($a->delete("/dhcp-servers/{$h1['id']}/subnets/{$s['id']}"), 204, null, 'remove server subnet');
    expectStatus($a->delete("/dhcp-servers/{$h1['id']}/subnets/{$s['id']}"), 404, 'not_found', 'already removed');
});

test('custom attributes: types, values on host/subnet/vlan, validation rule, in-use type', function () {
    $a = admin();
    $t = $a->ok('POST', '/custom-attribute-types', ['name' => name('cat'), 'notes' => 'test', 'validationRule' => '/^[a-z0-9 =&]+$/', 'failedRuleText' => 'lower case only'], 201);
    onCleanup(fn() => deleteIfExists("/custom-attribute-types/{$t['id']}"));
    eq(name('cat'), $t['name'], 'type name');
    expectStatus($a->post('/custom-attribute-types', ['name' => name('cat')]), 409, 'conflict', 'duplicate type');
    expectStatus($a->post('/custom-attribute-types', ['name' => name('cat2'), 'validationRule' => '[unclosed']), 422, 'validation_failed', 'bad regex');

    $net = freeNet();
    $s = makeSubnet('ca', $net);
    $h = makeHost('ca-h', "{$net}.10");
    $camp = makeCampus('ca-camp');
    $v = $a->ok('POST', '/vlans', ['campusId' => $camp['id'], 'name' => name('ca-v'), 'number' => 333], 201);
    onCleanup(fn() => deleteIfExists("/vlans/{$v['id']}"));

    $ha = $a->ok('POST', '/custom-attributes', ['typeId' => $t['id'], 'value' => 'a = b & c', 'hostId' => $h['id']], 201);
    onCleanup(fn() => deleteIfExists("/custom-attributes/{$ha['id']}"));
    eq(['kind' => 'host', 'id' => $h['id']], $ha['target'], 'host target');
    eq('a = b & c', $ha['value'], 'value with = and &');
    $sa = $a->ok('POST', '/custom-attributes', ['typeId' => $t['id'], 'value' => 'subnet', 'subnetId' => $s['id']], 201);
    onCleanup(fn() => deleteIfExists("/custom-attributes/{$sa['id']}"));
    $va = $a->ok('POST', '/custom-attributes', ['typeId' => $t['id'], 'value' => 'vlan', 'vlanId' => $v['id']], 201);
    onCleanup(fn() => deleteIfExists("/custom-attributes/{$va['id']}"));
    eq('vlan', $va['target']['kind'], 'vlan target');

    $r = $a->post('/custom-attributes', ['typeId' => $t['id'], 'value' => 'UPPER', 'subnetId' => $s['id']]);
    check(in_array($r->status, [409, 422], true), 'duplicate/invalid value rejected: ' . $r->summary());
    $r = $a->patch("/custom-attributes/{$ha['id']}", ['value' => 'NOT OK']);
    expectStatus($r, 422, 'module_error', 'value fails rule');
    $ha = $a->ok('PATCH', "/custom-attributes/{$ha['id']}", ['value' => 'changed'], 200);
    eq('changed', $ha['value'], 'patched');
    eq([$ha['id']], array_column($a->get("/custom-attributes?hostId={$h['id']}")->json['data'], 'id'), 'list by host');

    expectStatus($a->delete("/custom-attribute-types/{$t['id']}"), 409, 'conflict', 'type in use');
    foreach ([$ha, $sa, $va] as $x) expectStatus($a->delete("/custom-attributes/{$x['id']}"), 204, null, "delete attribute {$x['id']}");
    $t = $a->ok('PATCH', "/custom-attribute-types/{$t['id']}", ['notes' => 'updated'], 200);
    eq('updated', $t['notes'], 'type notes');
    expectStatus($a->delete("/custom-attribute-types/{$t['id']}"), 204, null, 'delete type');
});

test('locations: create, patch (incl. longitude), used by a host, delete', function () {
    $a = admin();
    $ref = 'T' . strtoupper(substr($GLOBALS['RUN'], 0, 6));
    $l = $a->ok('POST', '/locations', ['reference' => $ref, 'name' => name('loc'), 'city' => 'Springfield', 'zipCode' => 12345], 201);
    onCleanup(fn() => deleteIfExists("/locations/{$l['id']}"));
    eq($ref, $l['reference'], 'reference');
    eq(12345, $l['zipCode'], 'zip');
    expectStatus($a->post('/locations', ['reference' => $ref, 'name' => 'dup']), 409, 'conflict', 'duplicate reference');
    $l = $a->ok('PATCH', "/locations/{$l['id']}", ['latitude' => '45.1', 'longitude' => '-122.6', 'address' => '1 Main St'], 200);
    eq('-122.6', $l['longitude'], 'longitude');
    eq('45.1', $l['latitude'], 'latitude');
    eq('Springfield', $l['city'], 'city kept');
    $l = $a->ok('PATCH', "/locations/{$l['id']}", ['city' => 'Springfield'], 200);   // no change: no error

    $net = freeNet();
    makeSubnet('loc', $net);
    $h = makeHost('loc-h', "{$net}.10", ['locationId' => $l['id']]);
    eq($l['id'], $h['location']['id'] ?? null, 'host location');
    expectStatus($a->delete("/locations/{$l['id']}"), 409, 'conflict', 'in use');
    $a->ok('PATCH', "/hosts/{$h['id']}", ['locationId' => 0], 200);
    expectStatus($a->delete("/locations/{$l['id']}"), 204, null, 'delete');
});

test('domains: create child domain, patch SOA keeps parent, in-use delete, delete', function () {
    $a = admin();
    $parent = null;
    foreach ($a->get('/domains')->json['data'] as $d) if ($d['name'] === 'example.com') $parent = $d;
    if (!$parent) skip('no example.com domain');
    $label = name('dom');
    $d = $a->ok('POST', '/domains', ['name' => $label, 'parentId' => $parent['id'], 'defaultTtl' => 600], 201);
    onCleanup(fn() => deleteIfExists("/domains/{$d['id']}"));
    eq("{$label}.example.com", $d['name'], 'fqdn');
    eq($parent['id'], $d['parent']['id'] ?? null, 'parent');
    eq(600, $d['defaultTtl'], 'ttl');
    expectStatus($a->post('/domains', ['name' => 'bad name!']), 422, 'validation_failed', 'bad name');
    $d = $a->ok('PATCH', "/domains/{$d['id']}", ['refresh' => 7200, 'adminEmail' => 'dns@example.com'], 200);
    eq(7200, $d['refresh'], 'refresh');
    eq('dns@example.com', $d['adminEmail'], 'admin email');
    eq($parent['id'], $d['parent']['id'] ?? null, 'parent kept');
    eq($d['id'], $a->get("/domains/{$d['id']}")->json['id'], 'get');

    $net = freeNet();
    makeSubnet('dom', $net);
    $h = makeHost('dom-h', "{$net}.10");
    $rec = $a->ok('POST', '/dns-records', ['type' => 'CNAME', 'name' => "alias.{$label}.example.com", 'pointsTo' => $h['fqdn']], 201);
    onCleanup(fn() => deleteIfExists("/dns-records/{$rec['id']}"));
    eq($d['id'], $rec['domain']['id'], 'record in new domain');
    expectStatus($a->delete("/domains/{$d['id']}"), 409, 'conflict', 'in use');
    expectStatus($a->delete("/dns-records/{$rec['id']}"), 204, null, 'delete record');
    expectStatus($a->delete("/domains/{$d['id']}"), 204, null, 'delete');
});

test('admin: groups, users, permissions, login as the new user', function () {
    $a = admin();
    $grp = $a->ok('POST', '/groups', ['name' => name('grp'), 'description' => 'test group', 'permissions' => ['vlan_add', 'vlan_modify']], 201);
    onCleanup(fn() => deleteIfExists("/groups/{$grp['id']}"));
    eq(['vlan_add', 'vlan_modify'], $grp['permissions'], 'group permissions');
    expectStatus($a->post('/groups', ['name' => name('grp')]), 409, 'conflict', 'duplicate group');
    expectStatus($a->post('/groups', ['name' => name('grp2'), 'permissions' => ['no_such_perm']]), 422, 'validation_failed', 'unknown permission');

    $user = 'resttest_u' . $GLOBALS['RUN'];
    $pass = bin2hex(random_bytes(6));
    $u = $a->ok('POST', '/users', ['username' => $user, 'password' => $pass, 'groupIds' => [$grp['id']], 'permissions' => ['subnet_add']], 201);
    onCleanup(fn() => deleteIfExists("/users/{$u['id']}"));
    eq($user, $u['username'], 'username');
    eq([$grp['id']], array_column($u['groups'], 'id'), 'user groups');
    eq(['subnet_add'], $u['permissions'], 'user permissions');
    check(!array_key_exists('password', $u), 'password not returned');
    expectStatus($a->post('/users', ['username' => $user, 'password' => 'x']), 409, 'conflict', 'duplicate user');
    expectStatus($a->post('/users', ['username' => 'bad user', 'password' => 'x']), 422, 'validation_failed', 'bad username');
    eq([$u['id']], array_column($a->get("/groups/{$grp['id']}")->json['members'], 'id'), 'group members');

    $c = new Client($GLOBALS['BASE'], 'new-user');
    $r = $c->login($user, $pass);
    expectStatus($r, 200, null, 'new user login');
    eq(['subnet_add', 'vlan_add', 'vlan_modify'], $r->json['permissions'], 'effective permissions');
    expectStatus($c->get('/users'), 403, 'forbidden', 'no user_admin');

    $newPass = bin2hex(random_bytes(6));
    $u = $a->ok('PATCH', "/users/{$u['id']}", ['password' => $newPass, 'groupIds' => [], 'permissions' => []], 200);
    eq([], $u['groups'], 'groups cleared');
    expectStatus((new Client($GLOBALS['BASE']))->login($user, $pass), 401, 'unauthorized', 'old password');
    $r = (new Client($GLOBALS['BASE']))->login($user, $newPass);
    expectStatus($r, 200, null, 'new password');
    eq([], $r->json['permissions'], 'permissions removed');

    $me = null;
    foreach ($a->get('/users')->json['data'] as $x) if ($x['username'] === ADMIN_USER) $me = $x;
    expectStatus($a->delete("/users/{$me['id']}"), 409, 'conflict', 'cannot delete yourself');
    expectStatus($a->delete("/users/{$u['id']}"), 204, null, 'delete user');
    expectStatus($a->delete("/groups/{$grp['id']}"), 204, null, 'delete group');
    check(in_array('user_admin', array_column($a->get('/permissions')->json['data'], 'name'), true), 'permissions list');
});

test('admin: sys-config create, validate, patch, protected entries, delete; plugins', function () {
    $a = admin();
    $key = 'resttest_' . $GLOBALS['RUN'];
    $e = $a->ok('POST', '/sys-config', ['name' => $key, 'value' => 'one', 'description' => 'test entry'], 201);
    onCleanup(fn() => deleteIfExists("/sys-config/{$key}"));
    eq(true, $e['editable'] && $e['deleteable'], 'new entries are editable and deleteable');
    expectStatus($a->post('/sys-config', ['name' => $key]), 409, 'conflict', 'duplicate');
    $e = $a->ok('PATCH', "/sys-config/{$key}", ['value' => 'two & = three'], 200);
    eq('two & = three', $e['value'], 'patched value');
    eq($e, $a->get("/sys-config/{$key}")->json, 'get');
    expectStatus($a->patch('/sys-config/version', ['value' => 'v0']), 409, 'conflict', 'not editable');
    expectStatus($a->delete('/sys-config/dns_defaultdomain'), 409, 'conflict', 'not deleteable');
    expectStatus($a->get('/sys-config/no_such_key_' . $GLOBALS['RUN']), 404, 'not_found', 'missing key');
    expectStatus($a->delete("/sys-config/{$key}"), 204, null, 'delete');

    $plugins = $a->get('/plugins');
    expectStatus($plugins, 200, null, 'plugins');
    $names = array_column($plugins->json['data'], 'name');
    expectStatus($a->patch('/plugins/no_such_plugin', ['enabled' => true]), 404, 'not_found', 'unknown plugin');
    if (!$names) return;
    $p = $plugins->json['data'][0];
    $r = $a->patch("/plugins/{$p['name']}", ['enabled' => !$p['enabled']]);
    if ($r->status === 409) return;   // plugin directory not writable by the web server
    expectStatus($r, 200, null, 'toggle plugin');
    eq(!$p['enabled'], $r->json['enabled'], 'toggled');
    expectStatus($a->patch("/plugins/{$p['name']}", ['enabled' => $p['enabled']]), 200, null, 'toggle back');
});

// ===========================================================================
// 8. Spec sanity
// ===========================================================================

test('spec: openapi.yaml documents every implemented route', function () {
    $r = (new Client($GLOBALS['BASE']))->get('/openapi.yaml');
    expectStatus($r, 200);
    check(str_starts_with(ltrim($r->body), 'openapi:'), 'does not look like an OpenAPI document');
    // The paths: block, up to the next top-level key.
    check(preg_match('/^paths:\s*\n(.*?)(?=^\S|\z)/ms', $r->body, $m) === 1, 'no top-level paths: key');
    $paths = $m[1];
    $missing = [];
    foreach (array_merge(PUBLIC_ROUTES, PROTECTED_ROUTES) as [$m, $p]) {
        if (!preg_match('#^  ' . preg_quote($p, '#') . ':\s*$((?:\n(?:    .*|\s*))*)#m', $paths, $mm)) {
            $missing[] = "{$p} (path)";
        } elseif (!preg_match('#^    ' . strtolower($m) . ':#m', $mm[1])) {
            $missing[] = "{$m} {$p} (method)";
        }
    }
    check(!$missing, 'not in spec: ' . implode(', ', $missing));
});

// ===========================================================================
// 9. Output escaping / headers
// ===========================================================================

test('escaping: HTML in notes is JSON-escaped with nosniff headers', function () {
    $a = admin();
    $net = freeNet();
    makeSubnet('xss', $net);
    $note = '<script>alert("x")</script> & \'q\'';
    $h = makeHost('xss-h', "{$net}.10", ['notes' => $note]);
    eq($note, $h['notes'], 'notes round-trip (decoded)');
    $r = $a->get("/hosts/{$h['id']}");
    expectStatus($r, 200);
    check(!str_contains($r->body, '<script'), 'raw <script in body');
    $escaped = trim(json_encode('<script>', JSON_HEX_TAG), '"');   // the six-char \u003C form
    check(str_contains($r->body, $escaped), "expected {$escaped} in body: " . substr($r->body, 0, 300));
    check(str_starts_with((string)$r->header('content-type'), 'application/json'), 'content-type: ' . $r->header('content-type'));
    eq('nosniff', $r->header('x-content-type-options'), 'X-Content-Type-Options');
    // Error responses carry the same headers.
    $e = $a->get('/hosts/999999999');
    check(str_starts_with((string)$e->header('content-type'), 'application/json'), 'error content-type');
    eq('nosniff', $e->header('x-content-type-options'), 'error X-Content-Type-Options');
});

// ===========================================================================
// Runner
// ===========================================================================

$only = getenv('ONA_TEST_ONLY') ?: '';
$pass = $failc = $skipped = 0;
$t0 = microtime(true);
echo "ONA REST API tests against {$BASE} (run id {$RUN})\n";

$health = (new Client($BASE))->get('/health');
if ($health->status !== 200) {
    echo "FAIL  API not reachable: {$health->summary()}\n";
    exit(2);
}

foreach ($TESTS as [$name, $fn]) {
    if ($only !== '' && stripos($name, $only) === false) continue;
    $status = 'PASS';
    $reason = '';
    try {
        $fn();
    } catch (Skipped $e) {
        $status = 'SKIP';
        $reason = $e->getMessage();
    } catch (AssertionFailed $e) {
        $status = 'FAIL';
        $reason = $e->getMessage();
    } catch (Throwable $e) {
        $status = 'FAIL';
        $reason = get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    }
    $cleanupErrors = runCleanup();
    if ($cleanupErrors) {
        if ($status === 'PASS') $status = 'FAIL';
        $reason .= ($reason ? '; ' : '') . 'cleanup: ' . implode('; ', $cleanupErrors);
    }
    match ($status) { 'PASS' => $pass++, 'FAIL' => $failc++, 'SKIP' => $skipped++ };
    printf("%-4s  %s%s\n", $status, $name, $reason !== '' ? "\n      {$reason}" : '');
}

printf("\n%d passed, %d failed, %d skipped in %.1fs\n", $pass, $failc, $skipped, microtime(true) - $t0);
exit($failc > 0 ? 1 : 0);
