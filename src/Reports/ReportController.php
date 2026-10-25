<?php
declare(strict_types=1);

namespace Iha\Reports;

use Iha\Admin\AdminApplicationService;
use Iha\Admin\Permissions;
use Iha\Auth\Auth;
use Iha\Core\Audit;
use Iha\Core\Request;
use Iha\Core\Response;
use Iha\Core\View;
use Iha\Geo\GeoService;

final class ReportController
{
    private const FILTERS = ['date_from', 'date_to', 'province_id', 'district_id', 'city_id', 'school'];
    private const APP_FILTERS = ['q', 'eligibility', 'status', 'province_id', 'district_id', 'city_id', 'school', 'pct_min', 'pct_max', 'date_from', 'date_to', 'qr'];

    private ReportService $reports;

    public function __construct()
    {
        $this->reports = new ReportService();
    }

    public function reports(): void
    {
        $user = Auth::requireRole(Auth::ADMIN_ROLES, '/admin/login');
        Permissions::require($user, 'reports.view');
        $perm = Permissions::flags($user['role']);

        $section = Request::query('section', 20);
        if (!in_array($section, ['eligibility', 'academic', 'registration'], true)) {
            $section = $perm['elig'] ? 'eligibility' : 'registration';
        }
        if ($section === 'eligibility') {
            Permissions::require($user, 'eligibility.view');   // eligibility reports stay private to authorised roles
        }

        $f = [];
        foreach (self::FILTERS as $k) {
            $f[$k] = Request::query($k, 100);
        }
        $page = ctype_digit(Request::query('page', 6)) ? (int) Request::query('page', 6) : 1;

        $data = match ($section) {
            'eligibility' => $this->reports->eligibility($f),
            'academic' => $this->reports->academic($f, $page),
            default => $this->reports->registrations($f),
        };
        $geo = new GeoService();
        View::render('admin/reports', [
            'title' => 'Reports', 'section' => $section, 'f' => $f, 'perm' => $perm, 'data' => $data,
            'geoOptions' => $geo->optionsFor(['province_id' => $f['province_id'], 'district_id' => $f['district_id']]),
            'qs' => array_filter($f, static fn($v) => $v !== ''),
        ]);
    }

    public function export(): void
    {
        $user = Auth::requireRole(Auth::ADMIN_ROLES, '/admin/login');
        Permissions::require($user, 'applications.export');
        $perm = Permissions::flags($user['role']);
        $type = Request::query('type', 30);

        $f = [];
        foreach (self::APP_FILTERS as $k) {
            $f[$k] = Request::query($k, 100);
        }

        $filename = 'iha-' . $type . '-' . gmdate('Ymd-His') . '.csv';
        $audit = static fn(int $rows) => Audit::log('CSV_EXPORTED', 'report', $type, (int) $user['id'], $user['role'], null,
            ['type' => $type, 'rows' => $rows, 'filters' => array_filter($f, static fn($v) => $v !== '')]);

        switch ($type) {
            case 'applications':
                Permissions::require($user, 'applications.view');
                [$header, $rows] = ReportService::applicationsCsv((new AdminApplicationService())->exportApplications($f, $perm), $perm);
                $audit(count($rows));
                Csv::send($filename, $header, $rows);
                return;

            case 'eligibility':
                Permissions::require($user, 'eligibility.view');
                $e = $this->reports->eligibility($f);
                $rows = [['Submitted applications', $e['submitted']], ['Eligible', $e['eligible']], ['Ineligible', $e['ineligible']], ['Pending review', $e['pending']],
                         ['Not yet evaluated', $e['not_evaluated']], ['Manual overrides in effect', $e['overridden']],
                         ['Eligibility percentage (eligible / evaluated)', $e['pct_eligible'] ?? ''], ['QR codes issued', $e['qr_issued']],
                         ['Active QR codes', $e['qr_active']], ['Revoked QR codes', $e['qr_revoked']]];
                $audit(count($rows));
                Csv::send($filename, ['Measure', 'Value'], $rows);
                return;

            case 'schools': case 'provinces': case 'districts': case 'cities': case 'subjects':
                $a = $this->reports->academic($f, 1, 100000);
                $list = $a[$type];
                $label = ['schools' => 'School/College', 'provinces' => 'Province', 'districts' => 'District', 'cities' => 'City', 'subjects' => 'Subject'][$type];
                $rows = array_map(static fn($r) => $type === 'subjects'
                    ? [$r['label'], $r['n'], $r['avg_pct']]
                    : [$r['label'], $r['n'], $r['avg_pct'], $r['ge60']], $list);
                $audit(count($rows));
                Csv::send($filename, $type === 'subjects' ? [$label, 'Records', 'Average %'] : [$label, 'Applications', 'Average %', 'Scoring 60% or above'], $rows);
                return;

            case 'daily': case 'weekly': case 'monthly':
                $r = $this->reports->registrations($f);
                $rows = [];
                foreach ($r[$type] as $label => $n) {
                    $rows[] = [$label, $n];
                }
                $audit(count($rows));
                Csv::send($filename, [$type === 'daily' ? 'Date' : ($type === 'weekly' ? 'Week starting' : 'Month starting'), 'New student accounts'], $rows);
                return;
        }
        Response::error(404, 'Not found', 'Unknown export type.');
    }
}
