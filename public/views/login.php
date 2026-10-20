<?php $title = 'Přihlášení'; ?>
<form method="post" action="/login" class="card plain-card stack-lg">
  <?= csrf_field() ?>
  <div class="stack" style="gap: 6px">
    <span style="font-family: var(--font-display); font-weight: 700; font-size: 24px">MarMal</span>
    <h1 style="font-size: 30px">Brand Hub</h1>
    <p class="muted">Přihlášení do administrace</p>
  </div>
  <?php foreach (take_flashes() as $f): ?>
    <div class="alert alert-<?= $f['type'] === 'error' ? 'error' : 'success' ?>"><?= icon($f['type'] === 'error' ? 'alert' : 'check') ?><div><?= e($f['message']) ?></div></div>
  <?php endforeach; ?>
  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><?= icon('alert') ?><div><?= e($error) ?></div></div>
  <?php endif; ?>
  <div class="field">
    <label for="email">E-mail</label>
    <input class="input" type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
  </div>
  <div class="field">
    <label for="password">Heslo</label>
    <input class="input" type="password" id="password" name="password" autocomplete="current-password" required>
  </div>
  <button class="btn btn-primary btn-block">Přihlásit</button>
</form>
