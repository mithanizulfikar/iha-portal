<?php
/** @var array $form @var array $errors @var array $geoCur @var array $geoPerm @var array $genders @var string $email @var string $registrationDate */
use Iha\Core\View;
$f = static fn(string $k): string => e($form[$k] ?? '');
$same = !empty($form['perm_same_as_current']);
?>
<div class="container py-4">
  <?= View::capture('student/_steps', ['current' => $current, 'steps' => $steps]) ?>
  <?= View::capture('student/_header', ['current' => 1, 'heading' => 'Student Profile', 'studentId' => $studentId, 'applicationNumber' => $applicationNumber]) ?>
  <?php if ($errors): ?><div class="alert alert-danger" role="alert">Nothing was saved. Please correct the highlighted fields.</div><?php endif; ?>

  <form method="post" action="/student/profile" novalidate class="card card-body shadow-sm">
    <?= csrf_field() ?>
    <div class="row g-3 mb-2">
      <div class="col-md-4"><label class="form-label">Student ID</label><input class="form-control" value="<?= e($studentId) ?>" readonly></div>
      <div class="col-md-4"><label class="form-label">Application Number</label><input class="form-control" value="<?= e($applicationNumber) ?>" readonly></div>
      <div class="col-md-4"><label class="form-label">Registration Date</label><input class="form-control" value="<?= e($registrationDate) ?>" readonly></div>
      <div class="col-md-6"><label class="form-label">Verified email address</label><input class="form-control" value="<?= e($email) ?>" readonly></div>
    </div>
    <hr>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label" for="full_name">Full name <span class="text-danger">*</span></label>
        <input class="form-control<?= field_class($errors, 'full_name') ?>" id="full_name" name="full_name" value="<?= $f('full_name') ?>" maxlength="100">
        <?= field_error($errors, 'full_name') ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="gender">Gender <span class="text-danger">*</span></label>
        <select class="form-select<?= field_class($errors, 'gender') ?>" id="gender" name="gender">
          <option value="">Select</option>
          <?php foreach ($genders as $k => $label): ?><option value="<?= e($k) ?>"<?= ($form['gender'] ?? '') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <?= field_error($errors, 'gender') ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="father_name">Father's name <span class="text-danger">*</span></label>
        <input class="form-control<?= field_class($errors, 'father_name') ?>" id="father_name" name="father_name" value="<?= $f('father_name') ?>" maxlength="100">
        <?= field_error($errors, 'father_name') ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="mother_name">Mother's name <span class="text-danger">*</span></label>
        <input class="form-control<?= field_class($errors, 'mother_name') ?>" id="mother_name" name="mother_name" value="<?= $f('mother_name') ?>" maxlength="100">
        <?= field_error($errors, 'mother_name') ?>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="date_of_birth">Date of birth <span class="text-danger">*</span></label>
        <input class="form-control<?= field_class($errors, 'date_of_birth') ?>" type="date" id="date_of_birth" name="date_of_birth" value="<?= $f('date_of_birth') ?>" min="1900-01-01" max="<?= e(date('Y-m-d')) ?>">
        <?= field_error($errors, 'date_of_birth') ?>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="nationality">Nationality <span class="text-danger">*</span></label>
        <input class="form-control<?= field_class($errors, 'nationality') ?>" id="nationality" name="nationality" value="<?= $f('nationality') ?>" maxlength="60">
        <?= field_error($errors, 'nationality') ?>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="id_number">CNIC / B-Form number <span class="text-muted small">(optional)</span></label>
        <input class="form-control<?= field_class($errors, 'id_number') ?>" id="id_number" name="id_number" value="<?= $f('id_number') ?>" placeholder="35202-1234567-1" maxlength="15" inputmode="numeric">
        <?= field_error($errors, 'id_number') ?>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="contact_number">Contact number <span class="text-danger">*</span></label>
        <input class="form-control<?= field_class($errors, 'contact_number') ?>" id="contact_number" name="contact_number" value="<?= $f('contact_number') ?>" placeholder="0300-1234567" maxlength="20" inputmode="tel">
        <?= field_error($errors, 'contact_number') ?>
      </div>
    </div>

    <hr>
    <h2 class="h5">Current residential address</h2>
    <?= View::capture('student/_location', ['prefix' => '', 'legend' => 'Location', 'loc' => $form['loc'], 'geo' => $geoCur, 'errors' => $errors]) ?>
    <div class="row g-3">
      <div class="col-md-9">
        <label class="form-label" for="address_line">Full address <span class="text-danger">*</span></label>
        <input class="form-control<?= field_class($errors, 'address_line') ?>" id="address_line" name="address_line" value="<?= $f('address_line') ?>" maxlength="255">
        <?= field_error($errors, 'address_line') ?>
      </div>
      <div class="col-md-3">
        <label class="form-label" for="postal_code">Postal code <span class="text-muted small">(optional)</span></label>
        <input class="form-control<?= field_class($errors, 'postal_code') ?>" id="postal_code" name="postal_code" value="<?= $f('postal_code') ?>" maxlength="10">
        <?= field_error($errors, 'postal_code') ?>
      </div>
    </div>

    <hr>
    <h2 class="h5">Permanent address</h2>
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" value="1" id="perm_same_as_current" name="perm_same_as_current"<?= $same ? ' checked' : '' ?>>
      <label class="form-check-label" for="perm_same_as_current">Same as current residential address</label>
    </div>
    <div id="perm-block"<?= $same ? ' class="d-none"' : '' ?>>
      <?= View::capture('student/_location', ['prefix' => 'perm_', 'legend' => 'Permanent location', 'loc' => $form['perm_loc'], 'geo' => $geoPerm, 'errors' => $errors]) ?>
      <div class="row g-3">
        <div class="col-md-9">
          <label class="form-label" for="perm_address_line">Full permanent address <span class="text-danger">*</span></label>
          <input class="form-control<?= field_class($errors, 'perm_address_line') ?>" id="perm_address_line" name="perm_address_line" value="<?= $f('perm_address_line') ?>" maxlength="255">
          <?= field_error($errors, 'perm_address_line') ?>
        </div>
        <div class="col-md-3">
          <label class="form-label" for="perm_postal_code">Postal code <span class="text-muted small">(optional)</span></label>
          <input class="form-control<?= field_class($errors, 'perm_postal_code') ?>" id="perm_postal_code" name="perm_postal_code" value="<?= $f('perm_postal_code') ?>" maxlength="10">
          <?= field_error($errors, 'perm_postal_code') ?>
        </div>
      </div>
    </div>
    <?= View::capture('student/_buttons', []) ?>
  </form>
</div>
<script src="/assets/js/geo.js"></script>
<script src="/assets/js/profile.js"></script>
