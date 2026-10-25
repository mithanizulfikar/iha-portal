<?php
declare(strict_types=1);

/** Forgot-password and change-password services against the real database (rolled back).   php tests/password_flow.php */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Auth\AuthService;
use Iha\Auth\PasswordService;
use Iha\Auth\RateLimiter;
use Iha\Core\Database;
use Iha\Mail\Mailer;

final class CaptureMailer implements Mailer
{
    public array $sent = [];
    public function send(string $to, string $subject, string $htmlBody, string $textBody): bool
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $textBody, 'html' => $htmlBody];
        return true;
    }
    public function lastToken(): ?string
    {
        $last = end($this->sent);
        return $last && preg_match('/token=([0-9a-f]{64})/', $last['text'], $m) ? $m[1] : null;
    }
}

putenv('APP_URL=http://localhost:8080');

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
}
function one(string $sql, array $p = []): mixed
{
    $s = Database::pdo()->prepare($sql);
    $s->execute($p);
    return $s->fetchColumn();
}

$pdo = Database::pdo();
$pdo->beginTransaction();
$mail = new CaptureMailer();
$limiter = new RateLimiter();
$auth = new AuthService($mail, $limiter);
$pw = new PasswordService($mail, $limiter, $auth);

$OLD = 'OldPassword123';
$mk = static fn(string $email, string $role = 'student', string $status = 'active'): int => (int) one(
    'INSERT INTO users (email, password_hash, full_name, role, status, email_verified_at) VALUES (?, ?, ?, ?, ?, now()) RETURNING id',
    [$email, password_hash($OLD, PASSWORD_DEFAULT), 'Pat Word', $role, $status]
);
$u1 = $mk('pw1@test.local');
$admin = $mk('pwadmin@test.local', 'super_admin');
$suspended = $mk('pwsusp@test.local', 'student', 'suspended');

echo "Password rules\n";
check('a good password passes', AuthService::passwordErrors('GoodPass12345', 'GoodPass12345') === []);
check('too short, no digit, mismatch are each rejected', isset(AuthService::passwordErrors('short1', 'short1')['password']) && isset(AuthService::passwordErrors('onlyletterspass', 'onlyletterspass')['password'])
    && isset(AuthService::passwordErrors('GoodPass12345', 'Different12345')['password_confirm']));
check('over 72 bytes is rejected', isset(AuthService::passwordErrors(str_repeat('a1', 40), str_repeat('a1', 40))['password']));

echo "Requesting a reset link\n";
$pw->requestReset('pw1@test.local');
$tok = $mail->lastToken();
check('a registered student gets one email with a 64-hex link', count($mail->sent) === 1 && $tok !== null && $mail->sent[0]['to'] === 'pw1@test.local');
check('only the hash is stored, the raw token is not in the database', (int) one('SELECT count(*) FROM password_reset_tokens WHERE token_hash = ?', [hash('sha256', (string) $tok)]) === 1
    && (int) one('SELECT count(*) FROM password_reset_tokens WHERE token_hash = ?', [str_repeat('0', 64)]) === 0);
check('the link is valid and expires in the future', $pw->tokenValid((string) $tok) && (bool) one('SELECT expires_at > now() FROM password_reset_tokens WHERE user_id = ?', [$u1]));
$before = count($mail->sent);
$pw->requestReset('nobody@test.local');
$pw->requestReset('not-an-email');
$pw->requestReset('pwadmin@test.local');
$pw->requestReset('pwsusp@test.local');
check('unknown, malformed, administrator and suspended addresses get no email', count($mail->sent) === $before);
$pw->requestReset('PW1@test.local');
$tok2 = $mail->lastToken();
check('asking again replaces the earlier link (email case-insensitive)', $tok2 !== $tok && !$pw->tokenValid((string) $tok) && $pw->tokenValid((string) $tok2));
$pw->requestReset('pw1@test.local');
$pw->requestReset('pw1@test.local');
$sentToPw1 = count(array_filter($mail->sent, static fn($m) => $m['to'] === 'pw1@test.local'));
check('requests are capped at 3 per hour per address', $sentToPw1 === 3);
$tok3 = (string) $mail->lastToken();

echo "Resetting\n";
check('a malformed token is invalid', !$pw->tokenValid('abc') && ($pw->resetPassword('abc', 'NewPassword12', 'NewPassword12')['invalid'] ?? false) === true);
$r = $pw->resetPassword($tok3, 'weak', 'weak');
check('a weak password is refused and the link is NOT used up', $r['ok'] === false && isset($r['errors']['password']) && $pw->tokenValid($tok3));
$r = $pw->resetPassword($tok3, 'NewPassword12', 'Mismatch12345');
check('a mismatched confirmation is refused', $r['ok'] === false && isset($r['errors']['password_confirm']) && $pw->tokenValid($tok3));
$before = count($mail->sent);
$r = $pw->resetPassword($tok3, 'NewPassword12', 'NewPassword12');
check('a good password resets the account', $r['ok'] === true && password_verify('NewPassword12', (string) one('SELECT password_hash FROM users WHERE id = ?', [$u1])));
check('the old password no longer works', !password_verify($OLD, (string) one('SELECT password_hash FROM users WHERE id = ?', [$u1])));
check('password_changed_at is set (other sessions will be signed out)', one('SELECT password_changed_at FROM users WHERE id = ?', [$u1]) !== null);
check('the link is single-use and any other links are gone', !$pw->tokenValid($tok3) && (int) one('SELECT count(*) FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL', [$u1]) === 0
    && ($pw->resetPassword($tok3, 'Another12345', 'Another12345')['invalid'] ?? false) === true);
check('a security notice is emailed, and it contains no link or password', count($mail->sent) === $before + 1 && !str_contains($mail->sent[$before]['text'], 'token=') && !str_contains($mail->sent[$before]['text'], 'NewPassword12'));
check('the reset was audited', (int) one("SELECT count(*) FROM audit_logs WHERE action = 'PASSWORD_RESET' AND entity_id = ?", [(string) $u1]) === 1);
$login = $auth->attemptLogin('pw1@test.local', 'NewPassword12', ['student'], '203.0.113.50', 'login');
check('the student can now log in with the new password', $login['ok'] === true);

echo "Expired link\n";
$pw->requestReset('pwsusp@test.local');
$u2 = $mk('pw2@test.local');
$pw->requestReset('pw2@test.local');
$old = (string) $mail->lastToken();
$pdo->prepare("UPDATE password_reset_tokens SET expires_at = now() - interval '1 minute' WHERE user_id = ?")->execute([$u2]);
check('an expired link is invalid and cannot reset', !$pw->tokenValid($old) && ($pw->resetPassword($old, 'NewPassword12', 'NewPassword12')['invalid'] ?? false) === true
    && password_verify($OLD, (string) one('SELECT password_hash FROM users WHERE id = ?', [$u2])));

echo "Changing a password while signed in\n";
$r = $pw->changePassword($admin, 'WrongPassword1', 'AdminNewPass123', 'AdminNewPass123');
check('a wrong current password is refused', $r['ok'] === false && isset($r['errors']['current_password']));
$r = $pw->changePassword($admin, $OLD, 'short', 'short');
check('a weak new password is refused', $r['ok'] === false && isset($r['errors']['password']));
$r = $pw->changePassword($admin, $OLD, 'Mismatch12345', 'Different12345');
check('a mismatched confirmation is refused', $r['ok'] === false && isset($r['errors']['password_confirm']));
$r = $pw->changePassword($admin, $OLD, $OLD, $OLD);
check('the new password must differ from the current one', $r['ok'] === false && isset($r['errors']['password']));
check('nothing changed after the refusals', password_verify($OLD, (string) one('SELECT password_hash FROM users WHERE id = ?', [$admin])) && one('SELECT password_changed_at FROM users WHERE id = ?', [$admin]) === null);
$before = count($mail->sent);
$r = $pw->changePassword($admin, $OLD, 'AdminNewPass123', 'AdminNewPass123');
check('a correct change works for an administrator too', $r['ok'] === true && password_verify('AdminNewPass123', (string) one('SELECT password_hash FROM users WHERE id = ?', [$admin])));
check('it returns the new session epoch and records the time', is_int($r['epoch']) && $r['epoch'] > 0 && one('SELECT password_changed_at FROM users WHERE id = ?', [$admin]) !== null);
check('a notice is emailed and the change audited', count($mail->sent) === $before + 1 && (int) one("SELECT count(*) FROM audit_logs WHERE action = 'PASSWORD_CHANGED' AND entity_id = ?", [(string) $admin]) === 1);

$u3 = $mk('pw3@test.local');
for ($i = 0; $i < 5; $i++) {
    $pw->changePassword($u3, 'WrongPassword1', 'NewPassword12', 'NewPassword12');
}
$r = $pw->changePassword($u3, $OLD, 'NewPassword12', 'NewPassword12');
check('after 5 wrong attempts even the right current password is blocked for a while', $r['ok'] === false && str_contains((string) ($r['errors']['current_password'] ?? ''), 'Too many'));
check('the password stayed unchanged', password_verify($OLD, (string) one('SELECT password_hash FROM users WHERE id = ?', [$u3])));

$pdo->rollBack();
echo "\n{$pass} passed, {$fail} failed (all test data rolled back)\n";
exit($fail === 0 ? 0 : 1);
