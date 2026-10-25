<?php
/** @var array $form @var array $errors @var array $geo @var array $primaries */
use Iha\Core\View;
$f = static fn(string $k): string => e($form[$k] ?? '');
$in = static function (string $k, string $label, bool $req = false, string $extra = '') use ($form, $errors): string {
    return '<div class="col-md-6"><label class="form-label" for="' . e($k) . '">' . e($label)
        . ($req ? ' <span class="text-danger">*</span>' : ' <span class="text-muted small">(optional)</span>') . '</label>'
        . '<input class="form-control' . field_class($errors, $k) . '" id="' . e($k) . '" name="' . e($k) . '" value="' . e($form[$k] ?? '') . '" maxlength="255"' . $extra . '>'
        . field_error($errors, $k) . '</div>';
};
?>
<div class="container py-4">
  <?= View::capture('student/_steps', ['current' => $current, 'steps' => $steps]) ?>
  <?= View::capture('student/_header', ['current' => 4, 'heading' => 'Parent/Guardian', 'studentId' => $studentId, 'applicationNumber' => $applicationNumber]) ?>
  <?php if ($errors): ?><div class="alert alert-danger" role="alert">Nothing was saved. Please correct the highlighted fields.</div><?php endif; ?>

  <form method="post" action="/student/guardian" novalidate class="card card-body shadow-sm">
    <?= csrf_field() ?>
    <h2 class="h5">Father</h2>
    <div class="row g-3 mb-3">
      <?= $in('father_name', 'Father\'s full name', true) ?>
      <?= $in('father_cnic', 'Father\'s CNIC', false, ' placeholder="35202-1234567-1" inputmode="numeric"') ?>
      <?= $in('father_contact', 'Father\'s contact number', true, ' inputmode="tel"') ?>
      <?= $in('father_email', 'Father\'s email address', false, ' type="email"') ?>
      <?= $in('father_occupation', 'Father\'s occupation') ?>
    </div>
    <h2 class="h5">Mother</h2>
    <div class="row g-3 mb-3">
      <?= $in('mother_name', 'Mother\'s full name', true) ?>
      <?= $in('mother_contact', 'Mother\'s contact number', false, ' inputmode="tel"') ?>
    </div>
    <h2 class="h5">Guardian <span class="text-muted small">(only if different from parents)</span></h2>
    <div class="row g-3 mb-3">
      <?= $in('guardian_name', 'Guardian\'s full name') ?>
      <?= $in('guardian_relationship', 'Relationship to student') ?>
      <?= $in('guardian_cnic', 'Guardian\'s CNIC', false, ' inputmode="numeric"') ?>
      <?= $in('guardian_contact', 'Guardian\'s contact number', false, ' inputmode="tel"') ?>
      <?= $in('guardian_email', 'Guardian\'s email address', false, ' type="email"') ?>
    </div>
    <div class="mb-3">
      <label class="form-label d-block">Primary guardian <span class="text-danger">*</span></label>
      <?php foreach ($primaries as $k => $label): ?>
        <div class="form-check form-check-inline">
          <input class="form-check-input<?= field_class($errors, 'primary_guardian') ?>" type="radio" name="primary_guardian" id="pg_<?= e($k) ?>" value="<?= e($k) ?>"<?= ($form['primary_guardian'] ?? '') === $k ? ' checked' : '' ?>>
          <label class="form-check-label" for="pg_<?= e($k) ?>"><?= e($label) ?></label>
        </div>
      <?php endforeach; ?>
      <?= field_error($errors, 'primary_guardian') ?>
      <div class="form-text">If you choose Guardian, the guardian's name, relationship and contact number are required.</div>
    </div>
    <hr>
    <h2 class="h5">Parent / guardian residential address</h2>
    <?= View::capture('student/_location', ['prefix' => 'guardian_', 'legend' => 'Location', 'loc' => $form['loc'], 'geo' => $geo, 'errors' => $errors]) ?>
    <div class="row g-3 mb-3">
      <?= str_replace('col-md-6', 'col-12', $in('address_line', 'Full address', true)) ?>
    </div>
    <hr>
    <h2 class="h5">Emergency contact</h2>
    <div class="row g-3">
      <?= $in('emergency_name', 'Emergency contact name', true) ?>
      <?= $in('emergency_contact', 'Emergency contact number', true, ' inputmode="tel"') ?>
      <?= $in('emergency_relationship', 'Relationship to student', true) ?>
    </div>
    <?= View::capture('student/_buttons', ['backUrl' => '/student/marks']) ?>
  </form>
</div>
<script src="/assets/js/geo.js"></script>
