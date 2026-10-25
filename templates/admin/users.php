<?php /** @var array $admins @var int $me @var array $roles @var ?array $credential */ ?>
<div class="container py-4">
  <h1 class="h3 mb-3">Administrator accounts</h1>

  <?php if ($credential): ?>
    <div class="alert alert-warning" role="alert">
      <strong>Temporary password for <?= e($credential['email']) ?>:</strong>
      <code class="fs-5 ms-2 user-select-all"><?= e($credential['password']) ?></code>
      <div class="small mt-1">Shown only once. Share it through a secure channel (not by email or chat). They must choose their own password when they first log in.</div>
    </div>
  <?php endif; ?>

  <div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach ($admins as $a): $self = (int) $a['id'] === $me; ?>
        <tr>
          <td><?= e($a['full_name']) ?><?= $self ? ' <span class="badge text-bg-light border">you</span>' : '' ?><?= $a['must_change_password'] ? ' <span class="badge text-bg-warning text-dark" title="Has a temporary password">temp password</span>' : '' ?></td>
          <td><?= e($a['email']) ?></td>
          <td>
            <?php if ($self): ?><?= e(str_replace('_', ' ', $a['role'])) ?>
            <?php else: ?>
              <form method="post" action="/admin/users/role" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
                <select class="form-select form-select-sm" name="role" aria-label="Role"><?php foreach ($roles as $r): ?><option value="<?= e($r) ?>"<?= $r === $a['role'] ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $r)) ?></option><?php endforeach; ?></select>
                <button class="btn btn-sm btn-outline-primary" type="submit">Set</button></form>
            <?php endif; ?>
          </td>
          <td><?= $a['status'] === 'active' ? '<span class="badge text-bg-success">ACTIVE</span>' : '<span class="badge text-bg-secondary">SUSPENDED</span>' ?></td>
          <td class="small"><?= e($a['last_login_at'] ? substr((string) $a['last_login_at'], 0, 16) . ' UTC' : 'never') ?></td>
          <td>
            <?php if (!$self): ?>
              <div class="d-flex flex-wrap gap-1">
                <form method="post" action="/admin/users/status"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="status" value="<?= $a['status'] === 'active' ? 'suspended' : 'active' ?>">
                  <button class="btn btn-sm <?= $a['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit"><?= $a['status'] === 'active' ? 'Suspend' : 'Reactivate' ?></button></form>
                <form method="post" action="/admin/users/password"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary" type="submit">Reset password</button></form>
              </div>
            <?php else: ?><span class="text-muted small">Use "Change password" in the top bar</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>

  <form method="post" action="/admin/users" novalidate class="card card-body mt-4">
    <?= csrf_field() ?>
    <h2 class="h5">Add an administrator</h2>
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" maxlength="100" required></div>
      <div class="col-md-4"><label class="form-label" for="email">Email address</label><input class="form-control" id="email" name="email" type="email" maxlength="254" required></div>
      <div class="col-md-4"><label class="form-label" for="role">Role</label>
        <select class="form-select" id="role" name="role"><?php foreach ($roles as $r): ?><option value="<?= e($r) ?>"><?= e(str_replace('_', ' ', $r)) ?></option><?php endforeach; ?></select></div>
    </div>
    <p class="small text-muted mt-2 mb-0"><strong>Super admin:</strong> everything. <strong>Application reviewer:</strong> views and reviews applications, sees eligibility. <strong>Report viewer:</strong> aggregate reports only, no personal data.
      A temporary password is generated and shown once.</p>
    <div class="mt-3"><button class="btn btn-primary" type="submit">Create account</button></div>
  </form>
</div>
