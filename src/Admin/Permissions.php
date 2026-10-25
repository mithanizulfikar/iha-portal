<?php
declare(strict_types=1);

namespace Iha\Admin;

use Iha\Core\Database;
use Iha\Core\Response;

/** Permission checks against role_permissions (data, not hard-coded role names). Always enforced server-side. */
final class Permissions
{
    private static array $cache = [];

    public static function has(string $role, string $code): bool
    {
        if (!isset(self::$cache[$role])) {
            $st = Database::pdo()->prepare('SELECT permission_code FROM role_permissions WHERE role = ?');
            $st->execute([$role]);
            self::$cache[$role] = array_flip($st->fetchAll(\PDO::FETCH_COLUMN));
        }
        return isset(self::$cache[$role][$code]);
    }

    public static function require(array $user, string $code): void
    {
        if (!self::has($user['role'], $code)) {
            Response::error(403, 'Access denied', 'Your role does not include permission for this page or action.');
            exit;
        }
    }

    /** @return array<string,bool> */
    public static function flags(string $role): array
    {
        return [
            'pii'      => self::has($role, 'pii.view'),
            'elig'     => self::has($role, 'eligibility.view'),
            'review'   => self::has($role, 'applications.review'),
            'override' => self::has($role, 'eligibility.override'),
            'audit'    => self::has($role, 'audit.view'),
            'export'   => self::has($role, 'applications.export'),
            'qr'       => self::has($role, 'qr.manage'),
            'rules'    => self::has($role, 'eligibility.rules'),
            'reports'  => self::has($role, 'reports.view'),
        ];
    }

    public static function forget(): void
    {
        self::$cache = [];
    }
}
