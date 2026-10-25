<?php /** @var string $name @var string $studentId @var ?string $qrUri */ ?>
<div class="container py-4">
  <div class="no-print mb-3 d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="/student">Back</a>
    <button class="btn btn-primary btn-sm" type="button" data-print>Print</button>
  </div>
  <div class="id-card mx-auto">
    <div class="id-card-head d-flex align-items-center gap-2"><img src="/assets/img/logo.svg" alt="" width="36" height="36"><strong>IHA Student ID</strong></div>
    <div class="id-card-body text-center">
      <p class="h5 mb-1"><?= e($name) ?></p>
      <p class="mb-2"><?= e($studentId) ?></p>
      <?php if ($qrUri): ?><img class="qr-img" src="<?= e($qrUri) ?>" alt="Verification QR code" width="180" height="180"><?php else: ?><p class="text-muted">QR code unavailable right now.</p><?php endif; ?>
      <p class="small text-muted mt-2 mb-0">Scan to verify</p>
    </div>
  </div>
</div>
