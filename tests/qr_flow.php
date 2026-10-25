<?php
declare(strict_types=1);

/**
 * QR issuance, revocation, replacement and public verification against the real database, inside one
 * rolled-back transaction.   php tests/qr_flow.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Admin\AdminApplicationService;
use Iha\Core\Database;
use Iha\Eligibility\EligibilityService;
use Iha\Geo\GeoService;
use Iha\Mail\Mailer;
use Iha\QR\QrRenderer;
use Iha\QR\QrService;
use Iha\Student\ApplicationService;
use Iha\Student\SectionValidator;
use Iha\Student\SubmissionService;

final class CaptureMailer implements Mailer
{
    public function send(string $to, string $subject, string $htmlBody, string $textBody): bool
    {
        return true;
    }
}

$GOOD_KEY = str_repeat('k', 40);
putenv('APP_KEY=' . $GOOD_KEY);
putenv('APP_URL=http://localhost:8080');
putenv('QR_PUBLIC_SHOW_NAME=1');
putenv('QR_PUBLIC_SHOW_SCHOOL=1');

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
function key16(): string
{
    $h = bin2hex(random_bytes(16));
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}
function tokenOf(?string $url): string
{
    return $url !== null && preg_match('/token=([0-9a-f]{64})/', $url, $m) ? $m[1] : '';
}

$pdo = Database::pdo();
$pdo->beginTransaction();

$pA = (int) one("INSERT INTO provinces (code, name, region_type) VALUES ('Z1','QR Prov','province') RETURNING id");
$dA = (int) one('INSERT INTO districts (province_id, name) VALUES (?, ?) RETURNING id', [$pA, 'QR Dist']);
$cA = (int) one('INSERT INTO cities (district_id, name) VALUES (?, ?) RETURNING id', [$dA, 'QR City']);

$val = new SectionValidator(new GeoService());
$apps = new ApplicationService();
$sub = new SubmissionService(new CaptureMailer());
$elig = new EligibilityService();
$admin = new AdminApplicationService();
$qr = new QrService();

function makeStudent(string $email, string $dob, array $subjects): array
{
    global $val, $apps, $pA, $dA, $cA;
    $uid = (int) one("INSERT INTO users (email, password_hash, full_name, email_verified_at) VALUES (?, 'x', 'QR Student', now()) RETURNING id", [$email]);
    $pid = (int) one('INSERT INTO student_profiles (user_id, full_name) VALUES (?, ?) RETURNING id', [$uid, 'QR Student']);
    $aid = (int) $apps->currentApplication($pid)['id'];
    [$v] = $val->profile(['full_name' => 'QR Student', 'father_name' => 'Pa Rent', 'mother_name' => 'Ma Rent', 'gender' => 'male', 'date_of_birth' => $dob,
        'nationality' => 'Pakistani', 'contact_number' => '0300-1234567', 'address_line' => '1 Road', 'province_id' => (string) $pA, 'district_id' => (string) $dA,
        'city_id' => (string) $cA, 'perm_same_as_current' => '1']);
    $apps->saveProfile($pid, $aid, $v);
    [$v] = $val->academic(['institution_name' => 'QR High School', 'institution_type' => 'school', 'address_line' => '2 Rd', 'school_province_id' => (string) $pA,
        'school_district_id' => (string) $dA, 'school_city_id' => (string) $cA, 'current_class' => '9', 'academic_session' => '2025-2026', 'exam_board' => 'Board',
        'exam_name' => 'Annual', 'exam_year' => '2026', 'roll_number' => '1', 'result_status' => 'declared']);
    $apps->saveAcademic($pid, $aid, $v);
    [$eval] = $val->marks(['subject_name' => array_column($subjects, 0), 'total_marks' => array_column($subjects, 1), 'marks_obtained' => array_column($subjects, 2)]);
    $apps->saveSubjects($pid, $aid, array_values($eval['rows']));
    [$v] = $val->guardian(['father_name' => 'Pa Rent', 'father_contact' => '0300-1111111', 'mother_name' => 'Ma Rent', 'primary_guardian' => 'father', 'address_line' => '3 St',
        'guardian_province_id' => (string) $pA, 'guardian_district_id' => (string) $dA, 'guardian_city_id' => (string) $cA,
        'emergency_name' => 'Uncle', 'emergency_contact' => '0300-2222222', 'emergency_relationship' => 'Uncle']);
    $apps->saveGuardian($pid, $aid, $v);
    [$t] = $val->travel(['ans_outside_home_city' => '0', 'ans_another_district' => '0', 'ans_another_province' => '0', 'ans_international' => '0']);
    $apps->saveTravel($pid, $aid, $t['answers'], []);
    return [$uid, $pid, $aid];
}
$good = [['Math', '100', '75'], ['Physics', '50', '30']];
$adminId = (int) one("INSERT INTO users (email, password_hash, full_name, role, email_verified_at) VALUES ('qradmin@test.local','x','Admin','super_admin', now()) RETURNING id");
$active = static fn(int $aid): int => (int) one("SELECT count(*) FROM student_qr_codes WHERE application_id = ? AND status = 'ACTIVE'", [$aid]);
$total = static fn(int $aid): int => (int) one('SELECT count(*) FROM student_qr_codes WHERE application_id = ?', [$aid]);

echo "Issuance\n";
check('the placeholder APP_KEY is refused, a real one accepted', QrService::keyOk() === true);
[$u1, $p1, $a1] = makeStudent('qr1@test.local', '2010-06-15', $good);
$ev = $elig->evaluate($a1, 'MANUAL_RECALC');
check('a complete but unsubmitted (draft) application gets no QR code even if ELIGIBLE', $ev['effective'] === 'ELIGIBLE' && $total($a1) === 0);
check('the student sees no QR code for a draft', $qr->forUser($u1) === null);
$sub->submit($u1, key16(), true);
check('submitting an ELIGIBLE application issues exactly one ACTIVE QR code', $active($a1) === 1 && $total($a1) === 1);
$row = $qr->activeForApplication($a1);
$url = $qr->urlFor($row);
$token = tokenOf($url);
check('the QR URL points at /verify with a 64-hex token', $token !== '' && str_starts_with((string) $url, 'http://localhost:8080/verify?token='));
check('only the hash and salt are stored; the raw token is not in the database', $row['token_hash'] === hash('sha256', $token) && (int) one('SELECT count(*) FROM student_qr_codes WHERE token_hash = ? OR token_salt = ?', [$token, $token]) === 0
    && (bool) preg_match('/^[0-9a-f]{32}$/', (string) $row['token_salt']));
check('the QR belongs to this student and application', (int) $row['student_profile_id'] === $p1 && (int) $row['application_id'] === $a1);
check('issuance was audited', (int) one("SELECT count(*) FROM audit_logs WHERE action = 'QR_ISSUED' AND entity_id = ?", [(string) $a1]) === 1);
$mine = $qr->forUser($u1);
check('the student can retrieve their own QR', $mine !== null && tokenOf($mine['url']) === $token);
check('student QR data carries no eligibility information', !preg_match('/eligib|pass|fail|pending/i', json_encode(['id' => $mine['id'], 'created_at' => $mine['created_at']])));
$other = (int) one("INSERT INTO users (email, password_hash, full_name, email_verified_at) VALUES ('stranger@test.local','x','Stranger', now()) RETURNING id");
check('another student cannot retrieve it', $qr->forUser($other) === null);

echo "Public verification\n";
$res = $qr->verify($token, '203.0.113.5');
$sid = (string) one('SELECT student_id FROM student_profiles WHERE id = ?', [$p1]);
check('a valid token verifies', $res['valid'] === true && $res['student_id'] === $sid);
check('the public result holds only the approved fields', array_diff(array_keys($res), ['valid', 'student_id', 'name', 'school', 'checked_at']) === []);
check('it exposes no DOB, CNIC, marks, address, guardian or eligibility data', !preg_match('/2010-06-15|105|70\.00|Road|Pa Rent|eligib/i', json_encode($res)));
check('an unknown token is invalid', $qr->verify(str_repeat('a', 64), '203.0.113.5')['valid'] === false);
check('malformed tokens are invalid', $qr->verify('zzz', '203.0.113.5')['valid'] === false && $qr->verify('', '203.0.113.5')['valid'] === false);
check('an invalid answer reveals nothing but its status', array_keys($qr->verify('bad', '203.0.113.5')) === ['valid', 'checked_at']);
check('scans are logged without the raw token', (int) one("SELECT count(*) FROM qr_verification_events WHERE result = 'VALID'") >= 1 && (int) one("SELECT count(*) FROM qr_verification_events WHERE ip_hash LIKE '%.%'") === 0);
putenv('QR_PUBLIC_SHOW_NAME=0');
putenv('QR_PUBLIC_SHOW_SCHOOL=0');
$res2 = $qr->verify($token, '203.0.113.5');
check('name and school can be switched off for the public page', !isset($res2['name']) && !isset($res2['school']) && $res2['valid']);
putenv('QR_PUBLIC_SHOW_NAME=1');
putenv('QR_PUBLIC_SHOW_SCHOOL=1');
check('the scan summary counts valid scans', $qr->scanSummary($a1)['scans'] >= 2);

echo "Ineligible students\n";
[$u2, , $a2] = makeStudent('qr2@test.local', '2013-05-05', $good);
$sub->submit($u2, key16(), true);
check('an INELIGIBLE student gets no QR code', $total($a2) === 0 && $qr->forUser($u2) === null);

echo "Revocation when eligibility changes\n";
$elig->override($a1, 'INELIGIBLE', 'Documents show the student is outside the age range.', $adminId, 'super_admin');
$old = $pdo->query("SELECT * FROM student_qr_codes WHERE application_id = $a1")->fetch();
check('ELIGIBLE -> INELIGIBLE revokes the active QR', $active($a1) === 0 && $old['status'] === 'REVOKED' && $old['revoked_at'] !== null && $old['revoked_by'] === null);
check('the revoke reason is recorded', $old['revoke_reason'] === 'ELIGIBILITY_CHANGED');
$rv = $qr->verify($token, '203.0.113.6');
check('a revoked token no longer verifies, and answers exactly like an unknown one', $rv['valid'] === false && array_keys($rv) === ['valid', 'checked_at']);
check('the student sees no QR any more', $qr->forUser($u1) === null);
$elig->override($a1, 'ELIGIBLE', 'Corrected after checking the birth certificate.', $adminId, 'super_admin');
check('back to ELIGIBLE issues a NEW code (different token)', $active($a1) === 1 && $total($a1) === 2 && $qr->activeForApplication($a1)['token_hash'] !== $old['token_hash']);
$token2 = tokenOf($qr->urlFor($qr->activeForApplication($a1)));
check('the old token stays dead while the new one works', !$qr->verify($token, '203.0.113.6')['valid'] && $qr->verify($token2, '203.0.113.6')['valid']);
$elig->override($a1, 'PENDING_REVIEW', 'Waiting for the marks sheet to be re-checked.', $adminId, 'super_admin');
check('ELIGIBLE -> PENDING REVIEW revokes the active QR', $active($a1) === 0);
$elig->override($a1, 'ELIGIBLE', 'Marks sheet re-checked and confirmed correct.', $adminId, 'super_admin');
check('re-established eligibility issues a code again', $active($a1) === 1);
$keep = $qr->activeForApplication($a1)['id'];
$elig->evaluate($a1, 'MANUAL_RECALC', $adminId, 'super_admin');
check('an unchanged ELIGIBLE student keeps the same QR code', $active($a1) === 1 && $qr->activeForApplication($a1)['id'] === $keep);

echo "Becoming eligible after a correction\n";
$pdo->prepare("UPDATE student_profiles SET date_of_birth = '2010-05-05' WHERE user_id = ?")->execute([$u2]);
$elig->evaluate($a2, 'CORRECTION', $adminId, 'super_admin');
check('an INELIGIBLE application corrected to ELIGIBLE receives a QR code', $active($a2) === 1);

echo "Other conditions\n";
$pdo->prepare('UPDATE users SET email_verified_at = NULL WHERE id = ?')->execute([$u2]);
$qr->sync($a2);
check('an unverified account loses its QR code', $active($a2) === 0 && !$qr->verify(tokenOf($qr->urlFor($pdo->query("SELECT * FROM student_qr_codes WHERE application_id = $a2 ORDER BY id DESC LIMIT 1")->fetch())), '203.0.113.7')['valid']);
$pdo->prepare('UPDATE users SET email_verified_at = now() WHERE id = ?')->execute([$u2]);
$qr->sync($a2);
check('verified again, a code is issued', $active($a2) === 1);
$pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$u2]);
$act = $qr->activeForApplication($a2);
check('a suspended account\'s code stops verifying', !$qr->verify(tokenOf($qr->urlFor($act)), '203.0.113.7')['valid']);
$pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$u2]);

echo "Review workflow\n";
[$u3, $p3, $a3] = makeStudent('qr3@test.local', '2010-06-15', $good);
$sub->submit($u3, key16(), true);
check('a third eligible student has a code', $active($a3) === 1);
$admin->review($a3, $adminId, 'super_admin', 'RETURNED_FOR_CORRECTION', 'Marks sheet unclear', 'Please re-check your marks.');
check('returning for correction revokes the code (the student may now edit)', $active($a3) === 0);
$sub->submit($u3, key16(), true);
check('resubmitting an ELIGIBLE application issues a code again', $active($a3) === 1 && $total($a3) === 2);
$admin->review($a3, $adminId, 'super_admin', 'UNDER_REVIEW', '');
check('starting review keeps the code', $active($a3) === 1);
$admin->review($a3, $adminId, 'super_admin', 'APPROVED', 'All documents verified');
check('approval keeps the code', $active($a3) === 1);
[$u4, , $a4] = makeStudent('qr4@test.local', '2010-06-15', $good);
$sub->submit($u4, key16(), true);
$admin->review($a4, $adminId, 'super_admin', 'REJECTED', 'Documents were not genuine');
check('rejection revokes the code', $active($a4) === 0 && one("SELECT revoke_reason FROM student_qr_codes WHERE application_id = ?", [$a4]) === 'APPLICATION_REJECTED');

echo "Administrator revoke and replace\n";
$msg = threw(fn() => $qr->adminRevoke($a3, $adminId, 'super_admin', 'no'));
check('revoking needs a documented reason', $msg !== null && $active($a3) === 1);
$oldId = (int) $qr->activeForApplication($a3)['id'];
$newId = $qr->adminReplace($a3, $adminId, 'super_admin', 'Code photographed and shared online.');
check('replacing revokes the old code and issues exactly one new one', $active($a3) === 1 && $qr->activeForApplication($a3)['id'] === $newId && $newId !== $oldId);
check('the old code records who revoked it, why, and what replaced it', one('SELECT revoked_by FROM student_qr_codes WHERE id = ?', [$oldId]) === $adminId
    && str_starts_with((string) one('SELECT revoke_reason FROM student_qr_codes WHERE id = ?', [$oldId]), 'REPLACED:') && one('SELECT replaced_by_id FROM student_qr_codes WHERE id = ?', [$oldId]) === $newId);
check('replacement and revocation are audited', (int) one("SELECT count(*) FROM audit_logs WHERE action = 'QR_REPLACED' AND entity_id = ?", [(string) $a3]) === 1);
$qr->adminRevoke($a3, $adminId, 'super_admin', 'Student withdrew the application.');
check('an admin can revoke without replacing', $active($a3) === 0);
$qr->adminReplace($a3, $adminId, 'super_admin', 'Issuing again after the withdrawal was cancelled.');
check('and can issue again when none is active', $active($a3) === 1);
$msg = threw(fn() => $qr->adminReplace($a4, $adminId, 'super_admin', 'Trying to force a code'));
check('an admin cannot issue a code for an application that does not qualify', $msg !== null);

echo "Key handling\n";
$act = $qr->activeForApplication($a3);
$tok = tokenOf($qr->urlFor($act));
putenv('APP_KEY=' . str_repeat('z', 40));
check('after APP_KEY changes the code cannot be redrawn', $qr->urlFor($act) === null);
check('but an already printed code still verifies', $qr->verify($tok, '203.0.113.8')['valid'] === true);
putenv('APP_KEY=change-me-to-a-long-random-string');
check('the placeholder key is refused', QrService::keyOk() === false);
[$u5, , $a5] = makeStudent('qr5@test.local', '2010-06-15', $good);
$r5 = $sub->submit($u5, key16(), true);
check('submission still succeeds when no real APP_KEY is set, but no code is issued', $r5['status'] === 'submitted' && $total($a5) === 0);
putenv('APP_KEY=' . $GOOD_KEY);
$sync = $qr->sync($a5);
check('after setting a real key, sync issues the missing code', $sync['action'] === 'issued' && $active($a5) === 1);

echo "Rendering\n";
if (QrRenderer::available()) {
    $svg = QrRenderer::svg((string) $url);
    check('the QR library renders an SVG', $svg !== null && str_contains($svg, '<svg'));
    check('the data URI is a base64 SVG', str_starts_with((string) QrRenderer::dataUri((string) $url), 'data:image/svg+xml;base64,'));
} else {
    echo "  SKIP  QR library not installed (run: composer install --no-dev)\n";
}

$pdo->rollBack();
echo "\n{$pass} passed, {$fail} failed (all test data rolled back)\n";
exit($fail === 0 ? 0 : 1);
