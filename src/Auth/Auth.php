<?php
declare(strict_types=1);

namespace Iha\Auth;

use Iha\Core\Csrf;
use Iha\Core\Database;
use Iha\Core\Response;
use Iha\Core\Session;

/** Who is logged in. The identity always comes from the server-side session, never from the browser. */
final class Auth
{
    public const IDLE_TIMEOUT = 1800; // 30 minutes
    public const ADMIN_ROLES = ['super_admin', 'application_reviewer', 'report_viewer'];

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function login(int $userId): void
    {
        Session::regenerate();
        Csrf::rotate();
        Session::set('uid', $userId);
        Session::set('last_activity', time());
        Session::set('auth_time', (int) Database::pdo()->query('SELECT EXTRACT(EPOCH FROM now())::bigint')->fetchColumn());
        self::$loaded = false;
        self::$user = null;
    }

    public static function logout(): void
    {
        Session::reset();
        self::$loaded = true;
        self::$user = null;
    }

    /** Current user (active accounts only) or null. */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $uid = Session::get('uid');
        if (!is_int($uid)) {
            return self::$user = null;
        }
        if (time() - (int) Session::get('last_activity', 0) > self::IDLE_TIMEOUT) {
            Session::reset();
            Session::flash('info', 'You were signed out after a period of inactivity. Please log in again.');
            return self::$user = null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, email, full_name, role, status, email_verified_at, must_change_password, EXTRACT(EPOCH FROM password_changed_at)::bigint AS pwd_changed FROM users WHERE id = ?'
        );
        $st->execute([$uid]);
        $row = $st->fetch();
        if (!$row || $row['status'] !== 'active') {
            Session::reset();
            return self::$user = null;
        }
        if ($row['pwd_changed'] !== null && (int) Session::get('auth_time', 0) < (int) $row['pwd_changed']) {
            Session::reset();
            Session::flash('info', 'Your password was changed. Please log in again.');
            return self::$user = null;
        }
        Session::set('last_activity', time());
        return self::$user = $row;
    }

    /**
     * Gate a page by role. Anonymous visitors go to $loginPath; a logged-in user with the wrong
     * role gets 403 (no redirect loop, no information about the other area).
     */
    public static function requireRole(array $roles, string $loginPath): array
    {
        $u = self::user();
        if ($u === null) {
            Response::redirect($loginPath);
        }
        if (!empty($u['must_change_password'])) {
            Session::flash('info', 'Please choose a new password before continuing.');
            Response::redirect('/account/password');
        }
        if (!in_array($u['role'], $roles, true)) {
            Response::error(403, 'Access denied', 'You do not have permission to view this page.');
            exit;
        }
        header('Cache-Control: no-store');
        return $u;
    }
}
