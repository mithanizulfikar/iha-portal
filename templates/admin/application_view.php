<?php
/** @var array $d @var array $perm */
use Iha\Student\SectionValidator as V;

$app = $d['app'];
$p = $d['pii'];
$el = $d['elig']['current'] ?? null;
$dl = static function (array $pairs): string {
    $h = '<dl class="row mb-0">';
    foreach ($pairs as $label => $value) {
        $h .= '<dt class="col-sm-4 text-muted fw-normal">' . e($label) . '</dt><dd class="col-sm-8">' . (($value === null || $value === '') ? '<span class="text-muted">&mdash;</span>' : e($value)) . '</dd>';
    }
    return $h . '</dl>';
};
$yn = static fn($v): string => $v === null ? '' : ($v ? 'Yes' : 'No');
$open = in_array($app['status'], ['SUBMITTED', 'UNDER_REVIEW'], true);
?>
<div class="container py-4">
  <p class="no-print d-flex justify-content-between"><a href="/admin/applications">&larr; All applications</a><button class="btn btn-outline-secondary btn-sm" type="button" data-print>Print application record</button></p>
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
      <h1 class="h3 mb-1"><?= e($d['profile']['full_name']) ?></h1>
      <p class="text-muted mb-0">Student ID <strong><?= e($d['profile']['student_id']) ?></strong> &middot; Application <strong><?= e($app['application_number']) ?></strong>
        &middot; Submitted <?= e($app['submitted_at'] ? substr((string) $app['submitted_at'], 0, 16) . ' UTC' : 'not yet') ?> &middot; QR <?= e($d['qr_status'] ?? 'none') ?></p>
    </div>
    <div class="text-end"><div class="small text-muted">Application status</div><div class="fs-5"><?= badge($app['status'], 'app') ?></div></div>
  </div>

  <?php if ($perm['elig']): ?>
    <div class="card mb-3 border-2 <?= $el ? ['ELIGIBLE' => 'border-success', 'INELIGIBLE' => 'border-danger', 'PENDING_REVIEW' => 'border-warning'][$el['effective_status']] ?? '' : '' ?>">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong>Eligibility (administrators only)</strong>
        <span class="fs-5"><?= badge($el['effective_status'] ?? null, 'elig') ?><?= $el && $el['override_id'] !== null ? ' <span class="badge text-bg-dark">MANUAL OVERRIDE</span>' : '' ?></span>
      </div>
      <div class="card-body">
        <?php if ($el): ?>
          <div class="row g-3">
            <div class="col-md-6"><div class="border rounded p-3 h-100">
              <div class="d-flex justify-content-between"><strong>Date of birth</strong><?= badge($el['dob_criterion'], 'crit') ?></div>
              <div class="small text-muted mt-1">Permitted: <?= e($el['dob_min']) ?> to <?= e($el['dob_max']) ?> (both inclusive)</div>
              <?php if ($p): ?><div class="mt-1">Student's date of birth: <?= e($p['profile']['date_of_birth']) ?></div><?php endif; ?>
            </div></div>
            <div class="col-md-6"><div class="border rounded p-3 h-100">
              <div class="d-flex justify-content-between"><strong>Academic percentage</strong><?= badge($el['academic_criterion'], 'crit') ?></div>
              <div class="small text-muted mt-1">Minimum: <?= e($el['min_percentage']) ?>%</div>
              <div class="mt-1">Calculated: <?= $el['calculated_percentage'] !== null ? e($el['calculated_percentage']) . '%' : 'not available yet' ?></div>
            </div></div>
          </div>
          <dl class="row mt-3 mb-0">
            <dt class="col-sm-3 text-muted fw-normal">Calculated result</dt><dd class="col-sm-9"><?= badge($el['calculated_status'], 'elig') ?> <span class="text-muted small">rule v<?= (int) $el['rule_version'] ?> &middot; <?= e(substr((string) $el['evaluated_at'], 0, 16)) ?> UTC &middot; <?= e($el['trigger_event']) ?></span></dd>
            <?php if ($el['reason']): ?><dt class="col-sm-3 text-muted fw-normal">Reason</dt><dd class="col-sm-9"><?= e($el['reason']) ?></dd><?php endif; ?>
            <?php if ($el['override_id'] !== null): ?>
              <dt class="col-sm-3 text-muted fw-normal">Override</dt><dd class="col-sm-9"><?= badge($el['override_status'], 'elig') ?> by <?= e($el['override_by']) ?> on <?= e(substr((string) $el['override_at'], 0, 16)) ?> UTC<br><em><?= e($el['override_reason']) ?></em></dd>
            <?php endif; ?>
          </dl>
        <?php else: ?>
          <p class="text-muted mb-0">Not evaluated yet. Eligibility is calculated when the student submits.</p>
        <?php endif; ?>

        <?php if ($perm['review']): ?>
          <form method="post" action="/admin/applications/recalculate" class="mt-3"><?= csrf_field() ?><input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
            <button class="btn btn-outline-primary btn-sm" type="submit">Recalculate eligibility</button></form>
        <?php endif; ?>
        <?php if ($perm['override'] && $el): ?>
          <hr>
          <h2 class="h6">Resolve with a manual override</h2>
          <form method="post" action="/admin/applications/override" class="row g-2"><?= csrf_field() ?><input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
            <div class="col-md-3"><label class="form-label small" for="ov_status">Set effective status to</label>
              <select class="form-select form-select-sm" id="ov_status" name="status"><option value="ELIGIBLE">ELIGIBLE</option><option value="INELIGIBLE">INELIGIBLE</option><option value="PENDING_REVIEW">PENDING REVIEW</option></select></div>
            <div class="col-md-7"><label class="form-label small" for="ov_reason">Documented reason (required, at least 10 characters)</label><input class="form-control form-control-sm" id="ov_reason" name="reason" maxlength="1000"></div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-warning btn-sm w-100" type="submit">Record override</button></div>
          </form>
          <p class="small text-muted mt-1 mb-0">The calculated result is never changed; the override is recorded separately and audited. It stops applying if a recalculation changes the calculated outcome.</p>
          <?php if ($el['override_id'] !== null): ?>
            <form method="post" action="/admin/applications/override/clear" class="row g-2 mt-2"><?= csrf_field() ?><input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
              <div class="col-md-10"><label class="form-label small" for="clr_reason">Reason for removing the override</label><input class="form-control form-control-sm" id="clr_reason" name="reason" maxlength="1000"></div>
              <div class="col-md-2 d-flex align-items-end"><button class="btn btn-outline-danger btn-sm w-100" type="submit">Remove override</button></div></form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($perm['review']): ?>
    <div class="card mb-3"><div class="card-header"><strong>Review decision</strong></div><div class="card-body">
      <?php if ($open): ?>
        <form method="post" action="/admin/applications/review" class="row g-2"><?= csrf_field() ?><input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
          <div class="col-md-3"><label class="form-label small" for="decision">Decision</label>
            <select class="form-select form-select-sm" id="decision" name="decision">
              <?php if ($app['status'] === 'SUBMITTED'): ?><option value="UNDER_REVIEW">Start review</option><?php endif; ?>
              <option value="APPROVED">Approve</option><option value="REJECTED">Reject</option><option value="RETURNED_FOR_CORRECTION">Return for correction</option></select></div>
          <div class="col-md-9"><label class="form-label small" for="reason">Reason (internal; required for approve, reject and return)</label><input class="form-control form-control-sm" id="reason" name="reason" maxlength="1000"></div>
          <div class="col-12"><label class="form-label small" for="student_message">Message to the student (only used when returning for correction)</label>
            <input class="form-control form-control-sm" id="student_message" name="student_message" maxlength="500" placeholder="Tell the student what to fix.">
            <div class="form-text text-danger">The student can read this message. Do not mention eligibility.</div></div>
          <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Apply decision</button></div>
        </form>
        <p class="small text-muted mt-2 mb-0">Approval is only possible when the effective eligibility status is ELIGIBLE.</p>
      <?php else: ?>
        <p class="text-muted mb-0">No review action is available while the status is <?= e(str_replace('_', ' ', $app['status'])) ?>.</p>
      <?php endif; ?>
    </div></div>
  <?php endif; ?>

  <?php if ($p): ?>
    <div class="card mb-3"><div class="card-header"><strong>Student profile</strong></div><div class="card-body">
      <?= $dl([
        'Email' => $p['profile']['email'], "Father's name" => $p['profile']['father_name'], "Mother's name" => $p['profile']['mother_name'],
        'Gender' => V::GENDERS[$p['profile']['gender']] ?? '', 'Date of birth' => $p['profile']['date_of_birth'], 'Nationality' => $p['profile']['nationality'],
        'CNIC / B-Form' => $p['profile']['id_number'], 'Contact' => $p['profile']['contact_number'],
        'Current address' => trim(($p['profile']['address_line'] ?? '') . ($p['profileLoc'] !== '' ? ', ' . $p['profileLoc'] : ''), ', '),
        'Permanent address' => trim(($p['profile']['perm_address_line'] ?? '') . ($p['permLoc'] !== '' ? ', ' . $p['permLoc'] : ''), ', '),
      ]) ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><strong>Academic</strong></div><div class="card-body">
      <?= $dl($p['academic'] ? [
        'School / College' => $p['academic']['institution_name'], 'Type' => V::INSTITUTION_TYPES[$p['academic']['institution_type']] ?? '', 'Location' => $p['schoolLoc'],
        'Class / session' => trim(($p['academic']['current_class'] ?? '') . ' / ' . ($p['academic']['academic_session'] ?? ''), ' /'),
        'Board' => $p['academic']['exam_board'], 'Examination' => trim(($p['academic']['exam_name'] ?? '') . ' ' . ($p['academic']['exam_year'] ?? '')),
        'Roll number' => $p['academic']['roll_number'], 'Result status' => V::RESULT_STATUSES[$p['academic']['result_status']] ?? '',
      ] : ['Academic details' => 'Not entered']) ?>
      <?php if ($p['subjects']): ?>
        <table class="table table-sm mt-3 mb-0"><thead><tr><th>Subject</th><th>Total</th><th>Obtained</th><th>%</th></tr></thead><tbody>
          <?php foreach ($p['subjects'] as $s): ?><tr><td><?= e($s['subject_name']) ?></td><td><?= e($s['total_marks']) ?></td><td><?= e($s['marks_obtained']) ?></td><td><?= $s['valid'] ? e($s['percent']) . '%' : 'Incomplete' ?></td></tr><?php endforeach; ?>
        </tbody><tfoot><tr class="fw-semibold"><td>Overall</td><td><?= e($p['totals']['total']) ?></td><td><?= e($p['totals']['obtained']) ?></td><td><?= $p['totals']['percent'] !== null ? e($p['totals']['percent']) . '%' : '&mdash;' ?></td></tr></tfoot></table>
      <?php endif; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><strong>Parent / guardian</strong></div><div class="card-body">
      <?= $dl($p['guardian'] ? [
        'Father' => trim(($p['guardian']['father_name'] ?? '') . ' ' . ($p['guardian']['father_contact'] ? '(' . $p['guardian']['father_contact'] . ')' : '')),
        'Mother' => trim(($p['guardian']['mother_name'] ?? '') . ' ' . ($p['guardian']['mother_contact'] ? '(' . $p['guardian']['mother_contact'] . ')' : '')),
        'Guardian' => trim(($p['guardian']['guardian_name'] ?? '') . ($p['guardian']['guardian_relationship'] ? ', ' . $p['guardian']['guardian_relationship'] : ''), ', '),
        'Primary guardian' => V::PRIMARY_GUARDIANS[$p['guardian']['primary_guardian']] ?? '',
        'Address' => trim(($p['guardian']['address_line'] ?? '') . ($p['guardianLoc'] !== '' ? ', ' . $p['guardianLoc'] : ''), ', '),
        'Emergency contact' => trim(($p['guardian']['emergency_name'] ?? '') . ' ' . ($p['guardian']['emergency_contact'] ? '(' . $p['guardian']['emergency_contact'] . ')' : '')),
      ] : ['Parent / guardian' => 'Not entered']) ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><strong>Travel history</strong> <span class="text-muted small">(never affects eligibility)</span></div><div class="card-body">
      <?= $dl($p['travel'] ? ['Outside home city' => $yn($p['travel']['outside_home_city']), 'Another district' => $yn($p['travel']['another_district']), 'Another province' => $yn($p['travel']['another_province']), 'International' => $yn($p['travel']['international'])] : ['Travel history' => 'Not entered']) ?>
      <?php foreach ($p['travelRecords'] as $r): ?><hr><?= $dl(['Destination' => trim(implode(', ', array_filter([$r['city_visited'], $r['district_visited'], $r['province_state'], $r['country_visited']])), ', '), 'Purpose' => $r['purpose'], 'Dates' => trim(($r['start_date'] ?? '') . ' to ' . ($r['end_date'] ?? ''), ' to')]) ?><?php endforeach; ?>
    </div></div>
  <?php endif; ?>

  <?php if ($perm['elig'] && $d['elig']): ?>
    <div class="card mb-3"><div class="card-header"><strong>Eligibility history</strong></div><div class="card-body">
      <table class="table table-sm mb-0"><thead><tr><th>When (UTC)</th><th>Calculated</th><th>DOB</th><th>Academic</th><th>%</th><th>Rule</th><th>Trigger</th></tr></thead><tbody>
        <?php foreach ($d['elig']['history'] as $h): ?>
          <tr><td><?= e(substr((string) $h['evaluated_at'], 0, 16)) ?></td><td><?= badge($h['calculated_status'], 'elig') ?></td><td><?= badge($h['dob_criterion'], 'crit') ?></td><td><?= badge($h['academic_criterion'], 'crit') ?></td>
            <td><?= $h['calculated_percentage'] !== null ? e($h['calculated_percentage']) . '%' : '&mdash;' ?></td><td>v<?= (int) $h['rule_version'] ?></td><td><?= e($h['trigger_event']) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table>
      <?php if ($d['elig']['overrides']): ?>
        <h2 class="h6 mt-3">Overrides</h2>
        <ul class="mb-0"><?php foreach ($d['elig']['overrides'] as $o): ?><li><?= badge($o['override_status'], 'elig') ?> by <?= e($o['full_name']) ?> on <?= e(substr((string) $o['created_at'], 0, 16)) ?> UTC: <?= e($o['reason']) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div></div>
  <?php endif; ?>

  <div class="card mb-3"><div class="card-header"><strong>Review history and internal notes</strong></div><div class="card-body">
    <?php foreach ($d['reviews'] as $r): ?><p class="mb-1"><?= badge($r['decision'], 'app') ?> by <?= e($r['full_name']) ?> on <?= e(substr((string) $r['decided_at'], 0, 16)) ?> UTC<?= $r['reason'] ? ': ' . e($r['reason']) : '' ?></p><?php endforeach; ?>
    <?php if (!$d['reviews']): ?><p class="text-muted">No review decisions yet.</p><?php endif; ?>
    <hr>
    <?php foreach ($d['notes'] as $n): ?><p class="mb-1"><em><?= e($n['note']) ?></em> <span class="text-muted small">&mdash; <?= e($n['full_name']) ?>, <?= e(substr((string) $n['created_at'], 0, 16)) ?> UTC</span></p><?php endforeach; ?>
    <?php if (!$d['notes']): ?><p class="text-muted">No internal notes.</p><?php endif; ?>
    <?php if ($perm['review']): ?>
      <form method="post" action="/admin/applications/note" class="row g-2 mt-2"><?= csrf_field() ?><input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
        <div class="col-md-10"><label class="form-label small" for="note">Add an internal note (never shown to the student)</label><input class="form-control form-control-sm" id="note" name="note" maxlength="2000"></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-outline-primary btn-sm w-100" type="submit">Add note</button></div></form>
    <?php endif; ?>
  </div></div>

  <?php if ($perm['qr'] && $d['qr']): $q = $d['qr']; ?>
    <div class="card mb-3"><div class="card-header"><strong>QR code</strong></div><div class="card-body">
      <?php if (!$q['key_ok']): ?><div class="alert alert-warning">APP_KEY in <code>.env</code> is missing or still the placeholder, so QR codes cannot be issued. Set a long random value, then press "Issue / replace" below or run <code>bin\qr-sync.php</code>.</div><?php endif; ?>
      <?php if (!$q['lib']): ?><div class="alert alert-warning">The QR image library is not installed. Run <code>composer install --no-dev</code> in the project folder.</div><?php endif; ?>
      <?php if ($q['active']): ?>
        <div class="d-flex flex-wrap gap-4 align-items-start">
          <?php if ($q['dataUri']): ?><img class="qr-img" src="<?= e($q['dataUri']) ?>" alt="QR code" width="180" height="180"><?php endif; ?>
          <div>
            <p class="mb-1"><span class="badge text-bg-success">ACTIVE</span> issued <?= e(substr((string) $q['active']['created_at'], 0, 16)) ?> UTC</p>
            <p class="mb-2 text-muted small">Valid scans: <?= (int) $q['scan']['scans'] ?><?= $q['scan']['last_scan'] ? ', last ' . e(substr((string) $q['scan']['last_scan'], 0, 16)) . ' UTC' : '' ?></p>
            <?php if ($q['dataUri']): ?><a class="btn btn-outline-primary btn-sm mb-2" href="/admin/applications/qr?id=<?= (int) $app['id'] ?>">Download (SVG)</a><?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <p class="text-muted">No active QR code. A code is issued automatically when the application is ELIGIBLE, submitted, complete and the account is verified.</p>
      <?php endif; ?>
      <form method="post" action="/admin/applications/qr/replace" class="row g-2 mt-1"><?= csrf_field() ?><input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
        <div class="col-md-9"><label class="form-label small" for="qr_replace_reason">Issue / replace (reason, e.g. code compromised)</label><input class="form-control form-control-sm" id="qr_replace_reason" name="reason" maxlength="1000"></div>
        <div class="col-md-3 d-flex align-items-end"><button class="btn btn-warning btn-sm w-100" type="submit">Issue / replace</button></div></form>
      <?php if ($q['active']): ?>
        <form method="post" action="/admin/applications/qr/revoke" class="row g-2 mt-1"><?= csrf_field() ?><input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
          <div class="col-md-9"><label class="form-label small" for="qr_revoke_reason">Revoke without replacing (reason)</label><input class="form-control form-control-sm" id="qr_revoke_reason" name="reason" maxlength="1000"></div>
          <div class="col-md-3 d-flex align-items-end"><button class="btn btn-outline-danger btn-sm w-100" type="submit">Revoke</button></div></form>
      <?php endif; ?>
    </div></div>
  <?php endif; ?>

  <?php if ($perm['audit']): ?>
    <div class="card mb-3"><div class="card-header"><strong>Audit trail</strong></div><div class="card-body">
      <table class="table table-sm mb-0"><thead><tr><th>When (UTC)</th><th>Action</th><th>By</th></tr></thead><tbody>
        <?php foreach ($d['audit'] as $a): ?><tr><td><?= e(substr((string) $a['created_at'], 0, 16)) ?></td><td><?= e($a['action']) ?></td><td><?= e($a['full_name'] ?? 'system') ?> <?= $a['actor_role'] ? '(' . e($a['actor_role']) . ')' : '' ?></td></tr><?php endforeach; ?>
        <?php if (!$d['audit']): ?><tr><td colspan="3" class="text-muted">No entries.</td></tr><?php endif; ?>
      </tbody></table>
    </div></div>
  <?php endif; ?>
</div>
