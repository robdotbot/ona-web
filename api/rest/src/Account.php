<?php
namespace OnaRest;

/** The logged-in user's own account. */
class Account
{
    /**
     * POST /auth/password {currentPassword, newPassword}: change your own
     * password, as the classic UI's "user info" window does. Only for ONA's
     * local users; LDAP and other external accounts change it there.
     */
    public static function changePassword(Request $req): array
    {
        global $conf;
        $username = Auth::requireUser();
        $body = $req->body();
        $in = new Input($body);
        $current = $body['currentPassword'] ?? null;
        $new = $body['newPassword'] ?? null;
        if (!is_string($current) || $current === '') $in->error('currentPassword', 'is required');
        if (!is_string($new) || $new === '') $in->error('newPassword', 'is required');
        elseif (strlen($new) > 1024) $in->error('newPassword', 'must be at most 1024 characters');
        $in->check();

        if (($conf['authtype'] ?? 'local') !== 'local') {
            throw new ApiError(409, 'conflict', 'Passwords are managed by the external authentication system');
        }
        $rows = Ona::select('SELECT id, password FROM users WHERE username = ?', [$username]);
        if (!$rows || !hash_equals((string)$rows[0]['password'], md5($current))) {
            usleep(random_int(200000, 400000));
            throw ApiError::validation('Invalid input', ['currentPassword' => 'is not correct']);
        }
        if ($current === $new) throw ApiError::validation('Invalid input', ['newPassword' => 'must be different from the current password']);

        // Your own password needs no admin permission; ONA stores unsalted MD5.
        Ona::write([], "User changed own password: {$username}", function ($db) use ($rows, $new) {
            Ona::dbOk(db_update_record($db, 'users', ['id' => (int)$rows[0]['id']], ['password' => md5($new)]));
        });
        return [204, null];
    }
}
