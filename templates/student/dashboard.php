<?php /** @var bool $verified @var ?string $studentId @var string $email @var string $name @var ?array $application @var array $notifications @var ?array $qr */ ?>
<div class="container py-4">
  <h1 class="h3 mb-3">Student Portal</h1>
  <?php if (!$verified): ?>
    <div class="alert alert-warning" role="alert">
      <strong>Please verify your email address.</strong> You can open the registration form after you confirm it using the link we emailed to <?= e($email) ?>.
    </div>
    <form method="post" action="/resend-verification" class="mb-4">
      <?= csrf_field() ?>
      <button class="btn btn-outline-primary" type="submit">Send a new verification link</button>
    </form>
  <?php endif; ?>
  <div class="row g-3">
    <div class="col-md-4"><div class="card h-100"><div class="card-body">
      <h2 class="h6 text-muted">Student</h2>
      <p class="h5 mb-1"><?= e($name) ?></p>
      <p class="mb-0 text-muted"><?= e($email) ?></p>
    </div></div></div>
    <div class="col-md-3"><div class="card h-100"><div class="card-body">
      <h2 class="h6 text-muted">Student ID</h2>
      <p class="h5 mb-0"><?= $studentId ? e($studentId) : 'Issued after email verification' ?></p>
    </div></div></div>
    <div class="col-md-2"><div class="card h-100"><div class="card-body">
      <h2 class="h6 text-muted">Email</h2>
      <p class="h5 mb-0"><?= $verified ? 'Verified' : 'Not verified' ?></p>
    </div></div></div>
    <?php if ($application): ?>
      <div class="col-md-3"><div class="card h-100"><div class="card-body">
        <h2 class="h6 text-muted">Application</h2>
        <p class="h5 mb-1"><?= e($application['number']) ?></p>
        <span class="badge text-bg-secondary"><?= e($application['status']) ?></span>
      </div></div></div>
    <?php endif; ?>
  </div>

  <?php if ($application): ?>
    <div class="card mt-4"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h5 mb-0">Registration progress</h2>
        <span class="fw-semibold"><?= (int) $application['completion'] ?>% complete</span>
      </div>
      <div class="progress mb-3" role="progressbar" aria-label="Registration completion" aria-valuenow="<?= (int) $application['completion'] ?>" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-bar progress-w-<?= (int) $application['completion'] ?>"></div>
      </div>
      <ul class="list-group mb-3">
        <?php foreach (\Iha\Student\Completeness::SECTIONS as $n => [$label, $url]): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <a href="<?= e($url) ?>"><?= (int) $n ?>. <?= e($label) ?></a>
            <?php if (!empty($application['steps'][$n])): ?><span class="badge text-bg-success">Complete</span><?php else: ?><span class="badge text-bg-light border">Incomplete</span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($application['editable']): ?>
        <a class="btn btn-primary" href="/student/application">Continue registration</a>
        <a class="btn btn-outline-primary" href="/student/review">Review and submit</a>
      <?php else: ?>
        <a class="btn btn-outline-primary" href="/student/review">View application</a>
      <?php endif; ?>
      <a class="btn btn-outline-secondary" href="/student/academic-report">Academic report</a>
    </div></div>
    <div class="card mt-4"><div class="card-body">
      <h2 class="h5">Verification QR code</h2>
      <?php if ($qr): ?>
        <?php if ($qr['uri']): ?>
          <img class="qr-img mb-2" src="<?= e($qr['uri']) ?>" alt="Verification QR code" width="200" height="200">
          <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-primary btn-sm" href="/student/qr/download">Download (SVG)</a>
            <a class="btn btn-outline-primary btn-sm" href="/student/id-card">Student ID card (print)</a>
          </div>
        <?php else: ?>
          <p class="text-muted mb-0">Your QR code cannot be displayed right now. Please try again later.</p>
        <?php endif; ?>
      <?php else: ?>
        <p class="text-muted mb-0">No verification QR Code is currently available for this account.</p>
      <?php endif; ?>
    </div></div>
    <div class="card mt-4"><div class="card-body">
      <h2 class="h5">Notifications</h2>
      <?php if ($notifications): ?>
        <ul class="list-unstyled mb-0">
          <?php foreach ($notifications as $n): ?>
            <li class="mb-2"><strong><?= e($n['title']) ?></strong><br><span><?= e($n['body']) ?></span> <span class="text-muted small">(<?= e(substr((string) $n['created_at'], 0, 16)) ?> UTC)</span></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="text-muted mb-0">No notifications yet.</p>
      <?php endif; ?>
    </div></div>
  <?php endif; ?>
</div>
