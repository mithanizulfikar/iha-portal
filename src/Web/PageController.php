<?php
declare(strict_types=1);

namespace Iha\Web;

use Iha\Auth\Auth;
use Iha\Core\Database;
use Iha\Core\Env;
use Iha\Core\View;

final class PageController
{
    public function landing(): void
    {
        // Published criteria come from the active rule so the page can never drift from what is enforced.
        $rule = null;
        try {
            $row = Database::pdo()->query('SELECT dob_min, dob_max, min_percentage FROM eligibility_rules WHERE is_active')->fetch();
            if ($row) {
                $rule = [
                    'dob_min' => (new \DateTimeImmutable($row['dob_min']))->format('j F Y'),
                    'dob_max' => (new \DateTimeImmutable($row['dob_max']))->format('j F Y'),
                    'min_percentage' => rtrim(rtrim(number_format((float) $row['min_percentage'], 2, '.', ''), '0'), '.'),
                ];
            }
        } catch (\Throwable $e) {
            error_log('Landing: could not load rule: ' . $e->getMessage());
        }
        View::render('pages/landing', [
            'title' => 'IHA Student Registration & Eligibility Portal',
            'rule' => $rule,
            'supportEmail' => (string) Env::get('SUPPORT_EMAIL', ''),
            'supportPhone' => (string) Env::get('SUPPORT_PHONE', ''),
        ]);
    }

    public function privacy(): void
    {
        View::render('pages/privacy', ['title' => 'Privacy Policy']);
    }

    public function terms(): void
    {
        View::render('pages/terms', ['title' => 'Terms and Conditions']);
    }

    /** Student dashboard. Student-facing data only: nothing from the eligibility tables is read here. */
    public function studentDashboard(): void
    {
        $user = Auth::requireRole(['student'], '/login');
        $studentId = null;
        if ($user['email_verified_at'] !== null) {
            $st = Database::pdo()->prepare('SELECT student_id FROM student_profiles WHERE user_id = ?');
            $st->execute([$user['id']]);
            $studentId = $st->fetchColumn() ?: null;
        }
        $application = null;
        if ($user['email_verified_at'] !== null) {
            $svc = new \Iha\Student\ApplicationService();
            $profile = $svc->profileByUser((int) $user['id']);
            if ($profile) {
                $a = $svc->currentApplication((int) $profile['id']);
                $raw = json_decode((string) $a['section_status'], true) ?: [];
                $steps = [];
                foreach (array_keys(\Iha\Student\Completeness::SECTIONS) as $n) {
                    $steps[$n] = (bool) ($raw[(string) $n] ?? false);
                }
                $application = [
                    'number' => $a['application_number'],
                    'status' => strtoupper(str_replace('_', ' ', $a['status'])),
                    'completion' => (int) $a['completion_percent'],
                    'steps' => $steps,
                    'editable' => \Iha\Student\ApplicationService::isEditable($a),
                    'submitted_at' => $a['submitted_at'],
                ];
            }
        }
        $notifications = $user['email_verified_at'] !== null
            ? (new \Iha\Student\SubmissionService(\Iha\Mail\MailFactory::make()))->notifications((int) $user['id'])
            : [];
        $qr = null;
        if ($user['email_verified_at'] !== null) {
            $q = (new \Iha\QR\QrService())->forUser((int) $user['id']);
            if ($q !== null) {
                $qr = ['uri' => $q['url'] ? \Iha\QR\QrRenderer::dataUri($q['url']) : null];
            }
        }
        View::render('student/dashboard', [
            'qr' => $qr,
            'notifications' => $notifications,
            'application' => $application,
            'title' => 'Student Portal',
            'verified' => $user['email_verified_at'] !== null,
            'studentId' => $studentId,
            'email' => $user['email'],
            'name' => $user['full_name'],
        ]);
    }

    public function adminDashboard(): void
    {
        $user = Auth::requireRole(Auth::ADMIN_ROLES, '/admin/login');
        $perm = \Iha\Admin\Permissions::flags($user['role']);
        $stats = (new \Iha\Reports\ReportService())->dashboard($perm);
        $st = Database::pdo()->prepare('SELECT permission_code FROM role_permissions WHERE role = ? ORDER BY permission_code');
        $st->execute([$user['role']]);
        View::render('admin/dashboard', [
            'title' => 'Admin Portal',
            'stats' => $stats,
            'perm' => $perm,
            'permissions' => $st->fetchAll(\PDO::FETCH_COLUMN),
            'role' => $user['role'],
        ]);
    }
}
