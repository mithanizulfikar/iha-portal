<?php
declare(strict_types=1);

namespace Iha\Student;

use Iha\Academic\MarksCalculator;
use Iha\Geo\GeoService;

/**
 * Server-side validation for the five sections. Drafts may be incomplete, so empty fields are
 * allowed here (completeness is judged separately); anything that IS filled in must be valid.
 * Each method returns [values keyed by database column, errors keyed by form field name].
 */
final class SectionValidator
{
    public const GENDERS = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];
    public const INSTITUTION_TYPES = ['school' => 'School', 'college' => 'College', 'other' => 'Other'];
    public const RESULT_STATUSES = ['declared' => 'Result declared', 'awaiting' => 'Awaiting result'];
    public const PRIMARY_GUARDIANS = ['father' => 'Father', 'mother' => 'Mother', 'guardian' => 'Guardian'];

    public function __construct(private GeoService $geo) {}

    // ---- Section 1 -----------------------------------------------------------------------

    public function profile(array $post): array
    {
        $v = [];
        $e = [];
        $v['full_name']     = $this->person($post, 'full_name', $e, 'Full name');
        $v['father_name']   = $this->person($post, 'father_name', $e, "Father's name");
        $v['mother_name']   = $this->person($post, 'mother_name', $e, "Mother's name");
        $v['gender']        = $this->enum($post, 'gender', array_keys(self::GENDERS), $e, 'gender');
        $v['date_of_birth'] = $this->date($post, 'date_of_birth', $e, 'Date of birth');
        $v['nationality']   = $this->word($post, 'nationality', 60, $e, 'Nationality');
        $v['id_number']     = $this->cnic($post, 'id_number', $e, 'CNIC / B-Form number');
        $v['contact_number'] = $this->phone($post, 'contact_number', $e, 'Contact number');
        $v['address_line']  = $this->free($post, 'address_line', 255);
        $v['postal_code']   = $this->postal($post, 'postal_code', $e);

        [$loc, $le] = $this->geo->resolve($post, '');
        $e += $le;
        $v += $this->geoCols($loc, '');

        $same = ($post['perm_same_as_current'] ?? '') === '1';
        $v['perm_same_as_current'] = $same;
        if ($same) {
            $v['perm_address_line'] = $v['address_line'];
            $v['perm_postal_code']  = $v['postal_code'];
            $v += $this->geoCols($loc, 'perm_');
        } else {
            $v['perm_address_line'] = $this->free($post, 'perm_address_line', 255);
            $v['perm_postal_code']  = $this->postal($post, 'perm_postal_code', $e);
            [$ploc, $pe] = $this->geo->resolve($post, 'perm_');
            $e += $pe;
            $v += $this->geoCols($ploc, 'perm_');
        }
        return [$v, $e];
    }

    // ---- Section 2 -----------------------------------------------------------------------

    public function academic(array $post): array
    {
        $v = [];
        $e = [];
        $v['institution_name']   = $this->free($post, 'institution_name', 150);
        $v['institution_reg_no'] = $this->free($post, 'institution_reg_no', 50);
        $v['institution_type']   = $this->enum($post, 'institution_type', array_keys(self::INSTITUTION_TYPES), $e, 'institution type');
        $v['address_line']       = $this->free($post, 'address_line', 255);
        $v['current_class']      = $this->free($post, 'current_class', 30);
        $v['academic_session']   = $this->free($post, 'academic_session', 20);
        $v['exam_board']         = $this->free($post, 'exam_board', 100);
        $v['exam_name']          = $this->free($post, 'exam_name', 100);
        $v['roll_number']        = $this->free($post, 'roll_number', 30);
        $v['result_status']      = $this->enum($post, 'result_status', array_keys(self::RESULT_STATUSES), $e, 'result status');
        $v['previous_institution'] = $this->free($post, 'previous_institution', 150);

        $year = trim((string) ($post['exam_year'] ?? ''));
        if ($year === '') {
            $v['exam_year'] = null;
        } elseif (!ctype_digit($year) || (int) $year < 1990 || (int) $year > (int) date('Y')) {
            $e['exam_year'] = 'Enter a valid examination year (1990 to ' . date('Y') . ').';
            $v['exam_year'] = null;
        } else {
            $v['exam_year'] = (int) $year;
        }

        [$loc, $le] = $this->geo->resolve($post, 'school_');
        $e += $le;
        $v += $this->geoCols($loc, '');
        return [$v, $e];
    }

    // ---- Section 3 -----------------------------------------------------------------------

    /** @return array{0: array, 1: array<string,string>} evaluation from MarksCalculator and top-level errors */
    public function marks(array $post): array
    {
        $names = is_array($post['subject_name'] ?? null) ? array_values($post['subject_name']) : [];
        $totals = is_array($post['total_marks'] ?? null) ? array_values($post['total_marks']) : [];
        $gots = is_array($post['marks_obtained'] ?? null) ? array_values($post['marks_obtained']) : [];
        $rows = [];
        foreach ($names as $i => $n) {
            $rows[] = [
                'subject_name'   => is_string($n) ? $n : '',
                'total_marks'    => is_string($totals[$i] ?? null) ? $totals[$i] : '',
                'marks_obtained' => is_string($gots[$i] ?? null) ? $gots[$i] : '',
            ];
            if ($i >= 29) {
                break;
            }
        }
        $eval = MarksCalculator::evaluate($rows);
        $errors = [];
        if (count($eval['rows']) > 20) {
            $errors['subjects'] = 'You can enter at most 20 subjects.';
        }
        foreach ($eval['rows'] as $i => $r) {
            if ($r['error'] !== null) {
                $errors['subject.' . $i] = $r['error'];
            }
        }
        return [$eval, $errors];
    }

    // ---- Section 4 -----------------------------------------------------------------------

    public function guardian(array $post): array
    {
        $v = [];
        $e = [];
        $v['father_name']       = $this->person($post, 'father_name', $e, "Father's name");
        $v['father_cnic']       = $this->cnic($post, 'father_cnic', $e, "Father's CNIC");
        $v['father_contact']    = $this->phone($post, 'father_contact', $e, "Father's contact number");
        $v['father_email']      = $this->email($post, 'father_email', $e);
        $v['father_occupation'] = $this->free($post, 'father_occupation', 100);
        $v['mother_name']       = $this->person($post, 'mother_name', $e, "Mother's name");
        $v['mother_contact']    = $this->phone($post, 'mother_contact', $e, "Mother's contact number");
        $v['guardian_name']         = $this->person($post, 'guardian_name', $e, "Guardian's name");
        $v['guardian_relationship'] = $this->free($post, 'guardian_relationship', 50);
        $v['guardian_cnic']         = $this->cnic($post, 'guardian_cnic', $e, "Guardian's CNIC");
        $v['guardian_contact']      = $this->phone($post, 'guardian_contact', $e, "Guardian's contact number");
        $v['guardian_email']        = $this->email($post, 'guardian_email', $e);
        $v['primary_guardian']      = $this->enum($post, 'primary_guardian', array_keys(self::PRIMARY_GUARDIANS), $e, 'primary guardian');
        $v['address_line']          = $this->free($post, 'address_line', 255);
        $v['emergency_name']         = $this->person($post, 'emergency_name', $e, 'Emergency contact name');
        $v['emergency_contact']      = $this->phone($post, 'emergency_contact', $e, 'Emergency contact number');
        $v['emergency_relationship'] = $this->free($post, 'emergency_relationship', 50);

        [$loc, $le] = $this->geo->resolve($post, 'guardian_');
        $e += $le;
        $v += $this->geoCols($loc, '');
        return [$v, $e];
    }

    // ---- Section 5 -----------------------------------------------------------------------

    /** @return array{0: array{answers: array<string,?bool>, records: array<int,array>}, 1: array<string,string>} */
    public function travel(array $post): array
    {
        $e = [];
        $answers = [];
        foreach (['outside_home_city', 'another_district', 'another_province', 'international'] as $k) {
            $raw = $post['ans_' . $k] ?? '';
            $answers[$k] = $raw === '1' ? true : ($raw === '0' ? false : null);
        }

        $records = [];
        $any = in_array(true, $answers, true);
        if ($any) {   // when every answer is No the record fields are hidden and nothing is kept
            $cols = ['country' => 80, 'province' => 80, 'district' => 80, 'city' => 80, 'purpose' => 150, 'start' => 10, 'end' => 10, 'mode' => 60, 'with' => 100, 'notes' => 500];
            $count = is_array($post['rec_country'] ?? null) ? count($post['rec_country']) : 0;
            for ($i = 0; $i < min($count, 20); $i++) {
                $r = [];
                foreach ($cols as $c => $max) {
                    $val = $post['rec_' . $c][$i] ?? '';
                    $r[$c] = is_string($val) ? mb_substr(trim($val), 0, $max) : '';
                }
                if (implode('', $r) === '') {
                    continue;
                }
                $start = $this->dateOrNull($r['start']);
                $end = $this->dateOrNull($r['end']);
                if (($r['start'] !== '' && $start === null) || ($r['end'] !== '' && $end === null)) {
                    $e['rec.' . $i] = 'Enter valid dates (YYYY-MM-DD).';
                } elseif ($start !== null && $end !== null && $end < $start) {
                    $e['rec.' . $i] = 'The end date cannot be before the start date.';
                }
                $r['start'] = $start;
                $r['end'] = $end;
                $records[$i] = $r;
            }
        }
        return [['answers' => $answers, 'records' => $records], $e];
    }

    // ---- helpers -------------------------------------------------------------------------

    private function geoCols(array $loc, string $dbPrefix): array
    {
        return [
            $dbPrefix . 'province_id' => $loc['province_id'],
            $dbPrefix . 'district_id' => $loc['district_id'],
            $dbPrefix . 'city_id'     => $loc['city_id'],
            $dbPrefix . 'city_other'  => $loc['city_other'],
        ];
    }

    private function str(array $post, string $key): string
    {
        $v = $post[$key] ?? '';
        return is_string($v) ? preg_replace('/\s+/u', ' ', trim($v)) ?? '' : '';
    }

    private function free(array $post, string $key, int $max): ?string
    {
        $s = $this->str($post, $key);
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private function person(array $post, string $key, array &$e, string $label): ?string
    {
        $s = $this->str($post, $key);
        if ($s === '') {
            return null;
        }
        if (mb_strlen($s) < 2 || mb_strlen($s) > 100 || !preg_match('/^[\p{L}\p{M}\s.\'\-]+$/u', $s)) {
            $e[$key] = "{$label}: use 2 to 100 letters, spaces, apostrophes, hyphens or full stops.";
            return null;
        }
        return $s;
    }

    private function word(array $post, string $key, int $max, array &$e, string $label): ?string
    {
        $s = $this->str($post, $key);
        if ($s === '') {
            return null;
        }
        if (mb_strlen($s) > $max || !preg_match('/^[\p{L}\p{M}\s.\'\-]+$/u', $s)) {
            $e[$key] = "{$label}: use letters and spaces only.";
            return null;
        }
        return $s;
    }

    private function enum(array $post, string $key, array $allowed, array &$e, string $label): ?string
    {
        $s = $this->str($post, $key);
        if ($s === '') {
            return null;
        }
        if (!in_array($s, $allowed, true)) {
            $e[$key] = "Choose a valid {$label}.";
            return null;
        }
        return $s;
    }

    private function cnic(array $post, string $key, array &$e, string $label): ?string
    {
        $s = $this->str($post, $key);
        if ($s === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $s) ?? '';
        if (strlen($digits) !== 13 || preg_match('/[^\d\-\s]/', $s)) {
            $e[$key] = "{$label}: enter 13 digits, for example 35202-1234567-1.";
            return null;
        }
        return substr($digits, 0, 5) . '-' . substr($digits, 5, 7) . '-' . substr($digits, 12, 1);
    }

    private function phone(array $post, string $key, array &$e, string $label): ?string
    {
        $s = $this->str($post, $key);
        if ($s === '') {
            return null;
        }
        if (!preg_match('/^\+?[0-9][0-9 \-]{6,18}$/', $s)) {
            $e[$key] = "{$label}: enter a valid phone number, for example 0300-1234567.";
            return null;
        }
        return $s;
    }

    private function email(array $post, string $key, array &$e): ?string
    {
        $s = mb_strtolower($this->str($post, $key));
        if ($s === '') {
            return null;
        }
        if (strlen($s) > 254 || !filter_var($s, FILTER_VALIDATE_EMAIL)) {
            $e[$key] = 'Enter a valid email address.';
            return null;
        }
        return $s;
    }

    private function postal(array $post, string $key, array &$e): ?string
    {
        $s = $this->str($post, $key);
        if ($s === '') {
            return null;
        }
        if (!preg_match('/^[0-9A-Za-z \-]{3,10}$/', $s)) {
            $e[$key] = 'Enter a valid postal code.';
            return null;
        }
        return $s;
    }

    private function date(array $post, string $key, array &$e, string $label): ?string
    {
        $s = $this->str($post, $key);
        if ($s === '') {
            return null;
        }
        $d = $this->dateOrNull($s);
        if ($d === null || $d > date('Y-m-d')) {
            $e[$key] = "{$label}: enter a valid date that is not in the future.";
            return null;
        }
        return $d;
    }

    /** Strict YYYY-MM-DD, year 1900 or later; returns the same string or null. */
    private function dateOrNull(string $s): ?string
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        $err = \DateTimeImmutable::getLastErrors();
        if ($dt === false || ($err && ($err['warning_count'] > 0 || $err['error_count'] > 0)) || $dt->format('Y-m-d') !== $s || (int) $dt->format('Y') < 1900) {
            return null;
        }
        return $s;
    }
}
