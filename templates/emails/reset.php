<?php /** @var string $name @var string $link @var int $minutes */ ?>
<p>Hello <?= e($name) ?>,</p>
<p>Use the link below to choose a new password for your IHA Portal account.</p>
<p><a href="<?= e($link) ?>">Reset my password</a></p>
<p>It expires in <?= e($minutes) ?> minutes and works once. If you did not ask for this, ignore this email; your password has not changed.</p>
