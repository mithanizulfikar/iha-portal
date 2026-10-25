<?php /** @var string $name @var string $loginUrl */ ?>
<p>Hello <?= e($name) ?>,</p>
<p>Someone (hopefully you) tried to register for the IHA Portal with this email address, but an account already exists.</p>
<p><a href="<?= e($loginUrl) ?>">Log in to your account</a></p>
<p>If this wasn't you, you can ignore this email.</p>
