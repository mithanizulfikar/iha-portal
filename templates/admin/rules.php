<?php /** @var ?array $active @var array $history @var ?array $form */
$v = static fn(string $k, string $fallback): string => e($form[$k] ?? $fallback);
?>
<div class="container py-4">
  <h1 class="h3 mb-3">Eligibility rules</h1>
  <?php if ($active): ?>
    <div class="card mb-3"><div class="card-header"><strong>Active rule: version <?= (int) $active['version'] ?></strong></div><div class="card-body">
      <p class="mb-1">Date of birth between <strong><?= e($active['dob_min']) ?></strong> and <strong><?= e($active['dob_max']) ?></strong> (both inclusive).</p>
      <p class="mb-0">Overall academic percentage of at least <strong><?= e($active['min_percentage']) ?>%</strong>.</p>
    </div></div>
  <?php endif; ?>

  <form method="post" action="/admin/rules" class="card card-body mb-4">
    <?= csrf_field() ?>
    <h2 class="h5">Publish a new version</h2>
    <p class="small text-muted">Old versions are never edited: a change creates a new version and keeps the old one in the history. The change is written to the audit log.</p>
    <div class="row g-3">
      <div class="col-md-3"><label class="form-label" for="dob_min">Earliest date of birth</label><input class="form-control" type="date" id="dob_min" name="dob_min" value="<?= $v('dob_min', (string) ($active['dob_min'] ?? '')) ?>"></div>
      <div class="col-md-3"><label class="form-label" for="dob_max">Latest date of birth</label><input class="form-control" type="date" id="dob_max" name="dob_max" value="<?= $v('dob_max', (string) ($active['dob_max'] ?? '')) ?>"></div>
      <div class="col-md-3"><label class="form-label" for="min_percentage">Minimum percentage</label><input class="form-control" id="min_percentage" name="min_percentage" value="<?= $v('min_percentage', (string) ($active['min_percentage'] ?? '')) ?>" inputmode="decimal"></div>
      <div class="col-12"><label class="form-label" for="notes">Note (optional)</label><input class="form-control" id="notes" name="notes" maxlength="500" value="<?= $v('notes', '') ?>"></div>
      <div class="col-12"><div class="form-check">
        <input class="form-check-input" type="checkbox" id="reevaluate" name="reevaluate" value="1"<?= !empty($form['reevaluate']) ? ' checked' : '' ?>>
        <label class="form-check-label" for="reevaluate">Re-evaluate applications that are still waiting (submitted or under review) with the new rule now. Their QR codes are issued or revoked to match. Approved, rejected and returned applications are not touched.</label>
      </div></div>
    </div>
    <div class="mt-3"><button class="btn btn-warning" type="submit">Publish new version</button></div>
  </form>

  <h2 class="h5">History</h2>
  <div class="table-responsive"><table class="table table-sm">
    <thead><tr><th>Version</th><th>Date-of-birth range</th><th>Minimum %</th><th>Created</th><th>By</th><th>Note</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($history as $h): ?>
        <tr><td>v<?= (int) $h['version'] ?></td><td><?= e($h['dob_min']) ?> to <?= e($h['dob_max']) ?></td><td><?= e($h['min_percentage']) ?>%</td>
          <td><?= e(substr((string) $h['created_at'], 0, 16)) ?> UTC</td><td><?= e($h['created_by_name'] ?? 'system') ?></td><td><?= e($h['notes']) ?></td>
          <td><?= $h['is_active'] ? '<span class="badge text-bg-success">ACTIVE</span>' : '' ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
