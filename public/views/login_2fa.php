<?php $title = 'Ověřovací kód'; ?>
<form method="post" action="/login/2fa" class="card plain-card stack-lg">
  <?= csrf_field() ?>
  <div class="stack" style="gap: 6px">
    <span style="font-family: var(--font-display); font-weight: 700; font-size: 24px">MarMal</span>
    <h1 style="font-size: 30px">Ověřovací kód</h1>
    <p class="muted">Zadejte 6místný kód z aplikace Authenticator. Když nemáte telefon po ruce, použijte jeden ze záložních kódů.</p>
  </div>
  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><?= icon('alert') ?><div><?= e($error) ?></div></div>
  <?php endif; ?>
  <div class="field">
    <label for="code">Kód</label>
    <input class="input code-input" type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus>
  </div>
  <button class="btn btn-primary btn-block">Ověřit a přihlásit</button>
  <p class="small muted" style="text-align: center"><a href="/login">Zpět na přihlášení</a></p>
</form>
