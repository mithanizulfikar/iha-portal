<?php
declare(strict_types=1);

namespace Iha\Admin;

use Iha\Core\Audit;
use Iha\Core\Database;
use PDO;

/** Administrator accounts: create, change role, suspend/reactivate, reset to a temporary password. Students are not managed here. */
final class UserAdminService
{
    public const ROLES = ['super_admin', 'application_reviewer', 'report_viewer'];

    public function list(): array
    {
        return Database::pdo()->query(
            "SELECT id, email, full_name, role, status, last_login_at, created_at, must_change_password
             FROM users WHERE role <> 'student' ORDER BY (status = 'active') DESC, role, full_name"
        )->fetchAll();
    }

    /** @return array{id:int,password:string} the temporary password is shown to the creator once and never stored in plain text */
    public function create(string $email, string $name, string $role, int $actorId, string $actorRole): array
    {
        $email = mb_strtolower(trim($email));
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Enter a valid email address.');
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || !preg_match('/^[\p{L}\p{M}\s.\'\-]+$/u', $name)) {
            throw new \InvalidArgumentException('Enter the full name (2 to 100 letters).');
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Choose a valid role.');
        }
        $password = self::tempPassword();

        return Database::transaction(function (PDO $pdo) use ($email, $name, $role, $password, $actorId, $actorRole): array {
            $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
            $st->execute([$email]);
            if ($st->fetchColumn() !== false) {
                throw new \InvalidArgumentException('An account with that email address already exists.');
            }
            $st = $pdo->prepare(
                'INSERT INTO users (email, password_hash, full_name, role, email_verified_at, must_change_password) VALUES (?, ?, ?, ?, now(), TRUE) RETURNING id'
            );
            $st->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name, $role]);
            $id = (int) $st->fetchColumn();
            Audit::log('ADMIN_CREATED', 'user', $id, $actorId, $actorRole, null, ['email' => $email, 'role' => $role]);
            return ['id' => $id, 'password' => $password];
        });
    }

    public function changeRole(int $targetId, string $role, int $actorId, string $actorRole): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Choose a valid role.');
        }
        Database::transaction(function (PDO $pdo) use ($targetId, $role, $actorId, $actorRole): void {
            $t = $this->lockAdmin($targetId);
            if ($targetId === $actorId) {
                throw new \InvalidArgumentException('You cannot change your own role.');
            }
            if ($t['role'] === $role) {
                throw new \InvalidArgumentException('That account already has this role.');
            }
            if ($t['role'] === 'super_admin' && $t['status'] === 'active') {
                $this->requireAnotherSuperAdmin($targetId);
            }
            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $targetId]);
            Audit::log('ADMIN_ROLE_CHANGED', 'user', $targetId, $actorId, $actorRole, ['role' => $t['role']], ['role' => $role]);
        });
    }

    public function setStatus(int $targetId, string $status, int $actorId, string $actorRole): void
    {
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new \InvalidArgumentException('Invalid status.');
        }
        Database::transaction(function (PDO $pdo) use ($targetId, $status, $actorId, $actorRole): void {
            $t = $this->lockAdmin($targetId);
            if ($status === 'suspended') {
                if ($targetId === $actorId) {
                    throw new \InvalidArgumentException('You cannot suspend your own account.');
                }
                if ($t['role'] === 'super_admin' && $t['status'] === 'active') {
                    $this->requireAnotherSuperAdmin($targetId);
                }
            }
            if ($t['status'] === $status) {
                throw new \InvalidArgumentException('The account is already ' . $status . '.');
            }
            $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $targetId]);
            Audit::log($status === 'suspended' ? 'ADMIN_SUSPENDED' : 'ADMIN_REACTIVATED', 'user', $targetId, $actorId, $actorRole, ['status' => $t['status']], ['status' => $status]);
        });
    }

    /** Give an administrator a new temporary password (they must change it at next login; their other sessions end). */
    public function resetPassword(int $targetId, int $actorId, string $actorRole): string
    {
        if ($targetId === $actorId) {
            throw new \InvalidArgumentException('Use "Change password" for your own account.');
        }
        $password = self::tempPassword();
        Database::transaction(function (PDO $pdo) use ($targetId, $actorId, $actorRole, $password): void {
            $this->lockAdmin($targetId);
            $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = TRUE, password_changed_at = now() WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
            Audit::log('ADMIN_PASSWORD_RESET', 'user', $targetId, $actorId, $actorRole);
        });
        return $password;
    }

    /** 16 characters, no look-alike letters, always with letters and digits. */
    public static function tempPassword(): string
    {
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
        $digits = '23456789';
        $all = $letters . $digits;
        do {
            $p = '';
            for ($i = 0; $i < 16; $i++) {
                $p .= $all[random_int(0, strlen($all) - 1)];
            }
        } while (!preg_match('/\d/', $p) || !preg_match('/[A-Za-z]/', $p));
        return $p;
    }

    private function lockAdmin(int $id): array
    {
        $st = Database::pdo()->prepare("SELECT id, role, status FROM users WHERE id = ? AND role <> 'student' FOR UPDATE");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            throw new \InvalidArgumentException('That administrator account was not found.');
        }
        return $row;
    }

    private function requireAnotherSuperAdmin(int $excludeId): void
    {
        $st = Database::pdo()->prepare("SELECT id FROM users WHERE role = 'super_admin' AND status = 'active' AND id <> ? FOR UPDATE");
        $st->execute([$excludeId]);
        if ($st->fetch() === false) {
            throw new \InvalidArgumentException('At least one active Super Admin must remain.');
        }
    }
}
