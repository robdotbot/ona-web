<?php
namespace OnaRest;

/**
 * User, group and permission administration. ONA has no DCM modules for
 * these; like the legacy admin windows, writes go straight to the tables and
 * need `user_admin` (reads too). Permission changes apply at the user's next
 * login, as in ONA.
 */
class Users
{
    public static function list(): array
    {
        Auth::requirePerm('user_admin');
        $rows = Ona::select('SELECT * FROM users ORDER BY username');
        return [200, ['data' => array_map([self::class, 'format'], $rows)]];
    }

    public static function load(int $id): array
    {
        $row = Ona::byId('users', $id);
        if (!$row) throw ApiError::notFound('User');
        return $row;
    }

    public static function get(int $id): array
    {
        Auth::requirePerm('user_admin');
        return [200, self::format(self::load($id))];
    }

    public static function create(Request $req): array
    {
        Auth::requirePerm('user_admin');
        $in = new Input($req->body());
        $username = self::username($in, true);
        $password = self::password($in, true);
        $groups = Permissions::groupIds($in);
        $perms = Permissions::names($in);
        $in->check();
        if (self::byName($username)) throw new ApiError(409, 'conflict', "User {$username} already exists");

        $id = Ona::write(['user_admin'], "User ADDED: {$username}", function ($db) use ($username, $password, $groups, $perms) {
            Ona::dbOk(db_insert_record($db, 'users', [
                'username' => $username, 'password' => md5($password), 'level' => 0, 'ctime' => date('Y-m-d H:i:s'),
            ]));
            $id = (int)self::byName($username)['id'];
            if ($groups !== null) self::setGroups($db, $id, $groups);
            if ($perms !== null) Permissions::assign($db, 'user_id', $id, $perms);
            return $id;
        });
        return [201, self::format(self::load($id))];
    }

    public static function update(Request $req, int $id): array
    {
        Auth::requirePerm('user_admin');
        $user = self::load($id);
        $in = new Input($req->body());
        $username = self::username($in, false);
        $password = self::password($in, false);
        $groups = Permissions::groupIds($in);
        $perms = Permissions::names($in);
        $in->check();
        if ($username === null && $password === null && $groups === null && $perms === null) {
            throw ApiError::badRequest('Nothing to update; send username, password, groupIds and/or permissions');
        }
        if ($username !== null && strcasecmp($username, $user['username']) !== 0 && self::byName($username)) {
            throw new ApiError(409, 'conflict', "User {$username} already exists");
        }
        Ona::write(['user_admin'], "User UPDATED: {$user['username']}", function ($db) use ($id, $username, $password, $groups, $perms) {
            $set = [];
            if ($username !== null) $set['username'] = $username;
            if ($password !== null) $set['password'] = md5($password);
            if ($set) Ona::dbOk(db_update_record($db, 'users', ['id' => $id], $set));
            if ($groups !== null) self::setGroups($db, $id, $groups);
            if ($perms !== null) Permissions::assign($db, 'user_id', $id, $perms);
        });
        return [200, self::format(self::load($id))];
    }

    public static function delete(int $id): array
    {
        Auth::requirePerm('user_admin');
        $user = self::load($id);
        if (strcasecmp($user['username'], (string)Auth::user()) === 0) {
            throw new ApiError(409, 'conflict', 'You cannot delete the user you are logged in as');
        }
        Ona::write(['user_admin'], "User DELETED: {$user['username']}", function ($db) use ($id) {
            Ona::dbOk(db_delete_records($db, 'group_assignments', ['user_id' => $id]));
            Ona::dbOk(db_delete_records($db, 'permission_assignments', ['user_id' => $id]));
            Ona::dbOk(db_delete_records($db, 'users', ['id' => $id]));
        });
        return [204, null];
    }

    private static function byName(string $username): ?array
    {
        return Ona::select('SELECT * FROM users WHERE username = ?', [$username])[0] ?? null;
    }

    private static function username(Input $in, bool $required): ?string
    {
        $v = $in->string('username', $required, 32);
        if ($v !== null && !preg_match('/^[A-Za-z0-9.\-_]+$/', $v)) $in->error('username', 'may only contain A-Z 0-9 . - _');
        return $v;
    }

    /** ONA's local auth stores unsalted MD5, so that is what we write. */
    private static function password(Input $in, bool $required): ?string
    {
        if (!$in->has('password') || $in->raw('password') === null) {
            if ($required) $in->error('password', 'is required');
            return null;
        }
        $v = $in->raw('password');
        if (!is_string($v) || $v === '') {
            $in->error('password', 'must be a non-empty string');
            return null;
        }
        if (strlen($v) > 1024) $in->error('password', 'must be at most 1024 characters');
        return $v;
    }

    private static function setGroups($db, int $userId, array $groupIds): void
    {
        Ona::dbOk(db_delete_records($db, 'group_assignments', ['user_id' => $userId]));
        foreach ($groupIds as $g) Ona::dbOk(db_insert_record($db, 'group_assignments', ['user_id' => $userId, 'group_id' => $g]));
    }

    public static function format(array $u): array
    {
        $id = (int)$u['id'];
        $groups = Ona::select('SELECT g.id, g.name FROM group_assignments a JOIN auth_groups g ON g.id = a.group_id
            WHERE a.user_id = ? ORDER BY g.name', [$id]);
        return [
            'id' => $id,
            'username' => $u['username'],
            'level' => (int)$u['level'],
            'createdAt' => $u['ctime'] ?: null,
            'lastLoginAt' => ($u['atime'] && !str_starts_with((string)$u['atime'], '0000')) ? $u['atime'] : null,
            'groups' => array_map(fn($g) => ['id' => (int)$g['id'], 'name' => $g['name']], $groups),
            'permissions' => Permissions::assigned('user_id', $id),
        ];
    }
}

class Groups
{
    public static function list(): array
    {
        Auth::requirePerm('user_admin');
        $rows = Ona::select('SELECT * FROM auth_groups ORDER BY name');
        return [200, ['data' => array_map([self::class, 'format'], $rows)]];
    }

    public static function load(int $id): array
    {
        $row = Ona::byId('auth_groups', $id);
        if (!$row) throw ApiError::notFound('Group');
        return $row;
    }

    public static function get(int $id): array
    {
        Auth::requirePerm('user_admin');
        $out = self::format(self::load($id));
        $members = Ona::select('SELECT u.id, u.username FROM group_assignments a JOIN users u ON u.id = a.user_id
            WHERE a.group_id = ? ORDER BY u.username', [$id]);
        $out['members'] = array_map(fn($m) => ['id' => (int)$m['id'], 'username' => $m['username']], $members);
        return [200, $out];
    }

    public static function create(Request $req): array
    {
        Auth::requirePerm('user_admin');
        $in = new Input($req->body());
        $name = self::name($in, true);
        $description = $in->string('description', false, 191) ?? '';
        $perms = Permissions::names($in);
        $in->check();
        if (self::byName($name)) throw new ApiError(409, 'conflict', "Group {$name} already exists");
        $id = Ona::write(['user_admin'], "Group ADDED: {$name}", function ($db) use ($name, $description, $perms) {
            // The level column is unused; the legacy UI always writes 0.
            Ona::dbOk(db_insert_record($db, 'auth_groups', ['name' => $name, 'description' => $description, 'level' => 0]));
            $id = (int)self::byName($name)['id'];
            if ($perms !== null) Permissions::assign($db, 'group_id', $id, $perms);
            return $id;
        });
        return [201, self::get($id)[1]];
    }

    public static function update(Request $req, int $id): array
    {
        Auth::requirePerm('user_admin');
        $group = self::load($id);
        $in = new Input($req->body());
        $name = self::name($in, false);
        $description = $in->string('description', false, 191);
        $perms = Permissions::names($in);
        $in->check();
        if ($name === '') throw ApiError::validation('Invalid input', ['name' => 'is required']);
        if ($name === null && $description === null && $perms === null) {
            throw ApiError::badRequest('Nothing to update; send name, description and/or permissions');
        }
        if ($name !== null && strcasecmp($name, $group['name']) !== 0 && self::byName($name)) {
            throw new ApiError(409, 'conflict', "Group {$name} already exists");
        }
        Ona::write(['user_admin'], "Group UPDATED: {$group['name']}", function ($db) use ($id, $name, $description, $perms) {
            $set = array_filter(['name' => $name, 'description' => $description], fn($v) => $v !== null);
            if ($set) Ona::dbOk(db_update_record($db, 'auth_groups', ['id' => $id], $set));
            if ($perms !== null) Permissions::assign($db, 'group_id', $id, $perms);
        });
        return self::get($id);
    }

    public static function delete(int $id): array
    {
        Auth::requirePerm('user_admin');
        $group = self::load($id);
        Ona::write(['user_admin'], "Group DELETED: {$group['name']}", function ($db) use ($id) {
            Ona::dbOk(db_delete_records($db, 'group_assignments', ['group_id' => $id]));
            Ona::dbOk(db_delete_records($db, 'permission_assignments', ['group_id' => $id]));
            Ona::dbOk(db_delete_records($db, 'auth_groups', ['id' => $id]));
        });
        return [204, null];
    }

    private static function byName(string $name): ?array
    {
        return Ona::select('SELECT * FROM auth_groups WHERE name = ?', [$name])[0] ?? null;
    }

    private static function name(Input $in, bool $required): ?string
    {
        $v = $in->string('name', $required, 32);
        if ($v !== null && $v !== '' && !preg_match('/^[A-Za-z0-9.\-_ ]+$/', $v)) {
            $in->error('name', 'may only contain A-Z 0-9 . - _ and spaces');
        }
        return $v;
    }

    public static function format(array $g): array
    {
        $id = (int)$g['id'];
        return [
            'id' => $id,
            'name' => $g['name'],
            'description' => (string)$g['description'],
            'memberCount' => Ona::count('SELECT id FROM group_assignments WHERE group_id = ?', [$id]),
            'permissions' => Permissions::assigned('group_id', $id),
        ];
    }
}

class Permissions
{
    public static function list(): array
    {
        Auth::requirePerm('user_admin');
        $rows = Ona::select('SELECT * FROM permissions ORDER BY name');
        return [200, ['data' => array_map(fn($p) => [
            'id' => (int)$p['id'], 'name' => $p['name'], 'description' => (string)$p['description'],
        ], $rows)]];
    }

    /** Permission names assigned directly to a user or group. */
    public static function assigned(string $column, int $id): array
    {
        $col = $column === 'group_id' ? 'group_id' : 'user_id';
        $rows = Ona::select("SELECT p.name FROM permission_assignments a JOIN permissions p ON p.id = a.perm_id
            WHERE a.{$col} = ? ORDER BY p.name", [$id]);
        return array_map(fn($r) => $r['name'], $rows);
    }

    /** Replace the direct permissions of a user or group. */
    public static function assign($db, string $column, int $id, array $names): void
    {
        $col = $column === 'group_id' ? 'group_id' : 'user_id';
        $other = $col === 'group_id' ? 'user_id' : 'group_id';
        Ona::dbOk(db_delete_records($db, 'permission_assignments', [$col => $id]));
        foreach ($names as $name) {
            $permId = (int)Ona::select('SELECT id FROM permissions WHERE name = ?', [$name])[0]['id'];
            Ona::dbOk(db_insert_record($db, 'permission_assignments', ['perm_id' => $permId, $col => $id, $other => 0]));
        }
    }

    /** Optional "permissions": [names] in a body; null if absent. */
    public static function names(Input $in): ?array
    {
        $v = $in->raw('permissions');
        if (!$in->has('permissions') || $v === null) return null;
        if (!is_array($v) || !array_is_list($v) || array_filter($v, fn($x) => !is_string($x))) {
            $in->error('permissions', 'must be an array of permission names');
            return null;
        }
        $v = array_values(array_unique($v));
        $known = array_map(fn($r) => $r['name'], Ona::select('SELECT name FROM permissions'));
        $unknown = array_diff($v, $known);
        if ($unknown) $in->error('permissions', 'unknown permission(s): ' . implode(', ', $unknown));
        return $v;
    }

    /** Optional "groupIds": [ids] in a body; null if absent. */
    public static function groupIds(Input $in): ?array
    {
        $v = $in->raw('groupIds');
        if (!$in->has('groupIds') || $v === null) return null;
        if (!is_array($v) || !array_is_list($v) || array_filter($v, fn($x) => !is_int($x))) {
            $in->error('groupIds', 'must be an array of group IDs');
            return null;
        }
        $v = array_values(array_unique($v));
        foreach ($v as $g) {
            if (!Ona::byId('auth_groups', $g)) $in->error('groupIds', "no group with ID {$g}");
        }
        return $v;
    }
}

/**
 * System configuration (sys_config), `advanced` permission, with the legacy
 * rules: entries marked not editable can't be changed, only deleteable ones
 * can be deleted, and a value must match the entry's validation rule.
 */
class SysConfig
{
    public static function list(): array
    {
        Auth::requirePerm('advanced');
        $rows = Ona::select('SELECT * FROM sys_config ORDER BY name');
        return [200, ['data' => array_map([self::class, 'format'], $rows)]];
    }

    public static function load(string $name): array
    {
        $rows = Ona::select('SELECT * FROM sys_config WHERE name = ?', [$name]);
        if (!$rows) throw ApiError::notFound('Config entry');
        return $rows[0];
    }

    public static function get(string $name): array
    {
        Auth::requirePerm('advanced');
        return [200, self::format(self::load($name))];
    }

    public static function create(Request $req): array
    {
        Auth::requirePerm('advanced');
        $in = new Input($req->body());
        $name = $in->string('name', true, 128);
        $value = $in->string('value', false, 191) ?? '';
        $description = $in->string('description', false, 512) ?? '';
        if ($name !== null && $name !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $name)) $in->error('name', 'may only contain A-Z 0-9 _');
        $in->check();
        if (Ona::select('SELECT name FROM sys_config WHERE name = ?', [$name])) {
            throw new ApiError(409, 'conflict', "Config entry {$name} already exists");
        }
        Ona::write(['advanced'], "Sys_config ADDED: {$name}", function ($db) use ($name, $value, $description) {
            Ona::dbOk(db_insert_record($db, 'sys_config', [
                'name' => $name, 'value' => $value, 'description' => $description,
                'field_validation_rule' => '', 'failed_rule_text' => '', 'editable' => 1, 'deleteable' => 1,
            ]));
        });
        return [201, self::format(self::load($name))];
    }

    public static function update(Request $req, string $name): array
    {
        Auth::requirePerm('advanced');
        $entry = self::load($name);
        $in = new Input($req->body());
        $value = $in->string('value', false, 191);
        $description = $in->string('description', false, 512);
        $in->check();
        if ($value === null && $description === null) throw ApiError::badRequest('Nothing to update; send value and/or description');
        if (!(int)$entry['editable']) throw new ApiError(409, 'conflict', "Config entry {$name} is not editable");
        $rule = (string)$entry['field_validation_rule'];
        if ($value !== null && $rule !== '' && @preg_match($rule, $value) === 0) {
            throw ApiError::validation('Invalid input', ['value' => $entry['failed_rule_text'] ?: "must match {$rule}"]);
        }
        $set = array_filter(['value' => $value, 'description' => $description], fn($v) => $v !== null);
        Ona::write(['advanced'], "Sys_config UPDATED: {$name}", function ($db) use ($name, $set) {
            Ona::dbOk(db_update_record($db, 'sys_config', ['name' => $name], $set));
        });
        return [200, self::format(self::load($name))];
    }

    public static function delete(string $name): array
    {
        Auth::requirePerm('advanced');
        $entry = self::load($name);
        if (!(int)$entry['deleteable']) throw new ApiError(409, 'conflict', "Config entry {$name} can't be deleted");
        Ona::write(['advanced'], "Sys_config DELETED: {$name}", function ($db) use ($name) {
            Ona::dbOk(db_delete_records($db, 'sys_config', ['name' => $name]));
        });
        return [204, null];
    }

    public static function format(array $c): array
    {
        return [
            'name' => $c['name'],
            'value' => (string)$c['value'],
            'description' => (string)$c['description'],
            'validationRule' => (string)$c['field_validation_rule'],
            'failedRuleText' => (string)$c['failed_rule_text'],
            'editable' => (bool)$c['editable'],
            'deleteable' => (bool)$c['deleteable'],
        ];
    }
}

/**
 * Installed plugins (www/plugins and www/local/plugins), `advanced`.
 * plugin_info.php is read as text, never included. Plugins are enabled or
 * disabled with the same plugin_disabled marker file the legacy UI uses;
 * running a plugin's installer stays in the classic UI.
 */
class Plugins
{
    /** name => directory; local plugins override built-in ones of the same name. */
    private static function dirs(): array
    {
        global $base;
        $found = [];
        foreach ([$base . '/plugins', $base . '/local/plugins'] as $dir) {
            if (!is_dir($dir)) continue;
            foreach (scandir($dir) ?: [] as $name) {
                if ($name[0] === '.' || $name === 'README' || str_ends_with($name, '.tar.gz')) continue;
                if (is_dir("{$dir}/{$name}")) $found[$name] = "{$dir}/{$name}";
            }
        }
        ksort($found, SORT_NATURAL | SORT_FLAG_CASE);
        return $found;
    }

    public static function list(): array
    {
        Auth::requirePerm('advanced');
        $out = [];
        foreach (self::dirs() as $name => $dir) $out[] = self::format($name, $dir);
        return [200, ['data' => $out]];
    }

    /** PATCH /plugins/{name} {enabled: bool}. */
    public static function update(Request $req, string $name): array
    {
        Auth::requirePerm('advanced');
        $dirs = self::dirs();
        if (!isset($dirs[$name])) throw ApiError::notFound('Plugin');
        $dir = $dirs[$name];
        $in = new Input($req->body());
        if (!$in->has('enabled')) $in->error('enabled', 'is required');
        $enabled = $in->bool('enabled', true);
        $in->check();
        $marker = "{$dir}/plugin_disabled";
        if ($enabled === file_exists($marker)) {
            if (!is_writable($dir)) throw new ApiError(409, 'conflict', "The plugin directory is not writable by the web server");
            $ok = $enabled ? @unlink($marker) : @touch($marker);
            if (!$ok) throw new ApiError(500, 'internal_error', 'Could not change the plugin state');
            clearstatcache();
            printmsg('INFO => [REST API] Plugin ' . ($enabled ? 'enabled' : 'disabled') . ": {$name}", 0);
        }
        return [200, self::format($name, $dir)];
    }

    private static function format(string $name, string $dir): array
    {
        $info = @file_get_contents("{$dir}/plugin_info.php") ?: '';
        $read = function (string $var) use ($info): ?string {
            return preg_match('/\$' . $var . '\s*=\s*([\'"])(.*?)\1\s*;/s', $info, $m) ? stripcslashes($m[2]) : null;
        };
        return [
            'name' => $name,
            'version' => $read('plugin_version'),
            'description' => $read('plugin_description'),
            'helpUrl' => preg_match('#^https?://#i', (string)$read('plugin_help_url')) ? $read('plugin_help_url') : null,
            'enabled' => !file_exists("{$dir}/plugin_disabled"),
            'hasInstaller' => file_exists("{$dir}/install.php"),
            'local' => str_contains($dir, '/local/plugins/'),
        ];
    }
}
