<?php
declare(strict_types=1);

/**
 * Database smoke tests for the Stage 1-2 constraints. Run after `php bin/migrate.php && php bin/seed.php`:
 *     php tests/db_smoke.php
 * Everything runs inside one transaction that is rolled back, so no data is left behind.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Core\Database;

$pdo = Database::pdo();
$pdo->beginTransaction();

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
}

/** Statement must be rejected by the database. A savepoint keeps the outer transaction usable. */
function rejected(string $label, callable $fn): void
{
    global $pdo;
    $pdo->exec('SAVEPOINT t');
    try {
        $fn($pdo);
        $pdo->exec('RELEASE SAVEPOINT t');
        check($label, false);
    } catch (\PDOException $e) {
        $pdo->exec('ROLLBACK TO SAVEPOINT t');
        check($label, true);
    }
}

function one(string $sql, array $p = []): mixed
{
    global $pdo;
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return $s->fetchColumn();
}

function makeStudent(string $email): array
{
    global $pdo;
    $uid = (int) one(
        "INSERT INTO users (email, password_hash, full_name) VALUES (?, 'x', 'Test Student') RETURNING id",
        [$email]
    );
    $sp = (int) one('INSERT INTO student_profiles (user_id) VALUES (?) RETURNING id', [$uid]);
    $app = (int) one('INSERT INTO applications (student_profile_id) VALUES (?) RETURNING id', [$sp]);
    return [$uid, $sp, $app];
}

echo "Student ID\n";
[$u1, $sp1, $app1] = makeStudent('a@test.local');
[$u2, $sp2, $app2] = makeStudent('b@test.local');
$id1 = one('SELECT student_id FROM student_profiles WHERE id = ?', [$sp1]);
$id2 = one('SELECT student_id FROM student_profiles WHERE id = ?', [$sp2]);
check('student ID matches IHA-PK-######## format', (bool) preg_match('/^IHA-PK-\d{8}$/', (string) $id1));
check('two students receive different IDs', $id1 !== $id2);
rejected('student_id cannot be changed', fn($p) => $p->exec("UPDATE student_profiles SET student_id = 'IHA-PK-99999999' WHERE id = $sp1"));
rejected('student_id cannot be set to a malformed value on insert', function ($p) use ($u1) {
    $p->exec("INSERT INTO users (email, password_hash, full_name) VALUES ('c@test.local','x','C')");
    $uid = (int) $p->query("SELECT id FROM users WHERE email='c@test.local'")->fetchColumn();
    $p->exec("INSERT INTO student_profiles (user_id, student_id) VALUES ($uid, 'BAD-ID')");
});
rejected('duplicate email (case-insensitive) is rejected', fn($p) => $p->exec("INSERT INTO users (email, password_hash, full_name) VALUES ('a@test.local','x','Dup')"));
rejected('non-normalised email is rejected', fn($p) => $p->exec("INSERT INTO users (email, password_hash, full_name) VALUES ('Mixed@Test.local','x','M')"));

echo "Application\n";
check('application number generated and distinct from student ID', (bool) preg_match('/^IHA-APP-\d{4}-\d{6}$/', (string) one('SELECT application_number FROM applications WHERE id = ?', [$app1])));
rejected('second open application for the same student is rejected', fn($p) => $p->exec("INSERT INTO applications (student_profile_id) VALUES ($sp1)"));
rejected('non-draft application needs submitted_at', fn($p) => $p->exec("UPDATE applications SET status = 'SUBMITTED' WHERE id = $app1"));

echo "Geography\n";
$pA = (int) one("INSERT INTO provinces (code, name, region_type) VALUES ('T1','Test Province A','province') RETURNING id");
$pB = (int) one("INSERT INTO provinces (code, name, region_type) VALUES ('T2','Test Province B','province') RETURNING id");
$dA = (int) one('INSERT INTO districts (province_id, name) VALUES (?, ?) RETURNING id', [$pA, 'District A']);
$dB = (int) one('INSERT INTO districts (province_id, name) VALUES (?, ?) RETURNING id', [$pB, 'District B']);
$cA = (int) one('INSERT INTO cities (district_id, name) VALUES (?, ?) RETURNING id', [$dA, 'City A']);
$cB = (int) one('INSERT INTO cities (district_id, name) VALUES (?, ?) RETURNING id', [$dB, 'City B']);

$pdo->exec("UPDATE student_profiles SET province_id=$pA, district_id=$dA, city_id=$cA WHERE id=$sp1");
check('valid province/district/city combination is accepted', true);
rejected('district from another province is rejected', fn($p) => $p->exec("UPDATE student_profiles SET province_id=$pA, district_id=$dB, city_id=NULL WHERE id=$sp1"));
rejected('city from another district is rejected', fn($p) => $p->exec("UPDATE student_profiles SET province_id=$pA, district_id=$dA, city_id=$cB WHERE id=$sp1"));
rejected('city without district is rejected', fn($p) => $p->exec("UPDATE student_profiles SET province_id=$pA, district_id=NULL, city_id=$cA WHERE id=$sp1"));
rejected('city_id and city_other together are rejected', fn($p) => $p->exec("UPDATE student_profiles SET province_id=$pA, district_id=$dA, city_id=$cA, city_other='Somewhere' WHERE id=$sp1"));
$pdo->exec("UPDATE student_profiles SET province_id=$pA, district_id=$dA, city_id=NULL, city_other='Custom Town' WHERE id=$sp1");
check('custom city (Other) with valid province and district is accepted', true);
check('custom city names are not added to the official cities table', (int) one("SELECT count(*) FROM cities WHERE lower(name) = 'custom town'") === 0);
rejected('duplicate city name in a district is rejected (case-insensitive)', fn($p) => $p->exec("INSERT INTO cities (district_id, name) VALUES ($dA, 'CITY a')"));

echo "Academic marks\n";
$rec = (int) one('INSERT INTO academic_records (application_id, is_eligibility_basis) VALUES (?, TRUE) RETURNING id', [$app1]);
rejected('only one eligibility-basis record per application', fn($p) => $p->exec("INSERT INTO academic_records (application_id, is_eligibility_basis) VALUES ($app1, TRUE)"));
rejected('marks obtained above total are rejected', fn($p) => $p->exec("INSERT INTO academic_subjects (academic_record_id, subject_name, total_marks, marks_obtained) VALUES ($rec,'Math',100,101)"));
rejected('zero total marks are rejected', fn($p) => $p->exec("INSERT INTO academic_subjects (academic_record_id, subject_name, total_marks, marks_obtained) VALUES ($rec,'Math',0,0)"));
rejected('negative marks are rejected', fn($p) => $p->exec("INSERT INTO academic_subjects (academic_record_id, subject_name, total_marks, marks_obtained) VALUES ($rec,'Math',100,-1)"));
$pdo->exec("INSERT INTO academic_subjects (academic_record_id, subject_name, total_marks, marks_obtained) VALUES ($rec,'Math',100,75), ($rec,'',100,50), ($rec,'Physics',NULL,40)");
check('only complete, valid subject rows are flagged is_valid', (int) one('SELECT count(*) FROM academic_subjects WHERE academic_record_id = ? AND is_valid', [$rec]) === 1);

echo "Eligibility\n";
$rule = (int) one('SELECT id FROM eligibility_rules WHERE is_active');
check('exactly one active rule version is seeded', $rule > 0);
check('seeded rule: DOB 2009-02-02..2012-02-02, 60%', one('SELECT dob_min::text || dob_max::text || min_percentage::text FROM eligibility_rules WHERE id = ?', [$rule]) === '2009-02-022012-02-0260.00');
rejected('a second active rule is rejected', fn($p) => $p->exec("INSERT INTO eligibility_rules (version, dob_min, dob_max, min_percentage, is_active) VALUES (99, '2009-01-01','2012-01-01',50,TRUE)"));
rejected('rule thresholds are immutable', fn($p) => $p->exec("UPDATE eligibility_rules SET min_percentage = 50 WHERE id = $rule"));
rejected('ELIGIBLE with a failing criterion is rejected', fn($p) => $p->exec("INSERT INTO eligibility_results (application_id, rule_id, calculated_status, dob_criterion, academic_criterion, trigger_event) VALUES ($app1,$rule,'ELIGIBLE','PASS','FAIL','TEST')"));
rejected('PENDING_REVIEW with a failing criterion is rejected', fn($p) => $p->exec("INSERT INTO eligibility_results (application_id, rule_id, calculated_status, dob_criterion, academic_criterion, trigger_event) VALUES ($app1,$rule,'PENDING_REVIEW','FAIL','PENDING','TEST')"));
$res = (int) one("INSERT INTO eligibility_results (application_id, rule_id, calculated_status, dob_criterion, academic_criterion, calculated_percentage, trigger_event) VALUES (?,?,'INELIGIBLE','PASS','FAIL',59.99,'TEST') RETURNING id", [$app1, $rule]);
check('INELIGIBLE with one FAIL and one PASS is accepted', $res > 0);
rejected('evaluation history cannot be updated', fn($p) => $p->exec("UPDATE eligibility_results SET calculated_status = 'ELIGIBLE' WHERE id = $res"));
rejected('evaluation history cannot be deleted', fn($p) => $p->exec("DELETE FROM eligibility_results WHERE id = $res"));
rejected('override without a real reason is rejected', fn($p) => $p->exec("INSERT INTO eligibility_overrides (application_id, based_on_result_id, override_status, reason, created_by) VALUES ($app1,$res,'ELIGIBLE','short',$u1)"));

echo "QR codes\n";
$h1 = hash('sha256', 'token-one');
$h2 = hash('sha256', 'token-two');
$pdo->exec("INSERT INTO student_qr_codes (student_profile_id, application_id, token_hash) VALUES ($sp1,$app1,'$h1')");
rejected('second ACTIVE QR for the same application is rejected', fn($p) => $p->exec("INSERT INTO student_qr_codes (student_profile_id, application_id, token_hash) VALUES ($sp1,$app1,'$h2')"));
rejected('raw (non-hash) token is rejected', fn($p) => $p->exec("INSERT INTO student_qr_codes (student_profile_id, application_id, token_hash) VALUES ($sp2,$app2,'" . str_repeat('Z', 64) . "')"));
$pdo->exec("UPDATE student_qr_codes SET status='REVOKED', revoked_at=now() WHERE token_hash='$h1'");
$pdo->exec("INSERT INTO student_qr_codes (student_profile_id, application_id, token_hash) VALUES ($sp1,$app1,'$h2')");
check('after revocation a replacement ACTIVE QR can be issued', true);
rejected('REVOKED status without revoked_at is rejected', fn($p) => $p->exec("UPDATE student_qr_codes SET status='REVOKED' WHERE token_hash='$h2'"));

echo "Audit & reviews\n";
$pdo->exec("INSERT INTO audit_logs (action, entity_type, entity_id) VALUES ('TEST','test','1')");
rejected('audit log rows cannot be updated', fn($p) => $p->exec("UPDATE audit_logs SET action = 'X' WHERE action = 'TEST'"));
rejected('audit log rows cannot be deleted', fn($p) => $p->exec("DELETE FROM audit_logs WHERE action = 'TEST'"));
rejected('rejection without a reason is rejected', fn($p) => $p->exec("INSERT INTO application_reviews (application_id, reviewer_id, decision) VALUES ($app1,$u1,'REJECTED')"));

$pdo->rollBack();
echo "\n{$pass} passed, {$fail} failed (all test data rolled back)\n";
exit($fail === 0 ? 0 : 1);
