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
    $h = admin()->ok('POST', '/hosts', $extra + ['name' => name($suffix) . '.example.com', 'deviceTypeId' => 1, 'ip' => $ip], 201);
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

const REFERENCE_RESOURCES = ['manufacturers', 'models', 'roles', 'device-types', 'subnet-types', 'dhcp-options', 'config-types', 'dns-views'];
const REFERENCE_ROUTES = [
    ['GET', '/manufacturers'], ['POST', '/manufacturers'], ['GET', '/manufacturers/{id}'], ['PATCH', '/manufacturers/{id}'], ['DELETE', '/manufacturers/{id}'],
    ['GET', '/models'], ['POST', '/models'], ['GET', '/models/{id}'], ['PATCH', '/models/{id}'], ['DELETE', '/models/{id}'],
    ['GET', '/roles'], ['POST', '/roles'], ['GET', '/roles/{id}'], ['PATCH', '/roles/{id}'], ['DELETE', '/roles/{id}'],
    ['GET', '/device-types'], ['POST', '/device-types'], ['GET', '/device-types/{id}'], ['PATCH', '/device-types/{id}'], ['DELETE', '/device-types/{id}'],
    ['GET', '/subnet-types'], ['POST', '/subnet-types'], ['GET', '/subnet-types/{id}'], ['PATCH', '/subnet-types/{id}'], ['DELETE', '/subnet-types/{id}'],
    ['GET', '/dhcp-options'], ['POST', '/dhcp-options'], ['GET', '/dhcp-options/{id}'], ['PATCH', '/dhcp-options/{id}'], ['DELETE', '/dhcp-options/{id}'],
    ['GET', '/config-types'], ['POST', '/config-types'], ['GET', '/config-types/{id}'], ['PATCH', '/config-types/{id}'], ['DELETE', '/config-types/{id}'],
    ['GET', '/dns-views'], ['POST', '/dns-views'], ['GET', '/dns-views/{id}'], ['PATCH', '/dns-views/{id}'], ['DELETE', '/dns-views/{id}'],
];

const PROTECTED_ROUTES = [
    ['GET', '/auth/session'], ['POST', '/auth/password'],
    ['GET', '/search'], ['GET', '/stats'],
    ['GET', '/subnets'], ['POST', '/subnets'],
    ['GET', '/subnets/{id}'], ['PATCH', '/subnets/{id}'], ['DELETE', '/subnets/{id}'],
    ['GET', '/subnets/{id}/next-ip'], ['GET', '/subnets/{id}/map'], ['GET', '/subnets/{id}/addresses'],
    ['GET', '/hosts'], ['POST', '/hosts'],
    ['GET', '/hosts/{id}'], ['PATCH', '/hosts/{id}'], ['DELETE', '/hosts/{id}'],
    ['GET', '/hosts/{id}/actions'], ['DELETE', '/hosts/{id}/configs'],
    ['GET', '/interfaces'], ['POST', '/interfaces'],
    ['GET', '/interfaces/{id}'], ['PATCH', '/interfaces/{id}'], ['DELETE', '/interfaces/{id}'],
    ['POST', '/interfaces/move'], ['POST', '/interfaces/{id}/move'],
    ['POST', '/interfaces/{id}/shares'], ['DELETE', '/interfaces/{id}/shares/{hostId}'],
    ['POST', '/interfaces/{id}/nat'], ['DELETE', '/interfaces/{id}/nat'],
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
    ['GET', '/domain-servers'], ['POST', '/domain-servers'],
    ['GET', '/domain-servers/{id}'], ['DELETE', '/domain-servers/{id}'],
    ['GET', '/tags'], ['GET', '/tags/names'], ['POST', '/tags'], ['DELETE', '/tags/{id}'],
    ['GET', '/messages'], ['POST', '/messages'],
    ['GET', '/messages/{id}'], ['PATCH', '/messages/{id}'], ['DELETE', '/messages/{id}'],
    ['GET', '/configs'], ['POST', '/configs'], ['GET', '/configs/diff'],
    ['GET', '/configs/{id}'], ['DELETE', '/configs/{id}'],
    ['GET', '/reports'], ['GET', '/reports/{key}'], ['POST', '/reports/{key}/run'],
    ['GET', '/change-log'],
    ...REFERENCE_ROUTES,
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

test('errors: an unknown ona_context_name cookie falls back to the default context, not 503', function () {
    $c = new Client($GLOBALS['BASE'], 'stale-context');
    $r = $c->get('/health', ['Cookie' => 'ona_context_name=NO_SUCH_CONTEXT']);
    expectStatus($r, 200, null, 'health');
    // The response resets the stale cookie (read from the client's cookie jar).
    $jar = (string)file_get_contents((new ReflectionProperty($c, 'jar'))->getValue($c));
    check((bool)preg_match('/\tona_context_name\tDEFAULT\s*$/m', $jar), 'stale cookie not reset to DEFAULT');
    $r = $c->req('POST', '/auth/login', ['username' => ADMIN_USER, 'password' => ADMIN_PASS], ['Cookie' => 'ona_context_name=NO_SUCH_CONTEXT']);
    expectStatus($r, 200, null, 'login');
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

/** Make sure 10.in-addr.arpa exists (needed for PTR records in 10.x), deleting it afterwards if we added it. */
function ensureReverseZone(): void
{
    foreach (admin()->get('/domains')->json['data'] as $d) if ($d['name'] === '10.in-addr.arpa') return;
    $d = admin()->ok('POST', '/domains', ['name' => '10.in-addr.arpa'], 201);
    onCleanup(fn() => deleteIfExists("/domains/{$d['id']}"));
}

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
// 7b. Features added for the classic-UI parity work
// ===========================================================================

test('guest: reference data, DNS servers, interfaces, tags, messages, configs, logs are 403', function () {
    $net = freeNet();
    makeSubnet('g2', $net);
    $h = makeHost('g2-h', "{$net}.10");
    $iface = $h['interfaces'][0];
    $g = (new Client($GLOBALS['BASE'], 'guest'))->mustLogin(GUEST_USER, GUEST_PASS);
    $cases = [
        ['POST', '/manufacturers', ['name' => name('g-man')], 'advanced'],
        ['PATCH', '/roles/1', ['name' => name('g-role')], 'advanced'],
        ['DELETE', '/config-types/1', null, 'advanced'],
        ['POST', '/dns-views', ['name' => name('g-view')], 'advanced'],
        ['PATCH', "/dns-records/{$h['primaryDnsId']}", ['enabled' => false], 'host_modify'],
        ['POST', '/domain-servers', ['domainId' => $h['domain']['id'], 'hostId' => $h['id']], 'advanced'],
        ['POST', "/interfaces/{$iface['id']}/move", ['hostId' => $h['id']], 'advanced'],
        ['POST', "/interfaces/{$iface['id']}/shares", ['hostId' => $h['id']], 'interface_del'],
        ['DELETE', "/interfaces/{$iface['id']}/shares/{$h['id']}", null, 'interface_del'],
        ['POST', "/interfaces/{$iface['id']}/nat", ['ip' => "{$net}.99"], 'interface_modify'],
        ['DELETE', "/interfaces/{$iface['id']}/nat", null, 'interface_modify'],
        ['POST', '/interfaces/move', ['start' => "{$net}.10", 'newStart' => "{$net}.20"], 'interface_modify'],
        ['POST', '/tags', ['name' => 'gtag', 'hostId' => $h['id']], 'host_add'],
        ['POST', '/tags', ['name' => 'gtag', 'subnetId' => 1], 'subnet_add'],
        ['POST', '/messages', ['hostId' => $h['id'], 'message' => 'guest'], 'advanced'],
        ['PATCH', '/messages/1', ['priority' => 1], 'advanced'],
        ['DELETE', '/messages/1', null, 'advanced'],
        ['GET', '/configs', null, 'host_config_admin'],
        ['POST', '/configs', ['hostId' => $h['id'], 'typeId' => 1, 'text' => 'x'], 'host_config_admin'],
        ['DELETE', "/hosts/{$h['id']}/configs", null, 'host_config_admin'],
        ['GET', '/configs/diff?from=1&to=2', null, 'host_config_admin'],
        ['GET', '/change-log', null, 'advanced'],
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
    eq([], $a->get("/tags?hostId={$h['id']}")->json['data'], 'no guest tag');
    eq([], $a->get("/messages?hostId={$h['id']}")->json['data'], 'no guest message');
    eq(true, $a->get("/dns-records/{$h['primaryDnsId']}")->json['enabled'], 'record still enabled');
    // Reads that any logged-in user may make.
    expectStatus($g->get('/dns-views'), 200, null, 'guest reads DNS views');
    expectStatus($g->get('/reports'), 200, null, 'guest lists reports');
});

test('reference data: CRUD for every lookup table, in-use and built-in rows are 409', function () {
    $a = admin();
    foreach (REFERENCE_RESOURCES as $res) expectStatus($a->get("/{$res}"), 200, null, "list {$res}");

    $man = $a->ok('POST', '/manufacturers', ['name' => name('man')], 201);
    onCleanup(fn() => deleteIfExists("/manufacturers/{$man['id']}"));
    expectStatus($a->post('/manufacturers', ['name' => name('man')]), 409, 'conflict', 'duplicate manufacturer');
    $model = $a->ok('POST', '/models', ['name' => name('model'), 'manufacturerId' => $man['id'], 'snmpSysObjectId' => '1.3.6.1.4.1.9'], 201);
    onCleanup(fn() => deleteIfExists("/models/{$model['id']}"));
    expectStatus($a->post('/models', ['name' => 'x', 'manufacturerId' => 999999]), 422, 'validation_failed', 'unknown manufacturer');
    $role = $a->ok('POST', '/roles', ['name' => name('role')], 201);
    onCleanup(fn() => deleteIfExists("/roles/{$role['id']}"));
    $dt = $a->ok('POST', '/device-types', ['modelId' => $model['id'], 'roleId' => $role['id']], 201);
    onCleanup(fn() => deleteIfExists("/device-types/{$dt['id']}"));
    eq(name('model'), $dt['model'], 'device type model');
    eq(name('role'), $dt['role'], 'device type role');
    eq(1, $a->get("/models/{$model['id']}")->json['inUse'], 'model in use by the device type');
    expectStatus($a->delete("/models/{$model['id']}"), 409, 'conflict', 'model in use');
    $model = $a->ok('PATCH', "/models/{$model['id']}", ['name' => name('model2')], 200);
    eq(name('model2'), $model['name'], 'renamed model');

    // A host using the new device type blocks its delete.
    $net = freeNet();
    makeSubnet('ref', $net);
    $h = makeHost('ref-h', "{$net}.10", ['deviceTypeId' => $dt['id']]);
    expectStatus($a->delete("/device-types/{$dt['id']}"), 409, 'conflict', 'device type in use');
    $hosts = $a->get("/hosts?deviceTypeId={$dt['id']}")->json;
    eq([$h['id']], array_column($hosts['data'], 'id'), 'hosts filtered by device type');
    expectStatus($a->delete("/hosts/{$h['id']}"), 204, null, 'delete host');
    foreach (["/device-types/{$dt['id']}", "/models/{$model['id']}", "/roles/{$role['id']}", "/manufacturers/{$man['id']}"] as $p) {
        expectStatus($a->delete($p), 204, null, "delete {$p}");
    }

    $st = $a->ok('POST', '/subnet-types', ['name' => name('st'), 'shortName' => 'RT' . $GLOBALS['RUN'], 'notes' => 'n'], 201);
    onCleanup(fn() => deleteIfExists("/subnet-types/{$st['id']}"));
    eq(strtolower('RT' . $GLOBALS['RUN']), $st['shortName'], 'short name lower-cased');
    expectStatus($a->post('/subnet-types', ['name' => 'x', 'shortName' => 'bad name']), 422, 'validation_failed', 'bad short name');
    expectStatus($a->delete("/subnet-types/{$st['id']}"), 204, null, 'delete subnet type');

    $opt = $a->ok('POST', '/dhcp-options', ['name' => 'rt-' . $GLOBALS['RUN'], 'displayName' => 'Rest test', 'number' => 224, 'type' => 'S'], 201);
    onCleanup(fn() => deleteIfExists("/dhcp-options/{$opt['id']}"));
    eq(false, $opt['system'], 'custom option');
    $opt = $a->ok('PATCH', "/dhcp-options/{$opt['id']}", ['type' => 'N'], 200);
    eq('N', $opt['type'], 'patched type');
    $builtin = null;
    foreach ($a->get('/dhcp-options')->json['data'] as $o) if ($o['system']) $builtin = $o;
    if ($builtin) expectStatus($a->patch("/dhcp-options/{$builtin['id']}", ['displayName' => 'x']), 409, 'conflict', 'built-in option');
    expectStatus($a->delete("/dhcp-options/{$opt['id']}"), 204, null, 'delete option');

    $ct = $a->ok('POST', '/config-types', ['name' => strtoupper(name('ct'))], 201);
    onCleanup(fn() => deleteIfExists("/config-types/{$ct['id']}"));
    expectStatus($a->delete("/config-types/{$ct['id']}"), 204, null, 'delete config type');

    $v = $a->ok('POST', '/dns-views', ['name' => name('view one'), 'description' => 'test view'], 201);
    onCleanup(fn() => deleteIfExists("/dns-views/{$v['id']}"));
    eq(strtoupper(name('view-one')), $v['name'], 'view name normalised');
    expectStatus($a->patch('/dns-views/0', ['description' => 'x']), 409, 'conflict', 'default view');
    expectStatus($a->delete('/dns-views/0'), 409, 'conflict', 'default view delete');
    expectStatus($a->delete("/dns-views/{$v['id']}"), 204, null, 'delete view');
    expectStatus($a->patch('/roles/999999', ['name' => 'x']), 404, 'not_found', 'missing role');
});

test('account: change own password', function () {
    $a = admin();
    $user = 'resttest_p' . $GLOBALS['RUN'];
    $pass = bin2hex(random_bytes(6));
    $u = $a->ok('POST', '/users', ['username' => $user, 'password' => $pass], 201);
    onCleanup(fn() => deleteIfExists("/users/{$u['id']}"));
    $c = (new Client($GLOBALS['BASE'], 'pw-user'))->mustLogin($user, $pass);
    expectStatus($c->post('/auth/password', ['currentPassword' => 'wrong', 'newPassword' => 'abc']), 422, 'validation_failed', 'wrong current');
    expectStatus($c->post('/auth/password', ['currentPassword' => $pass]), 422, 'validation_failed', 'missing new');
    expectStatus($c->post('/auth/password', ['currentPassword' => $pass, 'newPassword' => $pass]), 422, 'validation_failed', 'same password');
    expectStatus($c->req('POST', '/auth/password', ['currentPassword' => $pass, 'newPassword' => 'x'], [], false), 403, 'csrf_failed', 'no csrf');
    $new = bin2hex(random_bytes(6));
    expectStatus($c->post('/auth/password', ['currentPassword' => $pass, 'newPassword' => $new]), 204, null, 'change');
    expectStatus((new Client($GLOBALS['BASE']))->login($user, $pass), 401, 'unauthorized', 'old password');
    expectStatus((new Client($GLOBALS['BASE']))->login($user, $new), 200, null, 'new password');
});

test('dns records: disable/enable, scheduled start, editing keeps disabled, views', function () {
    $a = admin();
    $net = freeNet();
    makeSubnet('dnsen', $net);
    $h = makeHost('dnsen-h', "{$net}.10");
    $primary = $a->get("/dns-records/{$h['primaryDnsId']}")->json;
    eq(true, $primary['enabled'], 'new A record enabled');
    eq(0, $primary['view']['id'], 'default view');

    $cn = $a->ok('POST', '/dns-records', ['type' => 'CNAME', 'name' => name('dnsen-cn') . '.example.com', 'pointsTo' => $h['fqdn'], 'enabled' => false], 201);
    onCleanup(fn() => deleteIfExists("/dns-records/{$cn['id']}"));
    eq(false, $cn['enabled'], 'created disabled');
    eq(null, $cn['activeFrom'], 'no active date when disabled');
    $cn = $a->ok('PATCH', "/dns-records/{$cn['id']}", ['ttl' => 1234], 200);
    eq(1234, $cn['ttl'], 'ttl changed');
    eq(false, $cn['enabled'], 'still disabled after an unrelated edit');
    $ids = array_column($a->get('/dns-records?enabled=false&perPage=200&q=' . name('dnsen-cn'))->json['data'], 'id');
    check(in_array($cn['id'], $ids, true), 'listed with enabled=false');
    $cn = $a->ok('PATCH', "/dns-records/{$cn['id']}", ['enabled' => true], 200);
    eq(true, $cn['enabled'], 're-enabled');
    $cn = $a->ok('PATCH', "/dns-records/{$cn['id']}", ['enabled' => true], 200);
    eq(true, $cn['enabled'], 'enabling an enabled record is a no-op');
    $ids = array_column($a->get('/dns-records?enabled=false&perPage=200&q=' . name('dnsen-cn'))->json['data'], 'id');
    check(!in_array($cn['id'], $ids, true), 'not listed with enabled=false');

    $future = date('Y-m-d H:i:s', time() + 86400 * 30);
    $cn = $a->ok('PATCH', "/dns-records/{$cn['id']}", ['activeFrom' => $future], 200);
    eq(false, $cn['enabled'], 'scheduled record not active yet');
    eq(strtotime($future), strtotime((string)$cn['activeFrom']), 'activeFrom kept');
    $cn = $a->ok('PATCH', "/dns-records/{$cn['id']}", ['notes' => 'later'], 200);
    check(abs(strtotime((string)$cn['activeFrom']) - strtotime($future)) <= 1, 'scheduled date survives an edit: ' . $cn['activeFrom']);
    $cn = $a->ok('PATCH', "/dns-records/{$cn['id']}", ['enabled' => false], 200);
    eq(false, $cn['enabled'], 'disabled');
    eq(null, $cn['activeFrom'], 'disabled has no date');
    expectStatus($a->patch("/dns-records/{$cn['id']}", ['activeFrom' => 'tomorrow']), 422, 'validation_failed', 'bad date');
    expectStatus($a->patch("/dns-records/{$cn['id']}", ['enabled' => 'no']), 422, 'validation_failed', 'non-boolean');

    // Views: a parent record moves with its children.
    $v = $a->ok('POST', '/dns-views', ['name' => name('v')], 201);
    onCleanup(fn() => deleteIfExists("/dns-views/{$v['id']}"));
    $txt = $a->ok('POST', '/dns-records', ['type' => 'TXT', 'name' => name('dnsen-txt') . '.example.com', 'txt' => 'v=1', 'viewId' => $v['id']], 201);
    onCleanup(fn() => deleteIfExists("/dns-records/{$txt['id']}"));
    eq($v['id'], $txt['view']['id'], 'created in view');
    eq(strtoupper(name('v')), $txt['view']['name'], 'view name');
    $txt = $a->ok('PATCH', "/dns-records/{$txt['id']}", ['viewId' => 0], 200);
    eq(0, $txt['view']['id'], 'moved back to the default view');
    expectStatus($a->patch("/dns-records/{$txt['id']}", ['viewId' => 999999]), 404, 'not_found', 'unknown view');
    expectStatus($a->patch("/dns-records/{$cn['id']}", ['viewId' => $v['id']]), 422, 'module_error', 'child record cannot change view');
    eq(0, $a->get("/dns-views/{$v['id']}")->json['inUse'], 'view not in use any more');
});

test('domain servers: assign master, NS record, duplicate, list, delete', function () {
    $a = admin();
    $parent = null;
    foreach ($a->get('/domains')->json['data'] as $d) if ($d['name'] === 'example.com') $parent = $d;
    if (!$parent) skip('no example.com domain');
    $dom = $a->ok('POST', '/domains', ['name' => name('ds'), 'parentId' => $parent['id']], 201);
    onCleanup(fn() => deleteIfExists("/domains/{$dom['id']}"));
    $net = freeNet();
    makeSubnet('ds', $net);
    $h = makeHost('ds-ns', "{$net}.10");
    $as = $a->ok('POST', '/domain-servers', ['domainId' => $dom['id'], 'hostId' => $h['id'], 'role' => 'slave'], 201);
    onCleanup(fn() => deleteIfExists("/domain-servers/{$as['id']}"));
    eq('slave', $as['role'], 'role');
    eq($h['fqdn'], $as['server']['fqdn'], 'server');
    eq($dom['name'], $as['domain']['name'], 'domain');
    expectStatus($a->post('/domain-servers', ['domainId' => $dom['id'], 'hostId' => $h['id']]), 409, 'conflict', 'duplicate');
    expectStatus($a->post('/domain-servers', ['domainId' => $dom['id'], 'hostId' => $h['id'], 'role' => 'boss']), 422, 'validation_failed', 'bad role');
    eq([$as['id']], array_column($a->get("/domain-servers?domainId={$dom['id']}")->json['data'], 'id'), 'list by domain');
    eq([$as['id']], array_column($a->get("/domains/{$dom['id']}")->json['servers'], 'id'), 'servers on the domain');
    $ns = $a->get("/dns-records?type=NS&domainId={$dom['id']}")->json['data'];
    eq(1, count($ns), 'NS record added');
    eq($h['fqdn'], $ns[0]['pointsTo']['fqdn'] ?? null, 'NS target');
    expectStatus($a->delete("/domain-servers/{$as['id']}"), 204, null, 'delete');
    eq(0, $a->get("/dns-records?type=NS&domainId={$dom['id']}")->json['meta']['total'], 'NS record removed');
    expectStatus($a->get("/domain-servers/{$as['id']}"), 404, 'not_found', 'gone');
});

test('interfaces: share, NAT, move to another host, bulk move between subnets', function () {
    $a = admin();
    $net = freeNet();
    $net2 = freeNet();
    makeSubnet('if1', $net);
    makeSubnet('if2', $net2);
    $h1 = makeHost('if-a', "{$net}.10");
    $h2 = makeHost('if-b', "{$net}.11");
    $vip = $a->ok('POST', '/interfaces', ['hostId' => $h1['id'], 'ip' => "{$net}.20", 'name' => 'vip'], 201);

    // Share the VIP with the second host.
    $i = $a->ok('POST', "/interfaces/{$vip['id']}/shares", ['hostId' => $h2['id'], 'name' => 'carp0'], 201);
    eq([['hostId' => $h2['id'], 'fqdn' => $h2['fqdn'], 'name' => 'carp0']], $i['sharedWith'], 'shared with');
    expectStatus($a->post("/interfaces/{$vip['id']}/shares", ['hostId' => $h2['id']]), 409, 'conflict', 'duplicate share');
    expectStatus($a->post("/interfaces/{$vip['id']}/shares", ['hostId' => $h1['id']]), 422, 'validation_failed', 'own host');
    $d2 = $a->get("/hosts/{$h2['id']}")->json;
    eq([$vip['id']], array_column($d2['sharedInterfaces'], 'id'), 'shared interface on host 2');
    expectStatus($a->delete("/interfaces/{$vip['id']}/shares/{$h2['id']}"), 204, null, 'unshare');
    expectStatus($a->delete("/interfaces/{$vip['id']}/shares/{$h2['id']}"), 404, 'not_found', 'unshare again');

    // NAT: an external address on another subnet. nat_add always adds a
    // PTR record, which needs a reverse zone.
    ensureReverseZone();
    $i = $a->ok('POST', "/interfaces/{$vip['id']}/nat", ['ip' => "{$net2}.50"], 201);
    check($i['natInterfaceId'] !== null, 'nat interface id set');
    $nat = $a->get("/interfaces/{$i['natInterfaceId']}")->json;
    eq("{$net2}.50", $nat['ip'], 'nat ip');
    eq($h1['id'], $nat['host']['id'], 'nat interface on the same host');
    expectStatus($a->post("/interfaces/{$vip['id']}/nat", ['ip' => "{$net2}.51"]), 409, 'conflict', 'second nat');
    expectStatus($a->delete("/interfaces/{$vip['id']}/nat"), 204, null, 'delete nat');
    expectStatus($a->get("/interfaces/{$nat['id']}"), 404, 'not_found', 'nat interface gone');
    eq(null, $a->get("/interfaces/{$vip['id']}")->json['natInterfaceId'], 'nat cleared');
    expectStatus($a->delete("/interfaces/{$vip['id']}/nat"), 404, 'not_found', 'no nat');

    // Move the VIP to host 2; the primary interface can't move.
    $i = $a->ok('POST', "/interfaces/{$vip['id']}/move", ['hostId' => $h2['id']], 200);
    eq($h2['id'], $i['host']['id'], 'moved');
    expectStatus($a->post("/interfaces/{$h1['primaryInterfaceId']}/move", ['hostId' => $h2['id']]), 422, 'module_error', 'primary interface');

    // Bulk move .10-.20 to the second subnet: preview, then commit.
    $p = $a->ok('POST', '/interfaces/move', ['start' => "{$net}.10", 'end' => "{$net}.20", 'newStart' => "{$net2}.100", 'newEnd' => "{$net2}.120"], 200);
    eq(false, $p['committed'], 'preview');
    eq(3, count($p['moves']), 'three interfaces would move: ' . json_encode($p));
    eq("{$net}.10", $p['moves'][0]['from'], 'first from');
    eq("{$net2}.100", $p['moves'][0]['to'], 'first to');
    eq("{$net}.10", $a->get("/interfaces/{$h1['primaryInterfaceId']}")->json['ip'], 'preview moved nothing');
    $p = $a->ok('POST', '/interfaces/move', ['start' => "{$net}.10", 'end' => "{$net}.20", 'newStart' => "{$net2}.100", 'newEnd' => "{$net2}.120", 'commit' => true], 200);
    eq(true, $p['committed'], 'committed');
    $moved = $a->get("/interfaces/{$h1['primaryInterfaceId']}")->json;
    eq("{$net2}.100", $moved['ip'], 'moved address');
    eq(strtoupper(name('if2')), $moved['subnet']['name'], 'moved subnet');
    expectStatus($a->post('/interfaces/move', ['start' => "{$net}.10", 'newStart' => "{$net2}.130"]), 422, 'module_error', 'nothing left to move');
    expectStatus($a->post('/interfaces/move', ['start' => 'fe80::1', 'newStart' => "{$net2}.1"]), 422, 'validation_failed', 'ipv6');
});

test('tags: on hosts and subnets, duplicates, names, list filters, delete', function () {
    $a = admin();
    $net = freeNet();
    $s = makeSubnet('tag', $net);
    $h = makeHost('tag-h', "{$net}.10");
    $tagName = 'rt-' . $GLOBALS['RUN'];
    $t = $a->ok('POST', '/tags', ['name' => $tagName, 'hostId' => $h['id']], 201);
    onCleanup(fn() => deleteIfExists("/tags/{$t['id']}"));
    eq(['kind' => 'host', 'id' => $h['id']], $t['target'], 'target');
    expectStatus($a->post('/tags', ['name' => $tagName, 'hostId' => $h['id']]), 409, 'conflict', 'duplicate');
    expectStatus($a->post('/tags', ['name' => 'bad<tag>', 'hostId' => $h['id']]), 422, 'validation_failed', 'bad name');
    expectStatus($a->post('/tags', ['name' => 'x', 'hostId' => $h['id'], 'subnetId' => $s['id']]), 422, 'validation_failed', 'two targets');
    $t2 = $a->ok('POST', '/tags', ['name' => "{$tagName} two", 'subnetId' => $s['id']], 201);
    onCleanup(fn() => deleteIfExists("/tags/{$t2['id']}"));
    eq("{$tagName}-two", $t2['name'], 'spaces become dashes');
    $t3 = $a->ok('POST', '/tags', ['name' => $tagName, 'subnetId' => $s['id']], 201);
    onCleanup(fn() => deleteIfExists("/tags/{$t3['id']}"));

    eq([$tagName], $a->get("/hosts/{$h['id']}")->json['tags'], 'tags on host');
    eq([$tagName, "{$tagName}-two"], $a->get("/subnets/{$s['id']}")->json['tags'], 'tags on subnet');
    eq([$h['id']], array_column($a->get("/hosts?tag={$tagName}")->json['data'], 'id'), 'hosts by tag');
    eq([$s['id']], array_column($a->get("/subnets?tag={$tagName}-two")->json['data'], 'id'), 'subnets by tag');
    eq(2, count($a->get("/tags?name={$tagName}")->json['data']), 'uses of the name');
    $names = [];
    foreach ($a->get('/tags/names')->json['data'] as $n) $names[$n['name']] = $n;
    eq(['name' => $tagName, 'hostCount' => 1, 'subnetCount' => 1], $names[$tagName] ?? null, 'name counts');
    expectStatus($a->get('/tags'), 400, 'bad_request', 'unfiltered');
    expectStatus($a->delete("/tags/{$t['id']}"), 204, null, 'delete');
    eq([], $a->get("/tags?hostId={$h['id']}")->json['data'], 'host has no tags');
    eq(0, $a->get("/hosts?tag={$tagName}")->json['meta']['total'], 'filter after delete');
});

test('messages: add to host and subnet, expire, edit, delete', function () {
    $a = admin();
    $net = freeNet();
    $s = makeSubnet('msg', $net);
    $h = makeHost('msg-h', "{$net}.10");
    $m = $a->ok('POST', '/messages', ['hostId' => $h['id'], 'message' => 'Maintenance & reboot = soon', 'priority' => 1], 201);
    onCleanup(fn() => deleteIfExists("/messages/{$m['id']}"));
    eq('Maintenance & reboot = soon', $m['message'], 'text round-trips');
    eq(1, $m['priority'], 'priority');
    eq('high', $m['priorityName'], 'priority name');
    eq(ADMIN_USER, $m['username'], 'username recorded');
    eq(['kind' => 'host', 'id' => $h['id'], 'name' => $h['fqdn']], $m['target'], 'target');
    check(strtotime((string)$m['expiration']) > time() + 86400 * 30, 'default expiration is weeks away');
    $sm = $a->ok('POST', '/messages', ['subnetId' => $s['id'], 'message' => 'subnet note', 'expiration' => date('Y-m-d', time() + 86400 * 3)], 201);
    onCleanup(fn() => deleteIfExists("/messages/{$sm['id']}"));
    eq(3, $sm['priority'], 'default priority');
    eq([$m['id']], array_column($a->get("/messages?hostId={$h['id']}")->json['data'], 'id'), 'host messages');
    eq([$sm['id']], array_column($a->get("/messages?subnetId={$s['id']}")->json['data'], 'id'), 'subnet messages');
    expectStatus($a->post('/messages', ['hostId' => $h['id'], 'message' => 'x', 'priority' => 7]), 422, 'validation_failed', 'bad priority');
    expectStatus($a->post('/messages', ['hostId' => $h['id']]), 422, 'validation_failed', 'no text');

    $m = $a->ok('PATCH', "/messages/{$m['id']}", ['message' => 'edited', 'priority' => 0], 200);
    eq('edited', $m['message'], 'edited');
    $m = $a->ok('PATCH', "/messages/{$m['id']}", ['expiration' => 'now'], 200);
    eq(true, $m['expired'], 'expired');
    eq([], $a->get("/messages?hostId={$h['id']}")->json['data'], 'expired messages hidden');
    eq([$m['id']], array_column($a->get("/messages?hostId={$h['id']}&includeExpired=true")->json['data'], 'id'), 'includeExpired');
    expectStatus($a->delete("/messages/{$m['id']}"), 204, null, 'delete');
    expectStatus($a->get("/messages/{$m['id']}"), 404, 'not_found', 'gone');
});

test('change log: lists ona_logs when log_to_db is on', function () {
    $a = admin();
    $orig = $a->get('/sys-config/log_to_db')->json['value'] ?? null;
    if ($orig === null) skip('no log_to_db setting');
    $a->ok('PATCH', '/sys-config/log_to_db', ['value' => '1'], 200);
    onCleanup(fn() => admin()->patch('/sys-config/log_to_db', ['value' => $orig]));
    $net = freeNet();
    makeSubnet('log', $net);
    // ONA logs the subnet's address, not its name.
    $r = $a->get('/change-log?q=' . urlencode("{$net}.0/24"));
    expectStatus($r, 200);
    eq(true, $r->json['meta']['logToDb'], 'logToDb');
    check($r->json['meta']['total'] >= 1, 'subnet add is logged: ' . $r->body);
    eq(ADMIN_USER, $r->json['data'][0]['username'], 'log username');
    expectStatus($a->get('/change-log?sort=message'), 400, 'bad_request', 'bad sort');
    check($a->get('/change-log?username=nobody_' . $GLOBALS['RUN'])->json['meta']['total'] === 0, 'username filter');
});

test('configs: add, list, view exact text, diff, delete', function () {
    $a = admin();
    $net = freeNet();
    makeSubnet('cfg', $net);
    $h = makeHost('cfg-h', "{$net}.10");
    $text1 = "hostname r1\ninterface eth0\n description a=b & c\n!\n";
    $text2 = "hostname r1\ninterface eth0\n description changed\n!\n";
    $c1 = $a->ok('POST', '/configs', ['hostId' => $h['id'], 'typeId' => 1, 'text' => $text1], 201);
    onCleanup(fn() => deleteIfExists("/configs/{$c1['id']}"));
    eq($text1, $c1['text'], 'exact text');
    eq(md5($text1), $c1['md5'], 'md5');
    eq('IOS_CONFIG', $c1['type']['name'], 'type');
    $c2 = $a->ok('POST', '/configs', ['hostId' => $h['id'], 'typeId' => 1, 'text' => $text2], 201);
    onCleanup(fn() => deleteIfExists("/configs/{$c2['id']}"));
    $list = $a->get("/configs?hostId={$h['id']}")->json;
    eq(2, $list['meta']['total'], 'two configs');
    check(!isset($list['data'][0]['text']), 'list has no text');
    $d = $a->ok('GET', "/configs/diff?from={$c1['id']}&to={$c2['id']}", null, 200);
    check(str_starts_with($d['diff'], '@@ '), 'unified diff: ' . $d['diff']);
    check(str_contains($d['diff'], 'description a=b & c') && str_contains($d['diff'], 'description changed'), 'diff shows both lines: ' . $d['diff']);
    eq($c1['id'], $d['from']['id'], 'from is the older config');
    expectStatus($a->get("/configs/diff?from={$c1['id']}&to={$c1['id']}"), 400, 'bad_request', 'same config');
    expectStatus($a->get("/configs/diff?from={$c1['id']}&to=999999999"), 404, 'not_found', 'missing config');
    expectStatus($a->post('/configs', ['hostId' => $h['id'], 'typeId' => 999999, 'text' => 'x']), 404, 'not_found', 'unknown type');
    expectStatus($a->post('/configs', ['hostId' => $h['id'], 'typeId' => 1, 'text' => '']), 422, 'validation_failed', 'empty text');
    expectStatus($a->delete("/configs/{$c1['id']}"), 204, null, 'delete');
    eq(1, $a->get("/configs?hostId={$h['id']}")->json['meta']['total'], 'one left');
    $a->ok('POST', '/configs', ['hostId' => $h['id'], 'typeId' => 2, 'text' => "version 1\n"], 201);
    eq(['deleted' => 2], $a->ok('DELETE', "/hosts/{$h['id']}/configs", null, 200), 'delete all');
    eq(0, $a->get("/configs?hostId={$h['id']}")->json['meta']['total'], 'none left');
    eq(['deleted' => 0], $a->ok('DELETE', "/hosts/{$h['id']}/configs", null, 200), 'delete all again');
});

test('reports: list, usage, run as text, refuse HTML, unknown names and reserved params', function () {
    $a = admin();
    $list = $a->get('/reports');
    expectStatus($list, 200);
    expectStatus($a->get('/reports/no_such_report'), 404, 'not_found', 'unknown report');
    expectStatus($a->post('/reports/no_such_report/run', []), 404, 'not_found', 'unknown report run');
    $names = array_column($list->json['data'], 'name');
    if (!in_array('ona_nmap_scans', $names, true)) skip('the bundled nmap report is not enabled');
    $u = $a->ok('GET', '/reports/ona_nmap_scans', null, 200);
    check(str_contains($u['usage'], 'nmap'), 'usage text: ' . $u['usage']);
    expectStatus($a->post('/reports/ona_nmap_scans/run', ['format' => 'html']), 422, 'validation_failed', 'html format');
    expectStatus($a->post('/reports/ona_nmap_scans/run', ['params' => ['module_loglevel' => '9']]), 422, 'validation_failed', 'reserved param');
    expectStatus($a->post('/reports/ona_nmap_scans/run', ['params' => ['Bad-Key' => 'x']]), 422, 'validation_failed', 'bad param name');
    $net = freeNet();
    makeSubnet('rpt', $net);
    // No scan file exists for the new subnet, so the report reports that.
    $r = $a->post('/reports/ona_nmap_scans/run', ['format' => 'text', 'params' => ['subnet' => "{$net}.0"]]);
    expectStatus($r, 422, 'module_error', 'no scan file');
    check(str_contains($r->json['error']['message'] . implode(' ', $r->json['error']['details']['output'] ?? []), 'scan'), 'scan error message: ' . $r->body);
});

test('subnet map: used addresses and pools as offsets; refuses big and IPv6 subnets', function () {
    $a = admin();
    $net = freeNet();
    $s = makeSubnet('map', $net);
    $h = makeHost('map-h', "{$net}.10");
    $pool = $a->ok('POST', '/dhcp-pools', ['start' => "{$net}.100", 'end' => "{$net}.110"], 201);
    onCleanup(fn() => deleteIfExists("/dhcp-pools/{$pool['id']}"));
    $m = $a->ok('GET', "/subnets/{$s['id']}/map", null, 200);
    eq(256, $m['size'], 'size');
    eq([0, 255], $m['reserved'], 'network and broadcast');
    eq([10], array_column($m['used'], 'offset'), 'used offsets');
    eq($h['id'], $m['used'][0]['host']['id'], 'used by host');
    eq([['id' => $pool['id'], 'start' => "{$net}.100", 'end' => "{$net}.110", 'startOffset' => 100, 'endOffset' => 110]], $m['pools'], 'pools');
    $big = $a->ok('POST', '/subnets', ['name' => strtoupper(name('map-big')), 'ip' => '10.249.0.0', 'netmask' => '16', 'typeId' => 13], 201);
    onCleanup(fn() => deleteIfExists("/subnets/{$big['id']}"));
    expectStatus($a->get("/subnets/{$big['id']}/map"), 422, 'unsupported', '/16');
});

test('advanced filters: hosts by location, IP range, custom attribute; subnets by VLAN and IP range', function () {
    $a = admin();
    $net = freeNet();
    $s = makeSubnet('flt', $net);
    $h1 = makeHost('flt-a', "{$net}.10");
    $h2 = makeHost('flt-b', "{$net}.20");
    $ids = fn(string $q) => array_column($a->get($q)->json['data'], 'id');
    eq([$h1['id']], $ids("/hosts?ipFrom={$net}.5&ipTo={$net}.15&perPage=200"), 'hosts in range');
    eq([$h1['id'], $h2['id']], $ids("/hosts?ipFrom={$net}.0&ipTo={$net}.255&perPage=200&sort=ip"), 'hosts in subnet range');
    eq([$s['id']], $ids("/subnets?ipFrom={$net}.0&ipTo={$net}.0"), 'subnets in range');
    expectStatus($a->get('/hosts?ipFrom=nope'), 400, 'bad_request', 'bad ipFrom');

    $loc = $a->ok('POST', '/locations', ['reference' => 'RT' . strtoupper($GLOBALS['RUN']), 'name' => name('loc')], 201);
    onCleanup(fn() => deleteIfExists("/locations/{$loc['id']}"));
    $a->ok('PATCH', "/hosts/{$h2['id']}", ['locationId' => $loc['id']], 200);
    onCleanup(fn() => admin()->patch("/hosts/{$h2['id']}", ['locationId' => 0]));
    eq([$h2['id']], $ids("/hosts?locationId={$loc['id']}"), 'hosts by location');

    $cat = $a->ok('POST', '/custom-attribute-types', ['name' => name('flt-ca')], 201);
    onCleanup(fn() => deleteIfExists("/custom-attribute-types/{$cat['id']}"));
    $ca = $a->ok('POST', '/custom-attributes', ['typeId' => $cat['id'], 'value' => 'rack-42', 'hostId' => $h1['id']], 201);
    onCleanup(fn() => deleteIfExists("/custom-attributes/{$ca['id']}"));
    eq([$h1['id']], $ids("/hosts?caTypeId={$cat['id']}"), 'hosts with attribute');
    eq([$h1['id']], $ids("/hosts?caTypeId={$cat['id']}&caValue=42"), 'attribute value match');
    eq([], $ids("/hosts?caTypeId={$cat['id']}&caValue=99"), 'attribute value miss');

    $camp = makeCampus('flt');
    $v = $a->ok('POST', '/vlans', ['campusId' => $camp['id'], 'name' => name('flt-v'), 'number' => 42], 201);
    onCleanup(fn() => deleteIfExists("/vlans/{$v['id']}"));
    $a->ok('PATCH', "/subnets/{$s['id']}", ['vlanId' => $v['id']], 200);
    onCleanup(fn() => admin()->patch("/subnets/{$s['id']}", ['vlanId' => 0]));
    eq([$s['id']], $ids("/subnets?vlanId={$v['id']}"), 'subnets by VLAN');
});


test('stats: counts for the home page', function () {
    $a = admin();
    $before = $a->ok('GET', '/stats', null, 200);
    foreach (['subnets', 'hosts', 'interfaces', 'dnsRecords', 'domains', 'vlans', 'blocks', 'locations', 'dhcpServers', 'dnsServers', 'activeMessages'] as $k) {
        check(is_int($before['counts'][$k] ?? null), "counts.{$k}: " . json_encode($before));
    }
    $net = freeNet();
    makeSubnet('stats', $net);
    makeHost('stats-h', "{$net}.10");
    $after = $a->ok('GET', '/stats', null, 200);
    eq($before['counts']['subnets'] + 1, $after['counts']['subnets'], 'one more subnet');
    eq($before['counts']['hosts'] + 1, $after['counts']['hosts'], 'one more host');
    $g = (new Client($GLOBALS['BASE'], 'guest'))->mustLogin(GUEST_USER, GUEST_PASS);
    expectStatus($g->get('/stats'), 200, null, 'guest can read stats');
});

test('hosts: services (DNS domains, DHCP subnets) and action links', function () {
    $a = admin();
    $net = freeNet();
    $s = makeSubnet('svc', $net);
    $h = makeHost('svc-h', "{$net}.10");
    eq(['dnsDomains' => [], 'dhcpSubnets' => [], 'dhcpFailoverGroupIds' => []], $a->get("/hosts/{$h['id']}")->json['services'], 'no services');
    $ds = $a->ok('POST', '/dhcp-servers', ['hostId' => $h['id'], 'subnetId' => $s['id']], 201);
    onCleanup(fn() => deleteIfExists("/dhcp-servers/{$h['id']}/subnets/{$s['id']}"));
    $dom = null;
    foreach ($a->get('/domains')->json['data'] as $d) if ($d['name'] === 'example.com') $dom = $d;
    $as = $a->ok('POST', '/domain-servers', ['domainId' => $dom['id'], 'hostId' => $h['id'], 'role' => 'slave'], 201);
    onCleanup(fn() => deleteIfExists("/domain-servers/{$as['id']}"));
    $svc = $a->get("/hosts/{$h['id']}")->json['services'];
    eq([['id' => $as['id'], 'domain' => ['id' => $dom['id'], 'name' => 'example.com'], 'role' => 'slave']], $svc['dnsDomains'], 'DNS domains');
    eq([$s['id']], array_column($svc['dhcpSubnets'], 'id'), 'DHCP subnets');

    // Action links come from ONA's host_actions config; the bundled examples
    // use %fqdn (Splunk), %ca[cacti_server] and %loc.
    $links = fn() => array_column($a->get("/hosts/{$h['id']}/actions")->json['data'], 'url', 'name');
    $l = $links();
    if (!isset($l['Splunk'])) skip('host_actions config has no Splunk example (customised locally)');
    check(str_ends_with($l['Splunk'], 'q=' . $h['fqdn']), 'fqdn substituted: ' . $l['Splunk']);
    check(!isset($l['Cacti Graph']), 'link with an unset custom attribute is left out');
    check(!isset($l['Wiki Page']), 'link needing a location is left out when the host has none');
    $cat = $a->ok('POST', '/custom-attribute-types', ['name' => 'cacti_server'], 201);
    onCleanup(fn() => deleteIfExists("/custom-attribute-types/{$cat['id']}"));
    $ca = $a->ok('POST', '/custom-attributes', ['typeId' => $cat['id'], 'value' => 'cacti.example.net', 'hostId' => $h['id']], 201);
    onCleanup(fn() => deleteIfExists("/custom-attributes/{$ca['id']}"));
    $l = $links();
    eq("https://cacti.example.net/cacti/graph_view.php?action=tree&name={$h['fqdn']}", $l['Cacti Graph'] ?? null, 'custom attribute substituted');
    expectStatus($a->get('/hosts/999999999/actions'), 404, 'not_found', 'missing host');
});

test('subnet addresses: every address with status, used and free filters, paging', function () {
    $a = admin();
    $net = freeNet();
    $s = makeSubnet('addr', $net);
    makeHost('addr-h', "{$net}.2");
    $pool = $a->ok('POST', '/dhcp-pools', ['start' => "{$net}.5", 'end' => "{$net}.6"], 201);
    onCleanup(fn() => deleteIfExists("/dhcp-pools/{$pool['id']}"));
    $r = $a->ok('GET', "/subnets/{$s['id']}/addresses?perPage=8", null, 200);
    eq(256, $r['meta']['total'], 'total');
    eq(32, $r['meta']['totalPages'], 'pages');
    eq(['reserved', 'free', 'used', 'free', 'free', 'pool', 'pool', 'free'], array_column($r['data'], 'status'), 'statuses');
    eq("{$net}.0", $r['data'][0]['ip'], 'first address');
    eq($pool['id'], $r['data'][5]['poolId'], 'pool id');
    $last = $a->ok('GET', "/subnets/{$s['id']}/addresses?perPage=8&page=32", null, 200);
    eq('reserved', end($last['data'])['status'], 'broadcast');
    $used = $a->ok('GET', "/subnets/{$s['id']}/addresses?status=used", null, 200);
    eq(["{$net}.2"], array_column($used['data'], 'ip'), 'used');
    $free = $a->ok('GET', "/subnets/{$s['id']}/addresses?status=free&perPage=3", null, 200);
    eq(256 - 2 - 1 - 2, $free['meta']['total'], 'free total');
    eq(["{$net}.1", "{$net}.3", "{$net}.4"], array_column($free['data'], 'ip'), 'first free page');
    $free2 = $a->ok('GET', "/subnets/{$s['id']}/addresses?status=free&perPage=3&page=2", null, 200);
    eq(["{$net}.7", "{$net}.8", "{$net}.9"], array_column($free2['data'], 'ip'), 'second free page skips the pool');
    expectStatus($a->get("/subnets/{$s['id']}/addresses?status=bogus"), 400, 'bad_request', 'bad status');
    expectStatus($a->get("/subnets/{$s['id']}/addresses?perPage=5000"), 400, 'bad_request', 'perPage limit');

    // IPv6: totals beyond PHP integers come back as strings.
    $v6 = $a->ok('POST', '/subnets', ['name' => strtoupper(name('addr6')), 'ip' => 'fd00:' . dechex(random_int(1, 65535)) . '::', 'netmask' => '64', 'typeId' => 13], 201);
    onCleanup(fn() => deleteIfExists("/subnets/{$v6['id']}"));
    $r6 = $a->ok('GET', "/subnets/{$v6['id']}/addresses?perPage=2&page=3", null, 200);
    eq('18446744073709551616', $r6['meta']['total'], 'IPv6 /64 total');
    eq('free', $r6['data'][0]['status'], 'IPv6 status');
    check(str_ends_with($r6['data'][0]['ip'], '::4'), 'IPv6 paging: ' . $r6['data'][0]['ip']);
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
