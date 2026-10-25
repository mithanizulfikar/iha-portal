<?php /** @var ?array $rule @var string $supportEmail @var string $supportPhone */ ?>
<section class="hero text-white">
  <div class="container py-5 text-center">
    <img src="/assets/img/logo.svg" alt="IHA logo" width="88" height="88" class="mb-3">
    <h1 class="display-5 fw-bold">IHA Student Registration &amp; Eligibility Portal</h1>
    <p class="lead mx-auto hero-lead">Welcome. Create your account, verify your email and complete your application online, step by step.</p>
    <div class="d-flex flex-column flex-sm-row gap-3 justify-content-center mt-4">
      <a class="btn btn-warning btn-lg" href="/register">Online Registration Form</a>
      <a class="btn btn-outline-light btn-lg" href="/login">Student Login</a>
    </div>
  </div>
</section>

<section id="criteria" class="container py-5">
  <h2 class="h3 mb-3">Eligibility Criteria</h2>
  <?php if ($rule): ?>
    <p>Applicants must meet <strong>both</strong> of the following published requirements:</p>
    <div class="row g-3">
      <div class="col-md-6"><div class="card h-100"><div class="card-body">
        <h3 class="h5">Date of birth</h3>
        <p class="mb-0">Born between <strong><?= e($rule['dob_min']) ?></strong> and <strong><?= e($rule['dob_max']) ?></strong>. Both dates are included.</p>
      </div></div></div>
      <div class="col-md-6"><div class="card h-100"><div class="card-body">
        <h3 class="h5">Academic result</h3>
        <p class="mb-0">An overall academic percentage of at least <strong><?= e($rule['min_percentage']) ?>%</strong> in the most recent examination.</p>
      </div></div></div>
    </div>
  <?php else: ?>
    <p>The eligibility criteria will be published here by the administration.</p>
  <?php endif; ?>
  <p class="text-muted mt-3 mb-0">Meeting the criteria does not by itself guarantee admission; every application is reviewed. Individual outcomes are handled by the administration, and your portal shows your application status.</p>
</section>

<section id="instructions" class="bg-light py-5">
  <div class="container">
    <h2 class="h3 mb-3">Registration Instructions</h2>
    <ol class="instructions">
      <li><strong>Create an account</strong> with your full name, a working email address and a password.</li>
      <li><strong>Verify your email</strong> using the link we send you. The link works once and expires after 24 hours.</li>
      <li><strong>Log in</strong> to the Student Portal and receive your permanent Student ID.</li>
      <li><strong>Complete the five sections:</strong> Student Profile, Academic Portal, Academic Reports and Marks, Parent/Guardian, and Travel History. You can save a draft and return later.</li>
      <li><strong>Review and submit</strong> your application. Keep your Application Number for reference.</li>
    </ol>
    <p class="mb-0">Keep your documents handy: CNIC or B-Form number, school or college details and your latest examination marks.</p>
  </div>
</section>

<section id="contact" class="container py-5">
  <h2 class="h3 mb-3">Contact and Support</h2>
  <?php if ($supportEmail !== '' || $supportPhone !== ''): ?>
    <ul class="list-unstyled mb-0">
      <?php if ($supportEmail !== ''): ?><li>Email: <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a></li><?php endif; ?>
      <?php if ($supportPhone !== ''): ?><li>Phone: <?= e($supportPhone) ?></li><?php endif; ?>
    </ul>
  <?php else: ?>
    <p class="mb-0">Support contact details will be published here by the administration.</p>
  <?php endif; ?>
</section>
