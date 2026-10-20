<?php $me = current_admin(); $nav = $nav ?? ''; ?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="upload-chunk" content="<?= upload_chunk_bytes() ?>">
<title><?= e($title ?? 'Administrace') ?> · Brand Hub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lexend:wght@200;300;500&display=swap">
<link rel="stylesheet" href="/assets/css/app.css?v=<?= e(APP_VERSION) ?>">
</head>
<body>
<header class="admin-top">
  <div class="admin-top-inner">
    <a class="brandmark" href="/admin"><span class="name">MarMal</span><span class="sub">Brand Hub · administrace</span></a>
    <div class="row small">
      <span><?= e($me['name'] ?? '') ?></span>
      <form method="post" action="/logout" class="inline"><?= csrf_field() ?><button class="link-btn" style="color:#fff">Odhlásit</button></form>
    </div>
  </div>
</header>
<div class="admin-wrap">
  <nav class="admin-nav" aria-label="Administrace">
    <a class="nav-link<?= $nav === 'clients' ? ' active' : '' ?>" href="/admin"<?= $nav === 'clients' ? ' aria-current="page"' : '' ?>><span><?= icon('users', 18) ?>Klienti</span></a>
    <a class="nav-link<?= $nav === 'template' ? ' active' : '' ?>" href="/admin/template"><span><?= icon('grid', 18) ?>Šablona sekcí</span></a>
    <a class="nav-link<?= $nav === 'activity' ? ' active' : '' ?>" href="/admin/activity"><span><?= icon('activity', 18) ?>Aktivita</span></a>
    <a class="nav-link<?= $nav === 'settings' ? ' active' : '' ?>" href="/admin/settings"><span><?= icon('settings', 18) ?>Nastavení</span></a>
    <p class="tiny muted" style="padding: 12px 16px">Verze <?= e(APP_VERSION) ?></p>
  </nav>
  <main class="admin-main">
    <?php foreach (take_flashes() as $f): ?>
      <div class="alert alert-<?= $f['type'] === 'error' ? 'error' : 'success' ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>">
        <?= icon($f['type'] === 'error' ? 'alert' : 'check', 20) ?><div><?= e($f['message']) ?></div>
      </div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</div>
<script src="/assets/js/app.js?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>
