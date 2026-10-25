<?php
/** @var string $prefix @var array $loc @var array $geo @var array $errors @var string $legend */
$id = static fn(string $f): string => e($prefix . $f);
?>
<fieldset class="mb-3">
  <legend class="h6"><?= e($legend) ?> <span class="text-danger">*</span></legend>
  <div class="row g-3" data-geo-group>
    <div class="col-md-4">
      <label class="form-label" for="<?= $id('province_id') ?>">Province / Territory</label>
      <select class="form-select<?= field_class($errors, $prefix . 'province_id') ?>" id="<?= $id('province_id') ?>" name="<?= $id('province_id') ?>" data-geo="province">
        <option value="">Select province / territory</option>
        <?php foreach ($geo['provinces'] as $p): ?>
          <option value="<?= (int) $p['id'] ?>"<?= (string) $p['id'] === $loc['province_id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?= field_error($errors, $prefix . 'province_id') ?>
    </div>
    <div class="col-md-4">
      <label class="form-label" for="<?= $id('district_id') ?>">District</label>
      <select class="form-select<?= field_class($errors, $prefix . 'district_id') ?>" id="<?= $id('district_id') ?>" name="<?= $id('district_id') ?>" data-geo="district"<?= $loc['province_id'] === '' ? ' disabled' : '' ?>>
        <option value=""><?= ($loc['province_id'] !== '' && !$geo['districts']) ? 'No districts available yet' : 'Select district' ?></option>
        <?php foreach ($geo['districts'] as $d): ?>
          <option value="<?= (int) $d['id'] ?>"<?= (string) $d['id'] === $loc['district_id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?= field_error($errors, $prefix . 'district_id') ?>
    </div>
    <div class="col-md-4">
      <label class="form-label" for="<?= $id('city_id') ?>">City / Locality</label>
      <select class="form-select<?= field_class($errors, $prefix . 'city_id') ?>" id="<?= $id('city_id') ?>" name="<?= $id('city_id') ?>" data-geo="city"<?= $loc['district_id'] === '' ? ' disabled' : '' ?>>
        <option value="">Select city / locality</option>
        <?php if ($loc['district_id'] !== ''): ?>
          <?php foreach ($geo['cities'] as $c): ?>
            <option value="<?= (int) $c['id'] ?>"<?= (string) $c['id'] === $loc['city_id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
          <option value="other"<?= $loc['city_id'] === 'other' ? ' selected' : '' ?>>Other</option>
        <?php endif; ?>
      </select>
      <?= field_error($errors, $prefix . 'city_id') ?>
    </div>
    <div class="col-12<?= $loc['city_id'] === 'other' ? '' : ' d-none' ?>" data-geo="other-wrap">
      <label class="form-label" for="<?= $id('city_other') ?>">Enter City/Locality Name</label>
      <input class="form-control<?= field_class($errors, $prefix . 'city_other') ?>" id="<?= $id('city_other') ?>" name="<?= $id('city_other') ?>" value="<?= e($loc['city_other']) ?>" maxlength="100" data-geo="other"<?= $loc['city_id'] === 'other' ? ' required' : '' ?>>
      <?= field_error($errors, $prefix . 'city_other') ?>
    </div>
  </div>
</fieldset>
