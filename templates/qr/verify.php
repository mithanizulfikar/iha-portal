<?php /** @var array $result */ ?>
<div class="container py-5">
  <div class="row justify-content-center"><div class="col-md-8 col-lg-6">
    <div class="card card-body shadow-sm text-center">
      <?php if ($result['valid']): ?>
        <div class="verify-mark text-success">&#10003;</div>
        <h1 class="h2 text-success">VALID</h1>
        <p class="mb-3">This verification code is valid.</p>
        <dl class="row text-start mb-0">
          <dt class="col-5 text-muted fw-normal">Student ID</dt><dd class="col-7"><?= e($result['student_id']) ?></dd>
          <?php if (isset($result['name'])): ?><dt class="col-5 text-muted fw-normal">Name</dt><dd class="col-7"><?= e($result['name']) ?></dd><?php endif; ?>
          <?php if (isset($result['school'])): ?><dt class="col-5 text-muted fw-normal">School / College</dt><dd class="col-7"><?= e($result['school']) ?></dd><?php endif; ?>
        </dl>
      <?php else: ?>
        <div class="verify-mark text-danger">&#10007;</div>
        <h1 class="h2 text-danger">INVALID</h1>
        <p class="mb-0">This verification code is not valid.</p>
      <?php endif; ?>
      <hr>
      <p class="small text-muted mb-0">Checked <?= e($result['checked_at']) ?></p>
    </div>
  </div></div>
</div>
