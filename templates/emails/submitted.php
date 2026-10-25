<?php /** @var string $name @var string $number @var string $portalUrl */ ?>
<p>Hello <?= e($name) ?>,</p>
<p>We have received your application.</p>
<p><strong>Application number:</strong> <?= e($number) ?><br><strong>Status:</strong> SUBMITTED</p>
<p>You can follow its status in the <a href="<?= e($portalUrl) ?>">Student Portal</a>.</p>
