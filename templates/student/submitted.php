<?php /** @var string $number @var string $status */ ?>
<div class="container py-5">
  <div class="row justify-content-center"><div class="col-md-8 col-lg-6">
    <div class="card card-body shadow-sm text-center">
      <h1 class="h3">Application submitted</h1>
      <p>Thank you. Your application has been received.</p>
      <p class="mb-1"><span class="text-muted">Application number</span><br><strong class="h4"><?= e($number) ?></strong></p>
      <p><span class="text-muted">Status</span><br><?= badge(str_replace(' ', '_', $status), 'app') ?></p>
      <p class="small text-muted">Keep your application number for reference. You will see any updates in your dashboard notifications.</p>
      <a class="btn btn-primary" href="/student">Go to dashboard</a>
    </div>
  </div></div>
</div>
