<?php
declare(strict_types=1);

namespace Iha\Student;

/**
 * Which of the five sections are complete (all submission-required fields filled).
 * Completeness is about form filling only; it says nothing about eligibility.
 */
final class Completeness
{
    public const SECTIONS = [
        1 => ['Student Profile', '/student/profile'],
        2 => ['Academic Portal', '/student/academic'],
        3 => ['Academic Reports and Marks', '/student/marks'],
        4 => ['Parent/Guardian', '/student/guardian'],
        5 => ['Travel History', '/student/travel'],
    ];

    /** @return array{sections: array<int,bool>, percent: int} */
    public static function evaluate(array $profile, ?array $academic, array $subjects, ?array $guardian, ?array $travel): array
    {
        $s = [
            1 => self::profile($profile),
            2 => $academic !== null && self::academic($academic),
            3 => self::marks($subjects),
            4 => $guardian !== null && self::guardian($guardian),
            5 => $travel !== null && self::travel($travel),
        ];
        return ['sections' => $s, 'percent' => count(array_filter($s)) * 20];
    }

    private static function filled(array $row, array $keys): bool
    {
        foreach ($keys as $k) {
            if (!isset($row[$k]) || trim((string) $row[$k]) === '') {
                return false;
            }
        }
        return true;
    }

    private static function location(array $row, string $p = ''): bool
    {
        return !empty($row[$p . 'province_id']) && !empty($row[$p . 'district_id'])
            && (!empty($row[$p . 'city_id']) || !empty($row[$p . 'city_other']));
    }

    private static function profile(array $p): bool
    {
        return self::filled($p, ['full_name', 'father_name', 'mother_name', 'gender', 'date_of_birth', 'nationality', 'contact_number', 'address_line', 'perm_address_line'])
            && self::location($p) && self::location($p, 'perm_');
    }

    private static function academic(array $a): bool
    {
        return self::filled($a, ['institution_name', 'institution_type', 'address_line', 'current_class', 'academic_session', 'exam_board', 'exam_name', 'exam_year', 'roll_number', 'result_status'])
            && self::location($a);
    }

    private static function marks(array $subjects): bool
    {
        $valid = 0;
        foreach ($subjects as $s) {
            if (!$s['is_valid']) {
                return false;        // an unfinished row blocks completion
            }
            $valid++;
        }
        return $valid >= 1;
    }

    private static function guardian(array $g): bool
    {
        if (!self::filled($g, ['father_name', 'father_contact', 'mother_name', 'primary_guardian', 'address_line', 'emergency_name', 'emergency_contact', 'emergency_relationship']) || !self::location($g)) {
            return false;
        }
        return $g['primary_guardian'] !== 'guardian' || self::filled($g, ['guardian_name', 'guardian_relationship', 'guardian_contact']);
    }

    private static function travel(array $t): bool
    {
        foreach (['outside_home_city', 'another_district', 'another_province', 'international'] as $k) {
            if (!array_key_exists($k, $t) || $t[$k] === null) {
                return false;
            }
        }
        return true;
    }
}
