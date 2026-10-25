<?php
declare(strict_types=1);

namespace Iha\Admin;

use Iha\Auth\Auth;
use Iha\Core\Request;
use Iha\Core\Response;
use Iha\Core\Session;
use Iha\Core\View;
use Iha\Geo\GeoService;
use Iha\QR\QrRenderer;

final class AdminController
{
    private AdminApplicationService $svc;

    public function __construct()
    {
        $this->svc = new AdminApplicationService();
    }

    public function applications(): void
    {
        $user = $this->gate('applications.view');
        $perm = Permissions::flags($user['role']);

        $keys = ['q', 'eligibility', 'status', 'province_id', 'district_id', 'city_id', 'school', 'pct_min', 'pct_max', 'date_from', 'date_to', 'qr'];
        $f = [];
        foreach ($keys as $k) {
            $f[$k] = Request::query($k, 100);
        }
        $page = ctype_digit(Request::query('page', 6)) ? (int) Request::query('page', 6) : 1;
        $result = $this->svc->listApplications($f, $perm, $page);

        $geo = new GeoService();
        View::render('admin/applications', [
            'title' => 'Applications',
            'f' => $f, 'perm' => $perm, 'result' => $result,
            'geoOptions' => $geo->optionsFor(['province_id' => $f['province_id'], 'district_id' => $f['district_id']]),
            'qs' => array_filter($f, static fn($v) => $v !== ''),
        ]);
    }

    public function view(): void
    {
        $user = $this->gate('applications.view');
        $perm = Permissions::flags($user['role']);
        $id = Request::query('id', 12);
        $d = ctype_digit($id) ? $this->svc->detail((int) $id, $perm) : null;
        if ($d === null) {
            Response::error(404, 'Not found', 'That application could not be found.');
            return;
        }
        View::render('admin/application_view', ['title' => 'Application ' . $d['app']['application_number'], 'd' => $d, 'perm' => $perm]);
    }

    public function review(): void
    {
        $user = $this->gate('applications.review');
        $id = $this->appId();
        $this->run($id, static function (AdminApplicationService $s) use ($user, $id): string {
            $s->review($id, (int) $user['id'], $user['role'], Request::post('decision', 30), Request::post('reason', 1000), Request::post('student_message', 600));
            return 'Application updated.';
        });
    }

    public function note(): void
    {
        $user = $this->gate('applications.review');
        $id = $this->appId();
        $this->run($id, static function (AdminApplicationService $s) use ($user, $id): string {
            $s->addNote($id, (int) $user['id'], $user['role'], Request::post('note', 2100));
            return 'Internal note added.';
        });
    }

    public function recalculate(): void
    {
        $user = $this->gate('applications.review');
        Permissions::require($user, 'eligibility.view');
        $id = $this->appId();
        $this->run($id, static function (AdminApplicationService $s) use ($user, $id): string {
            $r = $s->eligibility()->evaluate($id, 'MANUAL_RECALC', (int) $user['id'], $user['role']);
            $qrNote = ['issued' => ' A QR code was issued.', 'revoked' => ' The QR code was revoked.', 'blocked_key' => ' A QR code could not be issued: set APP_KEY in .env.'][$r['qr']] ?? '';
            return 'Eligibility recalculated: ' . str_replace('_', ' ', $r['effective']) . ($r['override_cleared'] ? ' (the earlier override no longer applied and was cleared).' : '.') . $qrNote;
        });
    }

    public function override(): void
    {
        $user = $this->gate('eligibility.override');
        $id = $this->appId();
        $this->run($id, static function (AdminApplicationService $s) use ($user, $id): string {
            $s->eligibility()->override($id, Request::post('status', 30), Request::post('reason', 1000), (int) $user['id'], $user['role']);
            return 'Eligibility override recorded. The original calculated result is kept in the history.';
        });
    }

    public function clearOverride(): void
    {
        $user = $this->gate('eligibility.override');
        $id = $this->appId();
        $this->run($id, static function (AdminApplicationService $s) use ($user, $id): string {
            $s->eligibility()->clearOverride($id, Request::post('reason', 1000), (int) $user['id'], $user['role']);
            return 'Override removed. The calculated result applies again.';
        });
    }

    public function qrDownload(): void
    {
        $this->gate('qr.manage');
        $id = Request::query('id', 12);
        $active = ctype_digit($id) ? $this->svc->qr()->activeForApplication((int) $id) : null;
        $url = $active ? $this->svc->qr()->urlFor($active) : null;
        $svg = $url ? QrRenderer::svg($url) : null;
        if ($svg === null) {
            Response::error(404, 'Not available', 'There is no QR code image available for that application.');
            return;
        }
        header('Content-Type: image/svg+xml');
        header('Content-Disposition: attachment; filename="iha-qr-' . (int) $id . '.svg"');
        header('Cache-Control: no-store');
        echo $svg;
    }

    public function qrRevoke(): void
    {
        $user = $this->gate('qr.manage');
        $id = $this->appId();
        $this->run($id, static function (AdminApplicationService $s) use ($user, $id): string {
            $s->qr()->adminRevoke($id, (int) $user['id'], $user['role'], Request::post('reason', 1000));
            return 'QR code revoked. It no longer verifies.';
        });
    }

    public function qrReplace(): void
    {
        $user = $this->gate('qr.manage');
        $id = $this->appId();
        $this->run($id, static function (AdminApplicationService $s) use ($user, $id): string {
            $s->qr()->adminReplace($id, (int) $user['id'], $user['role'], Request::post('reason', 1000));
            return 'A new QR code was issued. Any earlier code is revoked.';
        });
    }

    // ---- helpers ---------------------------------------------------------------------------

    private function gate(string $permission): array
    {
        $user = Auth::requireRole(Auth::ADMIN_ROLES, '/admin/login');
        Permissions::require($user, $permission);
        return $user;
    }

    private function appId(): int
    {
        $id = Request::post('app_id', 12);
        if (!ctype_digit($id) || !$this->svc->applicationExists((int) $id)) {
            Response::error(404, 'Not found', 'That application could not be found.');
            exit;
        }
        return (int) $id;
    }

    private function run(int $id, callable $action): never
    {
        try {
            Session::flash('success', $action($this->svc));
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('Admin action failed: ' . $e->getMessage());
            Session::flash('error', 'The action could not be completed. Nothing was changed.');
        }
        Response::redirect('/admin/applications/view?id=' . $id);
    }
}
