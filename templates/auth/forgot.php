<div class="container py-5">
  <div class="row justify-content-center"><div class="col-md-8 col-lg-5">
    <h1 class="h3 mb-1">Forgot your password?</h1>
    <p class="text-muted">Enter your student account email. If it is registered, we will email you a link to choose a new password.</p>
    <form method="post" action="/forgot-password" class="card card-body shadow-sm">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="email">Email address</label>
        <input class="form-control" id="email" name="email" type="email" autocomplete="email" maxlength="254" required>
      </div>
      <button class="btn btn-primary" type="submit">Send reset link</button>
      <p class="small text-muted mt-3 mb-0"><a href="/login">Back to login</a></p>
    </form>
  </div></div>
</div>
