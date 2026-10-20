<?php
require_once __DIR__ . '/_parts.php';
$s = SECTIONS[$section];
$empty = $section === 'barvy' ? !$colors : !$items;
?>
<div class="stack" style="gap: 8px">
  <div class="breadcrumb"><a href="<?= e(brand_url($brand)) ?>">Přehled</a> / <?= e($s['title']) ?></div>
  <div class="row-between">
    <h1><?= e($s['title']) ?></h1>
    <?php if (!$empty): ?>
      <a class="btn btn-secondary" href="<?= e(brand_url($brand, '/zip/' . $section)) ?>"><?= icon('download', 18) ?>Stáhnout sekci (ZIP)</a>
    <?php endif; ?>
  </div>
  <p class="muted" style="max-width: 640px"><?= e($s['lead']) ?><?= $section === 'tisk' ? ' Tiskové PDF je připravené rovnou pro tiskárnu.' : '' ?></p>
</div>

<?php if ($section === 'tisk' && !$empty): ?>
  <div class="alert alert-success" role="note"><?= icon('check') ?><div><b style="color: var(--success)">Tip:</b> Tiskárně můžete poslat přímo odkaz na tuto stránku. Vždy si stáhne nejnovější soubor.</div></div>
<?php endif; ?>

<?php if ($empty): ?>
  <div class="card empty-state">V této sekci zatím nic není.</div>
<?php elseif ($section === 'barvy'): ?>
  <div class="grid-colors">
    <?php foreach ($colors as $c): ?><?= client_color_card($c) ?><?php endforeach; ?>
  </div>
<?php elseif ($section === 'logo'): ?>
  <div class="grid-cards">
    <?php foreach ($items as $it): ?><?= client_asset_card($brand, $it) ?><?php endforeach; ?>
  </div>
<?php elseif ($section === 'typografie'): ?>
  <div class="grid-fonts">
    <?php foreach ($items as $it): ?><?= client_font_card($brand, $it) ?><?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="stack">
    <?php foreach ($items as $it): ?><?= client_doc_row($brand, $it) ?><?php endforeach; ?>
  </div>
<?php endif; ?>
