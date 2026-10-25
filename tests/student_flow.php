<?php
declare(strict_types=1);

/**
 * Student form services against the real database, inside one rolled-back transaction.
 *     php tests/student_flow.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Core\Database;
use Iha\Geo\GeoService;
use Iha\Student\ApplicationService;
use Iha\Student\SectionValidator;

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

// fixtures (rolled back at the end): two provinces, districts, cities
$pA = (int) one("INSERT INTO provinces (code, name, region_type) VALUES ('X1','Test Prov A','province') RETURNING id");
$pB = (int) one("INSERT INTO provinces (code, name, region_type) VALUES ('X2','Test Prov B','province') RETURNING id");
$dA = (int) one('INSERT INTO districts (province_id, name) VALUES (?, ?) RETURNING id', [$pA, 'Dist A']);
$dB = (int) one('INSERT INTO districts (province_id, name) VALUES (?, ?) RETURNING id', [$pB, 'Dist B']);
$cA = (int) one('INSERT INTO cities (district_id, name) VALUES (?, ?) RETURNING id', [$dA, 'City A']);
$cB = (int) one('INSERT INTO cities (district_id, name) VALUES (?, ?) RETURNING id', [$dB, 'City B']);

$uid = (int) one("INSERT INTO users (email, password_hash, full_name, email_verified_at) VALUES ('s@test.local','x','Stu Dent', now()) RETURNING id");
$pid = (int) one('INSERT INTO student_profiles (user_id, full_name) VALUES (?, ?) RETURNING id', [$uid, 'Stu Dent']);

$geo = new GeoService();
$val = new SectionValidator($geo);
$svc = new ApplicationService();

echo "Geography validation\n";
[$l, $e] = $geo->resolve(['province_id' => (string) $pA, 'district_id' => (string) $dA, 'city_id' => (string) $cA], '');
check('valid province/district/city accepted', $e === [] && $l['city_id'] === $cA);
[, $e] = $geo->resolve(['province_id' => (string) $pA, 'district_id' => (string) $dB], '');
check('district from another province rejected', isset($e['district_id']));
[, $e] = $geo->resolve(['province_id' => (string) $pA, 'district_id' => (string) $dA, 'city_id' => (string) $cB], '');
check('city from another district rejected', isset($e['city_id']));
[, $e] = $geo->resolve(['district_id' => (string) $dA], '');
check('district without province rejected', isset($e['province_id']));
[, $e] = $geo->resolve(['province_id' => 'abc'], '');
check('non-numeric id rejected', $e !== []);
[$l, $e] = $geo->resolve(['province_id' => (string) $pA, 'district_id' => (string) $dA, 'city_id' => 'other', 'city_other' => 'Small Town'], '');
check('Other with a name accepted; stored as city_other, city_id null', $e === [] && $l['city_other'] === 'Small Town' && $l['city_id'] === null);
[, $e] = $geo->resolve(['province_id' => (string) $pA, 'district_id' => (string) $dA, 'city_id' => 'other', 'city_other' => ' '], '');
check('Other without a name rejected', isset($e['city_other']));
[$l,] = $geo->resolve(['province_id' => (string) $pA, 'district_id' => (string) $dA, 'city_id' => (string) $cA, 'city_other' => 'Left Over'], '');
check('a regular city clears any custom name', $l['city_other'] === null);
[$l, $e] = $geo->resolve(['school_province_id' => (string) $pB, 'school_district_id' => (string) $dB, 'school_city_id' => (string) $cB], 'school_');
check('prefixed groups (school_) resolve independently', $e === [] && $l['city_id'] === $cB);
check('custom names never added to the cities table', (int) one("SELECT count(*) FROM cities WHERE name = 'Small Town'") === 0);

echo "Application\n";
$app = $svc->currentApplication($pid);
check('first visit creates a DRAFT with an application number', $app['status'] === 'DRAFT' && str_starts_with($app['application_number'], 'IHA-APP-'));
$app2 = $svc->currentApplication($pid);
check('visiting again returns the same application (no duplicates)', $app2['id'] === $app['id'] && (int) one('SELECT count(*) FROM applications WHERE student_profile_id = ?', [$pid]) === 1);
$appId = (int) $app['id'];

echo "Section 1 - profile\n";
$post = [
    'full_name' => 'Stu Dent', 'father_name' => 'Pa Rent', 'mother_name' => 'Ma Rent', 'gender' => 'male',
    'date_of_birth' => '2010-06-15', 'nationality' => 'Pakistani', 'id_number' => '3520212345671',
    'contact_number' => '0300-1234567', 'address_line' => '1 Test Road',
    'province_id' => (string) $pA, 'district_id' => (string) $dA, 'city_id' => (string) $cA,
    'perm_same_as_current' => '1',
];
[$v, $e] = $val->profile($post);
check('valid profile passes; CNIC normalised to 5-7-1', $e === [] && $v['id_number'] === '35202-1234567-1');
check('"same as current" copies the location to the permanent address', $v['perm_province_id'] === $pA && $v['perm_city_id'] === $cA && $v['perm_address_line'] === '1 Test Road');
$svc->saveProfile($pid, $appId, $v);
check('profile saved', one('SELECT father_name FROM student_profiles WHERE id = ?', [$pid]) === 'Pa Rent');
check('Student ID untouched by saving', (bool) preg_match('/^IHA-PK-\d{8}$/', (string) one('SELECT student_id FROM student_profiles WHERE id = ?', [$pid])));
[, $e] = $val->profile(array_merge($post, ['gender' => 'robot']));
check('bad gender rejected', isset($e['gender']));
[, $e] = $val->profile(array_merge($post, ['date_of_birth' => '2999-01-01']));
check('future date of birth rejected', isset($e['date_of_birth']));
[, $e] = $val->profile(array_merge($post, ['date_of_birth' => '2010-02-30']));
check('impossible date rejected', isset($e['date_of_birth']));
[, $e] = $val->profile(array_merge($post, ['id_number' => '123']));
check('short CNIC rejected', isset($e['id_number']));
[, $e] = $val->profile(array_merge($post, ['contact_number' => 'call me']));
check('bad phone rejected', isset($e['contact_number']));
[$v, $e] = $val->profile(array_merge($post, ['perm_same_as_current' => '0', 'perm_province_id' => (string) $pB, 'perm_district_id' => (string) $dA]));
check('mismatched permanent district rejected', isset($e['perm_district_id']));

echo "Section 2 - academic\n";
[$v, $e] = $val->academic([
    'institution_name' => 'Test School', 'institution_type' => 'school', 'address_line' => '2 School Rd',
    'school_province_id' => (string) $pB, 'school_district_id' => (string) $dB, 'school_city_id' => 'other', 'school_city_other' => 'Tiny Village',
    'current_class' => '9', 'academic_session' => '2025-2026', 'exam_board' => 'Test Board', 'exam_name' => 'Annual', 'exam_year' => '2026',
    'roll_number' => '12345', 'result_status' => 'declared',
]);
check('valid academic section passes', $e === []);
$svc->saveAcademic($pid, $appId, $v);
check('academic record saved as the eligibility-basis record', (int) one('SELECT count(*) FROM academic_records WHERE application_id = ? AND is_eligibility_basis', [$appId]) === 1);
check('school custom city saved in city_other', one('SELECT city_other FROM academic_records WHERE application_id = ?', [$appId]) === 'Tiny Village');
[, $e] = $val->academic(['exam_year' => '1850']);
check('implausible exam year rejected', isset($e['exam_year']));
$svc->saveAcademic($pid, $appId, $v);
check('saving again does not create a second record', (int) one('SELECT count(*) FROM academic_records WHERE application_id = ?', [$appId]) === 1);

echo "Section 3 - marks\n";
[$eval, $e] = $val->marks(['subject_name' => ['Math', 'Physics', ''], 'total_marks' => ['100', '50', ''], 'marks_obtained' => ['75', '30', '']]);
check('valid marks pass', $e === [] && $eval['valid_count'] === 2);
$svc->saveSubjects($pid, $appId, array_values($eval['rows']));
check('two subjects stored and flagged valid', (int) one('SELECT count(*) FROM academic_subjects s JOIN academic_records r ON r.id = s.academic_record_id WHERE r.application_id = ? AND s.is_valid', [$appId]) === 2);
[$eval2, $e] = $val->marks(['subject_name' => ['Math'], 'total_marks' => ['100'], 'marks_obtained' => ['101']]);
check('marks above total rejected by the server', isset($e['subject.0']));
$svc->saveSubjects($pid, $appId, array_values($eval['rows']));
check('re-saving replaces rather than duplicates subjects', (int) one('SELECT count(*) FROM academic_subjects s JOIN academic_records r ON r.id = s.academic_record_id WHERE r.application_id = ?', [$appId]) === 2);

echo "Section 4 - guardian\n";
[$v, $e] = $val->guardian([
    'father_name' => 'Pa Rent', 'father_contact' => '0300-1111111', 'mother_name' => 'Ma Rent', 'primary_guardian' => 'father',
    'address_line' => '3 Home St', 'guardian_province_id' => (string) $pA, 'guardian_district_id' => (string) $dA, 'guardian_city_id' => (string) $cA,
    'emergency_name' => 'Uncle', 'emergency_contact' => '0300-2222222', 'emergency_relationship' => 'Uncle',
]);
check('valid guardian section passes', $e === []);
$svc->saveGuardian($pid, $appId, $v);
check('guardian saved', one('SELECT primary_guardian FROM parent_guardians WHERE application_id = ?', [$appId]) === 'father');
[, $e] = $val->guardian(['father_email' => 'not-an-email']);
check('bad email rejected', isset($e['father_email']));

echo "Section 5 - travel\n";
[$t, $e] = $val->travel(['ans_outside_home_city' => '1', 'ans_another_district' => '0', 'ans_another_province' => '0', 'ans_international' => '0',
    'rec_country' => ['Pakistan'], 'rec_province' => ['Test Prov A'], 'rec_district' => [''], 'rec_city' => [''], 'rec_purpose' => ['Visit'],
    'rec_start' => ['2025-01-02'], 'rec_end' => ['2025-01-05'], 'rec_mode' => ['Bus'], 'rec_with' => [''], 'rec_notes' => ['']]);
check('valid travel passes with one record', $e === [] && count($t['records']) === 1);
$svc->saveTravel($pid, $appId, $t['answers'], $t['records']);
check('answers and record saved', (int) one('SELECT count(*) FROM travel_history WHERE application_id = ?', [$appId]) === 1 && one('SELECT outside_home_city FROM travel_responses WHERE application_id = ?', [$appId]) === true);
[$t,] = $val->travel(['ans_outside_home_city' => '0', 'ans_another_district' => '0', 'ans_another_province' => '0', 'ans_international' => '0', 'rec_country' => ['Pakistan'], 'rec_start' => [''], 'rec_end' => ['']]);
check('all-No answers discard hidden trip records', $t['records'] === []);
$svc->saveTravel($pid, $appId, $t['answers'], $t['records']);
check('saving all-No removes stored records', (int) one('SELECT count(*) FROM travel_history WHERE application_id = ?', [$appId]) === 0);
[, $e] = $val->travel(['ans_international' => '1', 'rec_country' => ['X'], 'rec_start' => ['2025-02-10'], 'rec_end' => ['2025-02-01']]);
check('end date before start date rejected', $e !== []);

echo "Completion\n";
$row = $pdo->prepare('SELECT completion_percent, section_status FROM applications WHERE id = ?');
$row->execute([$appId]);
$a = $row->fetch();
$st = json_decode($a['section_status'], true);
check('all five sections complete -> 100%', (int) $a['completion_percent'] === 100 && count(array_filter($st)) === 5);
$svc->saveSubjects($pid, $appId, [['subject_name' => 'Math', 'total_marks' => '100', 'marks_obtained' => '', 'valid' => false]]);
$row->execute([$appId]);
$a = $row->fetch();
check('an unfinished subject row drops Section 3 and the percentage to 80%', (int) $a['completion_percent'] === 80);
check('saving the form creates no eligibility records (that happens at submission, admin-side)', (int) one('SELECT count(*) FROM eligibility_results WHERE application_id = ?', [$appId]) === 0);

$pdo->rollBack();
echo "\n{$pass} passed, {$fail} failed (all test data rolled back)\n";
exit($fail === 0 ? 0 : 1);
