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
$r->add('POST', '/auth/password', fn(Request $req) => Account::changePassword($req));

// --- Search & reference data -----------------------------------------------
$r->add('GET', '/search', fn(Request $req) => Search::run($req));
$r->add('GET', '/stats', fn() => Dashboard::stats());

// --- Subnets ---------------------------------------------------------------
$r->add('GET', '/subnets', fn(Request $req) => Subnets::list($req));
$r->add('POST', '/subnets', fn(Request $req) => Subnets::create($req));
$r->add('GET', '/subnets/{id}', fn(Request $req, array $p) => Subnets::get($p['id']));
$r->add('PATCH', '/subnets/{id}', fn(Request $req, array $p) => Subnets::update($req, $p['id']));
$r->add('DELETE', '/subnets/{id}', fn(Request $req, array $p) => Subnets::delete($p['id']));
$r->add('GET', '/subnets/{id}/next-ip', fn(Request $req, array $p) => Subnets::nextIp($p['id']));
$r->add('GET', '/subnets/{id}/map', fn(Request $req, array $p) => Subnets::map($p['id']));
$r->add('GET', '/subnets/{id}/addresses', fn(Request $req, array $p) => Subnets::addresses($req, $p['id']));

// --- Hosts -----------------------------------------------------------------
$r->add('GET', '/hosts', fn(Request $req) => Hosts::list($req));
$r->add('POST', '/hosts', fn(Request $req) => Hosts::create($req));
$r->add('GET', '/hosts/{id}', fn(Request $req, array $p) => Hosts::get($p['id']));
$r->add('PATCH', '/hosts/{id}', fn(Request $req, array $p) => Hosts::update($req, $p['id']));
$r->add('DELETE', '/hosts/{id}', fn(Request $req, array $p) => Hosts::delete($p['id']));
$r->add('GET', '/hosts/{id}/actions', fn(Request $req, array $p) => HostActions::list($p['id']));
$r->add('DELETE', '/hosts/{id}/configs', fn(Request $req, array $p) => Configs::deleteAll($p['id']));

// --- Interfaces ------------------------------------------------------------
$r->add('GET', '/interfaces', fn(Request $req) => Interfaces::list($req));
$r->add('POST', '/interfaces', fn(Request $req) => Interfaces::create($req));
$r->add('GET', '/interfaces/{id}', fn(Request $req, array $p) => Interfaces::get($p['id']));
$r->add('PATCH', '/interfaces/{id}', fn(Request $req, array $p) => Interfaces::update($req, $p['id']));
$r->add('DELETE', '/interfaces/{id}', fn(Request $req, array $p) => Interfaces::delete($p['id']));
$r->add('POST', '/interfaces/move', fn(Request $req) => Interfaces::bulkMove($req));
$r->add('POST', '/interfaces/{id}/move', fn(Request $req, array $p) => Interfaces::moveToHost($req, $p['id']));
$r->add('POST', '/interfaces/{id}/shares', fn(Request $req, array $p) => Interfaces::share($req, $p['id']));
$r->add('DELETE', '/interfaces/{id}/shares/{hostId}', fn(Request $req, array $p) => Interfaces::unshare($p['id'], $p['hostId']));
$r->add('POST', '/interfaces/{id}/nat', fn(Request $req, array $p) => Interfaces::addNat($req, $p['id']));
$r->add('DELETE', '/interfaces/{id}/nat', fn(Request $req, array $p) => Interfaces::deleteNat($p['id']));

// --- DNS records -----------------------------------------------------------
$r->add('GET', '/dns-records', fn(Request $req) => DnsRecords::list($req));
$r->add('POST', '/dns-records', fn(Request $req) => DnsRecords::create($req));
$r->add('GET', '/dns-records/{id}', fn(Request $req, array $p) => DnsRecords::get($p['id']));
$r->add('PATCH', '/dns-records/{id}', fn(Request $req, array $p) => DnsRecords::update($req, $p['id']));
$r->add('DELETE', '/dns-records/{id}', fn(Request $req, array $p) => DnsRecords::delete($p['id']));

// --- VLANs ------------------------------------------------------------------
$r->add('GET', '/vlan-campuses', fn(Request $req) => VlanCampuses::list($req));
$r->add('POST', '/vlan-campuses', fn(Request $req) => VlanCampuses::create($req));
$r->add('GET', '/vlan-campuses/{id}', fn(Request $req, array $p) => VlanCampuses::get($p['id']));
$r->add('PATCH', '/vlan-campuses/{id}', fn(Request $req, array $p) => VlanCampuses::update($req, $p['id']));
$r->add('DELETE', '/vlan-campuses/{id}', fn(Request $req, array $p) => VlanCampuses::delete($p['id']));
$r->add('GET', '/vlans', fn(Request $req) => Vlans::list($req));
$r->add('POST', '/vlans', fn(Request $req) => Vlans::create($req));
$r->add('GET', '/vlans/{id}', fn(Request $req, array $p) => Vlans::get($p['id']));
$r->add('PATCH', '/vlans/{id}', fn(Request $req, array $p) => Vlans::update($req, $p['id']));
$r->add('DELETE', '/vlans/{id}', fn(Request $req, array $p) => Vlans::delete($p['id']));

// --- Blocks ----------------------------------------------------------------
$r->add('GET', '/blocks', fn(Request $req) => Blocks::list($req));
$r->add('POST', '/blocks', fn(Request $req) => Blocks::create($req));
$r->add('GET', '/blocks/{id}', fn(Request $req, array $p) => Blocks::get($p['id']));
$r->add('PATCH', '/blocks/{id}', fn(Request $req, array $p) => Blocks::update($req, $p['id']));
$r->add('DELETE', '/blocks/{id}', fn(Request $req, array $p) => Blocks::delete($p['id']));

// --- DHCP ------------------------------------------------------------------
$r->add('GET', '/dhcp-servers', fn(Request $req) => DhcpServers::list($req));
$r->add('POST', '/dhcp-servers', fn(Request $req) => DhcpServers::addSubnet($req));
$r->add('GET', '/dhcp-servers/{id}', fn(Request $req, array $p) => DhcpServers::get($p['id']));
$r->add('DELETE', '/dhcp-servers/{id}/subnets/{subnetId}', fn(Request $req, array $p) => DhcpServers::removeSubnet($p['id'], $p['subnetId']));
$r->add('GET', '/dhcp-failover-groups', fn(Request $req) => DhcpFailoverGroups::list($req));
$r->add('POST', '/dhcp-failover-groups', fn(Request $req) => DhcpFailoverGroups::create($req));
$r->add('GET', '/dhcp-failover-groups/{id}', fn(Request $req, array $p) => DhcpFailoverGroups::get($p['id']));
$r->add('PATCH', '/dhcp-failover-groups/{id}', fn(Request $req, array $p) => DhcpFailoverGroups::update($req, $p['id']));
$r->add('DELETE', '/dhcp-failover-groups/{id}', fn(Request $req, array $p) => DhcpFailoverGroups::delete($p['id']));
$r->add('GET', '/dhcp-pools', fn(Request $req) => DhcpPools::list($req));
$r->add('POST', '/dhcp-pools', fn(Request $req) => DhcpPools::create($req));
$r->add('GET', '/dhcp-pools/{id}', fn(Request $req, array $p) => DhcpPools::get($p['id']));
$r->add('PATCH', '/dhcp-pools/{id}', fn(Request $req, array $p) => DhcpPools::update($req, $p['id']));
$r->add('DELETE', '/dhcp-pools/{id}', fn(Request $req, array $p) => DhcpPools::delete($p['id']));
$r->add('GET', '/dhcp-entries', fn(Request $req) => DhcpEntries::list($req));
$r->add('POST', '/dhcp-entries', fn(Request $req) => DhcpEntries::create($req));
$r->add('GET', '/dhcp-entries/{id}', fn(Request $req, array $p) => DhcpEntries::get($p['id']));
$r->add('PATCH', '/dhcp-entries/{id}', fn(Request $req, array $p) => DhcpEntries::update($req, $p['id']));
$r->add('DELETE', '/dhcp-entries/{id}', fn(Request $req, array $p) => DhcpEntries::delete($p['id']));

// --- Custom attributes -----------------------------------------------------
$r->add('GET', '/custom-attribute-types', fn() => CustomAttributeTypes::list());
$r->add('POST', '/custom-attribute-types', fn(Request $req) => CustomAttributeTypes::create($req));
$r->add('GET', '/custom-attribute-types/{id}', fn(Request $req, array $p) => CustomAttributeTypes::get($p['id']));
$r->add('PATCH', '/custom-attribute-types/{id}', fn(Request $req, array $p) => CustomAttributeTypes::update($req, $p['id']));
$r->add('DELETE', '/custom-attribute-types/{id}', fn(Request $req, array $p) => CustomAttributeTypes::delete($p['id']));
$r->add('GET', '/custom-attributes', fn(Request $req) => CustomAttributes::list($req));
$r->add('POST', '/custom-attributes', fn(Request $req) => CustomAttributes::create($req));
$r->add('GET', '/custom-attributes/{id}', fn(Request $req, array $p) => CustomAttributes::get($p['id']));
$r->add('PATCH', '/custom-attributes/{id}', fn(Request $req, array $p) => CustomAttributes::update($req, $p['id']));
$r->add('DELETE', '/custom-attributes/{id}', fn(Request $req, array $p) => CustomAttributes::delete($p['id']));

// --- Locations & domains ---------------------------------------------------
$r->add('GET', '/locations', fn() => Locations::list());
$r->add('POST', '/locations', fn(Request $req) => Locations::create($req));
$r->add('GET', '/locations/{id}', fn(Request $req, array $p) => Locations::get($p['id']));
$r->add('PATCH', '/locations/{id}', fn(Request $req, array $p) => Locations::update($req, $p['id']));
$r->add('DELETE', '/locations/{id}', fn(Request $req, array $p) => Locations::delete($p['id']));
$r->add('GET', '/domains', fn() => Domains::list());
$r->add('POST', '/domains', fn(Request $req) => Domains::create($req));
$r->add('GET', '/domains/{id}', fn(Request $req, array $p) => Domains::get($p['id']));
$r->add('PATCH', '/domains/{id}', fn(Request $req, array $p) => Domains::update($req, $p['id']));
$r->add('DELETE', '/domains/{id}', fn(Request $req, array $p) => Domains::delete($p['id']));
$r->add('GET', '/domain-servers', fn(Request $req) => DomainServers::list($req));
$r->add('POST', '/domain-servers', fn(Request $req) => DomainServers::create($req));
$r->add('GET', '/domain-servers/{id}', fn(Request $req, array $p) => DomainServers::get($p['id']));
$r->add('DELETE', '/domain-servers/{id}', fn(Request $req, array $p) => DomainServers::delete($p['id']));

// --- Tags & messages -------------------------------------------------------
$r->add('GET', '/tags', fn(Request $req) => Tags::list($req));
$r->add('GET', '/tags/names', fn() => Tags::names());
$r->add('POST', '/tags', fn(Request $req) => Tags::create($req));
$r->add('DELETE', '/tags/{id}', fn(Request $req, array $p) => Tags::delete($p['id']));
$r->add('GET', '/messages', fn(Request $req) => Messages::list($req));
$r->add('POST', '/messages', fn(Request $req) => Messages::create($req));
$r->add('GET', '/messages/{id}', fn(Request $req, array $p) => Messages::get($p['id']));
$r->add('PATCH', '/messages/{id}', fn(Request $req, array $p) => Messages::update($req, $p['id']));
$r->add('DELETE', '/messages/{id}', fn(Request $req, array $p) => Messages::delete($p['id']));

// --- Config archives & reports ---------------------------------------------
$r->add('GET', '/configs', fn(Request $req) => Configs::list($req));
$r->add('POST', '/configs', fn(Request $req) => Configs::create($req));
$r->add('GET', '/configs/diff', fn(Request $req) => Configs::diff($req));
$r->add('GET', '/configs/{id}', fn(Request $req, array $p) => Configs::get($p['id']));
$r->add('DELETE', '/configs/{id}', fn(Request $req, array $p) => Configs::delete($p['id']));
$r->add('GET', '/reports', fn() => Reports::list());
$r->add('GET', '/reports/{key}', fn(Request $req, array $p) => Reports::get($p['key']));
$r->add('POST', '/reports/{key}/run', fn(Request $req, array $p) => Reports::run($req, $p['key']));

// --- Reference data (lookup tables; writes need `advanced`) ---------------
foreach (array_keys(Reference::RESOURCES) as $res) {
    $r->add('GET', "/{$res}", fn() => Reference::list($res));
    $r->add('POST', "/{$res}", fn(Request $req) => Reference::create($res, $req));
    $r->add('GET', "/{$res}/{id}", fn(Request $req, array $p) => Reference::get($res, $p['id']));
    $r->add('PATCH', "/{$res}/{id}", fn(Request $req, array $p) => Reference::update($res, $req, $p['id']));
    $r->add('DELETE', "/{$res}/{id}", fn(Request $req, array $p) => Reference::delete($res, $p['id']));
}

// --- Admin -----------------------------------------------------------------
// Reads here need the admin permission too (user_admin / advanced), as in ONA.
$r->add('GET', '/users', fn() => Users::list());
$r->add('POST', '/users', fn(Request $req) => Users::create($req));
$r->add('GET', '/users/{id}', fn(Request $req, array $p) => Users::get($p['id']));
$r->add('PATCH', '/users/{id}', fn(Request $req, array $p) => Users::update($req, $p['id']));
$r->add('DELETE', '/users/{id}', fn(Request $req, array $p) => Users::delete($p['id']));
$r->add('GET', '/groups', fn() => Groups::list());
$r->add('POST', '/groups', fn(Request $req) => Groups::create($req));
$r->add('GET', '/groups/{id}', fn(Request $req, array $p) => Groups::get($p['id']));
$r->add('PATCH', '/groups/{id}', fn(Request $req, array $p) => Groups::update($req, $p['id']));
$r->add('DELETE', '/groups/{id}', fn(Request $req, array $p) => Groups::delete($p['id']));
$r->add('GET', '/permissions', fn() => Permissions::list());
$r->add('GET', '/sys-config', fn() => SysConfig::list());
$r->add('POST', '/sys-config', fn(Request $req) => SysConfig::create($req));
$r->add('GET', '/sys-config/{key}', fn(Request $req, array $p) => SysConfig::get($p['key']));
$r->add('PATCH', '/sys-config/{key}', fn(Request $req, array $p) => SysConfig::update($req, $p['key']));
$r->add('DELETE', '/sys-config/{key}', fn(Request $req, array $p) => SysConfig::delete($p['key']));
$r->add('GET', '/change-log', fn(Request $req) => ChangeLog::list($req));
$r->add('GET', '/plugins', fn() => Plugins::list());
$r->add('PATCH', '/plugins/{key}', fn(Request $req, array $p) => Plugins::update($req, $p['key']));
