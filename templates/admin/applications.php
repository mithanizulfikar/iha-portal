<?php
/** @var array $f @var array $perm @var array $result @var array $geoOptions @var array $qs */
$sel = static fn(string $a, string $b): string => $a === $b ? ' selected' : '';
$statuses = ['SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'RETURNED_FOR_CORRECTION', 'DRAFT'];
$link = static fn(int $p): string => '/admin/applications?' . http_build_query($qs + ['page' => $p]);
?>
<div class="container-fluid px-4 py-4">
  <h1 class="h3 mb-3">Applications</h1>

  <form method="get" action="/admin/applications" class="card card-body mb-3">
    <div class="row g-2">
      <div class="col-md-4"><label class="form-label small" for="q">Search</label>
        <input class="form-control form-control-sm" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="<?= $perm['pii'] ? 'Student ID, application no., name or email' : 'Student ID or application no.' ?>"></div>
      <div class="col-md-2"><label class="form-label small" for="status">Application status</label>
        <select class="form-select form-select-sm" id="status" name="status"><option value="">All (except drafts)</option>
          <?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>"<?= $sel($f['status'], $s) ?>><?= e(str_replace('_', ' ', $s)) ?></option><?php endforeach; ?></select></div>
      <?php if ($perm['elig']): ?>
        <div class="col-md-2"><label class="form-label small" for="eligibility">Eligibility</label>
          <select class="form-select form-select-sm" id="eligibility" name="eligibility"><option value="">Any</option>
            <?php foreach (['ELIGIBLE', 'INELIGIBLE', 'PENDING_REVIEW', 'NONE'] as $s): ?><option value="<?= e($s) ?>"<?= $sel($f['eligibility'], $s) ?>><?= e($s === 'NONE' ? 'NOT EVALUATED' : str_replace('_', ' ', $s)) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1"><label class="form-label small" for="pct_min">% from</label><input class="form-control form-control-sm" id="pct_min" name="pct_min" value="<?= e($f['pct_min']) ?>" inputmode="decimal"></div>
        <div class="col-md-1"><label class="form-label small" for="pct_max">% to</label><input class="form-control form-control-sm" id="pct_max" name="pct_max" value="<?= e($f['pct_max']) ?>" inputmode="decimal"></div>
      <?php endif; ?>
      <div class="col-md-2"><label class="form-label small" for="qr">QR code</label>
        <select class="form-select form-select-sm" id="qr" name="qr"><option value="">Any</option>
          <?php foreach (['ACTIVE' => 'Active', 'REVOKED' => 'Revoked', 'NONE' => 'None'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $sel($f['qr'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="row g-2 mt-1">
      <?php if ($perm['pii']): ?>
        <div class="row g-2 col-md-6" data-geo-group>
          <div class="col-md-4"><label class="form-label small" for="province_id">Province</label>
            <select class="form-select form-select-sm" id="province_id" name="province_id" data-geo="province"><option value="">Any</option>
              <?php foreach ($geoOptions['provinces'] as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $sel($f['province_id'], (string) $p['id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label small" for="district_id">District</label>
            <select class="form-select form-select-sm" id="district_id" name="district_id" data-geo="district"<?= $f['province_id'] === '' ? ' disabled' : '' ?>><option value="">Any</option>
              <?php foreach ($geoOptions['districts'] as $d): ?><option value="<?= (int) $d['id'] ?>"<?= $sel($f['district_id'], (string) $d['id']) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label small" for="city_id">City</label>
            <select class="form-select form-select-sm" id="city_id" name="city_id" data-geo="city"<?= $f['district_id'] === '' ? ' disabled' : '' ?>><option value="">Any</option>
              <?php foreach ($geoOptions['cities'] as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $sel($f['city_id'], (string) $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="col-md-2"><label class="form-label small" for="school">School / College</label><input class="form-control form-control-sm" id="school" name="school" value="<?= e($f['school']) ?>"></div>
      <?php endif; ?>
      <div class="col-md-2"><label class="form-label small" for="date_from">Submitted from</label><input class="form-control form-control-sm" type="date" id="date_from" name="date_from" value="<?= e($f['date_from']) ?>"></div>
      <div class="col-md-2"><label class="form-label small" for="date_to">Submitted to</label><input class="form-control form-control-sm" type="date" id="date_to" name="date_to" value="<?= e($f['date_to']) ?>"></div>
    </div>
    <div class="mt-3 d-flex gap-2"><button class="btn btn-primary btn-sm" type="submit">Search</button><a class="btn btn-outline-secondary btn-sm" href="/admin/applications">Reset</a>
      <?php if ($perm['export']): ?><a class="btn btn-outline-secondary btn-sm ms-auto" href="/admin/reports/export?<?= e(http_build_query($qs + ['type' => 'applications'])) ?>">Export CSV</a><?php endif; ?></div>
  </form>

  <p class="text-muted small"><?= (int) $result['total'] ?> application<?= $result['total'] === 1 ? '' : 's' ?> &middot; page <?= (int) $result['page'] ?> of <?= (int) $result['pages'] ?></p>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle">
    <thead><tr>
      <th>Student ID</th><th>Application</th>
      <?php if ($perm['pii']): ?><th>Name</th><th>Email</th><th>Date of birth</th><th>School / College</th><th>Province</th><th>District</th><th>City</th><?php endif; ?>
      <?php if ($perm['elig']): ?><th>% </th><th>Eligibility</th><th>DOB check</th><th>Academic check</th><?php endif; ?>
      <th>QR</th><th>Status</th><th>Submitted</th><th></th>
    </tr></thead>
    <tbody>
      <?php foreach ($result['rows'] as $r): ?>
        <tr>
          <td><?= e($r['student_id']) ?></td><td><?= e($r['application_number']) ?></td>
          <?php if ($perm['pii']): ?>
            <td><?= e($r['full_name']) ?></td><td><?= e($r['email']) ?></td><td><?= e($r['date_of_birth']) ?></td><td><?= e($r['institution_name']) ?></td>
            <td><?= e($r['province']) ?></td><td><?= e($r['district']) ?></td><td><?= e($r['city']) ?></td>
          <?php endif; ?>
          <?php if ($perm['elig']): ?>
            <td><?= $r['calculated_percentage'] !== null ? e($r['calculated_percentage']) . '%' : '&mdash;' ?></td>
            <td><?= badge($r['effective_status'], 'elig') ?><?= $r['override_id'] !== null ? ' <span class="badge text-bg-dark" title="Manual override in effect">OVERRIDDEN</span>' : '' ?></td>
            <td><?= $r['dob_criterion'] ? badge($r['dob_criterion'], 'crit') : '&mdash;' ?></td>
            <td><?= $r['academic_criterion'] ? badge($r['academic_criterion'], 'crit') : '&mdash;' ?></td>
          <?php endif; ?>
          <td><?= $r['qr_status'] ? e($r['qr_status']) : '<span class="text-muted">None</span>' ?></td>
          <td><?= badge($r['status'], 'app') ?></td>
          <td><?= e($r['submitted_at'] ? substr((string) $r['submitted_at'], 0, 10) : '') ?></td>
          <td><a class="btn btn-sm btn-outline-primary" href="/admin/applications/view?id=<?= (int) $r['id'] ?>">View details</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$result['rows']): ?><tr><td colspan="20" class="text-center text-muted py-4">No applications match.</td></tr><?php endif; ?>
    </tbody>
  </table></div>

  <?php if ($result['pages'] > 1): ?>
    <nav aria-label="Pages"><ul class="pagination pagination-sm">
      <li class="page-item<?= $result['page'] <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($link(max(1, $result['page'] - 1))) ?>">Previous</a></li>
      <li class="page-item disabled"><span class="page-link"><?= (int) $result['page'] ?> / <?= (int) $result['pages'] ?></span></li>
      <li class="page-item<?= $result['page'] >= $result['pages'] ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($link(min($result['pages'], $result['page'] + 1))) ?>">Next</a></li>
    </ul></nav>
  <?php endif; ?>
</div>
<?php if ($perm['pii']): ?><script src="/assets/js/geo.js"></script><?php endif; ?>
