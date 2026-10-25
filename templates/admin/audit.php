<?php /** @var array $f @var array $result @var array $actions @var array $qs */
$sel = static fn(string $a, string $b): string => $a === $b ? ' selected' : '';
$link = static fn(int $p): string => '/admin/audit?' . http_build_query($qs + ['page' => $p]);
?>
<div class="container-fluid px-4 py-4">
  <h1 class="h3 mb-3">Audit log</h1>
  <form method="get" action="/admin/audit" class="card card-body mb-3">
    <div class="row g-2">
      <div class="col-md-3"><label class="form-label small" for="action">Action</label>
        <select class="form-select form-select-sm" id="action" name="action"><option value="">Any</option>
          <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>"<?= $sel($f['action'], $a) ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><label class="form-label small" for="actor_role">Role</label>
        <select class="form-select form-select-sm" id="actor_role" name="actor_role"><option value="">Any</option>
          <?php foreach (['student', 'super_admin', 'application_reviewer', 'report_viewer', 'system'] as $r): ?><option value="<?= e($r) ?>"<?= $sel($f['actor_role'], $r) ?>><?= e($r) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><label class="form-label small" for="entity_type">Entity</label><input class="form-control form-control-sm" id="entity_type" name="entity_type" value="<?= e($f['entity_type']) ?>" placeholder="application, user..."></div>
      <div class="col-md-1"><label class="form-label small" for="entity_id">ID</label><input class="form-control form-control-sm" id="entity_id" name="entity_id" value="<?= e($f['entity_id']) ?>"></div>
      <div class="col-md-2"><label class="form-label small" for="date_from">From</label><input class="form-control form-control-sm" type="date" id="date_from" name="date_from" value="<?= e($f['date_from']) ?>"></div>
      <div class="col-md-2"><label class="form-label small" for="date_to">To</label><input class="form-control form-control-sm" type="date" id="date_to" name="date_to" value="<?= e($f['date_to']) ?>"></div>
    </div>
    <div class="mt-3 d-flex gap-2"><button class="btn btn-primary btn-sm" type="submit">Filter</button><a class="btn btn-outline-secondary btn-sm" href="/admin/audit">Reset</a></div>
  </form>
  <p class="text-muted small"><?= (int) $result['total'] ?> entr<?= $result['total'] === 1 ? 'y' : 'ies' ?> &middot; page <?= (int) $result['page'] ?> of <?= (int) $result['pages'] ?> &middot; entries cannot be edited or deleted</p>
  <div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th>When (UTC)</th><th>Action</th><th>By</th><th>Entity</th><th>IP</th><th>Details</th></tr></thead>
    <tbody>
      <?php foreach ($result['rows'] as $r): ?>
        <tr>
          <td><?= e(substr((string) $r['created_at'], 0, 19)) ?></td><td><?= e($r['action']) ?></td>
          <td><?= e($r['actor_name'] ?? 'system') ?> <?= $r['actor_role'] ? '<span class="text-muted">(' . e($r['actor_role']) . ')</span>' : '' ?></td>
          <td><?= e($r['entity_type']) ?> <?= e($r['entity_id']) ?></td><td><?= e($r['ip']) ?></td>
          <td><?php if ($r['old_values'] || $r['new_values']): ?><details><summary class="small">view</summary>
            <?php if ($r['old_values']): ?><div class="small"><strong>Before</strong> <code><?= e($r['old_values']) ?></code></div><?php endif; ?>
            <?php if ($r['new_values']): ?><div class="small"><strong>After</strong> <code><?= e($r['new_values']) ?></code></div><?php endif; ?></details><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$result['rows']): ?><tr><td colspan="6" class="text-center text-muted py-4">No entries match.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if ($result['pages'] > 1): ?>
    <nav><ul class="pagination pagination-sm">
      <li class="page-item<?= $result['page'] <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($link(max(1, $result['page'] - 1))) ?>">Previous</a></li>
      <li class="page-item disabled"><span class="page-link"><?= (int) $result['page'] ?> / <?= (int) $result['pages'] ?></span></li>
      <li class="page-item<?= $result['page'] >= $result['pages'] ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($link(min($result['pages'], $result['page'] + 1))) ?>">Next</a></li>
    </ul></nav>
  <?php endif; ?>
</div>
