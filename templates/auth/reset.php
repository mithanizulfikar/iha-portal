<?php /** @var string $token @var array<string,string> $errors */ ?>
<div class="container py-5">
  <div class="row justify-content-center"><div class="col-md-8 col-lg-5">
    <h1 class="h3 mb-3">Choose a new password</h1>
    <form method="post" action="/reset-password" novalidate class="card card-body shadow-sm">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
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
    </form>
  </div></div>
</div>
