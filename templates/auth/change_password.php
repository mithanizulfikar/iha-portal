<?php /** @var array<string,string> $errors */ ?>
<div class="container py-5">
  <div class="row justify-content-center"><div class="col-md-8 col-lg-5">
    <h1 class="h3 mb-3">Change password</h1>
    <form method="post" action="/account/password" novalidate class="card card-body shadow-sm">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="current_password">Current password</label>
        <input class="form-control<?= field_class($errors, 'current_password') ?>" id="current_password" name="current_password" type="password" autocomplete="current-password" maxlength="1024" required>
        <?= field_error($errors, 'current_password') ?>
      </div>
      <div class="mb-3">
        <label class="form-label" for="password">New password</label>
        <div class="input-group has-validation">
          <input class="form-control<?= field_class($errors, 'password') ?>" id="password" name="password" type="password" autocomplete="new-password" maxlength="72" required>
          <button class="btn btn-outline-secondary" type="button" data-toggle-password="password">Show</button>
          <?= field_error($errors, 'password') ?>
        </div>
        <div class="form-text">At least 10 characters, with a letter and a number.</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="password_confirm">Confirm new password</label>
        <input class="form-control<?= field_class($errors, 'password_confirm') ?>" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" maxlength="72" required>
        <?= field_error($errors, 'password_confirm') ?>
      </div>
      <button class="btn btn-primary" type="submit">Change password</button>
      <p class="small text-muted mt-3 mb-0">Changing your password signs you out on all other devices.</p>
    </form>
  </div></div>
</div>
