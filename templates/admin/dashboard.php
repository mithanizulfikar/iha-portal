<?php /** @var array $stats @var array $perm @var string[] $permissions @var string $role */
$g = $stats['general'];
$card = static function (string $label, mixed $value, string $hint = ''): string {
    return '<div class="col-6 col-md-4 col-lg-3"><div class="card h-100"><div class="card-body"><h2 class="h6 text-muted">' . e($label) . '</h2>'
        . '<p class="display-6 mb-0">' . e($value) . '</p>' . ($hint !== '' ? '<p class="small text-muted mb-0">' . e($hint) . '</p>' : '') . '</div></div></div>';
};
?>
<div class="container py-4">
  <h1 class="h3 mb-1">Admin Portal</h1>
  <p class="text-muted">Signed in as <strong><?= e(str_replace('_', ' ', $role)) ?></strong>. Figures are counted live from the database.</p>

  <div class="mb-3 d-flex flex-wrap gap-2">
    <?php if (in_array($role, ['super_admin', 'application_reviewer'], true)): ?><a class="btn btn-primary" href="/admin/applications">Applications</a><?php endif; ?>
    <a class="btn btn-outline-primary" href="/admin/reports">Reports</a>
    <?php if ($perm['audit']): ?><a class="btn btn-outline-primary" href="/admin/audit">Audit log</a><?php endif; ?>
    <?php if (in_array('users.manage', $permissions, true)): ?><a class="btn btn-outline-primary" href="/admin/users">Administrator accounts</a><?php endif; ?>
    <?php if ($perm['rules']): ?><a class="btn btn-outline-primary" href="/admin/rules">Eligibility rules</a><?php endif; ?>
  </div>

  <h2 class="h5">Registrations and applications</h2>
  <div class="row g-3 mb-4">
    <?= $card('Registered students', $g['students'], 'student accounts') ?>
    <?= $card('Verified accounts', $g['verified']) ?>
    <?= $card('Unverified accounts', $g['unverified']) ?>
    <?= $card('Total applications', $g['applications'], 'all application records') ?>
    <?= $card('Draft applications', $g['draft']) ?>
    <?= $card('Submitted applications', $g['submitted'], 'submitted at least once') ?>
    <?= $card('Under review', $g['under_review']) ?>
    <?= $card('Approved', $g['approved']) ?>
    <?= $card('Rejected', $g['rejected']) ?>
  </div>

  <?php if ($stats['elig']): ?>
    <h2 class="h5">Eligibility and QR codes <span class="badge text-bg-secondary">administrators only</span></h2>
    <div class="row g-3 mb-4">
      <?= $card('Eligible', $stats['elig']['eligible'], 'submitted applications') ?>
      <?= $card('Ineligible', $stats['elig']['ineligible'], 'submitted applications') ?>
      <?= $card('Pending review', $stats['elig']['pending'], 'submitted applications') ?>
      <?= $card('Active QR codes', $stats['qr']['active']) ?>
      <?= $card('Revoked QR codes', $stats['qr']['revoked']) ?>
    </div>
  <?php endif; ?>

  <details class="mb-3"><summary class="small text-muted">How these are counted</summary>
    <ul class="small text-muted mt-2">
      <li>Students are counted as accounts. Applications are counted as records, so a student with two applications counts twice there.</li>
      <li>"Submitted" means submitted at least once, whatever its status is now. Draft, under review, approved and rejected use the current status.</li>
      <li>Eligible, ineligible and pending review count submitted applications by their current effective status (an override counts if one is in effect). Incomplete drafts are never counted as ineligible.</li>
      <li>QR figures count code records, active and revoked.</li>
    </ul>
  </details>

  <h2 class="h6">Your permissions</h2>
  <p><?php foreach ($permissions as $p): ?><span class="badge text-bg-secondary me-1"><?= e($p) ?></span><?php endforeach; ?></p>
</div>
