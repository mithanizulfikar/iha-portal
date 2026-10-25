<?php
declare(strict_types=1);

namespace Iha\Student;

use Iha\Auth\Auth;
use Iha\Core\Request;
use Iha\Core\Response;
use Iha\Core\Session;
use Iha\Core\View;
use Iha\Mail\MailFactory;

/** Student review + submit. Nothing on these pages mentions eligibility. */
final class SubmissionController
{
    private SubmissionService $svc;

    public function __construct()
    {
        $this->svc = new SubmissionService(MailFactory::make());
    }

    public function showReview(): void
    {
        $user = $this->student();
        $data = $this->svc->reviewData((int) $user['id']);
        if ($data === null) {
            Response::error(500, 'Profile not found', 'Your student profile could not be found. Please contact support.');
            return;
        }
        $this->render($data, []);
    }

    public function submit(): void
    {
        $user = $this->student();
        $result = $this->svc->submit((int) $user['id'], Request::post('submission_key', 40), Request::post('declaration', 5) === '1');
        if ($result['status'] === 'submitted' || $result['status'] === 'already') {
            Response::redirect('/student/submitted');
        }
        $data = $this->svc->reviewData((int) $user['id']);
        $this->render($data, $result['errors'] ?? ['Your application could not be submitted.']);
    }

    public function submitted(): void
    {
        $user = $this->student();
        $data = $this->svc->reviewData((int) $user['id']);
        if ($data === null || $data['editable']) {
            Response::redirect('/student/review');
        }
        View::render('student/submitted', [
            'title' => 'Application submitted',
            'number' => $data['app']['application_number'],
            'status' => strtoupper(str_replace('_', ' ', $data['app']['status'])),
        ]);
    }

    private function render(array $data, array $errors): void
    {
        $h = bin2hex(random_bytes(16));
        $key = substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
        View::render('student/review', $data + ['title' => 'Review and submit', 'errors' => $errors, 'submissionKey' => $key]);
    }

    private function student(): array
    {
        $user = Auth::requireRole(['student'], '/login');
        if ($user['email_verified_at'] === null) {
            Session::flash('info', 'Please verify your email address first.');
            Response::redirect('/student');
        }
        return $user;
    }
}
