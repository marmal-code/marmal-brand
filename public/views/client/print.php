<?php
require_once __DIR__ . '/_parts.php';
$primary = null;
foreach ($bySection['logo'] ?? [] as $it) {
    if ($it['preview'] && ($primary === null || $it['slug'] === 'logo-primarni')) {
        $primary = $it;
    }
}
$dark = is_dark($brand['hero_bg']);
$order = array_keys(SECTIONS);
$present = array_values(array_filter($order, fn ($k) => $k === 'barvy' ? ($colors || isset($texts['barvy'])) : (!empty($bySection[$k]) || isset($texts[$k]))));
$num = 0;
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($brand['name']) ?> – brand manuál</title>
<?php if (empty($typefaces)): ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lexend:wght@200;300;500&display=swap">
<?php else: foreach ($typefaces['preload'] as [$href, $type]): ?>
<link rel="preload" href="<?= e($href) ?>" as="font" type="<?= e($type) ?>" crossorigin>
<?php endforeach; endif; ?>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= e(APP_VERSION) ?>">
<link rel="stylesheet" href="/assets/css/print.css?v=<?= e(APP_VERSION) ?>">
<?php if (!empty($typefaces)): ?><style><?= $typefaces['css'] ?></style><?php endif; ?>
<style><?php foreach ($bySection['typografie'] ?? [] as $it): ?><?= font_faces_css(font_faces($it), 'bf' . (int) $it['id'], fn ($f) => client_file_url($brand, $f, true)) ?><?php endforeach; ?></style>
</head>
<body class="print-body">

<div class="print-toolbar no-print">
  <div>
    <b>Tisková verze brand manuálu</b>
    <div class="small muted">V dialogu tisku zvolte <b>Uložit jako PDF</b>, formát A4. Zapněte „Grafika na pozadí“, aby se vytiskly barevné plochy.</div>
  </div>
  <div class="row">
    <a class="btn btn-ghost" href="<?= e(brand_url($brand)) ?>">Zpět</a>
    <button type="button" class="btn btn-primary" onclick="window.print()"><?= icon('download', 18) ?>Uložit jako PDF</button>
  </div>
</div>

<!-- Titulní strana -->
<section class="sheet cover" style="background: <?= e($brand['hero_bg']) ?>; color: <?= $dark ? '#fff' : 'var(--ink)' ?>">
  <div class="cover-logo">
    <?php if ($primary): ?>
      <img src="<?= e(client_file_url($brand, $primary['preview'], true)) ?>" alt="Logo <?= e($brand['name']) ?>">
    <?php else: ?>
      <span class="cover-name"><?= e($brand['name']) ?></span>
    <?php endif; ?>
  </div>
  <div class="cover-text">
    <h1>Brand manuál</h1>
    <p class="cover-sub"><?= e($brand['name']) ?></p>
    <p class="small">Stav k <?= e(date('j. n. Y')) ?> · aktuální verze vždy online</p>
  </div>
  <ol class="toc">
    <?php foreach ($present as $i => $k): ?><li><span><?= sprintf('%02d', $i + 1) ?></span> <?= e(SECTIONS[$k]['title']) ?></li><?php endforeach; ?>
  </ol>
</section>

<?php foreach ($present as $k): $num++; $text = $texts[$k] ?? ''; ?>
<section class="sheet">
  <header class="sheet-head">
    <span class="sheet-num"><?= sprintf('%02d', $num) ?></span>
    <h2><?= e(SECTIONS[$k]['title']) ?></h2>
  </header>

  <?php if ($k === 'barvy'): ?>
    <div class="p-colors">
      <?php foreach ($colors as $c): $cmyk = $c['cmyk'] ?: hex_to_cmyk($c['hex']) . ' (orient.)'; ?>
        <div class="p-color">
          <div class="p-swatch" style="background: <?= e($c['hex']) ?>; color: <?= is_dark($c['hex']) ? '#fff' : 'var(--ink)' ?>"><b><?= e($c['name']) ?></b><?php if ($c['role']): ?><span><?= e($c['role']) ?></span><?php endif; ?></div>
          <dl class="color-meta">
            <dt>HEX</dt><dd><?= e($c['hex']) ?></dd>
            <dt>RGB</dt><dd><?= e(hex_to_rgb($c['hex'])) ?></dd>
            <dt>CMYK</dt><dd><?= e($cmyk) ?></dd>
            <?php if ($c['pantone']): ?><dt>Pantone</dt><dd><?= e($c['pantone']) ?></dd><?php endif; ?>
          </dl>
          <?php if ($c['usage_note']): ?><p class="tiny"><?= e($c['usage_note']) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php $tinted = array_filter($colors, fn ($c) => !empty($c['tints'])); if ($tinted): ?>
      <h3 class="p-h3">Odstíny (tóny s bílou)</h3>
      <div class="tints">
        <?php foreach ($tinted as $c): ?>
          <div class="tints-row">
            <span class="tints-name"><?= e($c['name']) ?></span>
            <?php foreach (color_tints($c['hex']) as [$pct, $hx]): ?>
              <span class="tint" style="background: <?= e($hx) ?>; color: <?= is_dark($hx) ? '#fff' : 'var(--ink)' ?>"><span><?= $pct ?> %</span><span class="mono"><?= e($hx) ?></span></span>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($k === 'logo'): ?>
    <div class="p-grid">
      <?php foreach ($bySection['logo'] ?? [] as $it): ?>
        <div class="p-card">
          <div class="p-preview" style="background: <?= e($it['preview_bg'] ?: '#FFFFFF') ?>">
            <?php if ($it['preview']): ?><img src="<?= e(client_file_url($brand, $it['preview'], true)) ?>" alt=""><?php endif; ?>
          </div>
          <div class="p-body">
            <b><?= e($it['title']) ?></b>
            <?php if ($it['description']): ?><p class="small"><?= e($it['description']) ?></p><?php endif; ?>
            <p class="tiny muted"><?= e(implode(' · ', array_map(fn ($f) => $f['label'], $it['files']))) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  <?php elseif ($k === 'typografie'): ?>
    <div class="p-fonts">
      <?php foreach ($bySection['typografie'] ?? [] as $it): $m = $it['meta']; $ff = "'bf" . (int) $it['id'] . "', var(--font-sans)"; $mainW = font_main_weight($m); ?>
        <div class="p-font">
          <div class="p-font-head"><b><?= e(($m['family'] ?? '') ?: $it['title']) ?></b><span class="small muted"><?= e($it['title']) ?><?= !empty($m['weights']) ? ' · ' . e($m['weights']) : '' ?></span></div>
          <div style="font-family: <?= $ff ?>; font-weight: <?= $mainW ?>; font-size: 54px; line-height: 1">Aa Žž</div>
          <div style="font-family: <?= $ff ?>; font-size: 20px"><?= e(($m['sample'] ?? '') ?: 'Příliš žluťoučký kůň úpěl ďábelské ódy') ?></div>
          <div class="small" style="font-family: <?= $ff ?>">AÁBCČDĎEÉĚFGHIÍJKLMNŇOÓPQRŘSŠTŤUÚŮVWXYÝZŽ<br>aábcčdďeéěfghiíjklmnňoópqrřsštťuúůvwxyýzž 0123456789</div>
          <?php if ($it['description']): ?><p class="small"><?= e($it['description']) ?></p><?php endif; ?>
          <?php if (!empty($m['license']) || !empty($m['google_url'])): ?><p class="tiny muted"><?= e($m['license'] ?? '') ?><?= !empty($m['google_url']) ? ' · ' . e($m['google_url']) : '' ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

  <?php else: ?>
    <div class="p-list">
      <?php foreach ($bySection[$k] ?? [] as $it): ?>
        <div class="p-row">
          <div class="p-thumb" style="<?= $it['preview_bg'] ? 'background:' . e($it['preview_bg']) : '' ?>">
            <?php if ($it['preview']): ?><img src="<?= e(client_file_url($brand, $it['preview'], true)) ?>" alt=""><?php endif; ?>
          </div>
          <div>
            <b><?= e($it['title']) ?></b><?php if ($it['specs']): ?> <span class="small muted">· <?= e($it['specs']) ?></span><?php endif; ?>
            <?php if ($it['description']): ?><p class="small"><?= e($it['description']) ?></p><?php endif; ?>
            <p class="tiny muted"><?= e(implode(' · ', array_filter([$it['tags'], implode(', ', array_map(fn ($f) => $f['label'], $it['files']))]))) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($text !== ''): ?>
    <div class="prose p-prose"><?= markdown($text) ?></div>
  <?php endif; ?>
</section>
<?php endforeach; ?>

<section class="sheet p-end">
  <h2>Soubory ke stažení</h2>
  <p>Všechna loga, písma a podklady v aktuální verzi najdete online:</p>
  <p class="p-url"><?= e(brand_public_url($brand)) ?></p>
  <?php if ($brand['contact_name']): ?><p class="small muted">Brand spravuje <?= e($brand['contact_name']) ?><?= $brand['contact_note'] ? ' · ' . e($brand['contact_note']) : '' ?><?= !empty($brand['contact_phone']) ? '<br>' . e($brand['contact_phone']) : '' ?><?= !empty($brand['contact_email']) ? (empty($brand['contact_phone']) ? '<br>' : ' · ') . e($brand['contact_email']) : '' ?></p><?php endif; ?>
</section>

</body>
</html>
