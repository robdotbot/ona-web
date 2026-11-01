<?php
namespace OnaRest;

/** Read-only reference data the forms need (small tables, not paginated). */
class Lookups
{
    private static array $deviceTypes = [];

    /** "Manufacturer Model (role)", as the legacy UI shows device types. */
    public static function deviceType(int $id): array
    {
        if (isset(self::$deviceTypes[$id])) return self::$deviceTypes[$id];
        $rows = Ona::select('SELECT dt.id, m.name AS model, mf.name AS manufacturer, r.name AS role
            FROM device_types dt
            LEFT JOIN models m ON m.id = dt.model_id
            LEFT JOIN manufacturers mf ON mf.id = m.manufacturer_id
            LEFT JOIN roles r ON r.id = dt.role_id
            WHERE dt.id = ?', [$id]);
        $r = $rows[0] ?? ['model' => '', 'manufacturer' => '', 'role' => ''];
        $name = trim("{$r['manufacturer']} {$r['model']}") . ($r['role'] ? " ({$r['role']})" : '');
        return self::$deviceTypes[$id] = [
            'id' => $id, 'name' => $name, 'manufacturer' => $r['manufacturer'], 'model' => $r['model'], 'role' => $r['role'],
        ];
    }

}

/** GET /search?q= — the API version of the legacy quick search. */
class Search
{
    private const LIMIT = 10;

    public static function run(Request $req): array
    {
        $q = $req->queryString('q');
        if ($q === '') throw ApiError::validation('Query is required', ['q' => 'is required']);
        if (strlen($q) > 255) throw ApiError::validation('Query is too long', ['q' => 'must be at most 255 characters']);

        $result = ['query' => $q, 'bestMatch' => null, 'hosts' => [], 'subnets' => [], 'interfaces' => [], 'dnsRecords' => []];
        $like = '%' . Sql::likeEscape($q) . '%';
        [$namePart] = explode('.', $q, 2);
        $nameLike = '%' . Sql::likeEscape($namePart) . '%';
        $limit = self::LIMIT;

        if (($ip = Ona::ipNumeric($q)) !== null) {
            $ifaces = Ona::select(Interfaces::BASE_SQL . ' WHERE i.ip_addr = ?', [$ip], $limit);
            $result['interfaces'] = array_map([Interfaces::class, 'format'], $ifaces);
            $hostIds = array_unique(array_filter(array_map(fn($i) => (int)$i['host_id'], $ifaces)));
            foreach ($hostIds as $id) $result['hosts'][] = Hosts::format(Hosts::load($id));
            [, $rows, $subnet] = ona_find_subnet($q);
            if ($rows) $result['subnets'][] = Subnets::format(Subnets::load((int)$subnet['id']));
            $result['dnsRecords'] = array_map([DnsRecords::class, 'format'],
                Ona::select(DnsRecords::BASE_SQL . ' WHERE i.ip_addr = ? ORDER BY r.type', [$ip], $limit));
        } elseif (($mac = mac_mangle($q, 1)) !== -1 && preg_match('/[:.\-]|^[0-9a-f]{12}$/i', $q)) {
            $ifaces = Ona::select(Interfaces::BASE_SQL . ' WHERE i.mac_addr = ?', [$mac], $limit);
            $result['interfaces'] = array_map([Interfaces::class, 'format'], $ifaces);
            foreach (array_unique(array_map(fn($i) => (int)$i['host_id'], $ifaces)) as $id) {
                $result['hosts'][] = Hosts::format(Hosts::load($id));
            }
        } else {
            $hosts = Ona::select('SELECT h.id FROM hosts h JOIN dns d ON d.id = h.primary_dns_id
                WHERE d.name LIKE ? OR h.notes LIKE ? ORDER BY d.name', [$nameLike, $like], $limit);
            foreach ($hosts as $h) $result['hosts'][] = Hosts::format(Hosts::load((int)$h['id']));
            $subnets = Ona::select('SELECT s.id FROM subnets s WHERE s.name LIKE ? ORDER BY s.name', ['%' . Sql::likeEscape(strtoupper($q)) . '%'], $limit);
            foreach ($subnets as $s) $result['subnets'][] = Subnets::format(Subnets::load((int)$s['id']));
            $result['dnsRecords'] = array_map([DnsRecords::class, 'format'],
                Ona::select(DnsRecords::BASE_SQL . ' WHERE r.name LIKE ? ORDER BY r.name, r.type', [$nameLike], $limit));
        }

        // Mirror the legacy quick search: an exact address or name match wins.
        if (count($result['hosts']) === 1 && ($ip !== null || strcasecmp($result['hosts'][0]['fqdn'], $q) === 0 || strcasecmp((string)$result['hosts'][0]['name'], $q) === 0)) {
            $result['bestMatch'] = ['type' => 'host', 'id' => $result['hosts'][0]['id']];
        } elseif (count($result['subnets']) === 1 && ($ip !== null || strcasecmp($result['subnets'][0]['name'], $q) === 0)) {
            $result['bestMatch'] = ['type' => 'subnet', 'id' => $result['subnets'][0]['id']];
        }
        return [200, $result];
    }
}
