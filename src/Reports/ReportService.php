<?php
declare(strict_types=1);

namespace Iha\Reports;

use Iha\Core\Database;

/**
 * Aggregate statistics, always computed from the database (nothing hard-coded).
 *
 * Counting rules:
 *  - "Registered students" counts student ACCOUNTS. Verified/unverified split the same set.
 *  - "Applications" counts application records. A student can have more than one over time; each counts once.
 *  - "Submitted" means submitted at least once (submitted_at is set), whatever its status is now. This is also the
 *    denominator of the eligibility percentage's "submitted" figure. Draft/Under review/Approved/Rejected count by current status.
 *  - Eligible / Ineligible / Pending review count submitted applications by their CURRENT effective status
 *    (calculated result, or the administrator's override when one is in effect).
 *  - Eligibility percentage = Eligible / (Eligible + Ineligible + Pending review), i.e. evaluated submitted applications.
 *  - QR codes count code records: Active, Revoked, and Issued (= both, over all time).
 */
final class ReportService
{
    private const BASE_FROM = 'FROM applications a
        JOIN student_profiles sp ON sp.id = a.student_profile_id
        LEFT JOIN academic_records ar ON ar.application_id = a.id AND ar.is_eligibility_basis';

    private const PCT_FROM = 'FROM applications a
        JOIN student_profiles sp ON sp.id = a.student_profile_id
        LEFT JOIN academic_records ar ON ar.application_id = a.id AND ar.is_eligibility_basis
        JOIN (SELECT r.application_id, SUM(s.marks_obtained) AS got, SUM(s.total_marks) AS tot
              FROM academic_subjects s JOIN academic_records r ON r.id = s.academic_record_id AND r.is_eligibility_basis
              WHERE s.is_valid GROUP BY r.application_id HAVING SUM(s.total_marks) > 0) p ON p.application_id = a.id
        LEFT JOIN provinces pr ON pr.id = sp.province_id
        LEFT JOIN districts d ON d.id = sp.district_id
        LEFT JOIN cities c ON c.id = sp.city_id';

    public const BUCKETS = ['0-39.99', '40-49.99', '50-59.99', '60-69.99', '70-79.99', '80-89.99', '90-100'];

    /** @return array{general:array,elig:?array,qr:?array} */
    public function dashboard(array $perm): array
    {
        $pdo = Database::pdo();
        $g = $pdo->query(
            "SELECT
              (SELECT count(*) FROM users WHERE role = 'student') AS students,
              (SELECT count(*) FROM users WHERE role = 'student' AND email_verified_at IS NOT NULL) AS verified,
              (SELECT count(*) FROM users WHERE role = 'student' AND email_verified_at IS NULL) AS unverified,
              (SELECT count(*) FROM applications) AS applications,
              (SELECT count(*) FROM applications WHERE status = 'DRAFT') AS draft,
              (SELECT count(*) FROM applications WHERE submitted_at IS NOT NULL) AS submitted,
              (SELECT count(*) FROM applications WHERE status = 'UNDER_REVIEW') AS under_review,
              (SELECT count(*) FROM applications WHERE status = 'APPROVED') AS approved,
              (SELECT count(*) FROM applications WHERE status = 'REJECTED') AS rejected"
        )->fetch();

        $elig = $qr = null;
        if ($perm['elig'] ?? false) {
            $elig = $pdo->query(
                "SELECT count(*) FILTER (WHERE ae.effective_status = 'ELIGIBLE') AS eligible,
                        count(*) FILTER (WHERE ae.effective_status = 'INELIGIBLE') AS ineligible,
                        count(*) FILTER (WHERE ae.effective_status = 'PENDING_REVIEW') AS pending
                 FROM application_eligibility ae JOIN applications a ON a.id = ae.application_id WHERE a.submitted_at IS NOT NULL"
            )->fetch();
            $qr = $pdo->query(
                "SELECT count(*) FILTER (WHERE status = 'ACTIVE') AS active, count(*) FILTER (WHERE status = 'REVOKED') AS revoked FROM student_qr_codes"
            )->fetch();
        }
        return ['general' => $g, 'elig' => $elig, 'qr' => $qr];
    }

    /** @return array<string,int|string|null> */
    public function eligibility(array $f): array
    {
        [$w, $p] = $this->appFilter($f);
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "SELECT count(*) AS submitted,
                    count(*) FILTER (WHERE ae.effective_status = 'ELIGIBLE') AS eligible,
                    count(*) FILTER (WHERE ae.effective_status = 'INELIGIBLE') AS ineligible,
                    count(*) FILTER (WHERE ae.effective_status = 'PENDING_REVIEW') AS pending,
                    count(*) FILTER (WHERE ae.application_id IS NULL) AS not_evaluated,
                    count(*) FILTER (WHERE ae.override_id IS NOT NULL) AS overridden
             " . self::BASE_FROM . ' LEFT JOIN application_eligibility ae ON ae.application_id = a.id WHERE ' . $w
        );
        $st->execute($p);
        $r = $st->fetch();

        $st = $pdo->prepare(
            "SELECT count(*) AS issued, count(*) FILTER (WHERE q.status = 'ACTIVE') AS active, count(*) FILTER (WHERE q.status = 'REVOKED') AS revoked
             FROM student_qr_codes q JOIN applications a ON a.id = q.application_id
             JOIN student_profiles sp ON sp.id = a.student_profile_id
             LEFT JOIN academic_records ar ON ar.application_id = a.id AND ar.is_eligibility_basis WHERE " . $w
        );
        $st->execute($p);
        $q = $st->fetch();

        $evaluated = (int) $r['eligible'] + (int) $r['ineligible'] + (int) $r['pending'];
        $r['evaluated'] = $evaluated;
        $r['pct_eligible'] = $evaluated > 0 ? sprintf('%.2f', (int) $r['eligible'] * 100 / $evaluated) : null;
        $r['qr_issued'] = (int) $q['issued'];
        $r['qr_active'] = (int) $q['active'];
        $r['qr_revoked'] = (int) $q['revoked'];
        return $r;
    }

    /**
     * @return array{summary:array,distribution:array<string,int>,provinces:array,districts:array,cities:array,
     *               schools:array,school_total:int,school_pages:int,school_page:int,subjects:array}
     */
    public function academic(array $f, int $schoolPage = 1, int $perPage = 20): array
    {
        [$w, $p] = $this->appFilter($f);
        $pdo = Database::pdo();
        $pct = '(p.got * 100.0 / p.tot)';

        $st = $pdo->prepare(
            "SELECT count(*) AS n,
                    count(*) FILTER (WHERE p.got * 100 >= 60 * p.tot) AS ge60,
                    count(*) FILTER (WHERE p.got * 100 < 60 * p.tot) AS lt60,
                    round(avg({$pct})::numeric, 2) AS avg_pct " . self::PCT_FROM . " WHERE {$w}"
        );
        $st->execute($p);
        $summary = $st->fetch();

        $st = $pdo->prepare(
            "SELECT CASE WHEN {$pct} < 40 THEN '0-39.99' WHEN {$pct} < 50 THEN '40-49.99' WHEN {$pct} < 60 THEN '50-59.99'
                         WHEN {$pct} < 70 THEN '60-69.99' WHEN {$pct} < 80 THEN '70-79.99' WHEN {$pct} < 90 THEN '80-89.99' ELSE '90-100' END AS bucket,
                    count(*) AS n " . self::PCT_FROM . " WHERE {$w} GROUP BY 1"
        );
        $st->execute($p);
        $found = $st->fetchAll(\PDO::FETCH_KEY_PAIR);
        $dist = [];
        foreach (self::BUCKETS as $b) {
            $dist[$b] = (int) ($found[$b] ?? 0);
        }

        $group = function (string $label, string $expr) use ($pdo, $w, $p, $pct): array {
            $st = $pdo->prepare(
                "SELECT {$expr} AS label, count(*) AS n, round(avg({$pct})::numeric, 2) AS avg_pct, count(*) FILTER (WHERE p.got * 100 >= 60 * p.tot) AS ge60 "
                . self::PCT_FROM . " WHERE {$w} AND {$expr} IS NOT NULL GROUP BY 1 ORDER BY n DESC, label LIMIT 50"
            );
            $st->execute($p);
            return $st->fetchAll();
        };

        $st = $pdo->prepare('SELECT count(DISTINCT lower(btrim(ar.institution_name))) ' . self::PCT_FROM . " WHERE {$w} AND ar.institution_name IS NOT NULL");
        $st->execute($p);
        $schoolTotal = (int) $st->fetchColumn();
        $pages = max(1, (int) ceil($schoolTotal / $perPage));
        $schoolPage = min(max(1, $schoolPage), $pages);
        $offset = ($schoolPage - 1) * $perPage;
        $st = $pdo->prepare(
            "SELECT min(ar.institution_name) AS label, count(*) AS n, round(avg({$pct})::numeric, 2) AS avg_pct, count(*) FILTER (WHERE p.got * 100 >= 60 * p.tot) AS ge60 "
            . self::PCT_FROM . " WHERE {$w} AND ar.institution_name IS NOT NULL GROUP BY lower(btrim(ar.institution_name)) ORDER BY n DESC, label LIMIT {$perPage} OFFSET {$offset}"
        );
        $st->execute($p);
        $schools = $st->fetchAll();

        $st = $pdo->prepare(
            "SELECT lower(btrim(s.subject_name)) AS label, count(*) AS n, round(avg(s.marks_obtained * 100.0 / s.total_marks)::numeric, 2) AS avg_pct
             FROM academic_subjects s
             JOIN academic_records ar ON ar.id = s.academic_record_id AND ar.is_eligibility_basis
             JOIN applications a ON a.id = ar.application_id
             JOIN student_profiles sp ON sp.id = a.student_profile_id
             WHERE s.is_valid AND {$w} GROUP BY 1 ORDER BY n DESC, label LIMIT 30"
        );
        $st->execute($p);

        return [
            'summary' => $summary,
            'distribution' => $dist,
            'provinces' => $group('Province', 'pr.name'),
            'districts' => $group('District', 'd.name'),
            'cities' => $group('City', 'COALESCE(c.name, sp.city_other)'),
            'schools' => $schools, 'school_total' => $schoolTotal, 'school_pages' => $pages, 'school_page' => $schoolPage,
            'subjects' => $st->fetchAll(),
        ];
    }

    /** @return array{daily:array,weekly:array,monthly:array,accounts:array,applications:array,range:array} */
    public function registrations(array $f): array
    {
        $isDate = static fn(?string $s): bool => $s !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1;
        $from = $isDate($f['date_from'] ?? null) ? $f['date_from'] : null;
        $to = $isDate($f['date_to'] ?? null) ? $f['date_to'] : null;
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $toD = $to ?? $today->format('Y-m-d');

        $ranges = [
            'daily'   => [$from ?? $today->modify('-29 days')->format('Y-m-d'), $toD, 'day'],
            'weekly'  => [$from ?? $today->modify('-12 weeks')->format('Y-m-d'), $toD, 'week'],
            'monthly' => [$from ?? $today->modify('first day of this month')->modify('-11 months')->format('Y-m-d'), $toD, 'month'],
        ];

        $pdo = Database::pdo();
        $series = [];
        foreach ($ranges as $name => [$a, $b, $unit]) {
            $st = $pdo->prepare(
                "SELECT to_char(date_trunc('{$unit}', u.created_at), 'YYYY-MM-DD') AS label, count(*) AS n
                 FROM users u WHERE u.role = 'student' AND u.created_at >= ?::date AND u.created_at < (?::date + 1) GROUP BY 1 ORDER BY 1"
            );
            $st->execute([$a, $b]);
            $found = $st->fetchAll(\PDO::FETCH_KEY_PAIR);
            if ($unit === 'day') {      // show days with no sign-ups as zero
                $rows = [];
                for ($d = new \DateTimeImmutable($a); $d <= new \DateTimeImmutable($b); $d = $d->modify('+1 day')) {
                    $rows[$d->format('Y-m-d')] = (int) ($found[$d->format('Y-m-d')] ?? 0);
                }
                $series[$name] = $rows;
            } else {
                $series[$name] = array_map('intval', $found);
            }
        }

        [$a, $b] = [$ranges['daily'][0], $ranges['daily'][1]];
        $st = $pdo->prepare(
            "SELECT count(*) AS total, count(*) FILTER (WHERE email_verified_at IS NOT NULL) AS verified, count(*) FILTER (WHERE email_verified_at IS NULL) AS unverified
             FROM users WHERE role = 'student' AND created_at >= ?::date AND created_at < (?::date + 1)"
        );
        $st->execute([$a, $b]);
        $accounts = $st->fetch();
        $st = $pdo->prepare(
            "SELECT count(*) FILTER (WHERE status = 'DRAFT') AS draft, count(*) FILTER (WHERE submitted_at IS NOT NULL) AS submitted,
                    count(*) FILTER (WHERE status = 'SUBMITTED') AS awaiting_review, count(*) FILTER (WHERE status = 'APPROVED') AS approved,
                    count(*) FILTER (WHERE status = 'REJECTED') AS rejected
             FROM applications WHERE created_at >= ?::date AND created_at < (?::date + 1)"
        );
        $st->execute([$a, $b]);

        return ['daily' => $series['daily'], 'weekly' => $series['weekly'], 'monthly' => $series['monthly'],
                'accounts' => $accounts, 'applications' => $st->fetch(), 'range' => [$a, $b]];
    }

    /**
     * CSV header and rows for the applications list, limited to the columns this role may see.
     * @return array{0:array<int,string>,1:array<int,array>}
     */
    public static function applicationsCsv(array $rows, array $perm): array
    {
        $header = ['Student ID', 'Application Number', 'Application Status', 'Submitted'];
        if ($perm['pii'] ?? false) {
            array_push($header, 'Name', 'Email', 'Date of Birth', 'School/College', 'Province', 'District', 'City');
        }
        if ($perm['elig'] ?? false) {
            array_push($header, 'Academic Percentage', 'Eligibility Status', 'Date-of-Birth Criterion', 'Academic Criterion', 'Manual Override');
        }
        $header[] = 'QR Code';

        $out = [];
        foreach ($rows as $r) {
            $line = [$r['student_id'], $r['application_number'], str_replace('_', ' ', $r['status']), $r['submitted_at'] ? substr((string) $r['submitted_at'], 0, 16) : ''];
            if ($perm['pii'] ?? false) {
                array_push($line, $r['full_name'], $r['email'], $r['date_of_birth'], $r['institution_name'], $r['province'], $r['district'], $r['city']);
            }
            if ($perm['elig'] ?? false) {
                array_push($line, $r['calculated_percentage'], $r['effective_status'] ? str_replace('_', ' ', $r['effective_status']) : 'NOT EVALUATED',
                    $r['dob_criterion'], $r['academic_criterion'], $r['override_id'] !== null ? 'Yes' : 'No');
            }
            $line[] = $r['qr_status'] ?? 'None';
            $out[] = $line;
        }
        return [$header, $out];
    }

    /** WHERE fragment (without the word WHERE) and parameters for the submitted-application reports. */
    private function appFilter(array $f): array
    {
        $w = ['a.submitted_at IS NOT NULL'];
        $p = [];
        foreach (['date_from' => 'a.submitted_at >= ?::date', 'date_to' => 'a.submitted_at < (?::date + 1)'] as $k => $cond) {
            if (!empty($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) {
                $w[] = $cond;
                $p[] = $f[$k];
            }
        }
        foreach (['province_id' => 'sp.province_id', 'district_id' => 'sp.district_id', 'city_id' => 'sp.city_id'] as $k => $col) {
            if (!empty($f[$k]) && ctype_digit((string) $f[$k])) {
                $w[] = "{$col} = ?";
                $p[] = $f[$k];
            }
        }
        $school = trim((string) ($f['school'] ?? ''));
        if ($school !== '') {
            $w[] = 'ar.institution_name ILIKE ?';
            $p[] = '%' . addcslashes($school, '%_\\') . '%';
        }
        return [implode(' AND ', $w), $p];
    }
}
