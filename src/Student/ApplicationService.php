<?php
declare(strict_types=1);

namespace Iha\Student;

use Iha\Core\Database;
use PDO;
use PDOStatement;

/**
 * Student-facing data access. Everything is located through the logged-in user's id, never an id
 * supplied by the browser, and nothing here touches the eligibility tables.
 */
final class ApplicationService
{
    public const EDITABLE = ['DRAFT', 'RETURNED_FOR_CORRECTION'];

    public function profileByUser(int $userId): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM student_profiles WHERE user_id = ?');
        $st->execute([$userId]);
        return $st->fetch() ?: null;
    }

    /** The student's latest application, creating the first draft if none exists. */
    public function currentApplication(int $profileId): array
    {
        $pdo = Database::pdo();
        $find = $pdo->prepare('SELECT * FROM applications WHERE student_profile_id = ? ORDER BY id DESC LIMIT 1');
        $find->execute([$profileId]);
        $row = $find->fetch();
        if ($row) {
            return $row;
        }
        // The partial unique index makes a double-click harmless: the second insert does nothing.
        $pdo->prepare('INSERT INTO applications (student_profile_id) VALUES (?) ON CONFLICT DO NOTHING')->execute([$profileId]);
        $find->execute([$profileId]);
        return $find->fetch();
    }

    public static function isEditable(array $app): bool
    {
        return in_array($app['status'], self::EDITABLE, true);
    }

    // ---- loading ---------------------------------------------------------------------------

    public function academicRecord(int $appId): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM academic_records WHERE application_id = ? AND is_eligibility_basis');
        $st->execute([$appId]);
        $row = $st->fetch() ?: null;
        if ($row !== null) {
            unset($row['is_eligibility_basis']);   // internal flag: never hand it to student-facing code
        }
        return $row;
    }

    /** @return array<int,array> */
    public function subjects(int $appId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT s.id, s.subject_name, s.total_marks, s.marks_obtained, s.is_valid
             FROM academic_subjects s JOIN academic_records r ON r.id = s.academic_record_id
             WHERE r.application_id = ? AND r.is_eligibility_basis ORDER BY s.sort_order, s.id'
        );
        $st->execute([$appId]);
        return $st->fetchAll();
    }

    public function guardian(int $appId): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM parent_guardians WHERE application_id = ?');
        $st->execute([$appId]);
        return $st->fetch() ?: null;
    }

    public function travelResponses(int $appId): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM travel_responses WHERE application_id = ?');
        $st->execute([$appId]);
        return $st->fetch() ?: null;
    }

    /** @return array<int,array> */
    public function travelRecords(int $appId): array
    {
        $st = Database::pdo()->prepare('SELECT * FROM travel_history WHERE application_id = ? ORDER BY sort_order, id');
        $st->execute([$appId]);
        return $st->fetchAll();
    }

    // ---- saving (each runs in a transaction and refreshes completion) ------------------------

    public function saveProfile(int $profileId, int $appId, array $values): void
    {
        Database::transaction(function () use ($profileId, $appId, $values): void {
            $this->update('student_profiles', 'id', $profileId, $values);
            $this->refreshCompletion($appId, $profileId);
        });
    }

    public function saveAcademic(int $profileId, int $appId, array $values): void
    {
        Database::transaction(function () use ($profileId, $appId, $values): void {
            $id = $this->ensureAcademicRecord($appId);
            $this->update('academic_records', 'id', $id, $values);
            $this->refreshCompletion($appId, $profileId);
        });
    }

    /** Replaces the subject list. $rows come from MarksCalculator::evaluate() and contain no errors. */
    public function saveSubjects(int $profileId, int $appId, array $rows): void
    {
        Database::transaction(function (PDO $pdo) use ($profileId, $appId, $rows): void {
            $recId = $this->ensureAcademicRecord($appId);
            $pdo->prepare('DELETE FROM academic_subjects WHERE academic_record_id = ?')->execute([$recId]);
            $ins = $pdo->prepare(
                'INSERT INTO academic_subjects (academic_record_id, sort_order, subject_name, total_marks, marks_obtained)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $n = 0;
            foreach ($rows as $r) {
                $t = $r['total_marks'] === '' ? null : MarksSql::dec($r['total_marks']);
                $o = $r['marks_obtained'] === '' ? null : MarksSql::dec($r['marks_obtained']);
                $this->exec($ins, [$recId, $n++, $r['subject_name'], $t, $o]);
            }
            $this->refreshCompletion($appId, $profileId);
        });
    }

    public function saveGuardian(int $profileId, int $appId, array $values): void
    {
        Database::transaction(function (PDO $pdo) use ($profileId, $appId, $values): void {
            $pdo->prepare('INSERT INTO parent_guardians (application_id) VALUES (?) ON CONFLICT (application_id) DO NOTHING')->execute([$appId]);
            $this->update('parent_guardians', 'application_id', $appId, $values);
            $this->refreshCompletion($appId, $profileId);
        });
    }

    public function saveTravel(int $profileId, int $appId, array $answers, array $records): void
    {
        Database::transaction(function (PDO $pdo) use ($profileId, $appId, $answers, $records): void {
            $pdo->prepare('INSERT INTO travel_responses (application_id) VALUES (?) ON CONFLICT (application_id) DO NOTHING')->execute([$appId]);
            $this->update('travel_responses', 'application_id', $appId, $answers);
            $pdo->prepare('DELETE FROM travel_history WHERE application_id = ?')->execute([$appId]);
            $ins = $pdo->prepare(
                'INSERT INTO travel_history (application_id, sort_order, country_visited, province_state, district_visited, city_visited,
                                             purpose, start_date, end_date, mode_of_travel, accompanied_by, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $n = 0;
            foreach ($records as $r) {
                $nz = static fn(string $s): ?string => $s === '' ? null : $s;
                $this->exec($ins, [$appId, $n++, $nz($r['country']), $nz($r['province']), $nz($r['district']), $nz($r['city']),
                    $nz($r['purpose']), $r['start'], $r['end'], $nz($r['mode']), $nz($r['with']), $nz($r['notes'])]);
            }
            $this->refreshCompletion($appId, $profileId);
        });
    }

    /** Recomputes which sections are complete and stores the result on the application. */
    public function refreshCompletion(int $appId, int $profileId): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT * FROM student_profiles WHERE id = ?');
        $st->execute([$profileId]);
        $profile = $st->fetch();

        $result = Completeness::evaluate(
            $profile,
            $this->academicRecord($appId),
            $this->subjects($appId),
            $this->guardian($appId),
            $this->travelResponses($appId)
        );
        $pdo->prepare('UPDATE applications SET completion_percent = ?, section_status = ?::jsonb WHERE id = ?')
            ->execute([$result['percent'], json_encode($result['sections']), $appId]);
        return $result;
    }

    // ---- internals ---------------------------------------------------------------------------

    private function ensureAcademicRecord(int $appId): int
    {
        $rec = $this->academicRecord($appId);
        if ($rec) {
            return (int) $rec['id'];
        }
        $st = Database::pdo()->prepare('INSERT INTO academic_records (application_id, is_eligibility_basis) VALUES (?, TRUE) RETURNING id');
        $st->execute([$appId]);
        return (int) $st->fetchColumn();
    }

    /** Column names come from validator code (never from the browser); values are always bound. */
    private function update(string $table, string $keyCol, int $key, array $cols): void
    {
        if ($cols === []) {
            return;
        }
        $sets = array_map(static fn(string $c): string => "{$c} = ?", array_keys($cols));
        $st = Database::pdo()->prepare("UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$keyCol} = ?");
        $this->exec($st, [...array_values($cols), $key]);
    }

    /** Binds with explicit types: PDO's execute() would send PHP false as '' which PostgreSQL rejects for booleans. */
    private function exec(PDOStatement $st, array $params): void
    {
        foreach (array_values($params) as $i => $v) {
            $type = match (true) {
                is_null($v) => PDO::PARAM_NULL,
                is_bool($v) => PDO::PARAM_BOOL,
                is_int($v)  => PDO::PARAM_INT,
                default     => PDO::PARAM_STR,
            };
            $st->bindValue($i + 1, $v, $type);
        }
        $st->execute();
    }
}
