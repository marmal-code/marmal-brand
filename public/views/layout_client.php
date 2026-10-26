<?php
$current = $current ?? 'home';
$navItems = [];
foreach (SECTIONS as $key => $s) {
    if (($counts[$key] ?? 0) > 0 || isset($texts[$key])) {
        $navItems[$key] = $s;
    }
}
$updated = cz_date($brand['updated_at']);
?>
<?php ob_start(); ?>
    <?php if ($brand['contact_name'] || $brand['contact_note'] || !empty($brand['contact_phone']) || !empty($brand['contact_email'])): ?>
      <div class="card contact-card">
        <div class="small muted">Brand spravuje</div>
        <div style="font-weight: var(--fw-medium)"><?= e($brand['contact_name']) ?></div>
        <?php if ($brand['contact_note']): ?><div class="small muted" style="margin-top: 4px"><?= e($brand['contact_note']) ?></div><?php endif; ?>
        <?php if (!empty($brand['contact_phone']) || !empty($brand['contact_email'])): ?>
          <div class="contact-links">
            <?php if (!empty($brand['contact_phone'])): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $brand['contact_phone'])) ?>"><?= icon('phone', 16) ?><?= e($brand['contact_phone']) ?></a><?php endif; ?>
            <?php if (!empty($brand['contact_email'])): ?><a href="mailto:<?= e($brand['contact_email']) ?>"><?= icon('mail', 16) ?><?= e($brand['contact_email']) ?></a><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
<?php $contactCard = trim((string) ob_get_clean()); ?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? $brand['name']) ?></title>
<?php if (!empty($typefaces)): ?>
<?php foreach ($typefaces['preload'] as [$href, $type]): ?>
<link rel="preload" href="<?= e($href) ?>" as="font" type="<?= e($type) ?>" crossorigin>
<?php endforeach; ?>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= e(APP_VERSION) ?>">
<style><?= $typefaces['css'] ?></style>
<?php else: ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lexend:wght@200;300;500&display=swap">
<link rel="stylesheet" href="/assets/css/app.css?v=<?= e(APP_VERSION) ?>">
<?php endif; ?>
<?php if ($symbol): ?><link rel="icon" href="<?= e(brand_url($brand, '/file/' . (int) $symbol['preview']['id'])) ?>?inline=1"><?php endif; ?>
</head>
<body>
<?php if ($admin): ?>
  <div style="background: var(--ink); color: #fff; font-size: 14px">
    <div class="client-top-inner" style="padding-top: 8px; padding-bottom: 8px">
      <span>Díváte se jako klient (náhled administrátora)</span>
      <a href="/admin/brands/<?= (int) $brand['id'] ?>/s/<?= isset(SECTIONS[$current]) ? e($current) : 'logo' ?>" style="color: #fff">Upravit v administraci</a>
    </div>
  </div>
<?php endif; ?>
<header class="client-top">
  <div class="client-top-inner">
    <a class="client-id" href="<?= e(brand_url($brand)) ?>">
      <span class="avatar" style="background: <?= e($brand['hero_bg']) ?>; color: <?= is_dark($brand['hero_bg']) ? '#fff' : 'var(--ink)' ?>">
        <?php if ($symbol): ?><img src="<?= e(brand_url($brand, '/file/' . (int) $symbol['preview']['id'])) ?>?inline=1" alt=""><?php else: ?><?= e(mb_strtoupper(mb_substr($brand['name'], 0, 1))) ?><?php endif; ?>
      </span>
      <span>
        <span class="name" style="display: block"><?= e($brand['name']) ?></span>
        <span class="small muted">Brand manuál · aktualizováno <?= e($updated) ?></span>
      </span>
    </a>
    <div class="row">
      <?php if ($manualPdf): ?>
        <a class="btn btn-ghost" href="<?= e(brand_url($brand, '/file/' . (int) $manualPdf['id'])) ?>"><?= icon('file', 18) ?>Manuál PDF</a>
      <?php endif; ?>
      <a class="btn btn-ghost" href="<?= e(brand_url($brand, '/manual')) ?>" target="_blank" rel="noopener"><?= icon('printer', 18) ?><?= $manualPdf ? 'Tisková verze' : 'Stáhnout jako PDF' ?></a>
      <?php if ((int) $totals['n'] > 0): ?>
        <span class="small muted"><?= (int) $totals['n'] ?> <?= (int) $totals['n'] === 1 ? 'soubor' : ((int) $totals['n'] < 5 ? 'soubory' : 'souborů') ?> · <?= human_size((int) $totals['size']) ?></span>
        <a class="btn btn-primary" href="<?= e(brand_url($brand, '/zip')) ?>"><?= icon('download', 18) ?>Stáhnout vše (ZIP)</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<div class="client-wrap">
  <nav class="client-nav" aria-label="Sekce brand manuálu">
    <a class="nav-link<?= $current === 'home' ? ' active' : '' ?>" href="<?= e(brand_url($brand)) ?>"<?= $current === 'home' ? ' aria-current="page"' : '' ?>><span>Přehled</span></a>
    <?php foreach ($navItems as $key => $s): ?>
      <a class="nav-link<?= $current === $key ? ' active' : '' ?>" href="<?= e(brand_url($brand, '/s/' . $key)) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>>
        <span><?= e($s['title']) ?></span><?php if (($counts[$key] ?? 0) > 0): ?><span class="badge-count"><?= (int) $counts[$key] ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
    <?= $contactCard ?>
    <?php if ($access === 'client'): ?>
      <form method="post" action="<?= e(brand_url($brand, '/logout')) ?>" style="padding: 12px 16px"><?= csrf_field() ?><button class="link-btn small">Odhlásit se</button></form>
    <?php endif; ?>
  </nav>
  <main class="client-main">
    <?= $content ?>
    <?php if ($contactCard !== ''): ?><div class="contact-mobile"><?= $contactCard ?></div><?php endif; ?>
    <footer class="footer-note">
      <span>Odkaz na tento manuál můžete poslat grafikovi nebo tiskárně.</span>
      <span>Brand Hub od MarMal.cz</span>
    </footer>
  </main>
</div>
<script src="/assets/js/app.js?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>
