<?php
namespace OnaRest;

class DnsRecords
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'PTR', 'SRV', 'TXT'];

    public const BASE_SQL = 'SELECT r.*, i.ip_addr, i.host_id,
            t.name AS target_name, t.domain_id AS target_domain_id, v.name AS view_name
        FROM dns r
        LEFT JOIN interfaces i ON i.id = r.interface_id
        LEFT JOIN dns t ON t.id = r.dns_id
        LEFT JOIN dns_views v ON v.id = r.dns_view_id';

    /**
     * ONA disables a record by moving its begin date (dns.ebegin) to
     * 2038-01-19, the end of MySQL's TIMESTAMP range; anything after
     * 2038-01-10 counts as disabled (as in the legacy record list). A record
     * with an earlier future date becomes active then.
     */
    public const DISABLED_AFTER = '2038-01-10 00:00:00';

    public static function list(Request $req): array
    {
        $paging = new Paging($req);
        $where = [];
        $params = [];
        $q = $req->queryString('q');
        if ($q !== '') {
            if (($ip = Ona::ipNumeric($q)) !== null) {
                $where[] = 'i.ip_addr = ?';
                $params[] = $ip;
            } else {
                [$name] = explode('.', $q, 2);
                $where[] = '(r.name LIKE ? OR r.txt LIKE ? OR r.notes LIKE ?)';
                $params[] = '%' . Sql::likeEscape($name) . '%';
                $params[] = '%' . Sql::likeEscape($q) . '%';
                $params[] = '%' . Sql::likeEscape($q) . '%';
            }
        }
        $type = strtoupper($req->queryString('type'));
        if ($type !== '') {
            if (!in_array($type, self::TYPES, true)) throw ApiError::badRequest('type must be one of ' . implode(', ', self::TYPES));
            $where[] = 'r.type = ?';
            $params[] = $type;
        }
        if (($domain = $req->queryInt('domainId')) !== null) {
            $where[] = 'r.domain_id = ?';
            $params[] = $domain;
        }
        if (($view = $req->queryInt('viewId')) !== null) {
            $where[] = 'r.dns_view_id = ?';
            $params[] = $view;
        }
        $enabled = $req->queryString('enabled');
        if ($enabled !== '') {
            if (!in_array($enabled, ['true', 'false'], true)) throw ApiError::badRequest('enabled must be true or false');
            // Compare in PHP's clock, as ONA writes ebegin with date().
            $where[] = $enabled === 'true' ? 'r.ebegin <= ?' : 'r.ebegin > ?';
            $params[] = date('Y-m-d H:i:s');
        }
        if (($host = $req->queryInt('hostId')) !== null) {
            $where[] = '(i.host_id = ? OR r.dns_id IN (SELECT x.id FROM dns x JOIN interfaces xi ON xi.id = x.interface_id WHERE xi.host_id = ?))';
            array_push($params, $host, $host);
        }
        $sql = self::BASE_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $order = Paging::orderBy($req, ['name' => 'r.name', 'type' => 'r.type', 'domain' => 'r.domain_id', 'id' => 'r.id'], 'name');
        return $paging->run($sql, $params, $order . ', r.type, r.id', [self::class, 'format']);
    }

    public static function load(int $id): array
    {
        $rows = Ona::select(self::BASE_SQL . ' WHERE r.id = ?', [$id]);
        if (!$rows) throw ApiError::notFound('DNS record');
        return $rows[0];
    }

    public static function get(int $id): array
    {
        return [200, self::format(self::load($id))];
    }

    public static function create(Request $req): array
    {
        $in = new Input($req->body());
        $type = strtoupper((string)$in->string('type', true, 10));
        if ($type !== '' && !in_array($type, self::TYPES, true)) $in->error('type', 'must be one of ' . implode(', ', self::TYPES));
        $opts = self::fields($in, '') + ['type' => $type];
        $opts['name'] = $in->string('name', true);
        $opts['addptr'] = $in->bool('addPtr', false);
        $opts['view'] = $in->int('viewId', false, 0);
        $ebegin = self::ebegin($in, null);
        if ($ebegin !== null) $opts['ebegin'] = $ebegin;
        $in->check();
        if ($opts['view'] !== null) Reference::load('dns-views', $opts['view']);
        // ONA stores AAAA records as type A with an IPv6 address.
        if ($opts['type'] === 'AAAA') $opts['type'] = 'A';

        $before = (int)(Ona::select('SELECT MAX(id) AS id FROM dns')[0]['id'] ?? 0);
        // view=0 is the default view, and ebegin=0 disables: keep those zeros.
        Ona::run('dns_record_add', array_filter($opts, fn($v) => $v !== null && $v !== ''), ['host_add']);

        // The module doesn't report the new ID; it is the newest record of
        // that type (an A record's automatic PTR gets a higher ID).
        $rows = Ona::select('SELECT id FROM dns WHERE id > ? AND type = ? ORDER BY id', [$before, $opts['type']], 1);
        if (!$rows) throw new ApiError(500, 'internal_error', 'Record was added but could not be read back');
        return [201, self::format(self::load((int)$rows[0]['id']))];
    }

    public static function update(Request $req, int $id): array
    {
        $rec = self::load($id);
        $in = new Input($req->body());
        $set = self::fields($in, 'set_');
        $set['set_name'] = $in->string('name');
        $set['set_view'] = $in->int('viewId', false, 0);
        $set['set_ebegin'] = self::ebegin($in, $rec);
        $in->check();
        if ($set['set_view'] !== null) {
            Reference::load('dns-views', $set['set_view']);
            if ($set['set_view'] === (int)$rec['dns_view_id']) $set['set_view'] = null;
        }
        $set = array_filter($set, fn($v) => $v !== null);
        if (!$set) {
            if ($in->has('enabled') || $in->has('activeFrom') || $in->has('viewId')) return self::get($id); // already so
            throw ApiError::badRequest('Nothing to update');
        }
        // dns_record_modify sets ebegin to "now" on any change that doesn't
        // set it, which would silently re-enable a disabled or scheduled
        // record. Resend its own date, nudged by a second so the module sees
        // a change (it ignores an unchanged ebegin).
        if (!isset($set['set_ebegin']) && !self::isEnabled($rec)) $set['set_ebegin'] = self::nudge($rec['ebegin']);
        // dns_record_modify prints its usage text unless a set_* option other
        // than set_txt is truthy, so a txt-only change (or ttl 0 / empty notes)
        // needs a companion; " <ttl>" is truthy but compares equal to the ttl.
        // set_ebegin=0 (disable) and set_view=0 (default view) are falsy too.
        if (!array_filter(array_diff_key($set, ['set_txt' => 1]))) $set['set_ttl'] = ' ' . ($set['set_ttl'] ?? $rec['ttl']);
        Ona::run('dns_record_modify', ['name' => $id] + $set, ['host_modify']);
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        $rec = self::load($id);
        Ona::run('dns_record_del', ['name' => $id, 'type' => $rec['type'], 'commit' => 'Y'], ['host_del']);
        return [204, null];
    }

    /** Optional fields shared by create and update, keyed by module option. */
    private static function fields(Input $in, string $prefix): array
    {
        return [
            "{$prefix}ip" => $in->ip('ip'),
            "{$prefix}pointsto" => $in->string('pointsTo'),
            "{$prefix}ttl" => $in->int('ttl', false, 0, 2147483647),
            "{$prefix}notes" => $in->string('notes', false, 128),
            "{$prefix}mx_preference" => $in->int('mxPreference', false, 0, 65535),
            "{$prefix}txt" => $in->string('txt'),
            "{$prefix}srv_pri" => $in->int('srvPriority', false, 0, 65535),
            "{$prefix}srv_weight" => $in->int('srvWeight', false, 0, 65535),
            "{$prefix}srv_port" => $in->int('srvPort', false, 0, 65535),
        ];
    }

    /**
     * The module's ebegin value for `enabled` / `activeFrom`: "0" disables,
     * a date enables from then on (now when just enabling). Null when the
     * request doesn't change it ($rec is the current record on updates).
     */
    private static function ebegin(Input $in, ?array $rec): ?string
    {
        $enabled = $in->has('enabled') ? $in->bool('enabled', true) : null;
        $from = $in->string('activeFrom', false, 32);
        if ($from !== null && $from !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $from) || ($t = strtotime(str_replace('T', ' ', $from))) === false) {
                $in->error('activeFrom', 'must be a date (YYYY-MM-DD or YYYY-MM-DD HH:MM[:SS])');
                return null;
            }
            if ($t >= strtotime(self::DISABLED_AFTER)) { $in->error('activeFrom', 'must be before 2038-01-10'); return null; }
            if ($enabled === false) { $in->error('activeFrom', 'cannot be combined with enabled: false'); return null; }
            if ($rec !== null && strtotime($rec['ebegin']) === $t) return null;
            return date('Y-m-d H:i:s', $t);
        }
        if ($enabled === null) return null;
        // Enabling an active record, or disabling a disabled one, changes
        // nothing; disabling a scheduled (future) record does.
        if ($rec !== null && $enabled === true && self::isEnabled($rec)) return null;
        if ($rec !== null && $enabled === false && strtotime((string)$rec['ebegin']) >= strtotime(self::DISABLED_AFTER)) return null;
        return $enabled ? date('Y-m-d H:i:s') : '0';
    }

    public static function isEnabled(array $r): bool
    {
        return strtotime((string)$r['ebegin']) <= time();
    }

    /** The same moment +/- one second (stays on the same side of "now" and of 2038-01-10). */
    private static function nudge(string $ebegin): string
    {
        $t = strtotime($ebegin);
        return date('Y-m-d H:i:s', $t % 2 === 0 ? $t + 1 : $t - 1);
    }

    public static function format(array $r): array
    {
        $domain = Ona::domainName($r['domain_id']);
        $type = $r['type'];
        if ($type === 'A' && $r['ip_addr'] !== null && !Ona::isIpv4($r['ip_addr'])) $type = 'AAAA';
        $out = [
            'id' => (int)$r['id'],
            'type' => $type,
            'name' => $r['name'],
            'fqdn' => ($r['name'] !== '' ? $r['name'] . '.' : '') . $domain,
            'domain' => ['id' => (int)$r['domain_id'], 'name' => $domain],
            'ttl' => (int)$r['ttl'],
            'ip' => $r['ip_addr'] !== null ? Ona::ipText($r['ip_addr']) : null,
            'interfaceId' => $r['interface_id'] ? (int)$r['interface_id'] : null,
            'hostId' => $r['host_id'] ? (int)$r['host_id'] : null,
            'pointsTo' => null,
            'notes' => $r['notes'],
            // Enabled means active now (ebegin <= now); see DISABLED_AFTER.
            'enabled' => self::isEnabled($r),
            'activeFrom' => strtotime((string)$r['ebegin']) >= strtotime(self::DISABLED_AFTER) ? null : (string)$r['ebegin'],
            'view' => ['id' => (int)$r['dns_view_id'], 'name' => (string)($r['view_name'] ?? '')],
            'mxPreference' => null,
            'txt' => null,
            'srv' => null,
        ];
        if ($r['dns_id'] && $r['target_domain_id'] !== null) {
            $out['pointsTo'] = [
                'id' => (int)$r['dns_id'],
                'fqdn' => ($r['target_name'] !== '' ? $r['target_name'] . '.' : '') . Ona::domainName($r['target_domain_id']),
            ];
        }
        if ($r['type'] === 'MX') $out['mxPreference'] = (int)$r['mx_preference'];
        if ($r['type'] === 'TXT') $out['txt'] = $r['txt'];
        if ($r['type'] === 'SRV') {
            $out['srv'] = ['priority' => (int)$r['srv_pri'], 'weight' => (int)$r['srv_weight'], 'port' => (int)$r['srv_port']];
        }
        return $out;
    }
}
