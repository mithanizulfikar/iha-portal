<?php
declare(strict_types=1);

namespace Iha\Admin;

use Iha\Academic\MarksCalculator;
use Iha\Core\Audit;
use Iha\Core\Database;
use Iha\Eligibility\EligibilityService;
use Iha\Geo\GeoService;
use Iha\QR\QrRenderer;
use Iha\QR\QrService;
use Iha\Student\ApplicationService;
use PDO;

/** Admin-side application queries and review workflow. Callers must have checked permissions first. */
final class AdminApplicationService
{
    public const DECISIONS = ['UNDER_REVIEW', 'APPROVED', 'REJECTED', 'RETURNED_FOR_CORRECTION'];
    private const TRANSITIONS = [
        'SUBMITTED'    => ['UNDER_REVIEW', 'APPROVED', 'REJECTED', 'RETURNED_FOR_CORRECTION'],
        'UNDER_REVIEW' => ['APPROVED', 'REJECTED', 'RETURNED_FOR_CORRECTION'],
    ];

    private const FROM = "FROM applications a
        JOIN student_profiles sp ON sp.id = a.student_profile_id
        JOIN users u ON u.id = sp.user_id
        LEFT JOIN academic_records ar ON ar.application_id = a.id AND ar.is_eligibility_basis
        LEFT JOIN provinces pr ON pr.id = sp.province_id
        LEFT JOIN districts d ON d.id = sp.district_id
        LEFT JOIN cities c ON c.id = sp.city_id
        LEFT JOIN application_eligibility ae ON ae.application_id = a.id
        LEFT JOIN eligibility_results er ON er.id = ae.latest_result_id";

    public function __construct(
        private EligibilityService $eligibility = new EligibilityService(),
        private GeoService $geo = new GeoService(),
        private ApplicationService $apps = new ApplicationService(),
        private QrService $qr = new QrService()
    ) {}

    /**
     * @param array<string,string> $f    filters from the query string
     * @param array<string,bool>   $perm Permissions::flags(); filters/columns that need a permission are ignored without it
     * @return array{rows:array,total:int,pages:int,page:int}
     */
    public function listApplications(array $f, array $perm, int $page, int $perPage = 25): array
    {
        [$w, $p, $qrLatest] = $this->buildFilter($f, $perm);
        $pdo = Database::pdo();

        $st = $pdo->prepare('SELECT count(*) ' . self::FROM . " {$w}");
        $st->execute($p);
        $total = (int) $st->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        $st = $pdo->prepare(
            "SELECT a.id, a.application_number, a.status, a.submitted_at,
                    sp.student_id, sp.full_name, sp.date_of_birth, u.email, ar.institution_name,
                    pr.name AS province, d.name AS district, COALESCE(c.name, sp.city_other) AS city,
                    er.calculated_percentage, er.dob_criterion, er.academic_criterion,
                    ae.effective_status, ae.override_id, {$qrLatest} AS qr_status "
            . self::FROM . " {$w} ORDER BY a.submitted_at DESC NULLS LAST, a.id DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $st->execute($p);
        return ['rows' => $st->fetchAll(), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /** @return array{0:string,1:array,2:string} WHERE clause (with the word WHERE), parameters, and the latest-QR-status subquery */
    private function buildFilter(array $f, array $perm): array
    {
        $where = [];
        $p = [];

        $status = $f['status'] ?? '';
        if (in_array($status, ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'RETURNED_FOR_CORRECTION'], true)) {
            $where[] = 'a.status = ?';
            $p[] = $status;
        } else {
            $where[] = "a.status <> 'DRAFT'";
        }

        $q = trim($f['q'] ?? '');
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            if ($perm['pii']) {
                $where[] = '(sp.student_id ILIKE ? OR a.application_number ILIKE ? OR sp.full_name ILIKE ? OR u.email ILIKE ?)';
                array_push($p, $like, $like, $like, $like);
            } else {
                $where[] = '(sp.student_id ILIKE ? OR a.application_number ILIKE ?)';
                array_push($p, $like, $like);
            }
        }

        if ($perm['elig']) {
            $el = $f['eligibility'] ?? '';
            if (in_array($el, EligibilityService::STATUSES, true)) {
                $where[] = 'ae.effective_status = ?';
                $p[] = $el;
            } elseif ($el === 'NONE') {
                $where[] = 'ae.application_id IS NULL';
            }
            foreach (['pct_min' => '>=', 'pct_max' => '<='] as $k => $op) {
                if (isset($f[$k]) && $f[$k] !== '' && is_numeric($f[$k])) {
                    $where[] = "er.calculated_percentage {$op} ?";
                    $p[] = $f[$k];
                }
            }
        }

        if ($perm['pii']) {
            foreach (['province_id' => 'sp.province_id', 'district_id' => 'sp.district_id', 'city_id' => 'sp.city_id'] as $k => $col) {
                if (isset($f[$k]) && ctype_digit($f[$k])) {
                    $where[] = "{$col} = ?";
                    $p[] = $f[$k];
                }
            }
            $school = trim($f['school'] ?? '');
            if ($school !== '') {
                $where[] = 'ar.institution_name ILIKE ?';
                $p[] = '%' . addcslashes($school, '%_\\') . '%';
            }
        }

        foreach (['date_from' => 'a.submitted_at >= ?::date', 'date_to' => "a.submitted_at < (?::date + 1)"] as $k => $cond) {
            if (!empty($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) {
                $where[] = $cond;
                $p[] = $f[$k];
            }
        }

        $qr = $f['qr'] ?? '';
        $qrLatest = '(SELECT q.status FROM student_qr_codes q WHERE q.application_id = a.id ORDER BY (q.status = \'ACTIVE\') DESC, q.id DESC LIMIT 1)';
        if (in_array($qr, ['ACTIVE', 'REVOKED'], true)) {
            $where[] = "{$qrLatest} = ?";
            $p[] = $qr;
        } elseif ($qr === 'NONE') {
            $where[] = "{$qrLatest} IS NULL";
        }

        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        return [$w, $p, $qrLatest];
    }

    /** Every application matching the filters (no paging, capped at 50,000 rows), for CSV export. */
    public function exportApplications(array $f, array $perm): array
    {
        [$w, $p, $qrLatest] = $this->buildFilter($f, $perm);
        $st = Database::pdo()->prepare(
            "SELECT a.id, a.application_number, a.status, a.submitted_at,
                    sp.student_id, sp.full_name, sp.date_of_birth, u.email, ar.institution_name,
                    pr.name AS province, d.name AS district, COALESCE(c.name, sp.city_other) AS city,
                    er.calculated_percentage, er.dob_criterion, er.academic_criterion,
                    ae.effective_status, ae.override_id, {$qrLatest} AS qr_status "
            . self::FROM . " {$w} ORDER BY a.submitted_at DESC NULLS LAST, a.id DESC LIMIT 50000"
        );
        $st->execute($p);
        return $st->fetchAll();
    }

    /** Full application view for admins. Sections are included only when the permission allows. */
    public function detail(int $appId, array $perm): ?array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT a.*, sp.user_id FROM applications a JOIN student_profiles sp ON sp.id = a.student_profile_id WHERE a.id = ?');
        $st->execute([$appId]);
        $app = $st->fetch();
        if (!$app) {
            return null;
        }
        $st = $pdo->prepare('SELECT sp.*, u.email FROM student_profiles sp JOIN users u ON u.id = sp.user_id WHERE sp.id = ?');
        $st->execute([$app['student_profile_id']]);
        $profile = $st->fetch();

        $d = ['app' => $app, 'profile' => ['student_id' => $profile['student_id'], 'full_name' => $profile['full_name']], 'pii' => null, 'elig' => null];

        if ($perm['pii']) {
            $academic = $this->apps->academicRecord($appId);
            $guardian = $this->apps->guardian($appId);
            $rows = array_map(static fn($s) => [
                'subject_name' => (string) $s['subject_name'],
                'total_marks' => $s['total_marks'] === null ? '' : (string) $s['total_marks'],
                'marks_obtained' => $s['marks_obtained'] === null ? '' : (string) $s['marks_obtained'],
            ], $this->apps->subjects($appId));
            $eval = MarksCalculator::evaluate($rows);
            $d['pii'] = [
                'profile' => $profile,
                'profileLoc' => $this->geo->describe($profile['province_id'], $profile['district_id'], $profile['city_id'], $profile['city_other']),
                'permLoc' => $this->geo->describe($profile['perm_province_id'], $profile['perm_district_id'], $profile['perm_city_id'], $profile['perm_city_other']),
                'academic' => $academic,
                'schoolLoc' => $academic ? $this->geo->describe($academic['province_id'], $academic['district_id'], $academic['city_id'], $academic['city_other']) : '',
                'subjects' => $eval['rows'],
                'totals' => ['total' => MarksCalculator::formatCents($eval['total_cents']), 'obtained' => MarksCalculator::formatCents($eval['obtained_cents']), 'percent' => $eval['overall_percent']],
                'guardian' => $guardian,
                'guardianLoc' => $guardian ? $this->geo->describe($guardian['province_id'], $guardian['district_id'], $guardian['city_id'], $guardian['city_other']) : '',
                'travel' => $this->apps->travelResponses($appId),
                'travelRecords' => $this->apps->travelRecords($appId),
            ];
        }

        if ($perm['elig']) {
            $st = $pdo->prepare(
                'SELECT ae.effective_status, ae.override_id, ae.updated_at,
                        er.calculated_status, er.dob_criterion, er.academic_criterion, er.calculated_percentage, er.reason, er.evaluated_at, er.trigger_event,
                        r.version AS rule_version, r.dob_min, r.dob_max, r.min_percentage,
                        o.override_status, o.reason AS override_reason, o.created_at AS override_at, ou.full_name AS override_by
                 FROM application_eligibility ae
                 JOIN eligibility_results er ON er.id = ae.latest_result_id
                 JOIN eligibility_rules r ON r.id = er.rule_id
                 LEFT JOIN eligibility_overrides o ON o.id = ae.override_id
                 LEFT JOIN users ou ON ou.id = o.created_by
                 WHERE ae.application_id = ?'
            );
            $st->execute([$appId]);
            $cur = $st->fetch() ?: null;

            $st = $pdo->prepare('SELECT er.*, r.version AS rule_version FROM eligibility_results er JOIN eligibility_rules r ON r.id = er.rule_id WHERE er.application_id = ? ORDER BY er.id DESC');
            $st->execute([$appId]);
            $history = $st->fetchAll();
            $st = $pdo->prepare('SELECT o.*, u.full_name FROM eligibility_overrides o JOIN users u ON u.id = o.created_by WHERE o.application_id = ? ORDER BY o.id DESC');
            $st->execute([$appId]);
            $d['elig'] = ['current' => $cur, 'history' => $history, 'overrides' => $st->fetchAll()];
        }

        $st = $pdo->prepare('SELECT rv.decision, rv.reason, rv.decided_at, u.full_name FROM application_reviews rv JOIN users u ON u.id = rv.reviewer_id WHERE rv.application_id = ? ORDER BY rv.id DESC');
        $st->execute([$appId]);
        $d['reviews'] = $st->fetchAll();
        $st = $pdo->prepare('SELECT n.note, n.created_at, u.full_name FROM review_notes n JOIN users u ON u.id = n.author_id WHERE n.application_id = ? ORDER BY n.id DESC');
        $st->execute([$appId]);
        $d['notes'] = $st->fetchAll();

        $d['audit'] = [];
        if ($perm['audit']) {
            $st = $pdo->prepare(
                "SELECT al.action, al.actor_role, al.created_at, u.full_name FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_user_id
                 WHERE al.entity_type = 'application' AND al.entity_id = ? ORDER BY al.id DESC LIMIT 50"
            );
            $st->execute([(string) $appId]);
            $d['audit'] = $st->fetchAll();
        }

        $st = $pdo->prepare("SELECT status FROM student_qr_codes WHERE application_id = ? ORDER BY (status = 'ACTIVE') DESC, id DESC LIMIT 1");
        $st->execute([$appId]);
        $d['qr_status'] = $st->fetchColumn() ?: null;

        $d['qr'] = null;
        if ($perm['qr'] ?? false) {
            $active = $this->qr->activeForApplication($appId);
            $url = $active ? $this->qr->urlFor($active) : null;
            $d['qr'] = [
                'active' => $active,
                'dataUri' => $url ? QrRenderer::dataUri($url) : null,
                'key_ok' => QrService::keyOk(),
                'lib' => QrRenderer::available(),
                'scan' => $this->qr->scanSummary($appId),
            ];
        }
        return $d;
    }

    /** Move an application through the review workflow. Throws \InvalidArgumentException with an admin-facing message. */
    public function review(int $appId, int $adminId, string $role, string $decision, string $reason, string $studentMessage = ''): void
    {
        $reason = trim($reason);
        $studentMessage = trim($studentMessage);
        if (!in_array($decision, self::DECISIONS, true)) {
            throw new \InvalidArgumentException('Choose a valid decision.');
        }
        if ($decision !== 'UNDER_REVIEW' && mb_strlen($reason) < 5) {
            throw new \InvalidArgumentException('A reason of at least 5 characters is required.');
        }
        if (mb_strlen($studentMessage) > 500) {
            throw new \InvalidArgumentException('The message to the student is too long (500 characters maximum).');
        }

        Database::transaction(function (PDO $pdo) use ($appId, $adminId, $role, $decision, $reason, $studentMessage): void {
            $st = $pdo->prepare('SELECT a.id, a.status, a.application_number, sp.user_id FROM applications a JOIN student_profiles sp ON sp.id = a.student_profile_id WHERE a.id = ? FOR UPDATE OF a');
            $st->execute([$appId]);
            $app = $st->fetch();
            if (!$app) {
                throw new \InvalidArgumentException('Application not found.');
            }
            if (!in_array($decision, self::TRANSITIONS[$app['status']] ?? [], true)) {
                throw new \InvalidArgumentException('This application cannot be moved from ' . str_replace('_', ' ', $app['status']) . ' to ' . str_replace('_', ' ', $decision) . '.');
            }
            if ($decision === 'APPROVED') {
                $st = $pdo->prepare('SELECT effective_status FROM application_eligibility WHERE application_id = ?');
                $st->execute([$appId]);
                if ($st->fetchColumn() !== 'ELIGIBLE') {
                    throw new \InvalidArgumentException('Cannot approve: the effective eligibility status is not ELIGIBLE. Resolve it first (recalculate, or record an authorised override with a documented reason).');
                }
            }

            $pdo->prepare('INSERT INTO application_reviews (application_id, reviewer_id, decision, reason) VALUES (?, ?, ?, ?)')
                ->execute([$appId, $adminId, $decision, $reason === '' ? null : $reason]);
            $pdo->prepare('UPDATE applications SET status = ? WHERE id = ?')->execute([$decision, $appId]);
            $this->qr->sync($appId, $adminId, $role);   // rejection or return revokes an active QR code

            $n = $app['application_number'];
            $label = str_replace('_', ' ', $decision);
            $body = match ($decision) {
                'UNDER_REVIEW' => "Your application {$n} is now under review.",
                'RETURNED_FOR_CORRECTION' => $studentMessage !== '' ? $studentMessage : "Your application {$n} has been returned for correction. Please review your details and submit again.",
                default => "The status of your application {$n} is now {$label}.",
            };
            $pdo->prepare("INSERT INTO notifications (user_id, type, title, body) VALUES (?, 'APPLICATION_STATUS', ?, ?)")
                ->execute([$app['user_id'], 'Application ' . strtolower($label), $body]);

            Audit::log('APPLICATION_' . $decision, 'application', $appId, $adminId, $role, ['status' => $app['status']], ['status' => $decision, 'reason' => $reason]);
        });
    }

    public function addNote(int $appId, int $adminId, string $role, string $note): void
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 2000) {
            throw new \InvalidArgumentException('Enter a note (up to 2000 characters).');
        }
        Database::transaction(function (PDO $pdo) use ($appId, $adminId, $role, $note): void {
            $pdo->prepare('INSERT INTO review_notes (application_id, author_id, note) VALUES (?, ?, ?)')->execute([$appId, $adminId, $note]);
            Audit::log('REVIEW_NOTE_ADDED', 'application', $appId, $adminId, $role);
        });
    }

    public function applicationExists(int $appId): bool
    {
        $st = Database::pdo()->prepare('SELECT 1 FROM applications WHERE id = ?');
        $st->execute([$appId]);
        return $st->fetchColumn() !== false;
    }

    public function eligibility(): EligibilityService
    {
        return $this->eligibility;
    }

    public function qr(): QrService
    {
        return $this->qr;
    }
}
