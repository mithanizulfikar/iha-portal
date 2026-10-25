<?php
declare(strict_types=1);

/**
 * Tests the registration / verification / login service against the real database, inside one
 * transaction that is rolled back. No emails are sent (a capturing mailer is used).
 *     php tests/auth_flow.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Auth\AuthService;
use Iha\Auth\RateLimiter;
use Iha\Core\Database;
use Iha\Mail\Mailer;

final class CaptureMailer implements Mailer
{
    /** @var array<int, array{to:string,subject:string,text:string}> */
    public array $sent = [];
    public function send(string $to, string $subject, string $htmlBody, string $textBody): bool
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $textBody];
        return true;
    }
    public function lastToken(): ?string
    {
        $last = end($this->sent);
        return $last && preg_match('/token=([0-9a-f]{64})/', $last['text'], $m) ? $m[1] : null;
    }
}

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
$svc = new AuthService($mail, new RateLimiter());
$good = 'Password12345';

echo "Validation\n";
[, $e] = $svc->validateRegistration('A', 'not-an-email', 'short', 'x', false);
check('bad name, email, password, confirmation and terms each produce an error', count($e) === 5);
[$clean, $e] = $svc->validateRegistration('  Test   User ', ' Test@Example.COM ', $good, $good, true);
check('valid input has no errors; email lower-cased, name whitespace collapsed', $e === [] && $clean['email'] === 'test@example.com' && $clean['full_name'] === 'Test User');
[, $e] = $svc->validateRegistration('Test User', 'a@b.co', 'onlyletterspassword', 'onlyletterspassword', true);
check('password without a number is rejected', isset($e['password']));
[, $e] = $svc->validateRegistration('Test User', 'a@b.co', str_repeat('a1', 40), str_repeat('a1', 40), true);
check('password over 72 bytes is rejected', isset($e['password']));

echo "Registration\n";
$svc->register('Test User', 'test@example.com', $good);
$uid = (int) one("SELECT id FROM users WHERE email = 'test@example.com'");
check('account created, role student, unverified', $uid > 0 && one('SELECT email_verified_at FROM users WHERE id = ?', [$uid]) === null && one('SELECT role FROM users WHERE id = ?', [$uid]) === 'student');
check('password is stored hashed, not plain', one('SELECT password_hash FROM users WHERE id = ?', [$uid]) !== $good && password_verify($good, (string) one('SELECT password_hash FROM users WHERE id = ?', [$uid])));
check('no student profile / Student ID before verification', (int) one('SELECT count(*) FROM student_profiles WHERE user_id = ?', [$uid]) === 0);
$token = $mail->lastToken();
check('one verification email sent containing a 64-hex token', count($mail->sent) === 1 && $token !== null);
check('only the token hash is stored (raw token is not in the database)', (int) one('SELECT count(*) FROM email_verification_tokens WHERE token_hash = ?', [hash('sha256', (string) $token)]) === 1);
check('verification tokens expire in the future', (bool) one('SELECT expires_at > now() FROM email_verification_tokens WHERE user_id = ?', [$uid]));

echo "Enumeration protection / duplicates\n";
$svc->register('Test User', 'test@example.com', 'AnotherPass999');
check('re-registering an unverified email creates no second account', (int) one("SELECT count(*) FROM users WHERE email = 'test@example.com'") === 1);
check('it sends a fresh verification link instead', count($mail->sent) === 2 && $mail->lastToken() !== $token);
check('the stored password is NOT overwritten by the second attempt', password_verify($good, (string) one('SELECT password_hash FROM users WHERE id = ?', [$uid])));
check('the earlier link no longer works', $svc->verify((string) $token) === null);

echo "Verification\n";
$token2 = (string) $mail->lastToken();
check('malformed token is rejected', $svc->verify('abc') === null);
check('valid token verifies the account', $svc->verify($token2) === $uid);
check('email is now marked verified', one('SELECT email_verified_at FROM users WHERE id = ?', [$uid]) !== null);
$sid = (string) one('SELECT student_id FROM student_profiles WHERE user_id = ?', [$uid]);
check('permanent Student ID created at verification, in IHA-PK-######## format', (bool) preg_match('/^IHA-PK-\d{8}$/', $sid));
check('token is single-use', $svc->verify($token2) === null);

$before = count($mail->sent);
$svc->register('Test User', 'test@example.com', $good);
check('registering a verified email creates no account and sends an "already registered" notice', (int) one("SELECT count(*) FROM users WHERE email = 'test@example.com'") === 1 && count($mail->sent) === $before + 1 && str_contains($mail->sent[$before]['subject'], 'already'));

echo "Expiry\n";
$svc->register('Late User', 'late@example.com', $good);
$lateToken = (string) $mail->lastToken();
$lateId = (int) one("SELECT id FROM users WHERE email = 'late@example.com'");
$pdo->prepare("UPDATE email_verification_tokens SET expires_at = now() - interval '1 minute' WHERE user_id = ?")->execute([$lateId]);
check('expired token is rejected', $svc->verify($lateToken) === null);
check('expired token leaves the account unverified with no profile', one('SELECT email_verified_at FROM users WHERE id = ?', [$lateId]) === null && (int) one('SELECT count(*) FROM student_profiles WHERE user_id = ?', [$lateId]) === 0);

echo "Resend limits\n";
$before = count($mail->sent);
for ($i = 0; $i < 6; $i++) {
    $svc->resendVerification('late@example.com');
}
check('resend is capped (max 3 links per hour per address)', count($mail->sent) - $before === 3);
$before = count($mail->sent);
$svc->resendVerification('nobody@example.com');
check('resend for an unknown address sends nothing', count($mail->sent) === $before);

echo "Login\n";
$ip = '203.0.113.9';
$r = $svc->attemptLogin('test@example.com', 'wrong-password', ['student'], $ip, 'login');
$r2 = $svc->attemptLogin('ghost@example.com', 'wrong-password', ['student'], $ip, 'login');
check('wrong password and unknown email give the same generic error', !$r['ok'] && !$r2['ok'] && $r['error'] === $r2['error'] && $r['error'] === 'Invalid email or password.');
$r = $svc->attemptLogin('test@example.com', $good, ['super_admin', 'application_reviewer', 'report_viewer'], '203.0.113.10', 'admin_login');
check('a student account cannot log in through the admin form', !$r['ok']);
$r = $svc->attemptLogin('Test@Example.com', $good, ['student'], '203.0.113.11', 'login');
check('correct credentials succeed (email case-insensitive)', $r['ok'] && $r['user_id'] === $uid);
check('last_login_at is recorded', one('SELECT last_login_at FROM users WHERE id = ?', [$uid]) !== null);
$pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$uid]);
$r = $svc->attemptLogin('test@example.com', $good, ['student'], '203.0.113.12', 'login');
check('suspended accounts cannot log in', !$r['ok']);

$ip2 = '198.51.100.7';
for ($i = 0; $i < 5; $i++) {
    $svc->attemptLogin('late@example.com', 'wrong-password', ['student'], $ip2, 'login');
}
$r = $svc->attemptLogin('late@example.com', $good, ['student'], $ip2, 'login');
check('after 5 failures even the correct password is blocked (rate limit)', !$r['ok'] && str_contains((string) $r['error'], 'Too many'));
check('rate-limit table stores hashed keys only', (int) one("SELECT count(*) FROM auth_rate_limits WHERE key_hash LIKE '%@%' OR key_hash LIKE '%.%'") === 0);

echo "Audit\n";
check('registration and verification were audited', (int) one("SELECT count(*) FROM audit_logs WHERE entity_type = 'user' AND entity_id = ? AND action IN ('USER_REGISTERED','EMAIL_VERIFIED')", [(string) $uid]) === 2);

$pdo->rollBack();
echo "\n{$pass} passed, {$fail} failed (all test data rolled back)\n";
exit($fail === 0 ? 0 : 1);
