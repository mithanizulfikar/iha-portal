<?php
declare(strict_types=1);

/** Administrator account management against the real database (rolled back).   php tests/admin_users_flow.php */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Admin\Permissions;
use Iha\Admin\UserAdminService;
use Iha\Auth\AuthService;
use Iha\Auth\PasswordService;
use Iha\Auth\RateLimiter;
use Iha\Core\Database;
use Iha\Mail\Mailer;

final class CaptureMailer implements Mailer
{
    public function send(string $to, string $subject, string $htmlBody, string $textBody): bool
    {
        return true;
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
function threw(callable $fn): ?string
{
    Database::pdo()->exec('SAVEPOINT thr');
    try {
        $fn();
        Database::pdo()->exec('RELEASE SAVEPOINT thr');
        return null;
    } catch (\Throwable $e) {
        Database::pdo()->exec('ROLLBACK TO SAVEPOINT thr');
        return $e->getMessage();
    }
}

$pdo = Database::pdo();
$pdo->beginTransaction();
$svc = new UserAdminService();
$limiter = new RateLimiter();
$auth = new AuthService(new CaptureMailer(), $limiter);
$pw = new PasswordService(new CaptureMailer(), $limiter, $auth);

// Make this test independent of any real accounts: keep only our own Super Admin active.
$pdo->exec("UPDATE users SET status = 'suspended' WHERE role = 'super_admin'");
$me = (int) one("INSERT INTO users (email, password_hash, full_name, role, email_verified_at) VALUES ('boss@test.local', 'x', 'Boss', 'super_admin', now()) RETURNING id");
$student = (int) one("INSERT INTO users (email, password_hash, full_name, email_verified_at) VALUES ('stud@test.local', 'x', 'Some Student', now()) RETURNING id");

echo "Creating accounts\n";
$tp = UserAdminService::tempPassword();
check('temporary passwords are 16 characters with letters and digits and meet the password rules', strlen($tp) === 16 && AuthService::passwordErrors($tp, $tp) === []);
check('temporary passwords are different each time', UserAdminService::tempPassword() !== UserAdminService::tempPassword());
$r = $svc->create(' Rev@Test.Local ', 'Rita  Reviewer', 'application_reviewer', $me, 'super_admin');
$rev = $r['id'];
check('an administrator is created with a normalised email and the chosen role', one('SELECT email FROM users WHERE id = ?', [$rev]) === 'rev@test.local' && one('SELECT role FROM users WHERE id = ?', [$rev]) === 'application_reviewer'
    && one('SELECT full_name FROM users WHERE id = ?', [$rev]) === 'Rita Reviewer');
check('the temporary password works, is stored only as a hash, and must be changed', password_verify($r['password'], (string) one('SELECT password_hash FROM users WHERE id = ?', [$rev])) && one('SELECT password_hash FROM users WHERE id = ?', [$rev]) !== $r['password']
    && one('SELECT must_change_password FROM users WHERE id = ?', [$rev]) === true);
check('the new administrator can log in through the admin form', $auth->attemptLogin('rev@test.local', $r['password'], ['super_admin', 'application_reviewer', 'report_viewer'], '203.0.113.60', 'admin_login')['ok'] === true);
check('creation was audited without the password', (int) one("SELECT count(*) FROM audit_logs WHERE action = 'ADMIN_CREATED' AND entity_id = ? AND new_values::text NOT LIKE ?", [(string) $rev, '%' . $r['password'] . '%']) === 1);
foreach ([['rev@test.local', 'Dup Person', 'report_viewer', 'duplicate email'], ['bad-email', 'Some Name', 'report_viewer', 'invalid email'], ['x@test.local', 'A', 'report_viewer', 'name too short'],
          ['y@test.local', 'Some Name', 'student', 'role "student"'], ['z@test.local', 'Some Name', 'root', 'unknown role']] as [$em, $nm, $ro, $why]) {
    check("rejected: {$why}", threw(fn() => $svc->create($em, $nm, $ro, $me, 'super_admin')) !== null);
}

echo "Must change the temporary password\n";
$res = $pw->changePassword($rev, $r['password'], 'MyOwnPassword123', 'MyOwnPassword123');
check('choosing a new password clears the "must change" flag', $res['ok'] === true && one('SELECT must_change_password FROM users WHERE id = ?', [$rev]) === false);

echo "Roles\n";
$svc->changeRole($rev, 'report_viewer', $me, 'super_admin');
check('a role can be changed', one('SELECT role FROM users WHERE id = ?', [$rev]) === 'report_viewer');
check('the change is audited with old and new role', (int) one("SELECT count(*) FROM audit_logs WHERE action = 'ADMIN_ROLE_CHANGED' AND entity_id = ? AND old_values::text LIKE '%application_reviewer%' AND new_values::text LIKE '%report_viewer%'", [(string) $rev]) === 1);
check('unknown roles and unchanged roles are refused', threw(fn() => $svc->changeRole($rev, 'root', $me, 'super_admin')) !== null && threw(fn() => $svc->changeRole($rev, 'report_viewer', $me, 'super_admin')) !== null);
check('you cannot change your own role', threw(fn() => $svc->changeRole($me, 'report_viewer', $me, 'super_admin')) !== null && one('SELECT role FROM users WHERE id = ?', [$me]) === 'super_admin');
check('students cannot be managed here', threw(fn() => $svc->changeRole($student, 'report_viewer', $me, 'super_admin')) !== null && threw(fn() => $svc->setStatus($student, 'suspended', $me, 'super_admin')) !== null
    && threw(fn() => $svc->resetPassword($student, $me, 'super_admin')) !== null && one('SELECT role FROM users WHERE id = ?', [$student]) === 'student');

echo "Suspending and reactivating\n";
$svc->setStatus($rev, 'suspended', $me, 'super_admin');
check('a suspended administrator cannot log in', one('SELECT status FROM users WHERE id = ?', [$rev]) === 'suspended'
    && $auth->attemptLogin('rev@test.local', 'MyOwnPassword123', ['super_admin', 'application_reviewer', 'report_viewer'], '203.0.113.61', 'admin_login')['ok'] === false);
$svc->setStatus($rev, 'active', $me, 'super_admin');
check('reactivating restores access', $auth->attemptLogin('rev@test.local', 'MyOwnPassword123', ['super_admin', 'application_reviewer', 'report_viewer'], '203.0.113.62', 'admin_login')['ok'] === true);
check('suspending an already suspended account or yourself is refused', threw(fn() => $svc->setStatus($rev, 'active', $me, 'super_admin')) !== null && threw(fn() => $svc->setStatus($me, 'suspended', $me, 'super_admin')) !== null);

echo "Never lock everyone out\n";
$second = $svc->create('boss2@test.local', 'Second Boss', 'super_admin', $me, 'super_admin')['id'];
$svc->setStatus($me, 'suspended', $second, 'super_admin');
check('one Super Admin can suspend another while a third remains active', one('SELECT status FROM users WHERE id = ?', [$me]) === 'suspended');
$m1 = threw(fn() => $svc->setStatus($second, 'suspended', $me, 'super_admin'));
check('the last active Super Admin cannot be suspended', $m1 !== null && str_contains($m1, 'Super Admin') && one('SELECT status FROM users WHERE id = ?', [$second]) === 'active');
$m2 = threw(fn() => $svc->changeRole($second, 'report_viewer', $me, 'super_admin'));
check('the last active Super Admin cannot be demoted', $m2 !== null && one('SELECT role FROM users WHERE id = ?', [$second]) === 'super_admin');
$svc->setStatus($me, 'active', $second, 'super_admin');
$svc->changeRole($me, 'application_reviewer', $second, 'super_admin');
check('with another active Super Admin a demotion is allowed', one('SELECT role FROM users WHERE id = ?', [$me]) === 'application_reviewer');

echo "Resetting a password\n";
$before = one('SELECT password_hash FROM users WHERE id = ?', [$rev]);
$temp = $svc->resetPassword($rev, $second, 'super_admin');
check('a new temporary password replaces the old one', $temp !== '' && password_verify($temp, (string) one('SELECT password_hash FROM users WHERE id = ?', [$rev])) && !password_verify('MyOwnPassword123', (string) one('SELECT password_hash FROM users WHERE id = ?', [$rev]))
    && one('SELECT password_hash FROM users WHERE id = ?', [$rev]) !== $before);
check('the account must change it, and older sessions are marked for sign-out', one('SELECT must_change_password FROM users WHERE id = ?', [$rev]) === true && one('SELECT password_changed_at FROM users WHERE id = ?', [$rev]) !== null);
check('the reset is audited and the password is not in the audit log', (int) one("SELECT count(*) FROM audit_logs WHERE action = 'ADMIN_PASSWORD_RESET' AND entity_id = ? AND coalesce(new_values::text, '') NOT LIKE ?", [(string) $rev, '%' . $temp . '%']) === 1);
check('you cannot reset your own password this way', threw(fn() => $svc->resetPassword($second, $second, 'super_admin')) !== null);

echo "Listing and permissions\n";
$list = $svc->list();
$emails = array_column($list, 'email');
check('the list shows administrators only, never students', in_array('rev@test.local', $emails, true) && !in_array('stud@test.local', $emails, true));
check('the list never contains password hashes', !array_key_exists('password_hash', $list[0]));
check('only the Super Admin role can manage administrator accounts', Permissions::has('super_admin', 'users.manage') && !Permissions::has('application_reviewer', 'users.manage') && !Permissions::has('report_viewer', 'users.manage'));

$pdo->rollBack();
echo "\n{$pass} passed, {$fail} failed (all test data rolled back)\n";
exit($fail === 0 ? 0 : 1);
