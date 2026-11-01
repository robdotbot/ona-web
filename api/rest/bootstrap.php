<?php
// ONA REST API bootstrap. Required at global scope by www/api/v1/index.php.

define('ONA_REST', true);

require __DIR__ . '/src/Http.php';
require __DIR__ . '/src/Router.php';
require __DIR__ . '/src/Auth.php';
require __DIR__ . '/src/Ona.php';
require __DIR__ . '/src/Subnets.php';
require __DIR__ . '/src/Hosts.php';
require __DIR__ . '/src/Interfaces.php';
require __DIR__ . '/src/DnsRecords.php';
require __DIR__ . '/src/Lookups.php';
require __DIR__ . '/src/Vlans.php';
require __DIR__ . '/src/Blocks.php';
require __DIR__ . '/src/Dhcp.php';
require __DIR__ . '/src/CustomAttributes.php';
require __DIR__ . '/src/Locations.php';
require __DIR__ . '/src/Domains.php';
require __DIR__ . '/src/Admin.php';
require __DIR__ . '/src/Account.php';
require __DIR__ . '/src/Reference.php';
require __DIR__ . '/src/Tags.php';
require __DIR__ . '/src/Configs.php';

use OnaRest\ApiError;
use OnaRest\Request;
use OnaRest\Response;
use OnaRest\Router;

// ONA prints debug output and PHP 8 warnings in places; buffer everything and
// discard it so only our JSON reaches the client.
ob_start();
Response::installErrorHandlers();

// Upstream ONA's startSession() logs anyone sending dcm.pl's User-Agent in as
// the unix_username in ?options= (fixed in this repo's copy, see
// ../UPSTREAM.md), and skips the session for that User-Agent. The API never
// serves dcm.pl, so strip both before ONA sees them, whichever ONA is in use.
if (stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'console-module-interface') !== false) {
    $_SERVER['HTTP_USER_AGENT'] = 'ona-rest-client';
}
unset($_GET['options'], $_POST['options'], $_REQUEST['options']);

$base = dirname(__DIR__) . '/www';
$include = $base . '/include';
if (!file_exists("{$base}/local/config/database_settings.inc.php") || file_exists("{$base}/local/config/run_install")) {
    // config.inc.php would render the HTML installer here.
    Response::error(new ApiError(503, 'not_installed', 'ONA is not installed yet'));
}

// Sets $conf, $self, $onadb, loads sys_config and starts the ONA session.
require_once $base . '/config/config.inc.php';
if (!isset($onadb) || !$onadb || !$onadb->IsConnected()) {
    Response::error(new ApiError(503, 'database_unavailable', 'Database unavailable'));
}

ob_clean();
$r = new Router();
require __DIR__ . '/routes.php';
try {
    $r->dispatch(new Request());
} catch (ApiError $e) {
    Response::error($e);
}
