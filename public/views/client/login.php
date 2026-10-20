<form method="post" action="<?= e(brand_url($brand, '/login')) ?>" class="card plain-card stack-lg">
  <?= csrf_field() ?>
  <div class="stack" style="gap: 6px">
    <p class="small muted">Brand manuál</p>
    <h1 style="font-size: 32px"><?= e($brand['name']) ?></h1>
    <p class="muted">Pro přístup k logům, barvám a podkladům zadejte heslo, které jste dostali.</p>
  </div>
  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><?= icon('alert') ?><div><?= e($error) ?></div></div>
  <?php endif; ?>
  <div class="field">
    <label for="password">Heslo</label>
    <input class="input" type="password" id="password" name="password" autocomplete="current-password" required autofocus>
  </div>
  <button class="btn btn-primary btn-block"><?= icon('lock', 18) ?>Otevřít brand manuál</button>
  <?php if ($brand['contact_name']): ?><p class="small muted">Heslo nemáte? Napište: <?= e($brand['contact_name']) ?></p><?php endif; ?>
</form>
