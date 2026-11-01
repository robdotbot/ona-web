<?php
namespace OnaRest;

class Hosts
{
    // A host's display name is its primary DNS record.
    private const BASE_SQL = 'SELECT h.id, h.notes, h.device_id, d.id AS dns_id, d.name, d.domain_id,
            pi.id AS primary_interface_id, pi.ip_addr AS primary_ip,
            (SELECT COUNT(*) FROM interfaces ic WHERE ic.host_id = h.id) AS interface_count
        FROM hosts h
        LEFT JOIN dns d ON d.id = h.primary_dns_id
        LEFT JOIN interfaces pi ON pi.id = d.interface_id';

    public static function list(Request $req): array
    {
        $paging = new Paging($req);
        [$where, $params] = self::filters($req);
        $sql = self::BASE_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $order = Paging::orderBy($req, ['name' => 'd.name', 'ip' => 'pi.ip_addr', 'id' => 'h.id'], 'name');
        return $paging->run($sql, $params, $order . ', h.id', [self::class, 'format']);
    }

    private static function filters(Request $req): array
    {
        $where = [];
        $params = [];
        $q = $req->queryString('q');
        if ($q !== '') {
            if (($ip = Ona::ipNumeric($q)) !== null) {
                $where[] = 'EXISTS (SELECT 1 FROM interfaces i WHERE i.host_id = h.id AND i.ip_addr = ?)';
                $params[] = $ip;
            } elseif (($mac = mac_mangle($q, 1)) !== -1) {
                $where[] = 'EXISTS (SELECT 1 FROM interfaces i WHERE i.host_id = h.id AND i.mac_addr = ?)';
                $params[] = $mac;
            } else {
                // "web" matches web01.example.com; "web01.example" narrows by domain.
                [$name] = explode('.', $q, 2);
                $where[] = '(d.name LIKE ? OR h.notes LIKE ?)';
                $params[] = '%' . Sql::likeEscape($name) . '%';
                $params[] = '%' . Sql::likeEscape($q) . '%';
                if (strpos($q, '.') !== false) {
                    [, $rows, $dom] = ona_find_domain(substr($q, strpos($q, '.') + 1));
                    if ($rows) {
                        $where[] = 'd.domain_id = ?';
                        $params[] = $dom['id'];
                    }
                }
            }
        }
        if (($subnet = $req->queryInt('subnetId')) !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM interfaces i WHERE i.host_id = h.id AND i.subnet_id = ?)';
            $params[] = $subnet;
        }
        if (($domain = $req->queryInt('domainId')) !== null) {
            $where[] = 'd.domain_id = ?';
            $params[] = $domain;
        }
        if (($type = $req->queryInt('deviceTypeId')) !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM devices dv WHERE dv.id = h.device_id AND dv.device_type_id = ?)';
            $params[] = $type;
        }
        if (($loc = $req->queryInt('locationId')) !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM devices dv WHERE dv.id = h.device_id AND dv.location_id = ?)';
            $params[] = $loc;
        }
        // ipFrom / ipTo: hosts with any interface in the range.
        $range = Filters::ipRange($req);
        if ($range) {
            $where[] = 'EXISTS (SELECT 1 FROM interfaces i WHERE i.host_id = h.id AND ' . implode(' AND ', array_map(fn($r) => "i.ip_addr {$r[0]} CAST(? AS DECIMAL(39,0))", $range)) . ')';
            foreach ($range as $r) $params[] = $r[1];
        }
        $tag = $req->queryString('tag');
        if ($tag !== '') {
            $where[] = Tags::filter('host', 'h.id');
            $params[] = $tag;
        }
        // caTypeId [+ caValue]: hosts with that custom attribute (value substring).
        if (($ca = $req->queryInt('caTypeId')) !== null) {
            $cond = "EXISTS (SELECT 1 FROM custom_attributes ca WHERE ca.table_name_ref = 'hosts' AND ca.table_id_ref = h.id AND ca.custom_attribute_type_id = ?";
            $params[] = $ca;
            $value = $req->queryString('caValue');
            if ($value !== '') {
                $cond .= ' AND ca.value LIKE ?';
                $params[] = '%' . Sql::likeEscape($value) . '%';
            }
            $where[] = $cond . ')';
        }
        return [$where, $params];
    }

    public static function load(int $id): array
    {
        $rows = Ona::select(self::BASE_SQL . ' WHERE h.id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('Host');
        return $rows[0];
    }

    public static function get(int $id): array
    {
        $host = self::format(self::load($id), true);
        $ifaces = Ona::select(Interfaces::BASE_SQL . ' WHERE i.host_id = ? ORDER BY i.ip_addr', [$id]);
        $host['interfaces'] = array_map(fn($i) => Interfaces::format($i) + ['sharedWith' => Interfaces::sharedWith((int)$i['id'])], $ifaces);
        // Other hosts' interfaces shared with this one (interface_clusters).
        $shared = Ona::select(Interfaces::BASE_SQL . ', interface_clusters c WHERE c.interface_id = i.id AND c.host_id = ? ORDER BY i.ip_addr', [$id]);
        $host['sharedInterfaces'] = array_map([Interfaces::class, 'format'], $shared);
        $ids = array_map(fn($i) => (int)$i['id'], $ifaces) ?: [0];
        $in = implode(',', $ids);
        // Records on the host's addresses, plus CNAMEs etc. pointing at them.
        $dns = Ona::select(DnsRecords::BASE_SQL . " WHERE r.interface_id IN ({$in})
            OR r.dns_id IN (SELECT x.id FROM dns x WHERE x.interface_id IN ({$in}))
            ORDER BY r.type, r.name");
        $host['dnsRecords'] = array_map([DnsRecords::class, 'format'], $dns);
        return [200, $host];
    }

    public static function create(Request $req): array
    {
        $in = new Input($req->body());
        $opts = [
            'host' => $in->string('name', true),
            'type' => $in->int('deviceTypeId', true, 1),
            'ip' => $in->ip('ip', true),
            'notes' => $in->string('notes'),
            'location' => $in->int('locationId', false, 1),
            'mac' => $in->string('mac', false, 32),
            'name' => $in->string('interfaceName'),
            'description' => $in->string('interfaceDescription'),
            'addptr' => $in->bool('addPtr', false),
        ];
        $in->check();
        Ona::run('host_add', $opts, ['host_add']);

        [, $rows, $iface] = ona_find_interface($opts['ip']);
        if (!$rows) throw new ApiError(500, 'internal_error', 'Host was added but could not be read back');
        return [201, self::get((int)$iface['host_id'])[1]];
    }

    public static function update(Request $req, int $id): array
    {
        $host = self::load($id);
        $in = new Input($req->body());
        $name = $in->string('name');
        $set = [
            'set_type' => $in->int('deviceTypeId', false, 1),
            'set_notes' => $in->string('notes'),
            'set_location' => $in->int('locationId', false, 0),
        ];
        $in->check();
        $set = array_filter($set, fn($v) => $v !== null);
        if ($name === null && !$set) throw ApiError::badRequest('Nothing to update; send at least one of name, deviceTypeId, notes, locationId');
        if (($set['set_location'] ?? null) === 0) $set['set_location'] = '';
        // host_modify prints its usage text unless at least one set_* option is
        // truthy, so clearing notes or location alone needs a no-op companion.
        if ($set && !array_filter($set)) $set['set_notes'] = ($set['set_notes'] ?? $host['notes']) ?: ' ';

        if ($set) Ona::run('host_modify', ['host' => $id] + $set, ['host_modify']);
        if (($set['set_location'] ?? null) === '' && $host['device_id']) {
            // host_modify ignores an empty set_location (it can't clear a
            // location, in the legacy UI either), so clear it on the device.
            Ona::write(['host_modify'], "Host location CLEARED: {$id}", function ($db) use ($host) {
                Ona::dbOk(db_update_record($db, 'devices', ['id' => (int)$host['device_id']], ['location_id' => 0]));
            });
        }
        if ($name !== null && $name !== '' && $name !== self::fqdn($host)) {
            // Renaming a host renames its primary DNS record.
            Ona::run('dns_record_modify', ['name' => $host['dns_id'], 'set_name' => $name], ['host_modify']);
        }
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        self::load($id);
        Ona::run('host_del', ['host' => $id, 'commit' => 'Y'], ['host_del']);
        return [204, null];
    }

    private static function fqdn(array $h): string
    {
        return ($h['name'] ? $h['name'] . '.' : '') . Ona::domainName($h['domain_id']);
    }

    public static function format(array $h, bool $detail = false): array
    {
        $out = [
            'id' => (int)$h['id'],
            'name' => $h['name'],
            'fqdn' => self::fqdn($h),
            'domain' => ['id' => (int)$h['domain_id'], 'name' => Ona::domainName($h['domain_id'])],
            'primaryIp' => $h['primary_ip'] !== null ? Ona::ipText($h['primary_ip']) : null,
            'primaryInterfaceId' => $h['primary_interface_id'] !== null ? (int)$h['primary_interface_id'] : null,
            'interfaceCount' => (int)$h['interface_count'],
            'notes' => trim((string)$h['notes']),
            'deviceType' => null,
            'location' => null,
            'tags' => Tags::namesFor('host', (int)$h['id']),
        ];
        if ($h['device_id']) {
            $device = Ona::byId('devices', (int)$h['device_id']);
            if ($device) {
                $out['deviceType'] = Lookups::deviceType((int)$device['device_type_id']);
                if ($device['location_id']) {
                    $loc = Ona::byId('locations', (int)$device['location_id']);
                    if ($loc) $out['location'] = Locations::format($loc);
                }
            }
        }
        if ($detail) $out['primaryDnsId'] = (int)$h['dns_id'];
        return $out;
    }
}
