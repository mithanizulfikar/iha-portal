<?php
/** @var string $section @var array $f @var array $perm @var array $data @var array $geoOptions @var array $qs */
$sel = static fn(string $a, string $b): string => $a === $b ? ' selected' : '';
$tab = static fn(string $k, string $label, bool $show = true): string => $show
    ? '<li class="nav-item"><a class="nav-link' . ($k === $GLOBALS['__section'] ? ' active' : '') . '" href="/admin/reports?' . e(http_build_query(['section' => $k])) . '">' . e($label) . '</a></li>' : '';
$GLOBALS['__section'] = $section;
$chart = static fn(array $cfg): string => '<canvas data-chart="' . e(json_encode($cfg)) . '" height="120" aria-label="' . e($cfg['label'] ?? 'chart') . '" role="img"></canvas>';
$export = static fn(string $type) => $perm['export'] ? '<a class="btn btn-sm btn-outline-secondary" href="/admin/reports/export?' . e(http_build_query($GLOBALS['__qs'] + ['type' => $type])) . '">CSV</a>' : '';
$GLOBALS['__qs'] = $qs;
$qsSec = $qs + ['section' => $section];
?>
<div class="container-fluid px-4 py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Reports</h1>
    <button class="btn btn-outline-secondary btn-sm no-print" type="button" data-print>Print</button>
  </div>

  <ul class="nav nav-tabs mb-3 no-print">
    <?= $tab('eligibility', 'Eligibility', $perm['elig']) ?>
    <?= $tab('academic', 'Academic') ?>
    <?= $tab('registration', 'Registration') ?>
  </ul>

  <form method="get" action="/admin/reports" class="card card-body mb-3 no-print">
    <input type="hidden" name="section" value="<?= e($section) ?>">
    <div class="row g-2">
      <div class="col-md-2"><label class="form-label small" for="date_from"><?= $section === 'registration' ? 'Registered from' : 'Submitted from' ?></label><input class="form-control form-control-sm" type="date" id="date_from" name="date_from" value="<?= e($f['date_from']) ?>"></div>
      <div class="col-md-2"><label class="form-label small" for="date_to"><?= $section === 'registration' ? 'Registered to' : 'Submitted to' ?></label><input class="form-control form-control-sm" type="date" id="date_to" name="date_to" value="<?= e($f['date_to']) ?>"></div>
      <?php if ($section !== 'registration'): ?>
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
    </div>
    <div class="mt-3 d-flex gap-2"><button class="btn btn-primary btn-sm" type="submit">Apply</button><a class="btn btn-outline-secondary btn-sm" href="/admin/reports?section=<?= e($section) ?>">Reset</a></div>
  </form>

<?php if ($section === 'eligibility'): $e = $data; ?>
  <p class="text-muted small">Counts submitted applications by current effective eligibility status. Percentage = eligible &divide; evaluated (eligible + ineligible + pending review). Incomplete drafts are never counted as ineligible.</p>
  <div class="row g-3 mb-3">
    <?php foreach ([['Submitted applications', $e['submitted']], ['Eligible', $e['eligible']], ['Ineligible', $e['ineligible']], ['Pending review', $e['pending']],
        ['Eligibility percentage', $e['pct_eligible'] !== null ? $e['pct_eligible'] . '% of ' . $e['evaluated'] . ' evaluated' : 'no evaluated applications'],
        ['QR codes issued', $e['qr_issued']], ['Active QR codes', $e['qr_active']], ['Revoked QR codes', $e['qr_revoked']]] as [$l, $v]): ?>
      <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body"><h2 class="h6 text-muted"><?= e($l) ?></h2><p class="h3 mb-0"><?= e($v) ?></p></div></div></div>
    <?php endforeach; ?>
  </div>
  <p class="small text-muted">Not yet evaluated: <?= (int) $e['not_evaluated'] ?> &middot; Manual overrides in effect: <?= (int) $e['overridden'] ?> <?= $export('eligibility') ?></p>
  <div class="row"><div class="col-md-6"><?= $chart(['type' => 'doughnut', 'label' => 'Eligibility status', 'labels' => ['Eligible', 'Ineligible', 'Pending review', 'Not evaluated'],
      'values' => [(int) $e['eligible'], (int) $e['ineligible'], (int) $e['pending'], (int) $e['not_evaluated']], 'colors' => ['#198754', '#dc3545', '#ffc107', '#adb5bd']]) ?></div></div>

<?php elseif ($section === 'academic'): $a = $data; $s = $a['summary']; ?>
  <p class="text-muted small">Based on submitted applications with valid marks. Percentage = total marks obtained &divide; total marks over all valid subjects. "60% or above" uses the exact figure.</p>
  <div class="row g-3 mb-3">
    <?php foreach ([['Applications with marks', $s['n']], ['Scoring 60% or above', $s['ge60']], ['Scoring below 60%', $s['lt60']], ['Average percentage', $s['avg_pct'] !== null ? $s['avg_pct'] . '%' : '—']] as [$l, $v]): ?>
      <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body"><h2 class="h6 text-muted"><?= e($l) ?></h2><p class="h3 mb-0"><?= e($v) ?></p></div></div></div>
    <?php endforeach; ?>
  </div>
  <div class="row mb-4"><div class="col-md-7">
    <h2 class="h6">Percentage distribution</h2>
    <?= $chart(['type' => 'bar', 'label' => 'Applications', 'labels' => array_keys($a['distribution']), 'values' => array_values($a['distribution'])]) ?>
  </div></div>

  <?php
  $table = static function (string $title, string $type, array $rows, string $colLabel, bool $subjects = false) use ($export): string {
      $h = '<h2 class="h6 mt-4 d-flex justify-content-between align-items-center">' . e($title) . ' <span>' . $export($type) . '</span></h2>';
      $h .= '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>' . e($colLabel) . '</th><th>' . ($subjects ? 'Records' : 'Applications') . '</th><th>Average %</th>' . ($subjects ? '' : '<th>60% or above</th>') . '</tr></thead><tbody>';
      foreach ($rows as $r) {
          $h .= '<tr><td>' . e($r['label']) . '</td><td>' . e($r['n']) . '</td><td>' . e($r['avg_pct']) . '</td>' . ($subjects ? '' : '<td>' . e($r['ge60']) . '</td>') . '</tr>';
      }
      if (!$rows) { $h .= '<tr><td colspan="4" class="text-muted">No data.</td></tr>'; }
      return $h . '</tbody></table></div>';
  };
  echo $table('Province-wise', 'provinces', $a['provinces'], 'Province');
  echo $table('District-wise', 'districts', $a['districts'], 'District');
  echo $table('City-wise', 'cities', $a['cities'], 'City / locality');
  echo $table('School / College-wise (' . (int) $a['school_total'] . ')', 'schools', $a['schools'], 'School / College');
  ?>
  <?php if ($a['school_pages'] > 1): ?>
    <nav class="no-print"><ul class="pagination pagination-sm">
      <li class="page-item<?= $a['school_page'] <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="/admin/reports?<?= e(http_build_query($qsSec + ['page' => max(1, $a['school_page'] - 1)])) ?>">Previous</a></li>
      <li class="page-item disabled"><span class="page-link"><?= (int) $a['school_page'] ?> / <?= (int) $a['school_pages'] ?></span></li>
      <li class="page-item<?= $a['school_page'] >= $a['school_pages'] ? ' disabled' : '' ?>"><a class="page-link" href="/admin/reports?<?= e(http_build_query($qsSec + ['page' => min($a['school_pages'], $a['school_page'] + 1)])) ?>">Next</a></li>
    </ul></nav>
  <?php endif; ?>
  <?= $table('Subject-wise (top 30)', 'subjects', $a['subjects'], 'Subject', true) ?>

<?php else: $r = $data; ?>
  <p class="text-muted small">New student accounts from <?= e($r['range'][0]) ?> to <?= e($r['range'][1]) ?> (UTC).</p>
  <div class="row g-3 mb-3">
    <?php foreach ([['New accounts', $r['accounts']['total']], ['Verified', $r['accounts']['verified']], ['Unverified', $r['accounts']['unverified']],
        ['Draft applications', $r['applications']['draft']], ['Submitted', $r['applications']['submitted']], ['Awaiting review', $r['applications']['awaiting_review']],
        ['Approved', $r['applications']['approved']], ['Rejected', $r['applications']['rejected']]] as [$l, $v]): ?>
      <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body"><h2 class="h6 text-muted"><?= e($l) ?></h2><p class="h3 mb-0"><?= e($v) ?></p></div></div></div>
    <?php endforeach; ?>
  </div>
  <p class="small text-muted">Applications are those created in the same period.</p>
  <?php foreach (['daily' => 'Daily registrations', 'weekly' => 'Weekly registrations (week starting)', 'monthly' => 'Monthly registrations (month starting)'] as $k => $title): ?>
    <h2 class="h6 mt-4 d-flex justify-content-between align-items-center"><?= e($title) ?> <span><?= $export($k) ?></span></h2>
    <?= $chart(['type' => 'bar', 'label' => 'New student accounts', 'labels' => array_keys($r[$k]), 'values' => array_values($r[$k])]) ?>
  <?php endforeach; ?>
<?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="/assets/js/charts.js"></script>
<script src="/assets/js/geo.js"></script>
