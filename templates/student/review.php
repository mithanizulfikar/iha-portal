<?php
/** @var array $app @var array $profile @var string $profileLoc @var string $permLoc @var ?array $academic @var string $schoolLoc
 *  @var array $subjects @var array $totals @var ?array $guardian @var string $guardianLoc @var ?array $travel @var array $travelRecords
 *  @var array $steps @var int $completion @var bool $editable @var bool $complete @var array $errors @var string $submissionKey */
use Iha\Core\View;
use Iha\Student\Completeness;
use Iha\Student\SectionValidator as V;

$dl = static function (array $pairs): string {
    $h = '<dl class="row mb-0">';
    foreach ($pairs as $label => $value) {
        $h .= '<dt class="col-sm-4 text-muted fw-normal">' . e($label) . '</dt><dd class="col-sm-8">' . (($value === null || $value === '') ? '<span class="text-muted">&mdash;</span>' : e($value)) . '</dd>';
    }
    return $h . '</dl>';
};
$yn = static fn($v): string => $v === null ? '' : ($v ? 'Yes' : 'No');
$edit = static fn(string $url) => $editable ? '<a class="btn btn-sm btn-outline-primary" href="' . e($url) . '">Edit</a>' : '';
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-start">
    <h1 class="h3 mb-1"><?= $editable ? 'Review and submit' : 'Your application' ?></h1>
    <button class="btn btn-outline-secondary btn-sm no-print" type="button" data-print>Print</button>
  </div>
  <p class="small text-muted">Student ID <strong><?= e($profile['student_id']) ?></strong> &middot; Application No. <strong><?= e($app['application_number']) ?></strong>
    &middot; Status <?= badge(strtoupper($app['status']), 'app') ?> &middot; Completion <strong><?= (int) $completion ?>%</strong></p>

  <?php if ($errors): ?>
    <div class="alert alert-danger" role="alert"><strong>Your application was not submitted:</strong>
      <ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?php if ($editable && !$complete): ?>
    <div class="alert alert-warning">Some sections are not complete yet:
      <ul class="mb-0"><?php foreach (Completeness::SECTIONS as $n => [$label, $url]): if (empty($steps[$n])): ?><li><a href="<?= e($url) ?>"><?= (int) $n ?>. <?= e($label) ?></a></li><?php endif; endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><strong>1. Student Profile</strong><?= $edit('/student/profile') ?></div><div class="card-body">
    <?= $dl([
      'Full name' => $profile['full_name'], "Father's name" => $profile['father_name'], "Mother's name" => $profile['mother_name'],
      'Gender' => V::GENDERS[$profile['gender']] ?? '', 'Date of birth' => $profile['date_of_birth'], 'Nationality' => $profile['nationality'],
      'CNIC / B-Form' => $profile['id_number'], 'Contact number' => $profile['contact_number'],
      'Current address' => trim(($profile['address_line'] ?? '') . ($profileLoc !== '' ? ', ' . $profileLoc : '') . ($profile['postal_code'] ? ' ' . $profile['postal_code'] : ''), ', '),
      'Permanent address' => trim(($profile['perm_address_line'] ?? '') . ($permLoc !== '' ? ', ' . $permLoc : '') . ($profile['perm_postal_code'] ? ' ' . $profile['perm_postal_code'] : ''), ', '),
    ]) ?>
  </div></div>

  <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><strong>2. Academic Details</strong><?= $edit('/student/academic') ?></div><div class="card-body">
    <?= $dl($academic ? [
      'School / College' => $academic['institution_name'], 'Type' => V::INSTITUTION_TYPES[$academic['institution_type']] ?? '',
      'Registration no.' => $academic['institution_reg_no'], 'Location' => $schoolLoc, 'Address' => $academic['address_line'],
      'Class / grade' => $academic['current_class'], 'Session' => $academic['academic_session'], 'Board' => $academic['exam_board'],
      'Examination' => trim(($academic['exam_name'] ?? '') . ' ' . ($academic['exam_year'] ?? '')), 'Roll number' => $academic['roll_number'],
      'Result status' => V::RESULT_STATUSES[$academic['result_status']] ?? '', 'Previous institution' => $academic['previous_institution'],
    ] : ['Academic details' => 'Not entered yet']) ?>
  </div></div>

  <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><strong>3. Subject-wise Marks</strong><?= $edit('/student/marks') ?></div><div class="card-body">
    <?php if ($subjects): ?>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Subject</th><th>Total marks</th><th>Marks obtained</th><th>Percentage</th></tr></thead>
        <tbody>
          <?php foreach ($subjects as $s): ?>
            <tr><td><?= e($s['subject_name']) ?></td><td><?= e($s['total_marks']) ?></td><td><?= e($s['marks_obtained']) ?></td><td><?= $s['valid'] ? e($s['percent']) . '%' : '<span class="text-muted">Incomplete</span>' ?></td></tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr class="fw-semibold"><td>Overall</td><td><?= e($totals['total']) ?></td><td><?= e($totals['obtained']) ?></td><td><?= $totals['percent'] !== null ? e($totals['percent']) . '%' : '&mdash;' ?></td></tr></tfoot>
      </table></div>
    <?php else: ?><p class="text-muted mb-0">No subjects entered yet.</p><?php endif; ?>
  </div></div>

  <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><strong>4. Parent / Guardian</strong><?= $edit('/student/guardian') ?></div><div class="card-body">
    <?= $dl($guardian ? [
      "Father" => trim(($guardian['father_name'] ?? '') . ' ' . ($guardian['father_contact'] ? '(' . $guardian['father_contact'] . ')' : '')),
      "Father's CNIC" => $guardian['father_cnic'], "Father's email" => $guardian['father_email'], "Father's occupation" => $guardian['father_occupation'],
      'Mother' => trim(($guardian['mother_name'] ?? '') . ' ' . ($guardian['mother_contact'] ? '(' . $guardian['mother_contact'] . ')' : '')),
      'Guardian' => trim(($guardian['guardian_name'] ?? '') . ($guardian['guardian_relationship'] ? ', ' . $guardian['guardian_relationship'] : '') . ($guardian['guardian_contact'] ? ' (' . $guardian['guardian_contact'] . ')' : ''), ', '),
      'Primary guardian' => V::PRIMARY_GUARDIANS[$guardian['primary_guardian']] ?? '',
      'Address' => trim(($guardian['address_line'] ?? '') . ($guardianLoc !== '' ? ', ' . $guardianLoc : ''), ', '),
      'Emergency contact' => trim(($guardian['emergency_name'] ?? '') . ($guardian['emergency_relationship'] ? ', ' . $guardian['emergency_relationship'] : '') . ($guardian['emergency_contact'] ? ' (' . $guardian['emergency_contact'] . ')' : ''), ', '),
    ] : ['Parent / guardian details' => 'Not entered yet']) ?>
  </div></div>

  <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><strong>5. Travel History</strong><?= $edit('/student/travel') ?></div><div class="card-body">
    <?= $dl($travel ? [
      'Travelled outside home city' => $yn($travel['outside_home_city']), 'Travelled to another district' => $yn($travel['another_district']),
      'Travelled to another province' => $yn($travel['another_province']), 'International travel' => $yn($travel['international']),
    ] : ['Travel history' => 'Not entered yet']) ?>
    <?php foreach ($travelRecords as $r): ?>
      <hr><?= $dl([
        'Destination' => trim(implode(', ', array_filter([$r['city_visited'], $r['district_visited'], $r['province_state'], $r['country_visited']])), ', '),
        'Purpose' => $r['purpose'], 'Dates' => trim(($r['start_date'] ?? '') . ' to ' . ($r['end_date'] ?? ''), ' to'),
        'Mode' => $r['mode_of_travel'], 'Accompanied by' => $r['accompanied_by'], 'Notes' => $r['notes'],
      ]) ?>
    <?php endforeach; ?>
  </div></div>

  <?php if ($editable): ?>
    <form method="post" action="/student/submit" novalidate class="card card-body shadow-sm">
      <?= csrf_field() ?>
      <input type="hidden" name="submission_key" value="<?= e($submissionKey) ?>">
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="declaration" name="declaration" value="1"<?= $complete ? '' : ' disabled' ?>>
        <label class="form-check-label" for="declaration">I declare that the information I have provided is true and complete, and I consent to IHA processing it for my application as described in the <a href="/privacy" target="_blank" rel="noopener">Privacy Policy</a>.</label>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="/student">Save draft and return to dashboard</a>
        <button class="btn btn-primary ms-auto" type="submit"<?= $complete ? '' : ' disabled' ?>>Submit application</button>
      </div>
      <p class="small text-muted mt-2 mb-0">After you submit, you cannot edit your application unless it is returned to you for correction.</p>
    </form>
  <?php else: ?>
    <p class="text-muted">This application has been submitted and can't be edited. <a href="/student">Back to dashboard</a></p>
  <?php endif; ?>
</div>
