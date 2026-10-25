<?php /** @var array<string,string> $errors @var array<string,string> $old */
$err = static fn(string $k): string => $errors[$k] ?? '';
$cls = static fn(string $k): string => isset($errors[$k]) ? ' is-invalid' : '';
?>
<div class="container py-5">
  <div class="row justify-content-center"><div class="col-md-8 col-lg-6">
    <h1 class="h3 mb-1">Create your account</h1>
    <p class="text-muted">Step 1 of registration. We will email you a link to verify your address.</p>
    <form method="post" action="/register" novalidate class="card card-body shadow-sm" id="register-form">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="full_name">Full name <span class="text-danger">*</span></label>
        <input class="form-control<?= $cls('full_name') ?>" id="full_name" name="full_name" value="<?= e($old['full_name'] ?? '') ?>" autocomplete="name" maxlength="200" required>
        <div class="invalid-feedback"><?= e($err('full_name')) ?></div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="email">Email address <span class="text-danger">*</span></label>
        <input class="form-control<?= $cls('email') ?>" id="email" name="email" type="email" value="<?= e($old['email'] ?? '') ?>" autocomplete="email" maxlength="254" required>
        <div class="invalid-feedback"><?= e($err('email')) ?></div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="password">Password <span class="text-danger">*</span></label>
        <div class="input-group has-validation">
          <input class="form-control<?= $cls('password') ?>" id="password" name="password" type="password" autocomplete="new-password" maxlength="72" required aria-describedby="pwHelp">
          <button class="btn btn-outline-secondary" type="button" data-toggle-password="password">Show</button>
          <div class="invalid-feedback"><?= e($err('password')) ?></div>
        </div>
        <div id="pwHelp" class="form-text">At least 10 characters, with a letter and a number.</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="password_confirm">Confirm password <span class="text-danger">*</span></label>
        <input class="form-control<?= $cls('password_confirm') ?>" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" maxlength="72" required>
        <div class="invalid-feedback"><?= e($err('password_confirm')) ?></div>
      </div>
      <div class="form-check mb-3">
        <input class="form-check-input<?= $cls('accept_terms') ?>" type="checkbox" value="1" id="accept_terms" name="accept_terms"<?= ($old['accept_terms'] ?? '') === '1' ? ' checked' : '' ?>>
        <label class="form-check-label" for="accept_terms">I accept the <a href="/terms" target="_blank" rel="noopener">Terms and Conditions</a> and <a href="/privacy" target="_blank" rel="noopener">Privacy Policy</a>.</label>
        <div class="invalid-feedback"><?= e($err('accept_terms')) ?></div>
      </div>
      <button class="btn btn-primary" type="submit">Create account</button>
      <p class="small text-muted mt-3 mb-0">Already registered? <a href="/login">Log in</a></p>
    </form>
  </div></div>
</div>
