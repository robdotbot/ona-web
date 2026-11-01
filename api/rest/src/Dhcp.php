<?php
namespace OnaRest;

/**
 * DHCP servers: hosts that serve one or more subnets (dhcp_server_subnets,
 * via the dhcp_server_add / dhcp_server_del modules, `advanced` permission).
 * A server is identified by its host ID.
 */
class DhcpServers
{
    /** Hosts serving at least one subnet or belonging to a failover group. */
    private const SERVER_IDS = 'SELECT host_id AS id FROM dhcp_server_subnets
        UNION SELECT primary_server_id FROM dhcp_failover_groups
        UNION SELECT secondary_server_id FROM dhcp_failover_groups';

    public static function list(Request $req): array
    {
        $rows = Ona::select('SELECT s.id,
                (SELECT COUNT(*) FROM dhcp_server_subnets x WHERE x.host_id = s.id) AS subnet_count
            FROM (' . self::SERVER_IDS . ') s WHERE s.id > 0');
        $servers = [];
        foreach ($rows as $r) {
            $hosts = Ona::select('SELECT id FROM hosts WHERE id = ?', [$r['id']]);
            if (!$hosts) continue;
            $servers[] = self::summary((int)$r['id']) + ['subnetCount' => (int)$r['subnet_count']];
        }
        usort($servers, fn($a, $b) => strcmp($a['host']['fqdn'], $b['host']['fqdn']));
        return [200, ['data' => $servers]];
    }

    private static function summary(int $hostId): array
    {
        $h = Hosts::format(Hosts::load($hostId));
        return ['id' => $hostId, 'host' => ['id' => $h['id'], 'fqdn' => $h['fqdn'], 'primaryIp' => $h['primaryIp']]];
    }

    /** One server: its subnets, failover groups and server-level options. */
    public static function get(int $hostId): array
    {
        Hosts::load($hostId);
        $isServer = Ona::count('SELECT id FROM (' . self::SERVER_IDS . ') s WHERE s.id = ?', [$hostId]) > 0;
        if (!$isServer) throw ApiError::notFound('DHCP server');
        $out = self::summary($hostId);
        $subnets = Ona::select('SELECT s.*, t.display_name AS type_name FROM dhcp_server_subnets x
            JOIN subnets s ON s.id = x.subnet_id
            LEFT JOIN subnet_types t ON t.id = s.subnet_type_id
            WHERE x.host_id = ? ORDER BY s.ip_addr', [$hostId]);
        $out['subnets'] = array_map([Subnets::class, 'format'], $subnets);
        $groups = Ona::select('SELECT * FROM dhcp_failover_groups WHERE primary_server_id = ? OR secondary_server_id = ? ORDER BY id', [$hostId, $hostId]);
        $out['failoverGroups'] = array_map([DhcpFailoverGroups::class, 'format'], $groups);
        $out['options'] = array_map([DhcpEntries::class, 'format'],
            Ona::select(DhcpEntries::BASE_SQL . ' WHERE e.server_id = ? ORDER BY o.display_name', [$hostId]));
        return [200, $out];
    }

    /** POST /dhcp-servers {hostId, subnetId}: make a host serve a subnet. */
    public static function addSubnet(Request $req): array
    {
        Auth::requirePerm('advanced');
        $in = new Input($req->body());
        $hostId = $in->int('hostId', true, 1);
        $subnetId = $in->int('subnetId', true, 1);
        $in->check();
        Hosts::load($hostId);
        Subnets::load($subnetId);
        Ona::run('dhcp_server_add', ['server' => $hostId, 'subnet' => $subnetId], ['advanced']);
        return [201, self::get($hostId)[1]];
    }

    /** DELETE /dhcp-servers/{hostId}/subnets/{subnetId}. */
    public static function removeSubnet(int $hostId, int $subnetId): array
    {
        Auth::requirePerm('advanced');
        if (!Ona::select('SELECT id FROM dhcp_server_subnets WHERE host_id = ? AND subnet_id = ?', [$hostId, $subnetId])) {
            throw ApiError::notFound('DHCP server subnet');
        }
        Ona::run('dhcp_server_del', ['server' => $hostId, 'subnet' => $subnetId, 'commit' => 'Y'], ['advanced']);
        return [204, null];
    }
}

/** Failover pairs of DHCP servers (dhcp_failover_group_* modules, `advanced`). */
class DhcpFailoverGroups
{
    /** API field => [module option, DB column]. */
    private const SETTINGS = [
        'responseDelay' => ['response_delay', 'max_response_delay'],
        'unackedUpdates' => ['unacked_updates', 'max_unacked_updates'],
        'maxBalance' => ['max_balance', 'max_load_balance'],
        'primaryPort' => ['priport', 'primary_port'],
        'peerPort' => ['peerport', 'peer_port'],
        'mclt' => ['mclt', 'mclt'],
        'split' => ['split', 'split'],
    ];

    public static function list(Request $req): array
    {
        $rows = Ona::select('SELECT * FROM dhcp_failover_groups ORDER BY id');
        return [200, ['data' => array_map([self::class, 'format'], $rows)]];
    }

    public static function load(int $id): array
    {
        $row = Ona::byId('dhcp_failover_groups', $id);
        if (!$row) throw ApiError::notFound('DHCP failover group');
        return $row;
    }

    public static function get(int $id): array
    {
        $out = self::format(self::load($id));
        $out['pools'] = array_map([DhcpPools::class, 'format'],
            Ona::select(DhcpPools::BASE_SQL . ' WHERE p.dhcp_failover_group_id = ? ORDER BY p.ip_addr_start', [$id]));
        return [200, $out];
    }

    private static function settings(Input $in, string $prefix): array
    {
        $out = [];
        foreach (self::SETTINGS as $field => [$opt]) {
            $max = $field === 'split' ? 255 : 65535;
            if ($field === 'mclt' || $field === 'responseDelay') $max = 2147483647;
            $out[$prefix . $opt] = $in->int($field, false, 1, $max);
        }
        return $out;
    }

    public static function create(Request $req): array
    {
        Auth::requirePerm('advanced');
        $in = new Input($req->body());
        $opts = [
            'pri_server' => $in->int('primaryServerId', true, 1),
            'sec_server' => $in->int('secondaryServerId', true, 1),
        ] + self::settings($in, '');
        $in->check();
        Hosts::load($opts['pri_server']);
        Hosts::load($opts['sec_server']);
        $before = Ona::maxId('dhcp_failover_groups');
        Ona::run('dhcp_failover_group_add', $opts, ['advanced']);
        return [201, self::get(Ona::newId('dhcp_failover_groups', $before))[1]];
    }

    /**
     * PATCH: the failover settings only. ONA's dhcp_failover_group_modify
     * writes the old server IDs back when asked to change them, so changing
     * the servers means deleting and re-creating the group.
     */
    public static function update(Request $req, int $id): array
    {
        Auth::requirePerm('advanced');
        $group = self::load($id);
        $in = new Input($req->body());
        foreach (['primaryServerId', 'secondaryServerId'] as $k) {
            if ($in->has($k)) $in->error($k, 'cannot be changed; delete the group and create a new one');
        }
        $set = self::settings($in, 'set_');
        $in->check();
        if (!array_filter($set, fn($v) => $v !== null)) throw ApiError::badRequest('Nothing to update');
        // The module also fails when the update changes nothing, so only
        // send settings that differ.
        $changed = [];
        foreach (self::SETTINGS as [$opt, $col]) {
            $v = $set["set_{$opt}"];
            if ($v !== null && $v !== (int)$group[$col]) $changed["set_{$opt}"] = $v;
        }
        if ($changed) Ona::run('dhcp_failover_group_modify', ['id' => $id] + $changed, ['advanced']);
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        Auth::requirePerm('advanced');
        self::load($id);
        Ona::run('dhcp_failover_group_del', ['id' => $id, 'commit' => 'Y'], ['advanced']);
        return [204, null];
    }

    private static function server(int $hostId): ?array
    {
        $rows = Ona::select('SELECT id FROM hosts WHERE id = ?', [$hostId]);
        if (!$rows) return null;
        $h = Hosts::format(Hosts::load($hostId));
        return ['id' => $h['id'], 'fqdn' => $h['fqdn']];
    }

    public static function format(array $g): array
    {
        $out = [
            'id' => (int)$g['id'],
            'primaryServer' => self::server((int)$g['primary_server_id']),
            'secondaryServer' => self::server((int)$g['secondary_server_id']),
        ];
        foreach (self::SETTINGS as $field => [, $col]) $out[$field] = (int)$g[$col];
        return $out;
    }
}

/** Address pools inside a subnet (dhcp_pool_* modules, `advanced`). IPv4 only, as in ONA. */
class DhcpPools
{
    public const BASE_SQL = 'SELECT p.*, s.name AS subnet_name FROM dhcp_pools p LEFT JOIN subnets s ON s.id = p.subnet_id';

    /** API field => [create option, modify option, DB column]. */
    private const LEASE = [
        'leaseLength' => ['llength', 'set_llength', 'lease_length'],
        'leaseGrace' => [null, 'set_lgrace', 'lease_grace_period'],
        'leaseRenewal' => [null, 'set_lrenewal', 'lease_renewal_time'],
        'leaseRebind' => [null, 'set_lrebind', 'lease_rebind_time'],
    ];

    public static function list(Request $req): array
    {
        $paging = new Paging($req, 50);
        $where = [];
        $params = [];
        if (($subnet = $req->queryInt('subnetId')) !== null) {
            $where[] = 'p.subnet_id = ?';
            $params[] = $subnet;
        }
        if (($group = $req->queryInt('failoverGroupId')) !== null) {
            $where[] = 'p.dhcp_failover_group_id = ?';
            $params[] = $group;
        }
        $sql = self::BASE_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $order = Paging::orderBy($req, ['start' => 'p.ip_addr_start', 'subnet' => 's.name'], 'start');
        return $paging->run($sql, $params, $order . ', p.id', [self::class, 'format']);
    }

    public static function load(int $id): array
    {
        $rows = Ona::select(self::BASE_SQL . ' WHERE p.id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('DHCP pool');
        return $rows[0];
    }

    public static function get(int $id): array
    {
        return [200, self::format(self::load($id))];
    }

    public static function create(Request $req): array
    {
        Auth::requirePerm('advanced');
        $in = new Input($req->body());
        $opts = [
            'start' => self::ipv4($in, 'start', true),
            'end' => self::ipv4($in, 'end', true),
            'failover_group' => $in->int('failoverGroupId', false, 1),
        ];
        $later = [];
        foreach (self::LEASE as $field => [$addOpt, $setOpt]) {
            $v = $in->int($field, false, 1, 2147483647);
            if ($addOpt) $opts[$addOpt] = $v;
            elseif ($v !== null) $later[$setOpt] = $v;
        }
        $in->check();
        if ($opts['failover_group'] !== null) DhcpFailoverGroups::load($opts['failover_group']);
        $before = Ona::maxId('dhcp_pools');
        Ona::run('dhcp_pool_add', $opts, ['advanced']);
        $id = Ona::newId('dhcp_pools', $before);
        // dhcp_pool_add only takes the lease length; set the other timers after.
        if ($later) Ona::run('dhcp_pool_modify', ['pool' => $id, 'set_failover_group' => (int)$opts['failover_group']] + $later, ['advanced']);
        return [201, self::get($id)[1]];
    }

    public static function update(Request $req, int $id): array
    {
        Auth::requirePerm('advanced');
        $pool = self::load($id);
        $in = new Input($req->body());
        $set = [
            'set_start' => self::ipv4($in, 'start', false),
            'set_end' => self::ipv4($in, 'end', false),
        ];
        foreach (self::LEASE as $field => [, $setOpt]) $set[$setOpt] = $in->int($field, false, 1, 2147483647);
        $group = $in->int('failoverGroupId', false, 0);
        $in->check();
        $set = array_filter($set, fn($v) => $v !== null);
        if (!$set && $group === null) throw ApiError::badRequest('Nothing to update');
        if ($group) DhcpFailoverGroups::load($group);
        // dhcp_pool_modify clears the failover group unless it is sent, and
        // prints its usage unless a set_* option is truthy (the start address
        // always is).
        $set['set_failover_group'] = $group ?? (int)$pool['dhcp_failover_group_id'];
        $set['set_start'] ??= Ona::ipText($pool['ip_addr_start']);
        Ona::run('dhcp_pool_modify', ['pool' => $id] + $set, ['advanced']);
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        Auth::requirePerm('advanced');
        self::load($id);
        Ona::run('dhcp_pool_del', ['id' => $id, 'commit' => 'Y'], ['advanced']);
        return [204, null];
    }

    private static function ipv4(Input $in, string $key, bool $required): ?string
    {
        $ip = $in->ip($key, $required);
        if ($ip !== null && ($n = Ona::ipNumeric($ip)) !== null && !Ona::isIpv4($n)) $in->error($key, 'must be an IPv4 address');
        return $ip;
    }

    public static function format(array $p): array
    {
        $out = [
            'id' => (int)$p['id'],
            'subnet' => $p['subnet_id'] ? ['id' => (int)$p['subnet_id'], 'name' => $p['subnet_name']] : null,
            'failoverGroupId' => $p['dhcp_failover_group_id'] ? (int)$p['dhcp_failover_group_id'] : null,
            'start' => Ona::ipText($p['ip_addr_start']),
            'end' => Ona::ipText($p['ip_addr_end']),
            'size' => (int)$p['ip_addr_end'] - (int)$p['ip_addr_start'] + 1,
        ];
        foreach (self::LEASE as $field => [, , $col]) $out[$field] = (int)$p[$col];
        return $out;
    }
}

/** DHCP option definitions (read-only; managed in the classic UI). */
class DhcpOptions
{
    public const TYPES = ['L' => 'IP address list', 'S' => 'String', 'N' => 'Numeric', 'I' => 'IP address', 'B' => 'Boolean'];

    public static function list(): array
    {
        $rows = Ona::select('SELECT * FROM dhcp_options ORDER BY display_name');
        return [200, ['data' => array_map([self::class, 'format'], $rows)]];
    }

    public static function format(array $o): array
    {
        return [
            'id' => (int)$o['id'],
            'name' => $o['name'],
            'displayName' => $o['display_name'],
            'number' => (int)$o['number'],
            'type' => $o['type'],
            'typeName' => self::TYPES[$o['type']] ?? $o['type'],
            'system' => (bool)$o['sys_default'],
        ];
    }
}

/**
 * DHCP option values (dhcp_option_entries via dhcp_entry_*, `advanced`),
 * set globally or on a subnet, a host or a DHCP server.
 */
class DhcpEntries
{
    public const BASE_SQL = 'SELECT e.*, o.name AS option_name, o.display_name, o.number AS option_number, o.type AS option_type,
            o.sys_default AS option_sys
        FROM dhcp_option_entries e
        LEFT JOIN dhcp_options o ON o.id = e.dhcp_option_id';

    public static function list(Request $req): array
    {
        $where = [];
        $params = [];
        foreach (['hostId' => 'e.host_id', 'subnetId' => 'e.subnet_id', 'serverId' => 'e.server_id'] as $q => $col) {
            if (($v = $req->queryInt($q)) !== null) {
                $where[] = "{$col} = ?";
                $params[] = $v;
            }
        }
        if ($req->queryString('global') === 'true') {
            $where[] = 'e.host_id = 0 AND e.subnet_id = 0 AND e.server_id = 0';
        }
        $sql = self::BASE_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.display_name, e.id';
        return [200, ['data' => array_map([self::class, 'format'], Ona::select($sql, $params, 1000))]];
    }

    public static function load(int $id): array
    {
        $rows = Ona::select(self::BASE_SQL . ' WHERE e.id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('DHCP option entry');
        return $rows[0];
    }

    public static function get(int $id): array
    {
        return [200, self::format(self::load($id))];
    }

    /** POST /dhcp-entries {optionId, value, and one of hostId, subnetId, serverId or global: true}. */
    public static function create(Request $req): array
    {
        Auth::requirePerm('advanced');
        $in = new Input($req->body());
        $optionId = $in->int('optionId', true, 1);
        $value = $in->string('value', true, 127);
        $anchors = [
            'host' => $in->int('hostId', false, 1),
            'subnet' => $in->int('subnetId', false, 1),
            'server' => $in->int('serverId', false, 1),
            'global' => $in->bool('global', false) ? 'Y' : null,
        ];
        $anchors = array_filter($anchors, fn($v) => $v !== null);
        if (count($anchors) !== 1) $in->error('scope', 'send exactly one of hostId, subnetId, serverId or global: true');
        $in->check();
        if (!Ona::byId('dhcp_options', $optionId)) throw ApiError::validation('Invalid input', ['optionId' => 'no such DHCP option']);
        if (isset($anchors['host'])) Hosts::load($anchors['host']);
        if (isset($anchors['subnet'])) Subnets::load($anchors['subnet']);
        if (isset($anchors['server'])) Hosts::load($anchors['server']);

        $before = Ona::maxId('dhcp_option_entries');
        Ona::run('dhcp_entry_add', $anchors + ['option' => $optionId, 'value' => $value], ['advanced']);
        return [201, self::get(Ona::newId('dhcp_option_entries', $before))[1]];
    }

    public static function update(Request $req, int $id): array
    {
        Auth::requirePerm('advanced');
        $entry = self::load($id);
        $in = new Input($req->body());
        $optionId = $in->int('optionId', false, 1);
        $value = $in->string('value', false, 127);
        $in->check();
        if ($optionId === null && $value === null) throw ApiError::badRequest('Nothing to update; send optionId and/or value');
        if ($value === '') throw ApiError::validation('Invalid input', ['value' => 'is required']);
        if ($optionId !== null && !Ona::byId('dhcp_options', $optionId)) {
            throw ApiError::validation('Invalid input', ['optionId' => 'no such DHCP option']);
        }
        // dhcp_entry_modify needs set_value even when only the option changes.
        $set = ['set_value' => $value ?? $entry['value']];
        if ($optionId !== null && $optionId !== (int)$entry['dhcp_option_id']) $set['set_option'] = $optionId;
        Ona::run('dhcp_entry_modify', ['id' => $id] + $set, ['advanced']);
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        Auth::requirePerm('advanced');
        self::load($id);
        Ona::run('dhcp_entry_del', ['id' => $id, 'commit' => 'Y'], ['advanced']);
        return [204, null];
    }

    public static function format(array $e): array
    {
        $scope = 'global';
        $target = null;
        if ($e['host_id']) {
            $scope = 'host';
            $target = ['id' => (int)$e['host_id'], 'name' => self::hostName((int)$e['host_id'])];
        } elseif ($e['subnet_id']) {
            $scope = 'subnet';
            $s = Ona::byId('subnets', (int)$e['subnet_id']);
            $target = ['id' => (int)$e['subnet_id'], 'name' => $s['name'] ?? null];
        } elseif ($e['server_id']) {
            $scope = 'server';
            $target = ['id' => (int)$e['server_id'], 'name' => self::hostName((int)$e['server_id'])];
        }
        return [
            'id' => (int)$e['id'],
            'option' => [
                'id' => (int)$e['dhcp_option_id'],
                'name' => $e['option_name'],
                'displayName' => $e['display_name'],
                'number' => (int)$e['option_number'],
                'type' => $e['option_type'],
            ],
            'value' => $e['value'],
            'scope' => $scope,
            'target' => $target,
        ];
    }

    private static function hostName(int $id): ?string
    {
        $rows = Ona::select('SELECT id FROM hosts WHERE id = ?', [$id]);
        return $rows ? Hosts::format(Hosts::load($id))['fqdn'] : null;
    }
}
