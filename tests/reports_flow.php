<?php
declare(strict_types=1);

/**
 * Dashboard counts, reports, CSV rules, eligibility rule changes and the audit log against the real
 * database, inside one rolled-back transaction.   php tests/reports_flow.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Admin\AdminApplicationService;
use Iha\Admin\AuditService;
use Iha\Admin\Permissions;
use Iha\Core\Database;
use Iha\Eligibility\EligibilityService;
use Iha\Eligibility\RuleService;
use Iha\Geo\GeoService;
use Iha\Mail\Mailer;
use Iha\QR\QrService;
use Iha\Reports\Csv;
use Iha\Reports\ReportService;
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

putenv('APP_KEY=' . str_repeat('k', 40));
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

$pA = (int) one("INSERT INTO provinces (code, name, region_type) VALUES ('R1','Rep Province','province') RETURNING id");
$dA = (int) one('INSERT INTO districts (province_id, name) VALUES (?, ?) RETURNING id', [$pA, 'Rep District']);
$cA = (int) one('INSERT INTO cities (district_id, name) VALUES (?, ?) RETURNING id', [$dA, 'Rep City']);

$val = new SectionValidator(new GeoService());
$apps = new ApplicationService();
$sub = new SubmissionService(new CaptureMailer());
$elig = new EligibilityService();
$rules = new RuleService();
$reports = new ReportService();
$adminSvc = new AdminApplicationService();
$SCHOOL = 'ZZ Report School';
$adminId = (int) one("INSERT INTO users (email, password_hash, full_name, role, email_verified_at) VALUES ('ruleadmin@test.local','x','Rule Admin','super_admin', now()) RETURNING id");

function makeStudent(string $email, string $dob, array $subjects, bool $complete = true): array
{
    global $val, $apps, $pA, $dA, $cA, $SCHOOL;
    $uid = (int) one("INSERT INTO users (email, password_hash, full_name, email_verified_at) VALUES (?, 'x', 'Rep Student', now()) RETURNING id", [$email]);
    $pid = (int) one('INSERT INTO student_profiles (user_id, full_name) VALUES (?, ?) RETURNING id', [$uid, 'Rep Student']);
    $aid = (int) $apps->currentApplication($pid)['id'];
    [$v] = $val->profile(['full_name' => 'Rep Student', 'father_name' => 'Pa Rent', 'mother_name' => 'Ma Rent', 'gender' => 'female', 'date_of_birth' => $dob,
        'nationality' => 'Pakistani', 'contact_number' => '0300-1234567', 'address_line' => '1 Road', 'province_id' => (string) $pA, 'district_id' => (string) $dA,
        'city_id' => (string) $cA, 'perm_same_as_current' => '1']);
    $apps->saveProfile($pid, $aid, $v);
    [$v] = $val->academic(['institution_name' => $SCHOOL, 'institution_type' => 'school', 'address_line' => '2 Rd', 'school_province_id' => (string) $pA,
        'school_district_id' => (string) $dA, 'school_city_id' => (string) $cA, 'current_class' => '9', 'academic_session' => '2025-2026', 'exam_board' => 'Board',
        'exam_name' => 'Annual', 'exam_year' => '2026', 'roll_number' => '1', 'result_status' => 'declared']);
    $apps->saveAcademic($pid, $aid, $v);
    [$eval] = $val->marks(['subject_name' => array_column($subjects, 0), 'total_marks' => array_column($subjects, 1), 'marks_obtained' => array_column($subjects, 2)]);
    $apps->saveSubjects($pid, $aid, array_values($eval['rows']));
    if ($complete) {
        [$v] = $val->guardian(['father_name' => 'Pa Rent', 'father_contact' => '0300-1111111', 'mother_name' => 'Ma Rent', 'primary_guardian' => 'father', 'address_line' => '3 St',
            'guardian_province_id' => (string) $pA, 'guardian_district_id' => (string) $dA, 'guardian_city_id' => (string) $cA,
            'emergency_name' => 'Uncle', 'emergency_contact' => '0300-2222222', 'emergency_relationship' => 'Uncle']);
        $apps->saveGuardian($pid, $aid, $v);
        [$t] = $val->travel(['ans_outside_home_city' => '0', 'ans_another_district' => '0', 'ans_another_province' => '0', 'ans_international' => '0']);
        $apps->saveTravel($pid, $aid, $t['answers'], []);
    }
    return [$uid, $pid, $aid];
}

$all = ['pii' => true, 'elig' => true, 'review' => true, 'override' => true, 'audit' => true, 'export' => true, 'qr' => true, 'rules' => true, 'reports' => true];

echo "Dashboard counts\n";
$before = $reports->dashboard($all);
[$u1, , $a1] = makeStudent('rep1@test.local', '2010-06-15', [['Math', '100', '75'], ['Physics', '50', '30']]);   // 70.00%, eligible
[$u2, , $a2] = makeStudent('rep2@test.local', '2010-06-15', [['Math', '100', '59.99']]);                          // ineligible
[$u3, , $a3] = makeStudent('rep3@test.local', '2010-06-15', [['Math', '100', '90']]);                             // stays a draft
$pdo->exec("INSERT INTO users (email, password_hash, full_name) VALUES ('rep4@test.local', 'x', 'Unverified Person')");   // unverified, no profile
$sub->submit($u1, key16(), true);
$sub->submit($u2, key16(), true);
$after = $reports->dashboard($all);
$d = static fn(string $grp, string $k): int => (int) $after[$grp][$k] - (int) $before[$grp][$k];
check('registered students +4 (3 verified, 1 unverified)', $d('general', 'students') === 4 && $d('general', 'verified') === 3 && $d('general', 'unverified') === 1);
check('applications +3, draft +1, submitted +2', $d('general', 'applications') === 3 && $d('general', 'draft') === 1 && $d('general', 'submitted') === 2);
check('eligible +1 and ineligible +1; the incomplete draft is not counted as ineligible', $d('elig', 'eligible') === 1 && $d('elig', 'ineligible') === 1 && $d('elig', 'pending') === 0);
check('one active QR code was added', $d('qr', 'active') === 1);
$limited = $reports->dashboard(['elig' => false]);
check('a role without eligibility access gets no eligibility or QR figures', $limited['elig'] === null && $limited['qr'] === null && isset($limited['general']['students']));

echo "Eligibility report\n";
$e = $reports->eligibility(['school' => $SCHOOL]);
check('filtered to the test school: 2 submitted, 1 eligible, 1 ineligible, 0 pending', (int) $e['submitted'] === 2 && (int) $e['eligible'] === 1 && (int) $e['ineligible'] === 1 && (int) $e['pending'] === 0);
check('eligibility percentage = eligible / evaluated = 50.00', $e['pct_eligible'] === '50.00' && $e['evaluated'] === 2);
check('QR figures match: 1 issued, 1 active, 0 revoked', $e['qr_issued'] === 1 && $e['qr_active'] === 1 && $e['qr_revoked'] === 0);
$none = $reports->eligibility(['school' => 'no such school']);
check('an empty selection gives zeros and no percentage', (int) $none['submitted'] === 0 && $none['pct_eligible'] === null);
$byProvince = $reports->eligibility(['province_id' => (string) $pA]);
check('province filter selects the same applications', (int) $byProvince['submitted'] === 2);
check('date filter works (future range is empty, today includes them)', (int) $reports->eligibility(['school' => $SCHOOL, 'date_from' => '2999-01-01'])['submitted'] === 0
    && (int) $reports->eligibility(['school' => $SCHOOL, 'date_from' => gmdate('Y-m-d'), 'date_to' => gmdate('Y-m-d')])['submitted'] === 2);
$elig->override($a2, 'ELIGIBLE', 'Marks re-totalled by the board; certificate seen.', $adminId, 'super_admin');
$e2 = $reports->eligibility(['school' => $SCHOOL]);
check('an override moves the application in the report and is counted', (int) $e2['eligible'] === 2 && (int) $e2['overridden'] === 1 && $e2['qr_issued'] === 2);

echo "Academic report\n";
$a = $reports->academic(['school' => $SCHOOL]);
check('2 applications with marks: 1 at or above 60%, 1 below', (int) $a['summary']['n'] === 2 && (int) $a['summary']['ge60'] === 1 && (int) $a['summary']['lt60'] === 1);
check('59.99% counts as below 60 (exact comparison)', $a['distribution']['50-59.99'] === 1 && $a['distribution']['70-79.99'] === 1);
check('the distribution covers every bucket and sums to the total', array_keys($a['distribution']) === ReportService::BUCKETS && array_sum($a['distribution']) === 2);
check('province, district, city and school breakdowns include the test data', $a['provinces'][0]['label'] === 'Rep Province' && $a['districts'][0]['label'] === 'Rep District'
    && $a['cities'][0]['label'] === 'Rep City' && $a['schools'][0]['label'] === $SCHOOL && (int) $a['schools'][0]['n'] === 2);
$subjects = array_column($a['subjects'], 'n', 'label');
check('subject-wise summary counts subjects (math twice, physics once)', (int) ($subjects['math'] ?? 0) === 2 && (int) ($subjects['physics'] ?? 0) === 1);
check('draft applications are excluded from academic reports', (int) $reports->academic(['school' => $SCHOOL])['summary']['n'] === 2);
$p2 = $reports->academic([], 99);
check('school paging is clamped to the last page', $p2['school_page'] === $p2['school_pages']);

echo "Registration report\n";
$today = gmdate('Y-m-d');
$r = $reports->registrations(['date_from' => $today, 'date_to' => $today]);
check('today\'s accounts are counted (at least our 4)', (int) $r['accounts']['total'] >= 4 && (int) $r['accounts']['verified'] >= 3 && (int) $r['accounts']['unverified'] >= 1);
check('daily series includes today with our sign-ups', ($r['daily'][$today] ?? 0) >= 4);
check('application counts for the day include the 1 draft and 2 submitted', (int) $r['applications']['draft'] >= 1 && (int) $r['applications']['submitted'] >= 2);
$default = $reports->registrations([]);
check('without dates: 30 daily points, weekly and monthly series present', count($default['daily']) === 30 && is_array($default['weekly']) && is_array($default['monthly']));
check('totals in the report equal the database', (int) $r['accounts']['total'] === (int) one("SELECT count(*) FROM users WHERE role = 'student' AND created_at >= ?::date AND created_at < (?::date + 1)", [$today, $today]));

echo "Applications export\n";
$rows = $adminSvc->exportApplications(['school' => $SCHOOL], $all);
check('export returns every matching application (no paging)', count($rows) === 2);
[$header, $csv] = ReportService::applicationsCsv($rows, $all);
check('full permissions: personal and eligibility columns are present', in_array('Email', $header, true) && in_array('Eligibility Status', $header, true) && count($csv[0]) === count($header));
[$h2] = ReportService::applicationsCsv($rows, ['pii' => false, 'elig' => false]);
check('without PII permission the export has no personal columns', !in_array('Email', $h2, true) && !in_array('Name', $h2, true) && !in_array('Date of Birth', $h2, true));
check('without eligibility permission the export has no eligibility columns', !preg_grep('/eligib|Criterion|Percentage|Override/i', $h2));
check('both restricted: only IDs, status, submitted date and QR', $h2 === ['Student ID', 'Application Number', 'Application Status', 'Submitted', 'QR Code']);
check('CSV cells starting with = + - @ are neutralised', Csv::cell('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")" && Csv::cell('+1') === "'+1" && Csv::cell('-2') === "'-2" && Csv::cell('@a') === "'@a" && Csv::cell('plain') === 'plain' && Csv::cell(5) === '5');

echo "Eligibility rule changes\n";
$active = $rules->active();
check('rule v1 is active before the change', $active !== null && $active['dob_min'] === '2009-02-02' && $active['dob_max'] === '2012-02-02' && $active['min_percentage'] === '60.00');
foreach ([
    ['2012-02-02', '2009-02-02', '60', 'earliest after latest'],
    ['2009-13-45', '2012-02-02', '60', 'invalid date'],
    ['2009-02-02', '2012-02-02', '101', 'percentage above 100'],
    ['2009-02-02', '2012-02-02', 'abc', 'percentage not a number'],
    ['2009-02-02', '2012-02-02', '60', 'identical to the active rule'],
] as [$lo, $hi, $pc, $why]) {
    check("rejected: {$why}", threw(fn() => $rules->publish($lo, $hi, $pc, '', false, $adminId, 'super_admin')) !== null);
}
check('rejected changes left the rule untouched', $rules->active()['version'] === $active['version']);
$res = $rules->publish('2010-01-01', '2010-12-31', '75.5', 'Tighter intake for 2027', true, $adminId, 'super_admin');
$new = $rules->active();
check('a new version becomes the single active rule', $res['version'] === (int) $active['version'] + 1 && $new['version'] === $res['version'] && $new['min_percentage'] === '75.50'
    && (int) one('SELECT count(*) FROM eligibility_rules WHERE is_active') === 1);
check('the old version stays in the history, inactive and unchanged', (int) one('SELECT count(*) FROM eligibility_rules WHERE version = ? AND NOT is_active AND dob_min = ?::date', [$active['version'], '2009-02-02']) === 1
    && count($rules->history()) >= 2);
check('waiting applications were re-evaluated with the new rule', $res['reevaluated'] >= 2);
check('the 2010-06-15 student is now INELIGIBLE under the 2010-01-01..12-31 range at 70% < 75.5%', one('SELECT calculated_status FROM eligibility_results WHERE application_id = ? ORDER BY id DESC LIMIT 1', [$a1]) === 'INELIGIBLE'
    && one('SELECT academic_criterion FROM eligibility_results WHERE application_id = ? ORDER BY id DESC LIMIT 1', [$a1]) === 'FAIL');
check('their QR code was revoked to match', (int) one("SELECT count(*) FROM student_qr_codes WHERE application_id = ? AND status = 'ACTIVE'", [$a1]) === 0);
check('earlier evaluations were kept, with the rule version they used', (int) one('SELECT count(*) FROM eligibility_results er JOIN eligibility_rules r ON r.id = er.rule_id WHERE er.application_id = ? AND r.version = ?', [$a1, $active['version']]) === 1
    && (int) one("SELECT count(*) FROM eligibility_results WHERE application_id = ? AND trigger_event = 'RULE_CHANGE'", [$a1]) === 1);
$res2 = $rules->publish('2009-02-02', '2012-02-02', '60', 'Back to the original criteria', false, $adminId, 'super_admin');
check('publishing without re-evaluation changes no existing result', $res2['reevaluated'] === 0 && one('SELECT calculated_status FROM eligibility_results WHERE application_id = ? ORDER BY id DESC LIMIT 1', [$a1]) === 'INELIGIBLE');
check('the rule changes were audited with old and new values', (int) one("SELECT count(*) FROM audit_logs WHERE action = 'ELIGIBILITY_RULE_CHANGED' AND old_values IS NOT NULL AND new_values IS NOT NULL AND actor_user_id = ?", [$adminId]) === 2);
check('only the Super Admin role may change rules', Permissions::has('super_admin', 'eligibility.rules') && !Permissions::has('application_reviewer', 'eligibility.rules') && !Permissions::has('report_viewer', 'eligibility.rules'));

echo "Audit log viewer\n";
$audit = new AuditService();
$l = $audit->list(['action' => 'ELIGIBILITY_RULE_CHANGED'], 1);
check('filtering by action finds the rule changes', $l['total'] >= 2 && $l['rows'][0]['action'] === 'ELIGIBILITY_RULE_CHANGED' && $l['rows'][0]['new_values'] !== null);
check('filtering by entity finds an application\'s entries', $audit->list(['entity_type' => 'application', 'entity_id' => (string) $a1], 1)['total'] >= 3);
check('the action list includes the new actions', in_array('ELIGIBILITY_RULE_CHANGED', $audit->actions(), true) && in_array('QR_ISSUED', $audit->actions(), true));
check('paging is clamped', $audit->list([], 9999)['page'] === $audit->list([], 9999)['pages']);
check('audit entries cannot be altered', threw(fn() => $pdo->exec("UPDATE audit_logs SET action = 'X'")) !== null);
check('permissions: report viewer sees reports only; reviewer has no audit or rules access', Permissions::has('report_viewer', 'reports.view') && !Permissions::has('report_viewer', 'applications.export')
    && !Permissions::has('application_reviewer', 'audit.view') && Permissions::has('super_admin', 'audit.view'));

$pdo->rollBack();
echo "\n{$pass} passed, {$fail} failed (all test data rolled back)\n";
exit($fail === 0 ? 0 : 1);
