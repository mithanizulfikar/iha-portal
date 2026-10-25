<?php
declare(strict_types=1);

/** Pure rule tests (no database):  php tests/eligibility_engine.php */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Academic\MarksCalculator as M;
use Iha\Eligibility\EligibilityEngine as E;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
}

$rule = ['dob_min' => '2009-02-02', 'dob_max' => '2012-02-02', 'min_percentage' => '60.00'];

/** One subject: total 10000 marks, obtained = percentage x 100 (so 59.99% => 5999 of 10000). */
function marks(string $percent): array
{
    $obtained = (string) (M::toCents($percent) ?? 0) ;   // "59.99" -> 5999 (marks)
    return M::evaluate([['subject_name' => 'All subjects', 'total_marks' => '10000', 'marks_obtained' => $obtained]]);
}

echo "Specification table\n";
$cases = [
    ['2009-02-02', '60',    'ELIGIBLE'],
    ['2012-02-02', '60',    'ELIGIBLE'],
    ['2009-02-01', '80',    'INELIGIBLE'],
    ['2012-02-03', '80',    'INELIGIBLE'],
    ['2010-06-15', '59.99', 'INELIGIBLE'],
    ['2010-06-15', '60',    'ELIGIBLE'],
];
foreach ($cases as [$dob, $pct, $expected]) {
    $r = E::evaluate($dob, marks($pct), $rule);
    check("DOB {$dob}, {$pct}% => {$expected}", $r['status'] === $expected);
}

echo "Criterion detail\n";
$r = E::evaluate('2010-06-15', marks('59.99'), $rule);
check('59.99%: DOB PASS, academic FAIL, percentage 59.99', $r['dob'] === 'PASS' && $r['academic'] === 'FAIL' && $r['percentage'] === '59.99');
check('ineligible result carries a reason', $r['reason'] !== null && str_contains($r['reason'], '59.99'));
$r = E::evaluate('2013-01-01', marks('90'), $rule);
check('late DOB: DOB FAIL, academic PASS', $r['dob'] === 'FAIL' && $r['academic'] === 'PASS' && $r['status'] === 'INELIGIBLE');
check('eligible result has no reason', E::evaluate('2010-06-15', marks('75'), $rule)['reason'] === null);

echo "Pending / incomplete data\n";
$r = E::evaluate(null, marks('75'), $rule);
check('missing DOB with passing marks => PENDING_REVIEW', $r['dob'] === 'PENDING' && $r['status'] === 'PENDING_REVIEW');
$r = E::evaluate('2010-06-15', null, $rule);
check('no marks at all => academic PENDING, status PENDING_REVIEW', $r['academic'] === 'PENDING' && $r['status'] === 'PENDING_REVIEW' && $r['percentage'] === null);
$r = E::evaluate('2013-01-01', null, $rule);
check('completed failed DOB with missing marks => INELIGIBLE (not pending)', $r['dob'] === 'FAIL' && $r['academic'] === 'PENDING' && $r['status'] === 'INELIGIBLE');
$r = E::evaluate('2010-06-15', marks('40'), $rule);
$incomplete = M::evaluate([['subject_name' => 'Math', 'total_marks' => '100', 'marks_obtained' => '70'], ['subject_name' => 'Physics', 'total_marks' => '50', 'marks_obtained' => '']]);
$r = E::evaluate('2010-06-15', $incomplete, $rule);
check('an unfinished subject row keeps the academic criterion PENDING', $r['academic'] === 'PENDING' && $r['status'] === 'PENDING_REVIEW');
$r = E::evaluate('2010-06-15', M::evaluate([['subject_name' => 'Math', 'total_marks' => '100', 'marks_obtained' => '200']]), $rule);
check('invalid marks (obtained above total) never produce a result', $r['academic'] === 'PENDING');
$r = E::evaluate('2010-02-30', marks('75'), $rule);
check('impossible date of birth => PENDING (invalid), not a pass', $r['dob'] === 'PENDING');
$r = E::evaluate('not-a-date', marks('75'), $rule);
check('garbage date of birth => PENDING', $r['dob'] === 'PENDING');

echo "Exact threshold\n";
$exact = M::evaluate([['subject_name' => 'A', 'total_marks' => '100000', 'marks_obtained' => '59995']]);   // 59.995% shows as 60.00
$r = E::evaluate('2010-06-15', $exact, $rule);
check('59.995% displays 60.00 but is below 60% => FAIL with an explanatory reason', $r['percentage'] === '60.00' && $r['academic'] === 'FAIL' && str_contains((string) $r['reason'], 'exact'));
$multi = M::evaluate([['subject_name' => 'A', 'total_marks' => '100', 'marks_obtained' => '50'], ['subject_name' => 'B', 'total_marks' => '100', 'marks_obtained' => '70']]);
check('multiple subjects are combined (120/200 = 60.00%) => PASS', E::evaluate('2010-06-15', $multi, $rule)['academic'] === 'PASS');

echo "Configurable rule\n";
$custom = ['dob_min' => '2010-01-01', 'dob_max' => '2010-12-31', 'min_percentage' => '75.50'];
check('a changed rule changes the outcome (DOB 2011 now fails)', E::evaluate('2011-05-05', marks('90'), $custom)['status'] === 'INELIGIBLE');
check('a changed minimum applies (75.49% fails, 75.5% passes)', E::evaluate('2010-05-05', marks('75.49'), $custom)['academic'] === 'FAIL' && E::evaluate('2010-05-05', marks('75.5'), $custom)['academic'] === 'PASS');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
