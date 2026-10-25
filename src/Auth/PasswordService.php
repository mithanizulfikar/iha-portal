<?php
declare(strict_types=1);

namespace Iha\Auth;

use Iha\Core\Audit;
use Iha\Core\Database;
use Iha\Core\Env;
use Iha\Core\View;
use Iha\Mail\Mailer;
use PDO;

/** Forgot-password (students) and change-password (any signed-in user). */
final class PasswordService
{
    public const RESET_TTL_MINUTES = 60;

    public function __construct(private Mailer $mailer, private RateLimiter $limiter, private AuthService $auth) {}

    /**
     * Email a reset link if the address belongs to an active student account. Always returns quietly, so
     * the page can show the same message whether or not the address is registered. Administrator accounts
     * are not reset by email (use bin/set-password.php or Change Password).
     */
    public function requestReset(string $email): void
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        if (!$this->limiter->attempt('forgot', RateLimiter::key($email), 3, 3600)) {
            return;
        }
        $user = $this->auth->findByEmail($email);
        if ($user === null || $user['role'] !== 'student' || $user['status'] !== 'active') {
            return;
        }

        $raw = bin2hex(random_bytes(32));
        Database::transaction(function (PDO $pdo) use ($user, $raw): void {
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL')->execute([$user['id']]);
            $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, now() + (?::int * interval '1 minute'))")
                ->execute([$user['id'], hash('sha256', $raw), self::RESET_TTL_MINUTES]);
            Audit::log('PASSWORD_RESET_REQUESTED', 'user', (int) $user['id'], (int) $user['id'], 'student');
        });

        $link = $this->url('/reset-password?token=' . $raw);
        $this->mailer->send(
            $email,
            'Reset your IHA Portal password',
            View::capture('emails/reset', ['name' => $user['full_name'], 'link' => $link, 'minutes' => self::RESET_TTL_MINUTES]),
            "Hello {$user['full_name']},\n\nUse this link to choose a new password:\n\n{$link}\n\nIt expires in " . self::RESET_TTL_MINUTES
            . " minutes and works once. If you did not ask for this, ignore this email; your password has not changed."
        );
    }

    /** True while the link is unused, unexpired and belongs to an active student. Does not consume it. */
    public function tokenValid(string $raw): bool
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $raw)) {
            return false;
        }
        $st = Database::pdo()->prepare(
            "SELECT 1 FROM password_reset_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > now() AND u.status = 'active' AND u.role = 'student'"
        );
        $st->execute([hash('sha256', $raw)]);
        return $st->fetchColumn() !== false;
    }

    /** @return array{ok:bool, invalid?:bool, errors?:array<string,string>} */
    public function resetPassword(string $raw, string $password, string $confirm): array
    {
        $errors = AuthService::passwordErrors($password, $confirm);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];          // the link is not used up by a weak password
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $raw)) {
            return ['ok' => false, 'invalid' => true];
        }

        $user = Database::transaction(function (PDO $pdo) use ($raw, $password): ?array {
            $st = $pdo->prepare(
                "SELECT t.id AS token_id, u.id, u.email, u.full_name FROM password_reset_tokens t JOIN users u ON u.id = t.user_id
                 WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > now() AND u.status = 'active' AND u.role = 'student'
                 FOR UPDATE OF t"
            );
            $st->execute([hash('sha256', $raw)]);
            $row = $st->fetch();
            if (!$row) {
                return null;
            }
            $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = now(), must_change_password = FALSE WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
            $pdo->prepare('UPDATE password_reset_tokens SET used_at = now() WHERE id = ?')->execute([$row['token_id']]);
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL')->execute([$row['id']]);
            Audit::log('PASSWORD_RESET', 'user', (int) $row['id'], (int) $row['id'], 'student');
            return $row;
        });
        if ($user === null) {
            return ['ok' => false, 'invalid' => true];
        }
        $this->notice($user['email'], $user['full_name']);
        return ['ok' => true];
    }

    /** @return array{ok:bool, errors?:array<string,string>, epoch?:int} */
    public function changePassword(int $userId, string $current, string $new, string $confirm): array
    {
        $key = RateLimiter::key('pwchange|' . $userId);
        if ($this->limiter->tooMany('pwchange', $key, 5, 900)) {
            return ['ok' => false, 'errors' => ['current_password' => 'Too many attempts. Please wait 15 minutes and try again.']];
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT email, full_name, role, password_hash FROM users WHERE id = ?');
        $st->execute([$userId]);
        $u = $st->fetch();
        if (!$u || !password_verify($current, $u['password_hash'])) {
            $this->limiter->hit('pwchange', $key);
            return ['ok' => false, 'errors' => ['current_password' => 'Your current password is not correct.']];
        }
        $errors = AuthService::passwordErrors($new, $confirm);
        if (!$errors && $new === $current) {
            $errors['password'] = 'Choose a password different from your current one.';
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        Database::transaction(function (PDO $pdo) use ($userId, $new, $u): void {
            $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = now(), must_change_password = FALSE WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
            Audit::log('PASSWORD_CHANGED', 'user', $userId, $userId, $u['role']);
        });
        $this->limiter->clear('pwchange', $key);
        $st = $pdo->prepare('SELECT EXTRACT(EPOCH FROM password_changed_at)::bigint FROM users WHERE id = ?');
        $st->execute([$userId]);
        $this->notice($u['email'], $u['full_name']);
        return ['ok' => true, 'epoch' => (int) $st->fetchColumn()];
    }

    private function notice(string $email, string $name): void
    {
        try {
            $this->mailer->send(
                $email,
                'Your IHA Portal password was changed',
                View::capture('emails/password_changed', ['name' => $name]),
                "Hello {$name},\n\nThe password for your IHA Portal account was just changed. If this was you, no action is needed.\n"
                . "If it was not you, reset your password straight away using the Forgot password link: " . $this->url('/forgot-password') . "\n"
            );
        } catch (\Throwable $e) {
            error_log('Password notice email failed: ' . $e->getMessage());
        }
    }

    private function url(string $path): string
    {
        return rtrim((string) Env::get('APP_URL', 'http://localhost:8080'), '/') . $path;
    }
}
