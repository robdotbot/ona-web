<?php
// The complete, static list of API endpoints. Paths are relative to /api/v1.
// Every route requires an API login unless it is marked public, and every
// non-GET route also requires the session's X-CSRF-Token.
namespace OnaRest;

/** @var Router $r */

// --- Meta & auth -----------------------------------------------------------
$r->add('GET', '/health', fn() => [200, ['status' => 'ok', 'version' => $GLOBALS['conf']['version'] ?? null]], true);
$r->add('GET', '/openapi.yaml', function () {
    Response::raw(200, 'application/yaml; charset=utf-8', file_get_contents(__DIR__ . '/openapi.yaml'));
}, true);
$r->add('POST', '/auth/login', function (Request $req) {
    $in = new Input($req->body());
    $user = $in->string('username', true, 64);
    $pass = $req->body()['password'] ?? null;
    if (!is_string($pass) || $pass === '') $in->error('password', 'is required');
    $in->check();
    return [200, Auth::login($user, $pass)];
}, true);
$r->add('POST', '/auth/logout', function (Request $req) {
    if (Auth::user() !== null) Auth::checkCsrf($req);
    Auth::logout();
    return [204, null];
}, true);
$r->add('GET', '/auth/session', fn() => [200, Auth::session()]);

// --- Search & reference data -----------------------------------------------
$r->add('GET', '/search', fn(Request $req) => Search::run($req));
$r->add('GET', '/subnet-types', fn() => Lookups::subnetTypes());
$r->add('GET', '/device-types', fn() => Lookups::deviceTypes());
$r->add('GET', '/domains', fn() => Lookups::domains());
$r->add('GET', '/locations', fn() => Lookups::locations());

// --- Subnets ---------------------------------------------------------------
$r->add('GET', '/subnets', fn(Request $req) => Subnets::list($req));
$r->add('POST', '/subnets', fn(Request $req) => Subnets::create($req));
$r->add('GET', '/subnets/{id}', fn(Request $req, array $p) => Subnets::get($p['id']));
$r->add('PATCH', '/subnets/{id}', fn(Request $req, array $p) => Subnets::update($req, $p['id']));
$r->add('DELETE', '/subnets/{id}', fn(Request $req, array $p) => Subnets::delete($p['id']));
$r->add('GET', '/subnets/{id}/next-ip', fn(Request $req, array $p) => Subnets::nextIp($p['id']));

// --- Hosts -----------------------------------------------------------------
$r->add('GET', '/hosts', fn(Request $req) => Hosts::list($req));
$r->add('POST', '/hosts', fn(Request $req) => Hosts::create($req));
$r->add('GET', '/hosts/{id}', fn(Request $req, array $p) => Hosts::get($p['id']));
$r->add('PATCH', '/hosts/{id}', fn(Request $req, array $p) => Hosts::update($req, $p['id']));
$r->add('DELETE', '/hosts/{id}', fn(Request $req, array $p) => Hosts::delete($p['id']));

// --- Interfaces ------------------------------------------------------------
$r->add('GET', '/interfaces', fn(Request $req) => Interfaces::list($req));
$r->add('POST', '/interfaces', fn(Request $req) => Interfaces::create($req));
$r->add('GET', '/interfaces/{id}', fn(Request $req, array $p) => Interfaces::get($p['id']));
$r->add('PATCH', '/interfaces/{id}', fn(Request $req, array $p) => Interfaces::update($req, $p['id']));
$r->add('DELETE', '/interfaces/{id}', fn(Request $req, array $p) => Interfaces::delete($p['id']));

// --- DNS records -----------------------------------------------------------
$r->add('GET', '/dns-records', fn(Request $req) => DnsRecords::list($req));
$r->add('POST', '/dns-records', fn(Request $req) => DnsRecords::create($req));
$r->add('GET', '/dns-records/{id}', fn(Request $req, array $p) => DnsRecords::get($p['id']));
$r->add('PATCH', '/dns-records/{id}', fn(Request $req, array $p) => DnsRecords::update($req, $p['id']));
$r->add('DELETE', '/dns-records/{id}', fn(Request $req, array $p) => DnsRecords::delete($p['id']));

// --- Not yet implemented ---------------------------------------------------
// Planned resources, following the same pattern (a class in src/ whose writes
// go through Ona::run() with the module's permission). Until then they answer
// 501 so clients can tell "not built yet" from "no such thing".
foreach (['/vlans', '/vlan-campuses', '/blocks', '/dhcp-pools', '/dhcp-servers', '/dhcp-options',
          '/custom-attributes', '/locations/{id}', '/domains/{id}', '/users', '/groups', '/sys-config'] as $planned) {
    $r->add('GET', $planned, function () {
        throw new ApiError(501, 'not_implemented', 'This resource is planned but not implemented yet');
    });
}
