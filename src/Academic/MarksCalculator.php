<?php
declare(strict_types=1);

namespace Iha\Academic;

/**
 * Authoritative marks maths. All arithmetic is done in integer "cents" (marks x 100) so results
 * never suffer floating-point drift; the 60% threshold is compared exactly, not on a rounded value.
 * The browser script (marks.js) mirrors this for convenience only.
 */
final class MarksCalculator
{
    /** "75", "75.5", "75.50" -> 7550. Null for anything else (negative, text, more than 2 decimals, too large). */
    public static function toCents(string $s): ?int
    {
        $s = trim($s);
        if (!preg_match('/^(\d{1,6})(?:\.(\d{1,2}))?$/', $s, $m)) {
            return null;
        }
        $frac = isset($m[2]) ? str_pad($m[2], 2, '0') : '00';
        return ((int) $m[1]) * 100 + (int) $frac;
    }

    /** Percentage rounded half-up to 2 decimals, as "xx.xx". Needs $totalCents > 0. */
    public static function percentString(int $obtainedCents, int $totalCents): string
    {
        $h = intdiv($obtainedCents * 20000 + $totalCents, 2 * $totalCents);   // hundredths of a percent
        return sprintf('%d.%02d', intdiv($h, 100), $h % 100);
    }

    /** Exact test: obtained/total >= minHundredths/10000 (e.g. 6000 means 60.00%). */
    public static function meetsMinimum(int $obtainedCents, int $totalCents, int $minHundredths): bool
    {
        return $totalCents > 0 && $obtainedCents * 10000 >= $minHundredths * $totalCents;
    }

    /**
     * Classify submitted subject rows.
     *  - fully blank rows are ignored
     *  - rows with a bad value get an error (the save is refused)
     *  - rows that are fine but unfinished are "incomplete" (kept, never counted)
     *  - complete rows are "valid" and counted
     *
     * @param array<int, array{subject_name?:string,total_marks?:string,marks_obtained?:string}> $rows
     * @return array{rows:array<int,array>, total_cents:int, obtained_cents:int, overall_percent:?string, valid_count:int, incomplete_count:int, error_count:int}
     */
    public static function evaluate(array $rows): array
    {
        $out = [];
        $total = 0;
        $obtained = 0;
        $valid = $incomplete = $errors = 0;

        foreach ($rows as $i => $r) {
            $name = trim((string) ($r['subject_name'] ?? ''));
            $tRaw = trim((string) ($r['total_marks'] ?? ''));
            $oRaw = trim((string) ($r['marks_obtained'] ?? ''));
            if ($name === '' && $tRaw === '' && $oRaw === '') {
                continue;
            }

            $t = $tRaw === '' ? null : self::toCents($tRaw);
            $o = $oRaw === '' ? null : self::toCents($oRaw);
            $row = ['subject_name' => $name, 'total_marks' => $tRaw, 'marks_obtained' => $oRaw,
                    'valid' => false, 'incomplete' => false, 'percent' => null, 'error' => null];

            if (mb_strlen($name) > 100) {
                $row['error'] = 'Subject name is too long (100 characters maximum).';
            } elseif ($name === '') {
                $row['error'] = 'Subject name is required.';
            } elseif ($tRaw !== '' && ($t === null || $t <= 0)) {
                $row['error'] = 'Total marks must be a number greater than zero (up to 2 decimals).';
            } elseif ($oRaw !== '' && $o === null) {
                $row['error'] = 'Marks obtained must be zero or more (up to 2 decimals).';
            } elseif ($t !== null && $o !== null && $o > $t) {
                $row['error'] = 'Marks obtained cannot exceed total marks.';
            } elseif ($t === null || $o === null) {
                $row['incomplete'] = true;
            } else {
                $row['valid'] = true;
                $row['percent'] = self::percentString($o, $t);
                $total += $t;
                $obtained += $o;
            }

            $row['valid'] ? $valid++ : ($row['incomplete'] ? $incomplete++ : $errors++);
            $out[$i] = $row;
        }

        return [
            'rows' => $out,
            'total_cents' => $total,
            'obtained_cents' => $obtained,
            'overall_percent' => $total > 0 ? self::percentString($obtained, $total) : null,
            'valid_count' => $valid,
            'incomplete_count' => $incomplete,
            'error_count' => $errors,
        ];
    }

    public static function formatCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
