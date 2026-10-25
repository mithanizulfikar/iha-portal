<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Iha\Admin\AdminController;
use Iha\Admin\SettingsController;
use Iha\Admin\UserAdminController;
use Iha\Auth\AuthController;
use Iha\Core\Database;
use Iha\Core\Router;
use Iha\Core\Session;
use Iha\Geo\GeoController;
use Iha\QR\QrController;
use Iha\Reports\ReportController;
use Iha\Student\ApplicationController;
use Iha\Student\SubmissionController;
use Iha\Web\PageController;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net; "
     . "script-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; "
     . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'");

if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_starts_with((string) \Iha\Core\Env::get('APP_URL', ''), 'https://')) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

Session::start();

$router = new Router();
$pages = new PageController();
$auth  = new AuthController();
$app   = new ApplicationController();
$geo   = new GeoController();
$sub   = new SubmissionController();
$admin = new AdminController();
$qrc   = new QrController();
$rep   = new ReportController();
$set   = new SettingsController();
$usr   = new UserAdminController();

$router->get('/', [$pages, 'landing']);
$router->get('/privacy', [$pages, 'privacy']);
$router->get('/terms', [$pages, 'terms']);

$router->get('/register', [$auth, 'showRegister']);
$router->post('/register', [$auth, 'register']);
$router->get('/register/check-email', [$auth, 'checkEmail']);
$router->get('/verify-email', [$auth, 'showVerify']);
$router->post('/verify-email', [$auth, 'verify']);
$router->post('/resend-verification', [$auth, 'resend']);

$router->get('/login', [$auth, 'showLogin']);
$router->post('/login', [$auth, 'login']);
$router->get('/admin/login', [$auth, 'showAdminLogin']);
$router->post('/admin/login', [$auth, 'adminLogin']);
$router->post('/logout', [$auth, 'logout']);
$router->get('/forgot-password', [$auth, 'showForgot']);
$router->post('/forgot-password', [$auth, 'forgot']);
$router->get('/reset-password', [$auth, 'showReset']);
$router->post('/reset-password', [$auth, 'reset']);
$router->get('/account/password', [$auth, 'showChange']);
$router->post('/account/password', [$auth, 'change']);

$router->get('/student', [$pages, 'studentDashboard']);
$router->get('/admin', [$pages, 'adminDashboard']);

$router->get('/student/application', [$app, 'start']);
$router->get('/student/profile', [$app, 'showProfile']);
$router->post('/student/profile', [$app, 'saveProfile']);
$router->get('/student/academic', [$app, 'showAcademic']);
$router->post('/student/academic', [$app, 'saveAcademic']);
$router->get('/student/marks', [$app, 'showMarks']);
$router->post('/student/marks', [$app, 'saveMarks']);
$router->get('/student/guardian', [$app, 'showGuardian']);
$router->post('/student/guardian', [$app, 'saveGuardian']);
$router->get('/student/travel', [$app, 'showTravel']);
$router->post('/student/travel', [$app, 'saveTravel']);

$router->get('/student/review', [$sub, 'showReview']);
$router->post('/student/submit', [$sub, 'submit']);
$router->get('/student/submitted', [$sub, 'submitted']);

$router->get('/admin/applications', [$admin, 'applications']);
$router->get('/admin/applications/view', [$admin, 'view']);
$router->post('/admin/applications/review', [$admin, 'review']);
$router->post('/admin/applications/note', [$admin, 'note']);
$router->post('/admin/applications/recalculate', [$admin, 'recalculate']);
$router->post('/admin/applications/override', [$admin, 'override']);
$router->post('/admin/applications/override/clear', [$admin, 'clearOverride']);

$router->get('/verify', [$qrc, 'verify']);
$router->get('/student/id-card', [$qrc, 'idCard']);
$router->get('/student/qr/download', [$qrc, 'download']);
$router->get('/admin/applications/qr', [$admin, 'qrDownload']);
$router->post('/admin/applications/qr/revoke', [$admin, 'qrRevoke']);
$router->post('/admin/applications/qr/replace', [$admin, 'qrReplace']);

$router->get('/admin/reports', [$rep, 'reports']);
$router->get('/admin/reports/export', [$rep, 'export']);
$router->get('/admin/users', [$usr, 'index']);
$router->post('/admin/users', [$usr, 'create']);
$router->post('/admin/users/role', [$usr, 'role']);
$router->post('/admin/users/status', [$usr, 'status']);
$router->post('/admin/users/password', [$usr, 'password']);
$router->get('/admin/audit', [$set, 'audit']);
$router->get('/admin/rules', [$set, 'rules']);
$router->post('/admin/rules', [$set, 'publishRule']);
$router->get('/student/academic-report', [$app, 'academicReport']);

$router->get('/api/geo/districts', [$geo, 'districts']);
$router->get('/api/geo/cities', [$geo, 'cities']);

$router->get('/cron/cleanup', static function (): void {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $secret = (string) \Iha\Core\Env::get('CRON_SECRET', '');
    if (strlen($secret) < 16) {                       // disabled until a real secret is configured
        http_response_code(404);
        echo '{"error":"disabled"}';
        return;
    }
    if (!hash_equals('Bearer ' . $secret, (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''))) {
        http_response_code(401);
        echo '{"error":"unauthorised"}';
        return;
    }
    echo json_encode(\Iha\Core\Housekeeping::run());
});

$router->get('/health', static function (): void {
    header('Content-Type: application/json');
    try {
        $n = Database::pdo()->query('SELECT count(*) FROM schema_migrations')->fetchColumn();
        echo json_encode(['status' => 'ok', 'migrations_applied' => (int) $n]);
    } catch (\Throwable $e) {
        http_response_code(503);
        echo json_encode(['status' => 'database_unavailable']);
    }
});

try {
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
} catch (\Throwable $e) {
    error_log('Unhandled error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><p>Something went wrong. Please try again later.</p>';
    if (\Iha\Core\Env::get('APP_ENV') === 'local') {
        echo '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
}
