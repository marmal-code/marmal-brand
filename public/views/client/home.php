<?php
require_once __DIR__ . '/_parts.php';
$primary = null;
foreach ($bySection['logo'] ?? [] as $it) {
    if ($it['slug'] === 'logo-primarni' && $it['preview']) {
        $primary = $it;
        break;
    }
}
if (!$primary) {
    foreach ($bySection['logo'] ?? [] as $it) {
        if ($it['preview']) { $primary = $it; break; }
    }
}
$dark = is_dark($brand['hero_bg']);
$tiles = ['tisk', 'digital', 'kancelar', 'pravidla'];
$hasAny = $bySection || $colors;
?>
<section class="hero<?= $dark ? ' dark' : '' ?>" style="background: <?= e($brand['hero_bg']) ?>">
  <div class="hero-logo">
    <?php if ($primary): ?>
      <img src="<?= e(client_file_url($brand, $primary['preview'], true)) ?>" alt="Logo <?= e($brand['name']) ?>">
    <?php else: ?>
      <span style="font-family: var(--font-display); font-weight: 700; font-size: 48px"><?= e($brand['name']) ?></span>
    <?php endif; ?>
  </div>
  <div class="hero-text">
    <h1>Vše k vaší značce na jednom místě</h1>
    <p><?= nl2br(e($brand['intro'] ?: 'Soubory jsou tu vždy v aktuální verzi. Když něco upravím, starý soubor se automaticky nahradí. Nic dalšího hlídat nemusíte.')) ?></p>
  </div>
</section>

<?php if (!$hasAny): ?>
  <div class="card empty-state">Brand manuál se právě připravuje. Soubory se tu objeví, jakmile budou hotové.</div>
<?php endif; ?>

<?php if (!empty($bySection['logo'])): ?>
  <section class="stack-lg" id="logo">
    <div class="row-between">
      <h2>Logo</h2>
      <span class="row" style="gap: 16px">
        <?php if (isset($texts['logo'])): ?><a class="small" style="font-weight: 500" href="<?= e(brand_url($brand, '/s/logo')) ?>">Pravidla pro logo</a><?php endif; ?>
        <a class="small" style="font-weight: 500" href="<?= e(brand_url($brand, '/zip/logo')) ?>">Stáhnout všechna loga (ZIP)</a>
      </span>
    </div>
    <div class="grid-cards">
      <?php foreach ($bySection['logo'] as $it): ?><?= client_asset_card($brand, $it) ?><?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($colors): ?>
  <section class="stack-lg" id="barvy">
    <div class="row-between">
      <h2>Barvy</h2>
      <span class="row" style="gap: 16px">
        <a class="small" style="font-weight: 500" href="<?= e(brand_url($brand, '/s/barvy')) ?>">Odstíny a pravidla</a>
        <a class="small" style="font-weight: 500" href="<?= e(brand_url($brand, '/zip/barvy')) ?>">Stáhnout hodnoty (TXT, CSS)</a>
      </span>
    </div>
    <div class="grid-colors">
      <?php foreach ($colors as $c): ?><?= client_color_card($c) ?><?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php if (!empty($bySection['typografie'])): ?>
  <section class="stack-lg" id="typografie">
    <div class="row-between">
      <h2>Typografie</h2>
      <?php if (isset($texts['typografie'])): ?><a class="small" style="font-weight: 500" href="<?= e(brand_url($brand, '/s/typografie')) ?>">Typografická škála a zásady</a><?php endif; ?>
    </div>
    <div class="grid-fonts">
      <?php foreach ($bySection['typografie'] as $it): ?><?= client_font_card($brand, $it) ?><?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php
$tileItems = array_filter($tiles, fn ($k) => !empty($bySection[$k]) || isset($texts[$k]));
if ($tileItems): ?>
  <section class="stack-lg">
    <h2>Podklady podle použití</h2>
    <div class="grid-cards">
      <?php foreach ($tileItems as $k):
          $names = array_map(fn ($i) => $i['title'], array_slice($bySection[$k] ?? [], 0, 5));
          $n = count($bySection[$k] ?? []);
      ?>
        <a class="tile" href="<?= e(brand_url($brand, '/s/' . $k)) ?>">
          <?= icon(SECTIONS[$k]['icon'], 28) ?>
          <span class="tile-title"><?= e(SECTIONS[$k]['title']) ?></span>
          <span class="small muted"><?php if ($n): ?><?= e(implode(', ', $names)) ?><?= $n > 5 ? '…' : '' ?> · <?= $n ?> <?= $n === 1 ? 'položka' : ($n < 5 ? 'položky' : 'položek') ?><?php else: ?><?= e(SECTIONS[$k]['lead']) ?><?php endif; ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>
