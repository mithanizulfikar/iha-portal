<?php
declare(strict_types=1);

namespace Iha\Student;

use Iha\Academic\MarksCalculator;
use Iha\Auth\Auth;
use Iha\Core\Response;
use Iha\Core\Session;
use Iha\Core\View;
use Iha\Geo\GeoService;

/** The five registration sections. Student-facing: nothing here reads or shows eligibility data. */
final class ApplicationController
{
    private const NEXT = [
        'profile' => '/student/academic', 'academic' => '/student/marks', 'marks' => '/student/guardian',
        'guardian' => '/student/travel', 'travel' => '/student',
    ];

    private ApplicationService $svc;
    private GeoService $geo;
    private SectionValidator $val;

    public function __construct()
    {
        $this->svc = new ApplicationService();
        $this->geo = new GeoService();
        $this->val = new SectionValidator($this->geo);
    }

    public function start(): void
    {
        Response::redirect('/student/profile');
    }

    // ---- 1. Student Profile ------------------------------------------------------------------

    public function showProfile(): void
    {
        $c = $this->context();
        $p = $c['profile'];
        $form = $this->stringify($p) + [
            'loc' => $this->locFromRow($p, ''),
            'perm_loc' => $this->locFromRow($p, 'perm_'),
            'perm_same_as_current' => (bool) $p['perm_same_as_current'],
        ];
        $this->renderProfile($c, $form, []);
    }

    public function saveProfile(): void
    {
        $c = $this->context();
        [$values, $errors] = $this->val->profile($_POST);
        if ($errors) {
            $form = $this->postStrings($_POST, ['full_name', 'father_name', 'mother_name', 'gender', 'date_of_birth', 'nationality', 'id_number', 'contact_number', 'address_line', 'postal_code', 'perm_address_line', 'perm_postal_code'])
                + ['loc' => $this->locFromPost($_POST, ''), 'perm_loc' => $this->locFromPost($_POST, 'perm_'), 'perm_same_as_current' => ($_POST['perm_same_as_current'] ?? '') === '1'];
            $this->renderProfile($c, $form, $errors);
            return;
        }
        $this->svc->saveProfile((int) $c['profile']['id'], (int) $c['app']['id'], $values);
        $this->saved('profile');
    }

    private function renderProfile(array $c, array $form, array $errors): void
    {
        View::render('student/profile', $this->base($c, 1, 'Student Profile') + [
            'form' => $form, 'errors' => $errors,
            'email' => $c['user']['email'],
            'registrationDate' => $c['profile']['registration_date'],
            'geoCur' => $this->geo->optionsFor($form['loc']),
            'geoPerm' => $this->geo->optionsFor($form['perm_loc']),
            'genders' => SectionValidator::GENDERS,
        ]);
    }

    // ---- 2. Academic Portal ------------------------------------------------------------------

    public function showAcademic(): void
    {
        $c = $this->context();
        $rec = $this->svc->academicRecord((int) $c['app']['id']) ?? [];
        $form = $this->stringify($rec) + ['loc' => $this->locFromRow($rec, '')];
        $this->renderAcademic($c, $form, []);
    }

    public function saveAcademic(): void
    {
        $c = $this->context();
        [$values, $errors] = $this->val->academic($_POST);
        if ($errors) {
            $form = $this->postStrings($_POST, ['institution_name', 'institution_reg_no', 'institution_type', 'address_line', 'current_class', 'academic_session', 'exam_board', 'exam_name', 'exam_year', 'roll_number', 'result_status', 'previous_institution'])
                + ['loc' => $this->locFromPost($_POST, 'school_')];
            $this->renderAcademic($c, $form, $errors);
            return;
        }
        $this->svc->saveAcademic((int) $c['profile']['id'], (int) $c['app']['id'], $values);
        $this->saved('academic');
    }

    private function renderAcademic(array $c, array $form, array $errors): void
    {
        View::render('student/academic', $this->base($c, 2, 'Academic Portal') + [
            'form' => $form, 'errors' => $errors,
            'geo' => $this->geo->optionsFor($form['loc']),
            'types' => SectionValidator::INSTITUTION_TYPES,
            'statuses' => SectionValidator::RESULT_STATUSES,
        ]);
    }

    // ---- 3. Academic Reports and Marks -------------------------------------------------------

    public function showMarks(): void
    {
        $c = $this->context();
        $rows = [];
        foreach ($this->svc->subjects((int) $c['app']['id']) as $s) {
            $rows[] = [
                'subject_name' => (string) $s['subject_name'],
                'total_marks' => $s['total_marks'] === null ? '' : rtrim(rtrim((string) $s['total_marks'], '0'), '.'),
                'marks_obtained' => $s['marks_obtained'] === null ? '' : rtrim(rtrim((string) $s['marks_obtained'], '0'), '.'),
            ];
        }
        $this->renderMarks($c, $rows, MarksCalculator::evaluate($rows), []);
    }

    public function saveMarks(): void
    {
        $c = $this->context();
        [$eval, $errors] = $this->val->marks($_POST);
        if ($errors) {
            $rows = $this->postedRows($_POST);
            $this->renderMarks($c, $rows, $eval, $errors);
            return;
        }
        $this->svc->saveSubjects((int) $c['profile']['id'], (int) $c['app']['id'], array_values($eval['rows']));
        $this->saved('marks');
    }

    private function renderMarks(array $c, array $rows, array $eval, array $errors): void
    {
        $record = $this->svc->academicRecord((int) $c['app']['id']);
        View::render('student/marks', $this->base($c, 3, 'Academic Reports and Marks') + [
            'rows' => $rows ?: [['subject_name' => '', 'total_marks' => '', 'marks_obtained' => '']],
            'eval' => $eval, 'errors' => $errors,
            'record' => $record,
            'totals' => [
                'total' => MarksCalculator::formatCents($eval['total_cents']),
                'obtained' => MarksCalculator::formatCents($eval['obtained_cents']),
                'percent' => $eval['overall_percent'],
            ],
        ]);
    }

    // ---- 4. Parent/Guardian ------------------------------------------------------------------

    public function showGuardian(): void
    {
        $c = $this->context();
        $g = $this->svc->guardian((int) $c['app']['id']);
        if ($g === null) {   // convenience prefill from the profile; saved only when the student saves
            $g = ['father_name' => $c['profile']['father_name'], 'mother_name' => $c['profile']['mother_name']];
        }
        $form = $this->stringify($g) + ['loc' => $this->locFromRow($g, '')];
        $this->renderGuardian($c, $form, []);
    }

    public function saveGuardian(): void
    {
        $c = $this->context();
        [$values, $errors] = $this->val->guardian($_POST);
        if ($errors) {
            $form = $this->postStrings($_POST, ['father_name', 'father_cnic', 'father_contact', 'father_email', 'father_occupation', 'mother_name', 'mother_contact', 'guardian_name', 'guardian_relationship', 'guardian_cnic', 'guardian_contact', 'guardian_email', 'primary_guardian', 'address_line', 'emergency_name', 'emergency_contact', 'emergency_relationship'])
                + ['loc' => $this->locFromPost($_POST, 'guardian_')];
            $this->renderGuardian($c, $form, $errors);
            return;
        }
        $this->svc->saveGuardian((int) $c['profile']['id'], (int) $c['app']['id'], $values);
        $this->saved('guardian');
    }

    private function renderGuardian(array $c, array $form, array $errors): void
    {
        View::render('student/guardian', $this->base($c, 4, 'Parent/Guardian') + [
            'form' => $form, 'errors' => $errors,
            'geo' => $this->geo->optionsFor($form['loc']),
            'primaries' => SectionValidator::PRIMARY_GUARDIANS,
        ]);
    }

    // ---- 5. Travel History -------------------------------------------------------------------

    public function showTravel(): void
    {
        $c = $this->context();
        $appId = (int) $c['app']['id'];
        $resp = $this->svc->travelResponses($appId) ?? [];
        $answers = [];
        foreach (['outside_home_city', 'another_district', 'another_province', 'international'] as $k) {
            $answers[$k] = array_key_exists($k, $resp) && $resp[$k] !== null ? ($resp[$k] ? '1' : '0') : '';
        }
        $records = [];
        foreach ($this->svc->travelRecords($appId) as $r) {
            $records[] = [
                'country' => (string) $r['country_visited'], 'province' => (string) $r['province_state'],
                'district' => (string) $r['district_visited'], 'city' => (string) $r['city_visited'],
                'purpose' => (string) $r['purpose'], 'start' => (string) $r['start_date'], 'end' => (string) $r['end_date'],
                'mode' => (string) $r['mode_of_travel'], 'with' => (string) $r['accompanied_by'], 'notes' => (string) $r['notes'],
            ];
        }
        $this->renderTravel($c, $answers, $records, []);
    }

    public function saveTravel(): void
    {
        $c = $this->context();
        [$data, $errors] = $this->val->travel($_POST);
        if ($errors) {
            $answers = [];
            foreach ($data['answers'] as $k => $v) {
                $answers[$k] = $v === null ? '' : ($v ? '1' : '0');
            }
            $this->renderTravel($c, $answers, array_map(static fn($r) => array_map(static fn($x) => (string) $x, $r), $data['records']), $errors);
            return;
        }
        $this->svc->saveTravel((int) $c['profile']['id'], (int) $c['app']['id'], $data['answers'], array_values($data['records']));
        $this->saved('travel');
    }

    private function renderTravel(array $c, array $answers, array $records, array $errors): void
    {
        View::render('student/travel', $this->base($c, 5, 'Travel History') + [
            'answers' => $answers, 'records' => $records, 'errors' => $errors,
        ]);
    }

    // ---- printable academic report ------------------------------------------------------------

    /** Student's own academic report: details and marks only, no eligibility information. */
    public function academicReport(): void
    {
        $user = Auth::requireRole(['student'], '/login');
        if ($user['email_verified_at'] === null) {
            Session::flash('info', 'Please verify your email address first.');
            Response::redirect('/student');
        }
        $profile = $this->svc->profileByUser((int) $user['id']);
        $app = $profile ? $this->svc->currentApplication((int) $profile['id']) : null;
        $record = $app ? $this->svc->academicRecord((int) $app['id']) : null;
        if ($record === null) {
            Session::flash('info', 'Enter your academic details and marks first.');
            Response::redirect('/student/academic');
        }
        $rows = array_map(static fn($s) => [
            'subject_name' => (string) $s['subject_name'],
            'total_marks' => $s['total_marks'] === null ? '' : (string) $s['total_marks'],
            'marks_obtained' => $s['marks_obtained'] === null ? '' : (string) $s['marks_obtained'],
        ], $this->svc->subjects((int) $app['id']));
        $eval = MarksCalculator::evaluate($rows);
        View::render('student/academic_report', [
            'title' => 'Academic report', 'profile' => $profile, 'app' => $app, 'record' => $record,
            'loc' => $this->geo->describe($record['province_id'], $record['district_id'], $record['city_id'], $record['city_other']),
            'subjects' => $eval['rows'],
            'totals' => ['total' => MarksCalculator::formatCents($eval['total_cents']), 'obtained' => MarksCalculator::formatCents($eval['obtained_cents']), 'percent' => $eval['overall_percent']],
        ]);
    }

    // ---- shared ------------------------------------------------------------------------------

    /** @return array{user:array,profile:array,app:array} */
    private function context(): array
    {
        $user = Auth::requireRole(['student'], '/login');
        if ($user['email_verified_at'] === null) {
            Session::flash('info', 'Please verify your email address to open the registration form.');
            Response::redirect('/student');
        }
        $profile = $this->svc->profileByUser((int) $user['id']);
        if ($profile === null) {
            Response::error(500, 'Profile not found', 'Your student profile could not be found. Please contact support.');
            exit;
        }
        $app = $this->svc->currentApplication((int) $profile['id']);
        if (!ApplicationService::isEditable($app)) {
            Session::flash('info', 'Your application can no longer be edited here.');
            Response::redirect('/student');
        }
        return ['user' => $user, 'profile' => $profile, 'app' => $app];
    }

    private function base(array $c, int $current, string $title): array
    {
        $raw = json_decode((string) ($c['app']['section_status'] ?? '{}'), true) ?: [];
        $steps = [];
        foreach (array_keys(Completeness::SECTIONS) as $n) {
            $steps[$n] = (bool) ($raw[(string) $n] ?? false);
        }
        return [
            'title' => $title, 'current' => $current, 'steps' => $steps,
            'studentId' => $c['profile']['student_id'],
            'applicationNumber' => $c['app']['application_number'],
        ];
    }

    private function saved(string $page): never
    {
        if (($_POST['action'] ?? '') === 'next') {
            Session::flash('success', 'Saved. Continue with the next section.');
            Response::redirect(self::NEXT[$page]);
        }
        Session::flash('success', 'Draft saved.');
        Response::redirect('/student/' . $page);
    }

    private function stringify(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[$k] = is_bool($v) ? $v : ($v === null ? '' : (string) $v);
        }
        return $out;
    }

    private function postStrings(array $post, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = is_string($post[$k] ?? null) ? $post[$k] : '';
        }
        return $out;
    }

    private function locFromRow(array $row, string $p): array
    {
        $other = (string) ($row[$p . 'city_other'] ?? '');
        return [
            'province_id' => (string) ($row[$p . 'province_id'] ?? ''),
            'district_id' => (string) ($row[$p . 'district_id'] ?? ''),
            'city_id' => $other !== '' ? 'other' : (string) ($row[$p . 'city_id'] ?? ''),
            'city_other' => $other,
        ];
    }

    private function locFromPost(array $post, string $p): array
    {
        $s = static fn(string $k): string => is_string($post[$p . $k] ?? null) ? trim($post[$p . $k]) : '';
        return ['province_id' => $s('province_id'), 'district_id' => $s('district_id'), 'city_id' => $s('city_id'), 'city_other' => $s('city_other')];
    }

    private function postedRows(array $post): array
    {
        $names = is_array($post['subject_name'] ?? null) ? array_values($post['subject_name']) : [];
        $t = is_array($post['total_marks'] ?? null) ? array_values($post['total_marks']) : [];
        $o = is_array($post['marks_obtained'] ?? null) ? array_values($post['marks_obtained']) : [];
        $rows = [];
        foreach (array_slice($names, 0, 30, true) as $i => $n) {
            $rows[] = [
                'subject_name' => is_string($n) ? $n : '',
                'total_marks' => is_string($t[$i] ?? null) ? $t[$i] : '',
                'marks_obtained' => is_string($o[$i] ?? null) ? $o[$i] : '',
            ];
        }
        return $rows;
    }
}
