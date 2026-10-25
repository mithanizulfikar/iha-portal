<?php /** @var array $profile @var array $app @var array $record @var string $loc @var array $subjects @var array $totals */
use Iha\Student\SectionValidator as V; ?>
<div class="container py-4 legal">
  <div class="no-print mb-3 d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="/student">Back</a>
    <button class="btn btn-primary btn-sm" type="button" data-print>Print / save as PDF</button>
  </div>
  <div class="d-flex align-items-center gap-2 mb-2"><img src="/assets/img/logo.svg" alt="" width="40" height="40"><h1 class="h4 mb-0">Academic Report</h1></div>
  <dl class="row">
    <dt class="col-sm-4 text-muted fw-normal">Student</dt><dd class="col-sm-8"><?= e($profile['full_name']) ?></dd>
    <dt class="col-sm-4 text-muted fw-normal">Student ID</dt><dd class="col-sm-8"><?= e($profile['student_id']) ?></dd>
    <dt class="col-sm-4 text-muted fw-normal">Application No.</dt><dd class="col-sm-8"><?= e($app['application_number']) ?></dd>
    <dt class="col-sm-4 text-muted fw-normal">School / College</dt><dd class="col-sm-8"><?= e($record['institution_name']) ?><?= $record['institution_type'] ? ' (' . e(V::INSTITUTION_TYPES[$record['institution_type']] ?? '') . ')' : '' ?></dd>
    <dt class="col-sm-4 text-muted fw-normal">Location</dt><dd class="col-sm-8"><?= e($loc) ?></dd>
    <dt class="col-sm-4 text-muted fw-normal">Class / session</dt><dd class="col-sm-8"><?= e(trim(($record['current_class'] ?? '') . ' / ' . ($record['academic_session'] ?? ''), ' /')) ?></dd>
    <dt class="col-sm-4 text-muted fw-normal">Examination</dt><dd class="col-sm-8"><?= e(trim(($record['exam_name'] ?? '') . ' ' . ($record['exam_year'] ?? '') . ', ' . ($record['exam_board'] ?? ''), ' ,')) ?></dd>
    <dt class="col-sm-4 text-muted fw-normal">Roll number</dt><dd class="col-sm-8"><?= e($record['roll_number']) ?></dd>
    <dt class="col-sm-4 text-muted fw-normal">Result status</dt><dd class="col-sm-8"><?= e(V::RESULT_STATUSES[$record['result_status']] ?? '') ?></dd>
  </dl>
  <table class="table table-sm">
    <thead><tr><th>Subject</th><th>Total marks</th><th>Marks obtained</th><th>Percentage</th></tr></thead>
    <tbody>
      <?php foreach ($subjects as $s): ?><tr><td><?= e($s['subject_name']) ?></td><td><?= e($s['total_marks']) ?></td><td><?= e($s['marks_obtained']) ?></td><td><?= $s['valid'] ? e($s['percent']) . '%' : 'Incomplete' ?></td></tr><?php endforeach; ?>
      <?php if (!$subjects): ?><tr><td colspan="4" class="text-muted">No subjects entered.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr class="fw-semibold"><td>Overall</td><td><?= e($totals['total']) ?></td><td><?= e($totals['obtained']) ?></td><td><?= $totals['percent'] !== null ? e($totals['percent']) . '%' : '&mdash;' ?></td></tr></tfoot>
  </table>
  <p class="small text-muted">Generated <?= e(gmdate('Y-m-d H:i')) ?> UTC from the details you entered in the IHA portal.</p>
</div>
