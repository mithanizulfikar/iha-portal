<?php /** @var int $current @var string $heading @var string $studentId @var string $applicationNumber */ ?>
<h1 class="h3 mb-1">Section <?= (int) $current ?>: <?= e($heading) ?></h1>
<p class="small text-muted mb-3">Student ID <strong><?= e($studentId) ?></strong> &middot; Application No. <strong><?= e($applicationNumber) ?></strong></p>
<p class="small text-muted">Fields marked <span class="text-danger">*</span> are needed before you can submit. You can save a draft at any time and come back later.</p>
