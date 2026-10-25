<?php /** @var string $email */ ?>
<div class="container py-5">
  <div class="row justify-content-center"><div class="col-md-8 col-lg-6">
    <div class="card card-body shadow-sm">
      <h1 class="h4">Check your email</h1>
      <p>If this address can be registered, we have sent a verification link to<?= $email !== '' ? ' <strong>' . e($email) . '</strong>' : ' your email address' ?>. The link works once and expires in 24 hours.</p>
      <p class="text-muted small">Nothing arrived? Check your spam folder, then request a new link below. A new link replaces the previous one.</p>
      <form method="post" action="/resend-verification" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <div class="col-sm-8">
          <label class="form-label" for="email">Email address</label>
          <input class="form-control" id="email" name="email" type="email" value="<?= e($email) ?>" autocomplete="email" required>
        </div>
        <div class="col-sm-4 d-grid"><button class="btn btn-outline-primary" type="submit">Resend link</button></div>
      </form>
    </div>
  </div></div>
</div>
