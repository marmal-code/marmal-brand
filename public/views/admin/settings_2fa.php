<?php require APP_ROOT . '/views/admin/_settings_tabs.php'; ?>
<section class="card card-pad stack" style="max-width: 720px">
  <h2>Zapnout dvoufázové ověření</h2>
  <ol class="steps stack">
    <li>Do telefonu si nainstalujte aplikaci <b>Google Authenticator</b>, <b>Microsoft Authenticator</b> nebo použijte 1Password.</li>
    <li>V aplikaci přidejte účet a naskenujte QR kód.
      <div class="qr-box">
        <div class="qr" data-qr="<?= e($uri) ?>" role="img" aria-label="QR kód pro aplikaci Authenticator"></div>
        <details class="small">
          <summary>Nejde naskenovat? Zadejte klíč ručně</summary>
          <code class="mono secret"><?= e(trim(chunk_split($secret, 4, ' '))) ?></code>
          <div class="row" style="margin-top: 8px">
            <button type="button" class="btn btn-ghost btn-sm" data-copy="<?= e($secret) ?>" data-copy-msg="Klíč je zkopírovaný"><?= icon('copy', 16) ?>Kopírovat klíč</button>
            <a class="btn btn-ghost btn-sm" href="<?= e($uri) ?>">Otevřít v aplikaci (na telefonu)</a>
          </div>
        </details>
      </div>
    </li>
    <li>Opište 6místný kód, který aplikace ukazuje.</li>
  </ol>
  <?php if ($error): ?>
    <div class="alert alert-error" role="alert"><?= icon('alert') ?><div><?= e($error) ?></div></div>
  <?php endif; ?>
  <form method="post" action="/admin/settings/2fa" class="row" style="align-items: flex-end">
    <?= csrf_field() ?>
    <div class="field" style="max-width: 200px"><label for="code">Kód z aplikace</label><input class="input code-input" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus></div>
    <button class="btn btn-primary">Ověřit a zapnout</button>
    <a class="btn btn-ghost" href="/admin/settings">Zpět</a>
  </form>
  <p class="tiny muted">Po zapnutí dostanete 10 záložních kódů pro případ, že telefon ztratíte. Uložte si je do správce hesel nebo vytiskněte.</p>
</section>
