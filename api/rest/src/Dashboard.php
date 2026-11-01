<?php
namespace OnaRest;

/** GET /stats: record counts for the home page (the classic desktop's counts box). */
class Dashboard
{
    private const TABLES = [
        'subnets' => 'subnets',
        'hosts' => 'hosts',
        'interfaces' => 'interfaces',
        'dnsRecords' => 'dns',
        'domains' => 'domains',
        'vlans' => 'vlans',
        'vlanCampuses' => 'vlan_campuses',
        'blocks' => 'blocks',
        'locations' => 'locations',
        'dhcpPools' => 'dhcp_pools',
        'customAttributes' => 'custom_attributes',
    ];

    public static function stats(): array
    {
        $counts = [];
        foreach (self::TABLES as $key => $table) {
            $counts[$key] = (int)(Ona::select("SELECT COUNT(*) AS n FROM {$table}")[0]['n'] ?? 0);
        }
        $counts['dhcpServers'] = (int)(Ona::select('SELECT COUNT(DISTINCT host_id) AS n FROM dhcp_server_subnets')[0]['n'] ?? 0);
        $counts['dnsServers'] = (int)(Ona::select('SELECT COUNT(DISTINCT host_id) AS n FROM dns_server_domains')[0]['n'] ?? 0);
        $counts['activeMessages'] = (int)(Ona::select('SELECT COUNT(*) AS n FROM messages WHERE expiration IS NULL OR expiration > ?', [date('Y-m-d H:i:s')])[0]['n'] ?? 0);
        return [200, ['counts' => $counts, 'version' => $GLOBALS['conf']['version'] ?? null]];
    }
}

/**
 * GET /hosts/{id}/actions: links to outside tools for a host (the classic
 * "Host actions" box). Configured like the classic UI: the defaults in
 * www/workspace_plugins/builtin/host_actions/config.inc.php, overridden by
 * www/local/config/host_actions.config.php. URLs can use %fqdn, %ip, %loc,
 * %hostname, %onahostname, %domain, %onadomain and %ca[attribute]; a
 * missing attribute falls back to the sys_config value default_<attribute>.
 */
class HostActions
{
    /** Schemes that run code in the browser are never returned. */
    private const BLOCKED_SCHEMES = ['javascript', 'data', 'vbscript', 'file'];

    private static function config(): array
    {
        $base = $GLOBALS['base'];
        $files = [
            "{$base}/workspace_plugins/builtin/host_actions/config.inc.php",
            "{$base}/local/config/host_actions.config.php",
        ];
        // The files are admin-written PHP that set $conf[$modulename][...];
        // the paths are fixed, nothing from the request picks them.
        return (static function (array $files): array {
            $modulename = 'host_actions';
            $conf = $GLOBALS['conf'];
            $conf[$modulename] = [];
            foreach ($files as $f) if (is_file($f)) include $f;
            return is_array($conf[$modulename] ?? null) ? $conf[$modulename] : [];
        })($files);
    }

    public static function list(int $id): array
    {
        $h = Hosts::load($id);
        $fqdn = ($h['name'] ? $h['name'] . '.' : '') . Ona::domainName($h['domain_id']);
        [$short, $rest] = array_pad(explode('.', $fqdn, 2), 2, '');
        $location = '';
        if ($h['device_id']) {
            $loc = Ona::select('SELECT l.reference FROM devices d JOIN locations l ON l.id = d.location_id WHERE d.id = ?', [$h['device_id']]);
            $location = (string)($loc[0]['reference'] ?? '');
        }
        $vars = [
            '%fqdn' => $fqdn,
            '%ip' => $h['primary_ip'] !== null ? Ona::ipText($h['primary_ip']) : '',
            '%loc' => $location,
            '%onahostname' => (string)$h['name'],
            '%hostname' => $short,
            '%onadomain' => Ona::domainName($h['domain_id']),
            '%domain' => $rest,
        ];
        $out = [];
        foreach (self::config() as $name => $action) {
            if (!is_array($action) || !is_string($action['url'] ?? null) || $action['url'] === '') continue;
            // strtr() tries the longest names first, so %onadomain is not
            // taken for %domain.
            // Leave out a link when a placeholder it uses has no value for this
            // host (e.g. no location): it would point nowhere useful.
            $missing = false;
            $url = preg_replace_callback('/%ca\[(.*?)\]/', function ($m) use ($id, &$missing) {
                $v = self::attribute($id, $m[1]);
                if ($v === '') $missing = true;
                return $v;
            }, $action['url']);
            foreach ($vars as $k => $v) if ($v === '' && str_contains($url, $k)) $missing = $missing || !self::onlyInLongerName($url, $k, $vars);
            if ($missing) continue;
            $url = strtr($url, $vars);
            $scheme = strtolower((string)strtok(preg_replace('/[\x00-\x20]+/', '', $url), ':'));
            if ($url === '' || in_array($scheme, self::BLOCKED_SCHEMES, true)) continue;
            $out[] = ['name' => (string)$name, 'title' => (string)($action['title'] ?? $name), 'url' => $url];
        }
        return [200, ['data' => $out]];
    }

    /** True if every occurrence of $k in $url is part of a longer placeholder (%domain inside %onadomain). */
    private static function onlyInLongerName(string $url, string $k, array $vars): bool
    {
        $stripped = $url;
        foreach (array_keys($vars) as $other) if ($other !== $k && strlen($other) > strlen($k) && str_contains($other, substr($k, 1))) $stripped = str_replace($other, '', $stripped);
        return !str_contains($stripped, $k);
    }

    private static function attribute(int $hostId, string $name): string
    {
        $rows = Ona::select("SELECT a.value FROM custom_attributes a JOIN custom_attribute_types t ON t.id = a.custom_attribute_type_id
            WHERE t.name = ? AND a.table_name_ref = 'hosts' AND a.table_id_ref = ?", [$name, $hostId], 1);
        if ($rows) return (string)$rows[0]['value'];
        $rows = Ona::select('SELECT value FROM sys_config WHERE name = ?', ["default_{$name}"], 1);
        return (string)($rows[0]['value'] ?? '');
    }
}
