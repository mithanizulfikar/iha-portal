<?php
/** @var array $answers @var array $records @var array $errors */
use Iha\Core\View;
$questions = [
    'outside_home_city' => 'Have you travelled outside your home city?',
    'another_district'  => 'Have you travelled to another district?',
    'another_province'  => 'Have you travelled to another province?',
    'international'     => 'Have you travelled internationally?',
];
$showRecords = in_array('1', $answers, true);
$rec = static function (int|string $i, array $r, array $errors): string {
    $f = static fn(string $k, string $label, int $max, string $type = 'text'): string =>
        '<div class="col-md-4"><label class="form-label">' . e($label) . '</label><input class="form-control" type="' . $type . '" name="rec_' . $k . '[]" value="' . e($r[$k] ?? '') . '" maxlength="' . $max . '"></div>';
    $err = isset($errors['rec.' . $i]) ? '<div class="alert alert-danger py-1">' . e($errors['rec.' . $i]) . '</div>' : '';
    return '<div class="card card-body mb-3 travel-record">' . $err . '<div class="row g-3">'
        . $f('country', 'Country visited', 80) . $f('province', 'Province / State visited', 80) . $f('district', 'District visited', 80)
        . $f('city', 'City visited', 80) . $f('purpose', 'Purpose of travel', 150) . $f('mode', 'Mode of travel', 60)
        . $f('start', 'Travel start date', 10, 'date') . $f('end', 'Travel end date', 10, 'date') . $f('with', 'Accompanied by', 100)
        . '<div class="col-12"><label class="form-label">Additional notes</label><textarea class="form-control" name="rec_notes[]" rows="2" maxlength="500">' . e($r['notes'] ?? '') . '</textarea></div>'
        . '</div><div class="mt-2 text-end"><button class="btn btn-sm btn-outline-danger" type="button" data-remove-record>Remove this trip</button></div></div>';
};
?>
<div class="container py-4">
  <?= View::capture('student/_steps', ['current' => $current, 'steps' => $steps]) ?>
  <?= View::capture('student/_header', ['current' => 5, 'heading' => 'Travel History', 'studentId' => $studentId, 'applicationNumber' => $applicationNumber]) ?>
  <p class="small text-muted">Answer the four questions. Trip details are optional.</p>
  <?php if ($errors): ?><div class="alert alert-danger" role="alert">Nothing was saved. Please correct the marked trips.</div><?php endif; ?>

  <form method="post" action="/student/travel" novalidate class="card card-body shadow-sm">
    <?= csrf_field() ?>
    <?php foreach ($questions as $k => $q): ?>
      <div class="mb-3">
        <div class="fw-semibold"><?= e($q) ?> <span class="text-danger">*</span></div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="ans_<?= e($k) ?>" id="ans_<?= e($k) ?>_1" value="1"<?= ($answers[$k] ?? '') === '1' ? ' checked' : '' ?>>
          <label class="form-check-label" for="ans_<?= e($k) ?>_1">Yes</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="ans_<?= e($k) ?>" id="ans_<?= e($k) ?>_0" value="0"<?= ($answers[$k] ?? '') === '0' ? ' checked' : '' ?>>
          <label class="form-check-label" for="ans_<?= e($k) ?>_0">No</label>
        </div>
      </div>
    <?php endforeach; ?>

    <div id="travel-records"<?= $showRecords ? '' : ' class="d-none"' ?>>
      <hr>
      <h2 class="h5">Trip details <span class="text-muted small">(optional)</span></h2>
      <div id="record-list">
        <?php foreach ($records as $i => $r): ?><?= $rec($i, $r, $errors) ?><?php endforeach; ?>
      </div>
      <button class="btn btn-outline-primary" type="button" data-add-record>Add trip</button>
    </div>
    <?= View::capture('student/_buttons', ['backUrl' => '/student/guardian']) ?>
  </form>

  <template id="record-template"><?= $rec('new', [], []) ?></template>
</div>
<script src="/assets/js/travel.js"></script>
