<?php
declare(strict_types=1);

namespace Iha\Eligibility;

use Iha\Academic\MarksCalculator;

/**
 * Pure eligibility rules (no database, no session). ADMIN-SIDE ONLY: nothing outside the admin
 * services and the submission transaction may call this or show its output to a student.
 *
 *  ELIGIBLE        both criteria PASS
 *  INELIGIBLE      at least one completed criterion FAILS (even if the other is still pending)
 *  PENDING_REVIEW  nothing has failed, but a criterion cannot be decided yet
 */
final class EligibilityEngine
{
    /**
     * @param ?string $dob       'YYYY-MM-DD' or null
     * @param ?array  $academic  MarksCalculator::evaluate() result (valid/incomplete/error counts and cent totals) or null
     * @param array   $rule      ['dob_min'=>'YYYY-MM-DD','dob_max'=>'YYYY-MM-DD','min_percentage'=>'60.00']
     * @return array{status:string,dob:string,academic:string,percentage:?string,reason:?string}
     */
    public static function evaluate(?string $dob, ?array $academic, array $rule): array
    {
        [$dobResult, $dobNote] = self::dob($dob, $rule);
        [$acResult, $pct, $acNote] = self::academic($academic, $rule);

        if ($dobResult === 'PASS' && $acResult === 'PASS') {
            $status = 'ELIGIBLE';
        } elseif ($dobResult === 'FAIL' || $acResult === 'FAIL') {
            $status = 'INELIGIBLE';
        } else {
            $status = 'PENDING_REVIEW';
        }

        $reason = null;
        if ($status !== 'ELIGIBLE') {
            $notes = [];
            foreach ([[$dobResult, $dobNote], [$acResult, $acNote]] as [$res, $note]) {
                if ($res !== 'PASS' && $note !== null) {
                    $notes[] = $note;
                }
            }
            $reason = implode(' ', $notes);
        }
        return ['status' => $status, 'dob' => $dobResult, 'academic' => $acResult, 'percentage' => $pct, 'reason' => $reason];
    }

    /** @return array{0:string,1:?string} */
    private static function dob(?string $dob, array $rule): array
    {
        if ($dob === null || $dob === '') {
            return ['PENDING', 'Date of birth is missing.'];
        }
        $ok = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dob, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        if (!$ok) {
            return ['PENDING', 'Date of birth is invalid.'];
        }
        // ISO dates compare correctly as strings; both boundaries are inclusive.
        if ($dob >= $rule['dob_min'] && $dob <= $rule['dob_max']) {
            return ['PASS', null];
        }
        return ['FAIL', "Date of birth {$dob} is outside the permitted range {$rule['dob_min']} to {$rule['dob_max']}."];
    }

    /** @return array{0:string,1:?string,2:?string} */
    private static function academic(?array $a, array $rule): array
    {
        if ($a === null || $a['valid_count'] < 1 || $a['incomplete_count'] > 0 || $a['error_count'] > 0 || $a['total_cents'] <= 0) {
            return ['PENDING', null, 'Valid, complete academic marks are not yet available.'];
        }
        $pct = MarksCalculator::percentString($a['obtained_cents'], $a['total_cents']);
        $minHundredths = MarksCalculator::toCents($rule['min_percentage']) ?? 0;   // "60.00" -> 6000
        $min = MarksCalculator::formatCents($minHundredths);

        // Exact comparison, never on the rounded display value.
        if (MarksCalculator::meetsMinimum($a['obtained_cents'], $a['total_cents'], $minHundredths)) {
            return ['PASS', $pct, null];
        }
        $note = "Overall academic percentage {$pct}% is below the {$min}% minimum.";
        if (MarksCalculator::toCents($pct) >= $minHundredths) {
            $note = "Overall academic percentage rounds to {$pct}% but its exact value is below the {$min}% minimum.";
        }
        return ['FAIL', $pct, $note];
    }
}
