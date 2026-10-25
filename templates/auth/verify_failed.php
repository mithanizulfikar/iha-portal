<div class="container py-5">
  <div class="row justify-content-center"><div class="col-md-8 col-lg-6">
    <div class="alert alert-danger" role="alert">This verification link is invalid, already used, or has expired.</div>
    <div class="card card-body shadow-sm">
      <h1 class="h5">Get a new link</h1>
      <p class="small text-muted">Enter the email address you registered with.</p>
      <form method="post" action="/resend-verification" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <div class="col-sm-8">
          <label class="form-label" for="email">Email address</label>
          <input class="form-control" id="email" name="email" type="email" autocomplete="email" required>
        </div>
        <div class="col-sm-4 d-grid"><button class="btn btn-primary" type="submit">Send new link</button></div>
      </form>
    </div>
  </div></div>
</div>
