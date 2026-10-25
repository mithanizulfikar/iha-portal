<?php /** @var ?string $backUrl */ ?>
<div class="d-flex flex-wrap gap-2 mt-4">
  <?php if (!empty($backUrl)): ?><a class="btn btn-outline-secondary" href="<?= e($backUrl) ?>">Back</a><?php endif; ?>
  <button class="btn btn-outline-primary ms-auto" type="submit" name="action" value="save">Save Draft</button>
  <button class="btn btn-primary" type="submit" name="action" value="next">Save &amp; Continue</button>
</div>
