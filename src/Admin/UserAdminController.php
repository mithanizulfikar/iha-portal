<?php
declare(strict_types=1);

namespace Iha\Admin;

use Iha\Auth\Auth;
use Iha\Core\Request;
use Iha\Core\Response;
use Iha\Core\Session;
use Iha\Core\View;

final class UserAdminController
{
    private UserAdminService $svc;

    public function __construct()
    {
        $this->svc = new UserAdminService();
    }

    public function index(): void
    {
        $user = $this->gate();
        $credential = Session::get('new_credential');
        Session::forget('new_credential');      // shown once, then gone
        View::render('admin/users', [
            'title' => 'Administrator accounts', 'admins' => $this->svc->list(), 'me' => (int) $user['id'],
            'roles' => UserAdminService::ROLES, 'credential' => $credential,
        ]);
    }

    public function create(): void
    {
        $user = $this->gate();
        $this->run(function () use ($user): void {
            $r = $this->svc->create(Request::post('email', 300), Request::post('full_name', 200), Request::post('role', 40), (int) $user['id'], $user['role']);
            Session::set('new_credential', ['email' => mb_strtolower(Request::post('email', 300)), 'password' => $r['password'], 'reason' => 'created']);
            Session::flash('success', 'Administrator account created. Copy the temporary password below and give it to them securely; it is shown only once.');
        });
    }

    public function role(): void
    {
        $user = $this->gate();
        $this->run(function () use ($user): void {
            $this->svc->changeRole($this->targetId(), Request::post('role', 40), (int) $user['id'], $user['role']);
            Session::flash('success', 'Role updated.');
        });
    }

    public function status(): void
    {
        $user = $this->gate();
        $this->run(function () use ($user): void {
            $this->svc->setStatus($this->targetId(), Request::post('status', 20), (int) $user['id'], $user['role']);
            Session::flash('success', 'Account status updated. A suspended administrator is signed out immediately.');
        });
    }

    public function password(): void
    {
        $user = $this->gate();
        $this->run(function () use ($user): void {
            $id = $this->targetId();
            $pw = $this->svc->resetPassword($id, (int) $user['id'], $user['role']);
            $st = \Iha\Core\Database::pdo()->prepare('SELECT email FROM users WHERE id = ?');
            $st->execute([$id]);
            Session::set('new_credential', ['email' => (string) $st->fetchColumn(), 'password' => $pw, 'reason' => 'reset']);
            Session::flash('success', 'Temporary password set. Copy it below and give it to them securely; it is shown only once and must be changed at their next login.');
        });
    }

    private function gate(): array
    {
        $user = Auth::requireRole(['super_admin', 'application_reviewer', 'report_viewer'], '/admin/login');
        Permissions::require($user, 'users.manage');
        return $user;
    }

    private function targetId(): int
    {
        $id = Request::post('user_id', 12);
        if (!ctype_digit($id)) {
            throw new \InvalidArgumentException('That administrator account was not found.');
        }
        return (int) $id;
    }

    private function run(callable $action): never
    {
        try {
            $action();
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('Admin user action failed: ' . $e->getMessage());
            Session::flash('error', 'The action could not be completed. Nothing was changed.');
        }
        Response::redirect('/admin/users');
    }
}
