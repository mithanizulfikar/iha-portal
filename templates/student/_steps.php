<?php /** @var int $current @var array<int,bool> $steps */ ?>
<nav aria-label="Registration progress" class="mb-4">
  <ol class="steps list-unstyled d-flex flex-wrap gap-2 mb-0">
    <?php foreach (\Iha\Student\Completeness::SECTIONS as $n => [$label, $url]): ?>
      <li class="flex-fill">
        <a class="step d-flex align-items-center gap-2 text-decoration-none p-2 rounded border<?= $n === $current ? ' step-current' : '' ?><?= !empty($steps[$n]) ? ' step-done' : '' ?>"
           href="<?= e($url) ?>"<?= $n === $current ? ' aria-current="step"' : '' ?>>
          <span class="step-num"><?= !empty($steps[$n]) ? '&#10003;' : (int) $n ?></span>
          <span class="step-label"><?= e($label) ?></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ol>
</nav>
