<?php
/** @var array $rows @var array $eval @var array $errors @var ?array $record @var array $totals */
use Iha\Core\View;
?>
<div class="container py-4">
  <?= View::capture('student/_steps', ['current' => $current, 'steps' => $steps]) ?>
  <?= View::capture('student/_header', ['current' => 3, 'heading' => 'Academic Reports and Marks', 'studentId' => $studentId, 'applicationNumber' => $applicationNumber]) ?>
  <div class="alert alert-info">
    List every subject from your most recent examination<?= $record && !empty($record['exam_name']) ? ' (<strong>' . e($record['exam_name']) . '</strong>' . (!empty($record['exam_year']) ? ', ' . e($record['exam_year']) : '') . ')' : '' ?>.
    Percentages are calculated automatically. Rows that are unfinished or contain an error are not counted.
  </div>
  <?php if ($errors): ?><div class="alert alert-danger" role="alert">Nothing was saved. Please correct the marked rows.<?= isset($errors['subjects']) ? ' ' . e($errors['subjects']) : '' ?></div><?php endif; ?>

  <form method="post" action="/student/marks" novalidate class="card card-body shadow-sm">
    <?= csrf_field() ?>
    <div class="table-responsive">
      <table class="table align-middle" id="marks-table">
        <thead>
          <tr><th scope="col">Subject name <span class="text-danger">*</span></th><th scope="col" class="col-marks">Total marks <span class="text-danger">*</span></th><th scope="col" class="col-marks">Marks obtained <span class="text-danger">*</span></th><th scope="col" class="col-pct">Percentage</th><th scope="col"><span class="visually-hidden">Remove</span></th></tr>
        </thead>
        <tbody id="subject-rows">
          <?php foreach ($rows as $i => $r): $er = $errors['subject.' . $i] ?? null; $ev = $eval['rows'][$i] ?? null; ?>
            <tr>
              <td>
                <input class="form-control<?= $er ? ' is-invalid' : '' ?>" name="subject_name[]" value="<?= e($r['subject_name']) ?>" maxlength="100" aria-label="Subject name">
                <?php if ($er): ?><div class="invalid-feedback d-block"><?= e($er) ?></div><?php endif; ?>
              </td>
              <td><input class="form-control" name="total_marks[]" value="<?= e($r['total_marks']) ?>" inputmode="decimal" maxlength="9" aria-label="Total marks"></td>
              <td><input class="form-control" name="marks_obtained[]" value="<?= e($r['marks_obtained']) ?>" inputmode="decimal" maxlength="9" aria-label="Marks obtained"></td>
              <td><span data-percent><?= $ev && $ev['valid'] ? e($ev['percent']) . '%' : ($ev && $ev['incomplete'] ? 'Incomplete' : '') ?></span></td>
              <td class="text-end"><button class="btn btn-sm btn-outline-danger" type="button" data-remove-subject>Remove</button></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="table-light fw-semibold">
            <td>Overall</td>
            <td><span id="total-sum"><?= e($totals['total']) ?></span></td>
            <td><span id="obtained-sum"><?= e($totals['obtained']) ?></span></td>
            <td><span id="overall-percent"><?= $totals['percent'] !== null ? e($totals['percent']) . '%' : '&mdash;' ?></span></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <div><button class="btn btn-outline-primary" type="button" data-add-subject>Add subject</button></div>
    <p class="small text-muted mt-3 mb-0">Shown for your convenience. The portal recalculates all figures itself when you save.</p>
    <?= View::capture('student/_buttons', ['backUrl' => '/student/academic']) ?>
  </form>

  <template id="subject-row-template">
    <tr>
      <td><input class="form-control" name="subject_name[]" maxlength="100" aria-label="Subject name"></td>
      <td><input class="form-control" name="total_marks[]" inputmode="decimal" maxlength="9" aria-label="Total marks"></td>
      <td><input class="form-control" name="marks_obtained[]" inputmode="decimal" maxlength="9" aria-label="Marks obtained"></td>
      <td><span data-percent></span></td>
      <td class="text-end"><button class="btn btn-sm btn-outline-danger" type="button" data-remove-subject>Remove</button></td>
    </tr>
  </template>
</div>
<script src="/assets/js/marks.js"></script>
