<?php
declare(strict_types=1);

/** Pure arithmetic tests (no database needed):  php tests/marks_calc.php */

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Academic\MarksCalculator as M;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
}

echo "Parsing\n";
check('75 -> 7500', M::toCents('75') === 7500);
check('75.5 -> 7550', M::toCents('75.5') === 7550);
check('75.05 -> 7505', M::toCents('75.05') === 7505);
check('negative rejected', M::toCents('-1') === null);
check('text rejected', M::toCents('abc') === null);
check('three decimals rejected', M::toCents('1.234') === null);
check('empty rejected', M::toCents('') === null);

echo "Percentages\n";
check('75/100 = 75.00', M::percentString(7500, 10000) === '75.00');
check('60/100 = 60.00', M::percentString(6000, 10000) === '60.00');
check('59.99/100 = 59.99', M::percentString(5999, 10000) === '59.99');
check('1/3 = 33.33', M::percentString(100, 300) === '33.33');
check('2/3 = 66.67 (half-up rounding)', M::percentString(200, 300) === '66.67');
check('100/100 = 100.00', M::percentString(10000, 10000) === '100.00');
check('0 marks = 0.00', M::percentString(0, 10000) === '0.00');

echo "Exact 60% threshold (no rounding tricks)\n";
check('60/100 meets 60%', M::meetsMinimum(6000, 10000, 6000));
check('59.99/100 does not meet 60%', !M::meetsMinimum(5999, 10000, 6000));
check('59.995% (shows 60.00) does NOT meet 60%', !M::meetsMinimum(59995, 100000, 6000) && M::percentString(59995, 100000) === '60.00');
check('540/900 meets 60% exactly', M::meetsMinimum(54000, 90000, 6000));
check('zero total never meets', !M::meetsMinimum(0, 0, 6000));

echo "Row classification\n";
$e = M::evaluate([
    ['subject_name' => 'Math', 'total_marks' => '100', 'marks_obtained' => '75'],
    ['subject_name' => 'Physics', 'total_marks' => '50', 'marks_obtained' => '30'],
    ['subject_name' => '', 'total_marks' => '', 'marks_obtained' => ''],
    ['subject_name' => 'Chemistry', 'total_marks' => '100', 'marks_obtained' => ''],
]);
check('blank row ignored, 3 rows classified', count($e['rows']) === 3);
check('2 valid rows, 1 incomplete, 0 errors', $e['valid_count'] === 2 && $e['incomplete_count'] === 1 && $e['error_count'] === 0);
check('totals use valid rows only (150 total, 105 obtained)', $e['total_cents'] === 15000 && $e['obtained_cents'] === 10500);
check('overall = 70.00', $e['overall_percent'] === '70.00');
check('row percentages: Math 75.00, Physics 60.00', $e['rows'][0]['percent'] === '75.00' && $e['rows'][1]['percent'] === '60.00');

$bad = fn(array $row) => M::evaluate([$row])['error_count'] === 1;
check('obtained above total is an error', $bad(['subject_name' => 'X', 'total_marks' => '50', 'marks_obtained' => '51']));
check('zero total is an error', $bad(['subject_name' => 'X', 'total_marks' => '0', 'marks_obtained' => '0']));
check('negative marks is an error', $bad(['subject_name' => 'X', 'total_marks' => '50', 'marks_obtained' => '-5']));
check('marks without a subject name is an error', $bad(['subject_name' => '', 'total_marks' => '50', 'marks_obtained' => '10']));
check('non-numeric marks is an error', $bad(['subject_name' => 'X', 'total_marks' => 'fifty', 'marks_obtained' => '10']));
check('obtained equal to total is fine', M::evaluate([['subject_name' => 'X', 'total_marks' => '50', 'marks_obtained' => '50']])['valid_count'] === 1);
check('no valid rows -> no overall percentage', M::evaluate([['subject_name' => 'X', 'total_marks' => '', 'marks_obtained' => '']])['overall_percent'] === null);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
