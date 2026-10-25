<?php /** @var string $name @var string $link @var int $hours */ ?>
<p>Hello <?= e($name) ?>,</p>
<p>Please verify your email address to activate your IHA Portal account.</p>
<p><a href="<?= e($link) ?>">Verify my email</a></p>
<p>This link expires in <?= e($hours) ?> hours and can be used once. If you did not create an account, you can ignore this email.</p>
