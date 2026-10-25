<?php /** @var string $token */ ?>
<div class="container py-5">
  <div class="row justify-content-center"><div class="col-md-8 col-lg-6">
    <div class="card card-body shadow-sm text-center">
      <h1 class="h4">Confirm your email address</h1>
      <p>Press the button to finish verifying your account.</p>
      <form method="post" action="/verify-email">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <button class="btn btn-primary" type="submit">Verify my email</button>
      </form>
    </div>
  </div></div>
</div>
