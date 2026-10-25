<?php
/** @var array $form @var array $errors @var array $geo @var array $types @var array $statuses */
use Iha\Core\View;
$f = static fn(string $k): string => e($form[$k] ?? '');
?>
<div class="container py-4">
  <?= View::capture('student/_steps', ['current' => $current, 'steps' => $steps]) ?>
  <?= View::capture('student/_header', ['current' => 2, 'heading' => 'Academic Portal', 'studentId' => $studentId, 'applicationNumber' => $applicationNumber]) ?>
  <div class="alert alert-info">Enter your <strong>most recent examination</strong>. The subjects and marks you list in Section 3 for this examination are the results used for the academic percentage requirement; older results are not added to it.</div>
  <?php if ($errors): ?><div class="alert alert-danger" role="alert">Nothing was saved. Please correct the highlighted fields.</div><?php endif; ?>

  <form method="post" action="/student/academic" novalidate class="card card-body shadow-sm">
    <?= csrf_field() ?>
    <div class="row g-3">
      <div class="col-md-8">
        <label class="form-label" for="institution_name">School / College name <span class="text-danger">*</span></label>
        <input class="form-control<?= field_class($errors, 'institution_name') ?>" id="institution_name" name="institution_name" value="<?= $f('institution_name') ?>" maxlength="150">
        <?= field_error($errors, 'institution_name') ?>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="institution_type">Institution type <span class="text-danger">*</span></label>
        <select class="form-select<?= field_class($errors, 'institution_type') ?>" id="institution_type" name="institution_type">
          <option value="">Select</option>
          <?php foreach ($types as $k => $label): ?><option value="<?= e($k) ?>"<?= ($form['institution_type'] ?? '') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <?= field_error($errors, 'institution_type') ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="institution_reg_no">School / College registration number <span class="text-muted small">(optional)</span></label>
        <input class="form-control" id="institution_reg_no" name="institution_reg_no" value="<?= $f('institution_reg_no') ?>" maxlength="50">
      </div>
    </div>
    <hr>
    <?= View::capture('student/_location', ['prefix' => 'school_', 'legend' => 'School / College location', 'loc' => $form['loc'], 'geo' => $geo, 'errors' => $errors]) ?>
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label" for="address_line">School / College full address <span class="text-danger">*</span></label>
        <input class="form-control<?= field_class($errors, 'address_line') ?>" id="address_line" name="address_line" value="<?= $f('address_line') ?>" maxlength="255">
        <?= field_error($errors, 'address_line') ?>
      </div>
    </div>
    <hr>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label" for="current_class">Current class / grade <span class="text-danger">*</span></label>
        <input class="form-control<?= field_class($errors, 'current_class') ?>" id="current_class" name="current_class" value="<?= $f('current_class') ?>" maxlength="30">
        <?= field_error($errors, 'current_class') ?>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="academic_session">Academic session <span class="text-danger">*</span></label>
        <input class="form-control<?= field_class($errors, 'academic_session') ?>" id="academic_session" name="academic_session" value="<?= $f('academic_session') ?>" placeholder="2025-2026" maxlength="20">
        <?= field_error($errors, 'academic_session') ?>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="exam_board">Examination board <span class="text-danger">*</span></label>
        <input class="form-control<?= field_class($errors, 'exam_board') ?>" id="exam_board" name="exam_board" value="<?= $f('exam_board') ?>" maxlength="100">
        <?= field_error($errors, 'exam_board') ?>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="exam_name">Last examination name <span class="text-danger">*</span></label>
        <input class="form-control<?= field_class($errors, 'exam_name') ?>" id="exam_name" name="exam_name" value="<?= $f('exam_name') ?>" maxlength="100">
        <?= field_error($errors, 'exam_name') ?>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="exam_year">Examination year <span class="text-danger">*</span></label>
        <input class="form-control<?= field_class($errors, 'exam_year') ?>" id="exam_year" name="exam_year" value="<?= $f('exam_year') ?>" maxlength="4" inputmode="numeric">
        <?= field_error($errors, 'exam_year') ?>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="roll_number">Roll number <span class="text-danger">*</span></label>
        <input class="form-control<?= field_class($errors, 'roll_number') ?>" id="roll_number" name="roll_number" value="<?= $f('roll_number') ?>" maxlength="30">
        <?= field_error($errors, 'roll_number') ?>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="result_status">Result status <span class="text-danger">*</span></label>
        <select class="form-select<?= field_class($errors, 'result_status') ?>" id="result_status" name="result_status">
          <option value="">Select</option>
          <?php foreach ($statuses as $k => $label): ?><option value="<?= e($k) ?>"<?= ($form['result_status'] ?? '') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <?= field_error($errors, 'result_status') ?>
      </div>
      <div class="col-md-8">
        <label class="form-label" for="previous_institution">Previous school / college <span class="text-muted small">(optional)</span></label>
        <input class="form-control" id="previous_institution" name="previous_institution" value="<?= $f('previous_institution') ?>" maxlength="150">
      </div>
    </div>
    <?= View::capture('student/_buttons', ['backUrl' => '/student/profile']) ?>
  </form>
</div>
<script src="/assets/js/geo.js"></script>
