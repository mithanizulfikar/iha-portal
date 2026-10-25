<?php
declare(strict_types=1);

namespace Iha\Student;

use Iha\Academic\MarksCalculator;
use Iha\Core\Audit;
use Iha\Core\Database;
use Iha\Core\Env;
use Iha\Core\View;
use Iha\Eligibility\EligibilityService;
use Iha\Geo\GeoService;
use Iha\Mail\Mailer;
use Iha\QR\QrService;
use PDO;

/**
 * Student review + final submission. Eligibility is evaluated inside the submission transaction but
 * its result is never returned to, or shown in, anything the student sees.
 */
final class SubmissionService
{
    public function __construct(
        private Mailer $mailer,
        private ApplicationService $apps = new ApplicationService(),
        private EligibilityService $eligibility = new EligibilityService(),
        private GeoService $geo = new GeoService(),
        private QrService $qr = new QrService()
    ) {}

    /** Everything the review / read-only page shows. Student-authorised fields only. */
    public function reviewData(int $userId): ?array
    {
        $profile = $this->apps->profileByUser($userId);
        if ($profile === null) {
            return null;
        }
        $app = $this->apps->currentApplication((int) $profile['id']);
        $appId = (int) $app['id'];
        $academic = $this->apps->academicRecord($appId);
        $subjects = $this->apps->subjects($appId);
        $guardian = $this->apps->guardian($appId);

        $rows = array_map(static fn($s) => [
            'subject_name' => (string) $s['subject_name'],
            'total_marks' => $s['total_marks'] === null ? '' : (string) $s['total_marks'],
            'marks_obtained' => $s['marks_obtained'] === null ? '' : (string) $s['marks_obtained'],
        ], $subjects);
        $eval = MarksCalculator::evaluate($rows);

        $raw = json_decode((string) $app['section_status'], true) ?: [];
        $steps = [];
        foreach (array_keys(Completeness::SECTIONS) as $n) {
            $steps[$n] = (bool) ($raw[(string) $n] ?? false);
        }

        return [
            'app' => $app,
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
            'steps' => $steps,
            'completion' => (int) $app['completion_percent'],
            'editable' => ApplicationService::isEditable($app),
            'complete' => count(array_filter($steps)) === count($steps),
        ];
    }

    /**
     * @return array{status:string, errors?:array<int,string>, application_number?:string}
     *   status: submitted | already | blocked
     */
    public function submit(int $userId, string $submissionKey, bool $declared): array
    {
        $profile = $this->apps->profileByUser($userId);
        $st = Database::pdo()->prepare('SELECT email, full_name, email_verified_at FROM users WHERE id = ?');
        $st->execute([$userId]);
        $user = $st->fetch();
        if ($profile === null || !$user || $user['email_verified_at'] === null) {
            return ['status' => 'blocked', 'errors' => ['Your email address must be verified before you can submit.']];
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $submissionKey)) {
            return ['status' => 'blocked', 'errors' => ['The form expired. Please reload the review page and try again.']];
        }

        $out = Database::transaction(function (PDO $pdo) use ($profile, $submissionKey, $declared, $userId): array {
            // Row lock: a second click or a network retry waits here, then finds the application already submitted.
            $st = $pdo->prepare('SELECT * FROM applications WHERE student_profile_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $st->execute([$profile['id']]);
            $app = $st->fetch();
            if (!$app) {
                return ['status' => 'blocked', 'errors' => ['No application was found.']];
            }
            if (!ApplicationService::isEditable($app)) {
                return ['status' => 'already', 'application_number' => $app['application_number']];
            }
            $appId = (int) $app['id'];

            $completion = $this->apps->refreshCompletion($appId, (int) $profile['id']);
            $errors = [];
            foreach ($completion['sections'] as $n => $done) {
                if (!$done) {
                    $errors[] = 'Section ' . $n . ' (' . Completeness::SECTIONS[$n][0] . ') is not complete.';
                }
            }
            if (!$declared) {
                $errors[] = 'You must accept the declaration and consent.';
            }
            if ($errors) {
                return ['status' => 'blocked', 'errors' => $errors];
            }

            // Recalculates the percentage on the server and records the (admin-only) eligibility result.
            $this->eligibility->evaluate($appId, 'SUBMISSION');

            $pdo->prepare(
                "UPDATE applications SET status = 'SUBMITTED', submitted_at = now(), declaration_accepted_at = now(), submission_key = ?::uuid WHERE id = ?"
            )->execute([$submissionKey, $appId]);

            // Issue the QR code only if every condition holds (eligible, submitted, complete, verified).
            // A QR problem (for example a missing migration or APP_KEY) must never block a student's submission.
            // sync() runs in its own savepoint, so a failure here rolls back only the QR work.
            try {
                $this->qr->sync($appId);
            } catch (\Throwable $e) {
                error_log('QR sync failed for application ' . $appId . ': ' . $e->getMessage());
            }

            $st = $pdo->prepare(
                "INSERT INTO notifications (user_id, type, title, body) VALUES (?, 'APPLICATION_SUBMITTED', 'Application submitted', ?) RETURNING id"
            );
            $st->execute([$userId, "Your application {$app['application_number']} has been submitted and is awaiting review."]);
            $notificationId = (int) $st->fetchColumn();

            Audit::log('APPLICATION_SUBMITTED', 'application', $appId, $userId, 'student', ['status' => $app['status']], ['status' => 'SUBMITTED']);
            return ['status' => 'submitted', 'application_number' => $app['application_number'], 'notification_id' => $notificationId];
        });

        if ($out['status'] === 'submitted') {
            $sent = false;
            try {
                $num = $out['application_number'];
                $sent = $this->mailer->send(
                    $user['email'],
                    "Application {$num} received",
                    View::capture('emails/submitted', ['name' => $user['full_name'], 'number' => $num, 'portalUrl' => rtrim((string) Env::get('APP_URL', ''), '/') . '/student']),
                    "Hello {$user['full_name']},\n\nWe have received your application.\n\nApplication number: {$num}\nStatus: SUBMITTED\n\nYou can follow its status in the Student Portal: "
                    . rtrim((string) Env::get('APP_URL', ''), '/') . "/student\n"
                );
            } catch (\Throwable $e) {
                error_log('Confirmation email failed: ' . $e->getMessage());
            }
            Database::pdo()->prepare('UPDATE notifications SET email_status = ? WHERE id = ?')->execute([$sent ? 'SENT' : 'FAILED', $out['notification_id']]);
        }
        return $out;
    }

    /** Latest notifications for the dashboard. */
    public function notifications(int $userId, int $limit = 5): array
    {
        $st = Database::pdo()->prepare('SELECT title, body, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) $limit);
        $st->execute([$userId]);
        return $st->fetchAll();
    }
}
