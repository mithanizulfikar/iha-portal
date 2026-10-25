<?php
declare(strict_types=1);

namespace Iha\Eligibility;

use Iha\Academic\MarksCalculator;
use Iha\Core\Audit;
use Iha\Core\Database;
use Iha\QR\QrService;
use PDO;

/**
 * Runs the engine against stored data and records the outcome. ADMIN-SIDE ONLY: student-facing
 * controllers and templates must never read these tables or call the read methods below.
 */
final class EligibilityService
{
    public const STATUSES = ['ELIGIBLE', 'INELIGIBLE', 'PENDING_REVIEW'];

    public function __construct(private QrService $qr = new QrService()) {}

    public function activeRule(): array
    {
        $row = Database::pdo()->query('SELECT id, version, dob_min, dob_max, min_percentage FROM eligibility_rules WHERE is_active')->fetch();
        if (!$row) {
            throw new \RuntimeException('No active eligibility rule is configured.');
        }
        return $row;
    }

    /**
     * Evaluate an application with the active rule, keep the result as immutable history and update
     * the current effective status. A manual override survives only while the calculated outcome it
     * was based on is unchanged.
     *
     * @return array{result_id:int,calculated:string,effective:string,changed:bool,override_cleared:bool,dob:string,academic:string,percentage:?string,qr:string}
     */
    public function evaluate(int $appId, string $trigger, ?int $actorId = null, ?string $actorRole = null): array
    {
        return Database::transaction(function (PDO $pdo) use ($appId, $trigger, $actorId, $actorRole): array {
            $rule = $this->activeRule();

            $st = $pdo->prepare('SELECT sp.date_of_birth FROM applications a JOIN student_profiles sp ON sp.id = a.student_profile_id WHERE a.id = ?');
            $st->execute([$appId]);
            $dob = $st->fetchColumn();
            $dob = $dob === false || $dob === null ? null : (string) $dob;

            $st = $pdo->prepare(
                'SELECT s.subject_name, s.total_marks, s.marks_obtained
                 FROM academic_subjects s JOIN academic_records r ON r.id = s.academic_record_id
                 WHERE r.application_id = ? AND r.is_eligibility_basis ORDER BY s.sort_order, s.id'
            );
            $st->execute([$appId]);
            $rows = $st->fetchAll();
            $academic = $rows ? MarksCalculator::evaluate(array_map(static fn($r) => [
                'subject_name' => (string) $r['subject_name'],
                'total_marks' => $r['total_marks'] === null ? '' : (string) $r['total_marks'],
                'marks_obtained' => $r['marks_obtained'] === null ? '' : (string) $r['marks_obtained'],
            ], $rows)) : null;

            $r = EligibilityEngine::evaluate($dob, $academic, $rule);

            $st = $pdo->prepare(
                'INSERT INTO eligibility_results (application_id, rule_id, calculated_status, dob_criterion, academic_criterion,
                                                  calculated_percentage, reason, trigger_event, evaluated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
            );
            $st->execute([$appId, $rule['id'], $r['status'], $r['dob'], $r['academic'], $r['percentage'], $r['reason'], $trigger, $actorId]);
            $resultId = (int) $st->fetchColumn();

            $st = $pdo->prepare('SELECT latest_result_id, override_id, effective_status FROM application_eligibility WHERE application_id = ? FOR UPDATE');
            $st->execute([$appId]);
            $cur = $st->fetch() ?: null;

            $overrideId = $cur['override_id'] ?? null;
            $cleared = false;
            if ($overrideId !== null) {
                $st = $pdo->prepare(
                    'SELECT er.calculated_status FROM eligibility_overrides o JOIN eligibility_results er ON er.id = o.based_on_result_id WHERE o.id = ?'
                );
                $st->execute([$overrideId]);
                if ($st->fetchColumn() !== $r['status']) {
                    $overrideId = null;      // the facts changed, so the old decision no longer applies
                    $cleared = true;
                }
            }
            $effective = $r['status'];
            if ($overrideId !== null) {
                $st = $pdo->prepare('SELECT override_status FROM eligibility_overrides WHERE id = ?');
                $st->execute([$overrideId]);
                $effective = (string) $st->fetchColumn();
            }

            $pdo->prepare(
                'INSERT INTO application_eligibility (application_id, latest_result_id, override_id, effective_status)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT (application_id) DO UPDATE
                 SET latest_result_id = EXCLUDED.latest_result_id, override_id = EXCLUDED.override_id, effective_status = EXCLUDED.effective_status'
            )->execute([$appId, $resultId, $overrideId, $effective]);

            $changed = ($cur['effective_status'] ?? null) !== $effective;
            Audit::log(
                $trigger === 'SUBMISSION' ? 'ELIGIBILITY_EVALUATED' : 'ELIGIBILITY_RECALCULATED',
                'application', $appId, $actorId, $actorRole,
                $cur ? ['effective_status' => $cur['effective_status']] : null,
                ['calculated_status' => $r['status'], 'dob' => $r['dob'], 'academic' => $r['academic'], 'percentage' => $r['percentage'],
                 'effective_status' => $effective, 'rule_version' => (int) $rule['version'], 'trigger' => $trigger, 'override_cleared' => $cleared]
            );
            if ($cleared) {
                Audit::log('ELIGIBILITY_OVERRIDE_CLEARED', 'application', $appId, $actorId, $actorRole, null, ['reason' => 'calculated outcome changed']);
            }

            // At submission the application status is updated afterwards and SubmissionService syncs the QR code then.
            $qr = $trigger === 'SUBMISSION' ? ['action' => 'deferred'] : $this->qr->sync($appId, $actorId, $actorRole);
            return [
                'qr' => $qr['action'],
                'result_id' => $resultId, 'calculated' => $r['status'], 'effective' => $effective, 'changed' => $changed,
                'override_cleared' => $cleared, 'dob' => $r['dob'], 'academic' => $r['academic'], 'percentage' => $r['percentage'],
            ];
        });
    }

    /** Manual decision on top of the calculated result. Needs a documented reason; never edits the calculated result. */
    public function override(int $appId, string $status, string $reason, int $adminId, string $role): void
    {
        $reason = trim($reason);
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Choose a valid eligibility status.');
        }
        if (mb_strlen($reason) < 10) {
            throw new \InvalidArgumentException('A documented reason of at least 10 characters is required for an override.');
        }
        Database::transaction(function (PDO $pdo) use ($appId, $status, $reason, $adminId, $role): void {
            $st = $pdo->prepare(
                'SELECT ae.latest_result_id, ae.effective_status, er.calculated_status
                 FROM application_eligibility ae JOIN eligibility_results er ON er.id = ae.latest_result_id
                 WHERE ae.application_id = ? FOR UPDATE OF ae'
            );
            $st->execute([$appId]);
            $cur = $st->fetch();
            if (!$cur) {
                throw new \InvalidArgumentException('There is no calculated result yet. Recalculate eligibility first.');
            }
            $st = $pdo->prepare(
                'INSERT INTO eligibility_overrides (application_id, based_on_result_id, override_status, reason, created_by)
                 VALUES (?, ?, ?, ?, ?) RETURNING id'
            );
            $st->execute([$appId, $cur['latest_result_id'], $status, $reason, $adminId]);
            $overrideId = (int) $st->fetchColumn();
            $pdo->prepare('UPDATE application_eligibility SET override_id = ?, effective_status = ? WHERE application_id = ?')
                ->execute([$overrideId, $status, $appId]);
            Audit::log('ELIGIBILITY_OVERRIDE_SET', 'application', $appId, $adminId, $role,
                ['effective_status' => $cur['effective_status'], 'calculated_status' => $cur['calculated_status']],
                ['effective_status' => $status, 'reason' => $reason, 'override_id' => $overrideId]);
            $this->qr->sync($appId, $adminId, $role);
        });
    }

    /** Remove the override so the calculated result applies again. */
    public function clearOverride(int $appId, string $reason, int $adminId, string $role): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new \InvalidArgumentException('A documented reason of at least 10 characters is required.');
        }
        Database::transaction(function (PDO $pdo) use ($appId, $reason, $adminId, $role): void {
            $st = $pdo->prepare(
                'SELECT ae.override_id, ae.effective_status, er.calculated_status
                 FROM application_eligibility ae JOIN eligibility_results er ON er.id = ae.latest_result_id
                 WHERE ae.application_id = ? FOR UPDATE OF ae'
            );
            $st->execute([$appId]);
            $cur = $st->fetch();
            if (!$cur || $cur['override_id'] === null) {
                throw new \InvalidArgumentException('There is no active override to remove.');
            }
            $pdo->prepare('UPDATE application_eligibility SET override_id = NULL, effective_status = ? WHERE application_id = ?')
                ->execute([$cur['calculated_status'], $appId]);
            Audit::log('ELIGIBILITY_OVERRIDE_CLEARED', 'application', $appId, $adminId, $role,
                ['effective_status' => $cur['effective_status']], ['effective_status' => $cur['calculated_status'], 'reason' => $reason]);
            $this->qr->sync($appId, $adminId, $role);
        });
    }
}
