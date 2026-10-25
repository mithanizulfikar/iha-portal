<?php /** @var string $title @var string $content @var ?array $user @var array $flashes */ ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> | IHA Portal</title>
  <link rel="icon" href="/assets/img/logo.svg" type="image/svg+xml">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="d-flex flex-column min-vh-100">
<nav class="navbar navbar-expand-md navbar-dark iha-nav">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="/">
      <img src="/assets/img/logo.svg" alt="" width="36" height="36">
      <span>IHA Portal</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav ms-auto align-items-md-center gap-md-2">
        <li class="nav-item"><a class="nav-link" href="/#criteria">Eligibility Criteria</a></li>
        <li class="nav-item"><a class="nav-link" href="/#instructions">Instructions</a></li>
        <li class="nav-item"><a class="nav-link" href="/#contact">Contact</a></li>
        <?php if ($user): ?>
          <?php if (in_array($user['role'], ['super_admin', 'application_reviewer'], true)): ?>
            <li class="nav-item"><a class="nav-link" href="/admin/applications">Applications</a></li>
          <?php endif; ?>
          <?php if ($user['role'] !== 'student'): ?>
            <li class="nav-item"><a class="nav-link" href="/admin/reports">Reports</a></li>
          <?php endif; ?>
          <?php if ($user['role'] === 'super_admin'): ?>
            <li class="nav-item"><a class="nav-link" href="/admin/users">Admins</a></li>
            <li class="nav-item"><a class="nav-link" href="/admin/audit">Audit</a></li>
            <li class="nav-item"><a class="nav-link" href="/admin/rules">Rules</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link" href="<?= $user['role'] === 'student' ? '/student' : '/admin' ?>"><?= e($user['full_name']) ?></a></li>
          <li class="nav-item"><a class="nav-link" href="/account/password">Change password</a></li>
          <li class="nav-item">
            <form method="post" action="/logout" class="d-inline"><?= csrf_field() ?>
              <button class="btn btn-outline-light btn-sm" type="submit">Log out</button>
            </form>
          </li>
        <?php else: ?>
          <li class="nav-item"><a class="btn btn-outline-light btn-sm" href="/login">Student Login</a></li>
          <li class="nav-item"><a class="btn btn-warning btn-sm" href="/register">Register</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<main class="flex-grow-1">
  <?php if ($flashes): ?>
    <div class="container mt-3">
      <?php foreach ($flashes as $f): ?>
        <div class="alert alert-<?= e(['success' => 'success', 'error' => 'danger', 'info' => 'info'][$f['type']] ?? 'info') ?>" role="alert"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?= $content ?>
</main>

<footer class="iha-footer py-4 mt-5">
  <div class="container d-flex flex-column flex-md-row justify-content-between gap-2 small">
    <div>&copy; <?= date('Y') ?> IHA. All rights reserved.</div>
    <div class="d-flex gap-3">
      <a href="/privacy">Privacy Policy</a>
      <a href="/terms">Terms and Conditions</a>
      <a href="/admin/login">Staff login</a>
    </div>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/app.js"></script>
</body>
</html>
