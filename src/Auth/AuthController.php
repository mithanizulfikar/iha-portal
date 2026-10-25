<?php
declare(strict_types=1);

namespace Iha\Auth;

use Iha\Core\Request;
use Iha\Core\Response;
use Iha\Core\Session;
use Iha\Core\View;
use Iha\Mail\MailFactory;

final class AuthController
{
    private AuthService $auth;
    private PasswordService $passwords;
    private RateLimiter $limiter;

    public function __construct()
    {
        $this->limiter = new RateLimiter();
        $mailer = MailFactory::make();
        $this->auth = new AuthService($mailer, $this->limiter);
        $this->passwords = new PasswordService($mailer, $this->limiter, $this->auth);
    }

    // ---- registration -------------------------------------------------------------------

    public function showRegister(): void
    {
        $this->redirectIfLoggedIn();
        View::render('auth/register', ['title' => 'Create your account', 'errors' => [], 'old' => []]);
    }

    public function register(): void
    {
        $this->redirectIfLoggedIn();
        if (!$this->limiter->attempt('register', RateLimiter::key(Request::ip()), 10, 3600)) {
            Response::error(429, 'Too many requests', 'Too many registration attempts from your network. Please try again in an hour.');
            return;
        }

        $password = Request::postRaw('password');
        [$clean, $errors] = $this->auth->validateRegistration(
            Request::post('full_name', 200),
            Request::post('email', 300),
            $password,
            Request::postRaw('password_confirm'),
            Request::post('accept_terms', 5) === '1'
        );
        if ($errors) {
            View::render('auth/register', ['title' => 'Create your account', 'errors' => $errors, 'old' => $clean + ['accept_terms' => Request::post('accept_terms', 5)]]);
            return;
        }

        try {
            $this->auth->register($clean['full_name'], $clean['email'], $password);
        } catch (\Throwable $e) {
            error_log('Registration failed: ' . $e->getMessage());
            Response::error(500, 'Something went wrong', 'We could not complete your registration. Please try again shortly.');
            return;
        }
        Session::set('pending_email', $clean['email']);
        Response::redirect('/register/check-email');
    }

    public function checkEmail(): void
    {
        View::render('auth/check_email', ['title' => 'Check your email', 'email' => (string) Session::get('pending_email', '')]);
    }

    // ---- email verification --------------------------------------------------------------

    /** GET only shows a confirm button, so mail scanners that pre-fetch links cannot consume the token. */
    public function showVerify(): void
    {
        $token = Request::query('token', 100);
        View::render('auth/verify_confirm', ['title' => 'Confirm your email', 'token' => $token]);
    }

    public function verify(): void
    {
        if (!$this->limiter->attempt('verify', RateLimiter::key(Request::ip()), 30, 3600)) {
            Response::error(429, 'Too many requests', 'Too many verification attempts. Please try again later.');
            return;
        }
        $uid = $this->auth->verify(Request::post('token', 100));
        if ($uid === null) {
            View::render('auth/verify_failed', ['title' => 'Verification link problem']);
            return;
        }
        // The user logs in with their own password afterwards (no automatic login from a mailed link).
        Session::flash('success', 'Your email address is verified. Please log in to open the Student Portal.');
        Response::redirect('/login');
    }

    public function resend(): void
    {
        if (!$this->limiter->attempt('resend_ip', RateLimiter::key(Request::ip()), 10, 3600)) {
            Response::error(429, 'Too many requests', 'Too many requests from your network. Please try again later.');
            return;
        }
        $current = Auth::user();
        $email = $current['email'] ?? Request::post('email', 300);
        try {
            $this->auth->resendVerification($email);
        } catch (\Throwable $e) {
            error_log('Resend failed: ' . $e->getMessage());
        }
        Session::flash('info', 'If that address belongs to an account that still needs verification, a new link has been sent. Links already sent stop working when a new one is issued.');
        Response::redirect(Auth::user() ? '/student' : '/register/check-email');
    }

    // ---- login / logout -------------------------------------------------------------------

    public function showLogin(): void
    {
        $this->redirectIfLoggedIn();
        View::render('auth/login', ['title' => 'Student login', 'isAdmin' => false, 'error' => null, 'email' => '']);
    }

    public function login(): void
    {
        $this->doLogin(['student'], 'login', false, '/student');
    }

    public function showAdminLogin(): void
    {
        $this->redirectIfLoggedIn();
        View::render('auth/login', ['title' => 'Administrator login', 'isAdmin' => true, 'error' => null, 'email' => '']);
    }

    public function adminLogin(): void
    {
        $this->doLogin(Auth::ADMIN_ROLES, 'admin_login', true, '/admin');
    }

    public function logout(): void
    {
        Auth::logout();
        Session::flash('success', 'You have been logged out.');
        Response::redirect('/');
    }

    // ---- forgot / reset / change password ----------------------------------------------------

    public function showForgot(): void
    {
        $this->redirectIfLoggedIn();
        View::render('auth/forgot', ['title' => 'Forgot password']);
    }

    public function forgot(): void
    {
        if (!$this->limiter->attempt('forgot_ip', RateLimiter::key(Request::ip()), 10, 3600)) {
            Response::error(429, 'Too many requests', 'Too many requests from your network. Please try again later.');
            return;
        }
        try {
            $this->passwords->requestReset(Request::post('email', 300));
        } catch (\Throwable $e) {
            error_log('Password reset request failed: ' . $e->getMessage());
        }
        // Same answer whether or not the address is registered.
        Session::flash('info', 'If that address belongs to a student account, we have emailed a link to reset the password. It works once and expires in ' . PasswordService::RESET_TTL_MINUTES . ' minutes.');
        Response::redirect('/login');
    }

    public function showReset(): void
    {
        $token = Request::query('token', 100);
        if (!$this->passwords->tokenValid($token)) {
            View::render('auth/reset_invalid', ['title' => 'Reset link problem']);
            return;
        }
        View::render('auth/reset', ['title' => 'Choose a new password', 'token' => $token, 'errors' => []]);
    }

    public function reset(): void
    {
        if (!$this->limiter->attempt('reset_ip', RateLimiter::key(Request::ip()), 20, 3600)) {
            Response::error(429, 'Too many requests', 'Too many attempts. Please try again later.');
            return;
        }
        $token = Request::post('token', 100);
        $r = $this->passwords->resetPassword($token, Request::postRaw('password'), Request::postRaw('password_confirm'));
        if ($r['ok']) {
            Session::flash('success', 'Your password has been changed. Please log in with the new password.');
            Response::redirect('/login');
        }
        if (!empty($r['invalid'])) {
            View::render('auth/reset_invalid', ['title' => 'Reset link problem']);
            return;
        }
        View::render('auth/reset', ['title' => 'Choose a new password', 'token' => $token, 'errors' => $r['errors'] ?? []]);
    }

    public function showChange(): void
    {
        $this->signedIn();
        View::render('auth/change_password', ['title' => 'Change password', 'errors' => []]);
    }

    public function change(): void
    {
        $user = $this->signedIn();
        $r = $this->passwords->changePassword((int) $user['id'], Request::postRaw('current_password'), Request::postRaw('password'), Request::postRaw('password_confirm'));
        if (!$r['ok']) {
            View::render('auth/change_password', ['title' => 'Change password', 'errors' => $r['errors']]);
            return;
        }
        Session::regenerate();
        Session::set('auth_time', $r['epoch']);     // this session stays signed in; every other session is signed out
        Session::flash('success', 'Your password has been changed. Other devices have been signed out.');
        Response::redirect($user['role'] === 'student' ? '/student' : '/admin');
    }

    private function signedIn(): array
    {
        $u = Auth::user();
        if ($u === null) {
            Response::redirect('/login');
        }
        return $u;
    }

    private function doLogin(array $roles, string $bucket, bool $isAdmin, string $home): void
    {
        $email = Request::post('email', 300);
        $result = $this->auth->attemptLogin($email, Request::postRaw('password'), $roles, Request::ip(), $bucket);
        if (!$result['ok']) {
            View::render('auth/login', [
                'title' => $isAdmin ? 'Administrator login' : 'Student login',
                'isAdmin' => $isAdmin, 'error' => $result['error'], 'email' => $email,
            ]);
            return;
        }
        Auth::login((int) $result['user_id']);
        Response::redirect($home);
    }

    private function redirectIfLoggedIn(): void
    {
        $u = Auth::user();
        if ($u !== null) {
            Response::redirect($u['role'] === 'student' ? '/student' : '/admin');
        }
    }
}
