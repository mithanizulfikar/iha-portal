<?php
declare(strict_types=1);

/**
 * Submission, eligibility recording, privacy and admin review against the real database,
 * inside one rolled-back transaction.   php tests/submission_flow.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Admin\AdminApplicationService;
use Iha\Admin\Permissions;
use Iha\Core\Database;
use Iha\Eligibility\EligibilityService;
use Iha\Geo\GeoService;
use Iha\Mail\Mailer;
use Iha\Student\ApplicationService;
use Iha\Student\SectionValidator;
use Iha\Student\SubmissionService;

final class CaptureMailer implements Mailer
{
    public array $sent = [];
    public function send(string $to, string $subject, string $htmlBody, string $textBody): bool
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $htmlBody, 'text' => $textBody];
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
function key16(): string
{
    $h = bin2hex(random_bytes(16));
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

$pdo = Database::pdo();
$pdo->beginTransaction();

$pA = (int) one("INSERT INTO provinces (code, name, region_type) VALUES ('Y1','Sub Prov','province') RETURNING id");
$dA = (int) one('INSERT INTO districts (province_id, name) VALUES (?, ?) RETURNING id', [$pA, 'Sub Dist']);
$cA = (int) one('INSERT INTO cities (district_id, name) VALUES (?, ?) RETURNING id', [$dA, 'Sub City']);

$geo = new GeoService();
$val = new SectionValidator($geo);
$apps = new ApplicationService();
$mail = new CaptureMailer();
$sub = new SubmissionService($mail);
$adminSvc = new AdminApplicationService();
$elig = new EligibilityService();

/** Create a verified student and fill all five sections. Returns [userId, profileId, appId]. */
function makeStudent(string $email, string $dob, array $subjects, bool $complete = true): array
{
    global $pdo, $val, $apps, $pA, $dA, $cA;
    $uid = (int) one("INSERT INTO users (email, password_hash, full_name, email_verified_at) VALUES (?, 'x', 'Test Student', now()) RETURNING id", [$email]);
    $pid = (int) one('INSERT INTO student_profiles (user_id, full_name) VALUES (?, ?) RETURNING id', [$uid, 'Test Student']);
    $app = $apps->currentApplication($pid);
    $aid = (int) $app['id'];

    [$v] = $val->profile([
        'full_name' => 'Test Student', 'father_name' => 'Pa Rent', 'mother_name' => 'Ma Rent', 'gender' => 'female', 'date_of_birth' => $dob,
        'nationality' => 'Pakistani', 'contact_number' => '0300-1234567', 'address_line' => '1 Road',
        'province_id' => (string) $pA, 'district_id' => (string) $dA, 'city_id' => (string) $cA, 'perm_same_as_current' => '1',
    ]);
    $apps->saveProfile($pid, $aid, $v);
    if ($complete) {
        [$v] = $val->academic([
            'institution_name' => 'Test School', 'institution_type' => 'school', 'address_line' => '2 Rd',
            'school_province_id' => (string) $pA, 'school_district_id' => (string) $dA, 'school_city_id' => (string) $cA,
            'current_class' => '9', 'academic_session' => '2025-2026', 'exam_board' => 'Board', 'exam_name' => 'Annual', 'exam_year' => '2026',
            'roll_number' => '1', 'result_status' => 'declared',
        ]);
        $apps->saveAcademic($pid, $aid, $v);
        [$eval] = $val->marks(['subject_name' => array_column($subjects, 0), 'total_marks' => array_column($subjects, 1), 'marks_obtained' => array_column($subjects, 2)]);
        $apps->saveSubjects($pid, $aid, array_values($eval['rows']));
        [$v] = $val->guardian([
            'father_name' => 'Pa Rent', 'father_contact' => '0300-1111111', 'mother_name' => 'Ma Rent', 'primary_guardian' => 'father', 'address_line' => '3 St',
            'guardian_province_id' => (string) $pA, 'guardian_district_id' => (string) $dA, 'guardian_city_id' => (string) $cA,
            'emergency_name' => 'Uncle', 'emergency_contact' => '0300-2222222', 'emergency_relationship' => 'Uncle',
        ]);
        $apps->saveGuardian($pid, $aid, $v);
        [$t] = $val->travel(['ans_outside_home_city' => '0', 'ans_another_district' => '0', 'ans_another_province' => '0', 'ans_international' => '0']);
        $apps->saveTravel($pid, $aid, $t['answers'], []);
    }
    return [$uid, $pid, $aid];
}
$good = [['Math', '100', '75'], ['Physics', '50', '30']];     // 105/150 = 70.00%

echo "Submission guards\n";
[$u0, , $a0] = makeStudent('incomplete@test.local', '2010-06-15', $good, false);
$r = $sub->submit($u0, key16(), true);
check('incomplete application is refused and stays DRAFT', $r['status'] === 'blocked' && one('SELECT status FROM applications WHERE id = ?', [$a0]) === 'DRAFT');
check('refusal lists the incomplete sections', count($r['errors']) >= 4);
check('no eligibility record is created for a refused submission', (int) one('SELECT count(*) FROM eligibility_results WHERE application_id = ?', [$a0]) === 0);

[$u1, $p1, $a1] = makeStudent('ok@test.local', '2010-06-15', $good);
$r = $sub->submit($u1, key16(), false);
check('submission without the declaration is refused', $r['status'] === 'blocked' && one('SELECT status FROM applications WHERE id = ?', [$a1]) === 'DRAFT');
$r = $sub->submit($u1, 'not-a-key', true);
check('a malformed submission key is refused', $r['status'] === 'blocked');
$pdo->prepare('UPDATE users SET email_verified_at = NULL WHERE id = ?')->execute([$u1]);
$r = $sub->submit($u1, key16(), true);
check('an unverified email cannot submit', $r['status'] === 'blocked');
$pdo->prepare('UPDATE users SET email_verified_at = now() WHERE id = ?')->execute([$u1]);

echo "Eligible student submits\n";
$mail->sent = [];
$k = key16();
$r = $sub->submit($u1, $k, true);
check('submission succeeds', $r['status'] === 'submitted' && str_starts_with($r['application_number'], 'IHA-APP-'));
check('application status becomes SUBMITTED with a timestamp', one('SELECT status FROM applications WHERE id = ?', [$a1]) === 'SUBMITTED' && one('SELECT submitted_at FROM applications WHERE id = ?', [$a1]) !== null);
check('declaration time and submission key are stored', one('SELECT declaration_accepted_at FROM applications WHERE id = ?', [$a1]) !== null && one('SELECT submission_key::text FROM applications WHERE id = ?', [$a1]) === $k);
check('eligibility was calculated and stored (ELIGIBLE, both PASS, 70.00)', one('SELECT calculated_status FROM eligibility_results WHERE application_id = ?', [$a1]) === 'ELIGIBLE'
    && one('SELECT dob_criterion || academic_criterion FROM eligibility_results WHERE application_id = ?', [$a1]) === 'PASSPASS'
    && one('SELECT calculated_percentage::text FROM eligibility_results WHERE application_id = ?', [$a1]) === '70.00');
check('effective status recorded separately', one('SELECT effective_status FROM application_eligibility WHERE application_id = ?', [$a1]) === 'ELIGIBLE');
check('one notification and one confirmation email', (int) one('SELECT count(*) FROM notifications WHERE user_id = ?', [$u1]) === 1 && count($mail->sent) === 1);
check('notification email_status marked SENT', one('SELECT email_status FROM notifications WHERE user_id = ?', [$u1]) === 'SENT');

echo "Double submission\n";
$again = $sub->submit($u1, $k, true);
$again2 = $sub->submit($u1, key16(), true);
check('repeating the same request is harmless', $again['status'] === 'already' && $again2['status'] === 'already');
check('still exactly one result, one notification, one email', (int) one('SELECT count(*) FROM eligibility_results WHERE application_id = ?', [$a1]) === 1
    && (int) one('SELECT count(*) FROM notifications WHERE user_id = ?', [$u1]) === 1 && count($mail->sent) === 1);

echo "Student-facing privacy\n";
$noElig = static fn(string $s): bool => !preg_match('/eligib|pending review|\bpass\b|\bfail\b/i', $s);
$n = $pdo->prepare('SELECT title, body FROM notifications WHERE user_id = ?');
$n->execute([$u1]);
$row = $n->fetch();
check('notification text never mentions eligibility', $noElig($row['title'] . ' ' . $row['body']));
check('confirmation email (html and text) never mentions eligibility', $noElig($mail->sent[0]['html'] . $mail->sent[0]['text']));
check('confirmation email carries the application number and status', str_contains($mail->sent[0]['text'], $r['application_number']) && str_contains($mail->sent[0]['text'], 'SUBMITTED'));
$review = $sub->reviewData($u1);
check('student review data contains no eligibility information', $noElig(json_encode($review)));
check('student review data shows only the workflow status', $review['app']['status'] === 'SUBMITTED' && $review['editable'] === false);
check('a submitted application can no longer be edited by the student', ApplicationService::isEditable($review['app']) === false);

echo "Ineligible and boundary cases\n";
[$u2, , $a2] = makeStudent('late@test.local', '2013-05-05', $good);
$sub->submit($u2, key16(), true);
check('DOB outside the range => INELIGIBLE (DOB FAIL, academic PASS)', one('SELECT calculated_status || dob_criterion || academic_criterion FROM eligibility_results WHERE application_id = ?', [$a2]) === 'INELIGIBLEFAILPASS');
check('an ineligible student still gets SUBMITTED status and a neutral notification', one('SELECT status FROM applications WHERE id = ?', [$a2]) === 'SUBMITTED' && $noElig((string) one('SELECT body FROM notifications WHERE user_id = ?', [$u2])));
[$u3, , $a3] = makeStudent('low@test.local', '2010-06-15', [['All', '100', '59.99']]);
$sub->submit($u3, key16(), true);
check('59.99% => INELIGIBLE (academic FAIL)', one('SELECT calculated_status || academic_criterion FROM eligibility_results WHERE application_id = ?', [$a3]) === 'INELIGIBLEFAIL');
[$u4, , $a4] = makeStudent('edge@test.local', '2009-02-02', [['All', '100', '60']]);
$sub->submit($u4, key16(), true);
check('earliest DOB boundary with exactly 60% => ELIGIBLE', one('SELECT calculated_status FROM eligibility_results WHERE application_id = ?', [$a4]) === 'ELIGIBLE');
[$u5, , $a5] = makeStudent('edge2@test.local', '2012-02-02', [['All', '100', '60']]);
$sub->submit($u5, key16(), true);
check('latest DOB boundary with exactly 60% => ELIGIBLE', one('SELECT calculated_status FROM eligibility_results WHERE application_id = ?', [$a5]) === 'ELIGIBLE');

echo "Admin: permissions\n";
$adminId = (int) one("INSERT INTO users (email, password_hash, full_name, role, email_verified_at) VALUES ('admin@test.local','x','Admin','super_admin', now()) RETURNING id");
$revId = (int) one("INSERT INTO users (email, password_hash, full_name, role, email_verified_at) VALUES ('rev@test.local','x','Reviewer','application_reviewer', now()) RETURNING id");
Permissions::forget();
check('super admin has override and eligibility view', Permissions::has('super_admin', 'eligibility.override') && Permissions::has('super_admin', 'eligibility.view'));
check('reviewer can review and view eligibility but cannot override or change rules', Permissions::has('application_reviewer', 'applications.review') && Permissions::has('application_reviewer', 'eligibility.view')
    && !Permissions::has('application_reviewer', 'eligibility.override') && !Permissions::has('application_reviewer', 'eligibility.rules'));
check('report viewer cannot view applications, eligibility, PII or review', !Permissions::has('report_viewer', 'applications.view') && !Permissions::has('report_viewer', 'eligibility.view')
    && !Permissions::has('report_viewer', 'pii.view') && !Permissions::has('report_viewer', 'applications.review'));

echo "Admin: list and detail\n";
$perm = Permissions::flags('super_admin');
$list = $adminSvc->listApplications(['eligibility' => 'INELIGIBLE'], $perm, 1);
$ids = array_column($list['rows'], 'id');
check('eligibility filter finds the ineligible applications only', in_array($a2, $ids) && in_array($a3, $ids) && !in_array($a1, $ids));
$list = $adminSvc->listApplications(['q' => 'IHA-PK-'], $perm, 1);
check('search by Student ID prefix works', $list['total'] >= 5);
$one = $adminSvc->listApplications(['q' => 'ok@test.local'], $perm, 1);
check('search by email works for roles with PII access', $one['total'] === 1 && $one['rows'][0]['effective_status'] === 'ELIGIBLE');
$noPii = $adminSvc->listApplications(['q' => 'ok@test.local'], Permissions::flags('report_viewer'), 1);
check('email search is ignored without PII permission', $noPii['total'] !== 1 || $noPii['rows'][0]['id'] !== $a1);
$pctFilter = $adminSvc->listApplications(['pct_max' => '59.99'], $perm, 1);
check('academic percentage filter works', in_array($a3, array_column($pctFilter['rows'], 'id')) && !in_array($a1, array_column($pctFilter['rows'], 'id')));
$d = $adminSvc->detail($a2, $perm);
check('admin detail shows calculated result, criteria and reason', $d['elig']['current']['calculated_status'] === 'INELIGIBLE' && $d['elig']['current']['dob_criterion'] === 'FAIL' && str_contains((string) $d['elig']['current']['reason'], '2013-05-05'));
$dLimited = $adminSvc->detail($a2, ['pii' => false, 'elig' => false, 'review' => false, 'override' => false, 'audit' => false, 'export' => false]);
check('without permissions the detail view omits PII and eligibility entirely', $dLimited['pii'] === null && $dLimited['elig'] === null);

echo "Admin: resolve with override\n";
$msg = threw(fn() => $elig->override($a2, 'ELIGIBLE', 'short', $adminId, 'super_admin'));
check('override without a proper reason is refused', $msg !== null);
$beforeResults = (int) one('SELECT count(*) FROM eligibility_results WHERE application_id = ?', [$a2]);
$elig->override($a2, 'ELIGIBLE', 'Birth certificate verified; DOB was mistyped.', $adminId, 'super_admin');
check('override sets the effective status', one('SELECT effective_status FROM application_eligibility WHERE application_id = ?', [$a2]) === 'ELIGIBLE');
check('the calculated result is untouched and no result was rewritten', one('SELECT calculated_status FROM eligibility_results WHERE id = (SELECT latest_result_id FROM application_eligibility WHERE application_id = ?)', [$a2]) === 'INELIGIBLE'
    && (int) one('SELECT count(*) FROM eligibility_results WHERE application_id = ?', [$a2]) === $beforeResults);
check('the override is recorded with its reason and author', one('SELECT count(*) FROM eligibility_overrides WHERE application_id = ? AND created_by = ?', [$a2, $adminId]) === 1);
check('the override is audited', (int) one("SELECT count(*) FROM audit_logs WHERE action = 'ELIGIBILITY_OVERRIDE_SET' AND entity_id = ?", [(string) $a2]) === 1);
$same = $elig->evaluate($a2, 'MANUAL_RECALC', $adminId, 'super_admin');
check('recalculating with unchanged facts keeps the override', $same['effective'] === 'ELIGIBLE' && $same['calculated'] === 'INELIGIBLE' && !$same['override_cleared']);
$pdo->prepare("UPDATE student_profiles SET date_of_birth = '2010-05-05' WHERE user_id = ?")->execute([$u2]);
$changed = $elig->evaluate($a2, 'CORRECTION', $adminId, 'super_admin');
check('after a correction changes the calculated outcome the old override is cleared', $changed['calculated'] === 'ELIGIBLE' && $changed['override_cleared'] === true
    && one('SELECT override_id FROM application_eligibility WHERE application_id = ?', [$a2]) === null);
check('history keeps every evaluation', (int) one('SELECT count(*) FROM eligibility_results WHERE application_id = ?', [$a2]) === $beforeResults + 2);

echo "Admin: review workflow\n";
$msg = threw(fn() => $adminSvc->review($a3, $revId, 'application_reviewer', 'APPROVED', 'Looks fine to me', ''));
check('approving an INELIGIBLE application is refused', $msg !== null && str_contains($msg, 'Cannot approve') && one('SELECT status FROM applications WHERE id = ?', [$a3]) === 'SUBMITTED');
$adminSvc->review($a1, $revId, 'application_reviewer', 'UNDER_REVIEW', '');
check('start review moves SUBMITTED to UNDER_REVIEW', one('SELECT status FROM applications WHERE id = ?', [$a1]) === 'UNDER_REVIEW');
check('the student is notified with neutral text', $noElig(implode(' ', $pdo->query("SELECT body FROM notifications WHERE user_id = $u1")->fetchAll(PDO::FETCH_COLUMN))));
$msg = threw(fn() => $adminSvc->review($a1, $revId, 'application_reviewer', 'REJECTED', 'no', ''));
check('a rejection needs a real reason', $msg !== null);
$adminSvc->review($a1, $revId, 'application_reviewer', 'RETURNED_FOR_CORRECTION', 'Marks sheet unclear', 'Please re-check your subject marks and submit again.');
check('return for correction sets the status and reopens editing', one('SELECT status FROM applications WHERE id = ?', [$a1]) === 'RETURNED_FOR_CORRECTION' && ApplicationService::isEditable($apps->currentApplication($p1)));
check('the student sees the staff message but not the internal reason', in_array('Please re-check your subject marks and submit again.', $pdo->query("SELECT body FROM notifications WHERE user_id = $u1")->fetchAll(PDO::FETCH_COLUMN), true)
    && (int) one("SELECT count(*) FROM notifications WHERE user_id = ? AND body LIKE '%Marks sheet unclear%'", [$u1]) === 0);
$res = $sub->submit($u1, key16(), true);
check('the student can resubmit after a return, and eligibility is evaluated again', $res['status'] === 'submitted' && (int) one('SELECT count(*) FROM eligibility_results WHERE application_id = ?', [$a1]) === 2);
$adminSvc->review($a1, $adminId, 'super_admin', 'APPROVED', 'All documents verified');
check('an ELIGIBLE application can be approved', one('SELECT status FROM applications WHERE id = ?', [$a1]) === 'APPROVED');
$msg = threw(fn() => $adminSvc->review($a1, $adminId, 'super_admin', 'REJECTED', 'Changed my mind'));
check('a final decision cannot be changed', $msg !== null);
$adminSvc->addNote($a1, $revId, 'application_reviewer', 'Internal: parent phoned to confirm details.');
check('internal notes are stored for admins and never copied into notifications', (int) one('SELECT count(*) FROM review_notes WHERE application_id = ?', [$a1]) === 1
    && (int) one("SELECT count(*) FROM notifications WHERE body LIKE '%parent phoned%'") === 0);
check('every review decision is audited', (int) one("SELECT count(*) FROM audit_logs WHERE entity_id = ? AND action LIKE 'APPLICATION_%'", [(string) $a1]) >= 4);

$pdo->rollBack();
echo "\n{$pass} passed, {$fail} failed (all test data rolled back)\n";
exit($fail === 0 ? 0 : 1);
