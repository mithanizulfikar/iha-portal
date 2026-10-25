<?php
declare(strict_types=1);

namespace Iha\Auth;

use Iha\Core\Audit;
use Iha\Core\Database;
use Iha\Core\Env;
use Iha\Core\View;
use Iha\Mail\Mailer;

final class AuthService
{
    public const TOKEN_TTL_HOURS = 24;

    public function __construct(private Mailer $mailer, private RateLimiter $limiter) {}

    /** @return array{0: array{full_name:string,email:string}, 1: array<string,string>} cleaned input and field errors */
    public function validateRegistration(string $name, string $email, string $password, string $confirm, bool $acceptedTerms): array
    {
        $errors = [];
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        $email = mb_strtolower(trim($email));

        $len = mb_strlen($name);
        if ($len < 2 || $len > 100) {
            $errors['full_name'] = 'Enter your full name (2 to 100 characters).';
        } elseif (!preg_match('/^[\p{L}\p{M}\s.\'\-]+$/u', $name)) {
            $errors['full_name'] = 'Use letters, spaces, apostrophes, hyphens and full stops only.';
        }

        if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        $errors += self::passwordErrors($password, $confirm);
        if (!$acceptedTerms) {
            $errors['accept_terms'] = 'You must accept the Terms and Conditions and Privacy Policy.';
        }
        return [['full_name' => $name, 'email' => $email], $errors];
    }

    /** @return array<string,string> errors for the password and its confirmation (empty when fine) */
    public static function passwordErrors(string $password, string $confirm): array
    {
        $errors = [];
        if (strlen($password) < 10) {
            $errors['password'] = 'Password must be at least 10 characters.';
        } elseif (strlen($password) > 72) {
            $errors['password'] = 'Password must be at most 72 characters.';
        } elseif (!preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
            $errors['password'] = 'Password must include at least one letter and one number.';
        }
        if ($confirm !== $password) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }
        return $errors;
    }

    /**
     * Create an account, or quietly handle an already-registered email. The caller always shows
     * the same message, so the response never reveals whether an email is registered.
     */
    public function register(string $name, string $email, string $password): void
    {
        $existing = $this->findByEmail($email);

        if ($existing === null) {
            $raw = Database::transaction(function ($pdo) use ($name, $email, $password): string {
                $st = $pdo->prepare(
                    "INSERT INTO users (email, password_hash, full_name, role, terms_accepted_at)
                     VALUES (?, ?, ?, 'student', now()) RETURNING id"
                );
                $st->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name]);
                $id = (int) $st->fetchColumn();
                Audit::log('USER_REGISTERED', 'user', $id, $id, 'student');
                return $id . ':' . $this->issueToken($id);
            });
            [, $token] = explode(':', $raw, 2);
            $this->sendVerification($name, $email, $token);
            return;
        }

        // Existing account: act only if the per-email budget allows (counts real and fake emails alike).
        if (!$this->limiter->attempt('register_mail', RateLimiter::key($email), 3, 3600)) {
            return;
        }
        if ($existing['email_verified_at'] === null) {
            // Never overwrite the stored password: that would let a stranger hijack an unverified account.
            $token = $this->issueToken((int) $existing['id']);
            $this->sendVerification($existing['full_name'], $email, $token);
        } else {
            $this->mailer->send(
                $email,
                'You already have an IHA Portal account',
                View::capture('emails/account_exists', ['name' => $existing['full_name'], 'loginUrl' => $this->url('/login')]),
                "Hello {$existing['full_name']},\n\nSomeone (hopefully you) tried to register the IHA Portal with this email address, "
                . "but an account already exists.\n\nLog in here: " . $this->url('/login')
                . "\n\nIf this wasn't you, you can ignore this email."
            );
        }
    }

    public function resendVerification(string $email): void
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        if (!$this->limiter->attempt('resend', RateLimiter::key($email), 3, 3600)) {
            return;
        }
        $user = $this->findByEmail($email);
        if ($user === null || $user['email_verified_at'] !== null || $user['status'] !== 'active' || $user['role'] !== 'student') {
            return;
        }
        $this->sendVerification($user['full_name'], $email, $this->issueToken((int) $user['id']));
    }

    /**
     * Consume a verification token. Returns the user id on success, null for any invalid,
     * used or expired token (the caller shows one generic message for all of them).
     */
    public function verify(string $rawToken): ?int
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $rawToken)) {
            return null;
        }
        $hash = hash('sha256', $rawToken);

        return Database::transaction(function ($pdo) use ($hash): ?int {
            $st = $pdo->prepare(
                'SELECT t.id AS token_id, u.id AS user_id, u.status, u.role
                 FROM email_verification_tokens t JOIN users u ON u.id = t.user_id
                 WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > now()
                 FOR UPDATE OF t'
            );
            $st->execute([$hash]);
            $row = $st->fetch();
            if (!$row || $row['status'] !== 'active' || $row['role'] !== 'student') {
                return null;
            }
            $uid = (int) $row['user_id'];
            $pdo->prepare('UPDATE email_verification_tokens SET used_at = now() WHERE id = ?')->execute([$row['token_id']]);
            $pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, now()) WHERE id = ?')->execute([$uid]);
            // The permanent Student ID is created here (first profile creation), so unverified
            // sign-ups never consume IDs.
            $pdo->prepare(
                'INSERT INTO student_profiles (user_id, full_name)
                 SELECT id, full_name FROM users WHERE id = ?
                 ON CONFLICT (user_id) DO NOTHING'
            )->execute([$uid]);
            Audit::log('EMAIL_VERIFIED', 'user', $uid, $uid, 'student');
            return $uid;
        });
    }

    /**
     * @param string[] $roles roles allowed to log in through this form
     * @return array{ok:bool,error:?string,user_id:?int}
     */
    public function attemptLogin(string $email, string $password, array $roles, string $ip, string $bucket): array
    {
        $email = mb_strtolower(trim($email));
        $emailKey = RateLimiter::key($email . '|' . $ip);
        $ipKey = RateLimiter::key($ip);

        if ($this->limiter->tooMany($bucket, $emailKey, 5, 900) || $this->limiter->tooMany($bucket . '_ip', $ipKey, 30, 900)) {
            return ['ok' => false, 'error' => 'Too many login attempts. Please wait 15 minutes and try again.', 'user_id' => null];
        }

        $user = $this->findByEmail($email);
        $ok = false;
        if ($user !== null) {
            $ok = password_verify($password, $user['password_hash'])
                && $user['status'] === 'active'
                && in_array($user['role'], $roles, true);
        } else {
            password_hash($password, PASSWORD_DEFAULT); // equalise timing with the "user exists" path
        }

        if (!$ok) {
            $this->limiter->hit($bucket, $emailKey);
            $this->limiter->hit($bucket . '_ip', $ipKey);
            return ['ok' => false, 'error' => 'Invalid email or password.', 'user_id' => null];
        }

        $this->limiter->clear($bucket, $emailKey);
        $uid = (int) $user['id'];
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE users SET last_login_at = now() WHERE id = ?')->execute([$uid]);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
        }
        if ($user['role'] !== 'student') {
            Audit::log('ADMIN_LOGIN', 'user', $uid, $uid, $user['role']);
        }
        return ['ok' => true, 'error' => null, 'user_id' => $uid];
    }

    public function findByEmail(string $email): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT id, email, password_hash, full_name, role, status, email_verified_at FROM users WHERE email = ?'
        );
        $st->execute([mb_strtolower(trim($email))]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** Replaces any earlier unused tokens, so only the newest link works. Only the hash is stored. */
    private function issueToken(int $userId): string
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        $raw = bin2hex(random_bytes(32));
        $pdo->prepare(
            "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
             VALUES (?, ?, now() + (?::int * interval '1 hour'))"
        )->execute([$userId, hash('sha256', $raw), self::TOKEN_TTL_HOURS]);
        return $raw;
    }

    private function sendVerification(string $name, string $email, string $rawToken): void
    {
        $link = $this->url('/verify-email?token=' . $rawToken);
        $this->mailer->send(
            $email,
            'Verify your email for the IHA Portal',
            View::capture('emails/verify', ['name' => $name, 'link' => $link, 'hours' => self::TOKEN_TTL_HOURS]),
            "Hello {$name},\n\nPlease verify your email address to activate your IHA Portal account:\n\n{$link}\n\n"
            . 'This link expires in ' . self::TOKEN_TTL_HOURS . " hours and can be used once.\n"
            . "If you did not create an account, you can ignore this email."
        );
    }

    private function url(string $path): string
    {
        return rtrim((string) Env::get('APP_URL', 'http://localhost:8080'), '/') . $path;
    }
}
