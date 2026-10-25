<?php
declare(strict_types=1);

namespace Iha\QR;

use Iha\Auth\Auth;
use Iha\Auth\RateLimiter;
use Iha\Core\Request;
use Iha\Core\Response;
use Iha\Core\Session;
use Iha\Core\View;

final class QrController
{
    private QrService $qr;

    public function __construct()
    {
        $this->qr = new QrService();
    }

    /** Public page opened by scanning a code. Shows a bare VALID / INVALID plus the few approved fields. */
    public function verify(): void
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        if (!(new RateLimiter())->attempt('qr_verify', RateLimiter::key(Request::ip()), 600, 3600)) {
            Response::error(429, 'Too many requests', 'Too many verification requests from your network. Please try again later.');
            return;
        }
        $result = $this->qr->verify(Request::query('token', 80), Request::ip());
        View::render('qr/verify', ['title' => 'Verification', 'result' => $result]);
    }

    /** Printable student ID card (only while the backend says a QR code is active). */
    public function idCard(): void
    {
        [$user, $qr, $uri] = $this->studentQr();
        $st = \Iha\Core\Database::pdo()->prepare('SELECT student_id, full_name FROM student_profiles WHERE user_id = ?');
        $st->execute([$user['id']]);
        $p = $st->fetch();
        View::render('student/id_card', ['title' => 'Student ID card', 'name' => $p['full_name'], 'studentId' => $p['student_id'], 'qrUri' => $uri]);
    }

    public function download(): void
    {
        [, $qr] = $this->studentQr();
        $svg = $qr['url'] ? QrRenderer::svg($qr['url']) : null;
        if ($svg === null) {
            Response::error(503, 'Not available', 'The QR code image cannot be produced right now.');
            return;
        }
        header('Content-Type: image/svg+xml');
        header('Content-Disposition: attachment; filename="iha-verification-qr.svg"');
        header('Cache-Control: no-store');
        echo $svg;
    }

    /** @return array{0:array,1:array,2:?string} */
    private function studentQr(): array
    {
        $user = Auth::requireRole(['student'], '/login');
        $qr = $this->qr->forUser((int) $user['id']);
        if ($qr === null) {
            Session::flash('info', 'No verification QR Code is currently available for this account.');
            Response::redirect('/student');
        }
        return [$user, $qr, $qr['url'] ? QrRenderer::dataUri($qr['url']) : null];
    }
}
