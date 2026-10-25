<?php
declare(strict_types=1);

namespace Iha\Admin;

use Iha\Auth\Auth;
use Iha\Core\Request;
use Iha\Core\Response;
use Iha\Core\Session;
use Iha\Core\View;
use Iha\Eligibility\RuleService;

/** Audit log viewer and eligibility rule configuration. */
final class SettingsController
{
    public function audit(): void
    {
        $user = Auth::requireRole(Auth::ADMIN_ROLES, '/admin/login');
        Permissions::require($user, 'audit.view');
        $svc = new AuditService();
        $f = [];
        foreach (['action', 'actor_role', 'entity_type', 'entity_id', 'date_from', 'date_to'] as $k) {
            $f[$k] = Request::query($k, 80);
        }
        $page = ctype_digit(Request::query('page', 6)) ? (int) Request::query('page', 6) : 1;
        View::render('admin/audit', [
            'title' => 'Audit log', 'f' => $f, 'result' => $svc->list($f, $page), 'actions' => $svc->actions(),
            'qs' => array_filter($f, static fn($v) => $v !== ''),
        ]);
    }

    public function rules(): void
    {
        $user = Auth::requireRole(Auth::ADMIN_ROLES, '/admin/login');
        Permissions::require($user, 'eligibility.rules');
        $svc = new RuleService();
        View::render('admin/rules', ['title' => 'Eligibility rules', 'active' => $svc->active(), 'history' => $svc->history(), 'form' => null]);
    }

    public function publishRule(): void
    {
        $user = Auth::requireRole(Auth::ADMIN_ROLES, '/admin/login');
        Permissions::require($user, 'eligibility.rules');
        $svc = new RuleService();
        $form = [
            'dob_min' => Request::post('dob_min', 10), 'dob_max' => Request::post('dob_max', 10), 'min_percentage' => Request::post('min_percentage', 8),
            'notes' => Request::post('notes', 600), 'reevaluate' => Request::post('reevaluate', 2) === '1',
        ];
        try {
            $r = $svc->publish($form['dob_min'], $form['dob_max'], $form['min_percentage'], $form['notes'], $form['reevaluate'], (int) $user['id'], $user['role']);
            Session::flash('success', "Rule version {$r['version']} is now active." . ($form['reevaluate'] ? " {$r['reevaluated']} waiting application(s) were re-evaluated." : ' Existing results were not changed.'));
            Response::redirect('/admin/rules');
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('Rule change failed: ' . $e->getMessage());
            Session::flash('error', 'The rule could not be changed. Nothing was saved.');
        }
        View::render('admin/rules', ['title' => 'Eligibility rules', 'active' => $svc->active(), 'history' => $svc->history(), 'form' => $form]);
    }
}
