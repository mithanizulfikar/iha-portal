<?php /** @var bool $isAdmin @var ?string $error @var string $email */ ?>
<div class="container py-5">
  <div class="row justify-content-center"><div class="col-md-8 col-lg-5">
    <h1 class="h3 mb-3"><?= $isAdmin ? 'Administrator login' : 'Student login' ?></h1>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= $isAdmin ? '/admin/login' : '/login' ?>" class="card card-body shadow-sm">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="email">Email address</label>
        <input class="form-control" id="email" name="email" type="email" value="<?= e($email) ?>" autocomplete="username" maxlength="254" required>
      </div>
      <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <div class="input-group">
          <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" maxlength="1024" required>
          <button class="btn btn-outline-secondary" type="button" data-toggle-password="password">Show</button>
        </div>
      </div>
      <button class="btn btn-primary" type="submit">Log in</button>
      <?php if (!$isAdmin): ?><p class="small text-muted mt-3 mb-0"><a href="/forgot-password">Forgot your password?</a> &middot; New here? <a href="/register">Create an account</a></p><?php endif; ?>
    </form>
  </div></div>
</div>
