<?php require APP_ROOT . '/views/admin/_settings_tabs.php'; ?>
<form method="post" action="/admin/settings" class="card card-pad stack" style="max-width: 720px">
  <?= csrf_field() ?>
  <h2>Můj profil</h2>
  <div class="form-grid">
    <div class="field"><label for="name">Jméno</label><input class="input" id="name" name="name" value="<?= e($me['name']) ?>" required></div>
    <div class="field"><label for="email">E-mail pro přihlášení</label><input class="input" type="email" id="email" name="email" value="<?= e($me['email']) ?>" required autocomplete="username"></div>
  </div>
  <h3>Změna hesla</h3>
  <div class="form-grid">
    <div class="field"><label for="current_password">Současné heslo</label><input class="input" type="password" id="current_password" name="current_password" autocomplete="current-password"></div>
    <div class="field"><label for="new_password">Nové heslo</label><input class="input" type="password" id="new_password" name="new_password" minlength="10" autocomplete="new-password"><span class="hint">Aspoň 10 znaků. Nechte prázdné, pokud heslo neměníte.</span></div>
  </div>
  <div class="row"><button class="btn <?= $me['totp_enabled_at'] ? 'btn-primary' : 'btn-ghost' ?>">Uložit profil</button></div>
</form>

<section class="card card-pad stack" style="max-width: 720px" id="2fa">
  <div class="row-between">
    <h2>Dvoufázové ověření</h2>
    <?php if ($me['totp_enabled_at']): ?>
      <span class="tag tag-ok"><?= icon('shield', 16) ?>Zapnuté</span>
    <?php else: ?>
      <span class="tag tag-warn"><?= icon('alert', 16) ?>Vypnuté</span>
    <?php endif; ?>
  </div>

  <?php if ($newCodes): ?>
    <div class="alert alert-info stack" style="display: block">
      <p><b>Záložní kódy – uložte si je hned, víckrát je neuvidíte.</b><br>
      Každý kód platí jednou. Použijete ho místo kódu z telefonu, když telefon nebudete mít po ruce.</p>
      <ol class="recovery-codes mono"><?php foreach ($newCodes as $c): ?><li><?= e($c) ?></li><?php endforeach; ?></ol>
      <div class="row">
        <button type="button" class="btn btn-ghost btn-sm" data-copy="<?= e(implode("\n", $newCodes)) ?>" data-copy-msg="Záložní kódy jsou zkopírované"><?= icon('copy', 16) ?>Kopírovat</button>
        <a class="btn btn-ghost btn-sm" download="brand-hub-zalozni-kody.txt" href="data:text/plain;charset=utf-8,<?= rawurlencode("Brand Hub – záložní kódy (" . date('j. n. Y') . ")\n\n" . implode("\n", $newCodes) . "\n") ?>"><?= icon('download', 16) ?>Stáhnout .txt</a>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($me['totp_enabled_at']): ?>
    <p class="small">Při přihlášení zadáváte kromě hesla i kód z aplikace Authenticator. Zapnuto <?= e(cz_date($me['totp_enabled_at'])) ?>.
      Zbývá <b><?= (int) $codesLeft ?></b> nepoužitých záložních kódů.</p>
    <form method="post" action="/admin/settings/2fa/codes" class="row" style="align-items: flex-end">
      <?= csrf_field() ?>
      <div class="field" style="max-width: 200px"><label for="codes_code">Kód z aplikace</label><input class="input code-input" id="codes_code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required></div>
      <button class="btn btn-ghost"><?= icon('refresh', 18) ?>Vytvořit nové záložní kódy</button>
    </form>
    <details class="small">
      <summary>Vypnout dvoufázové ověření</summary>
      <form method="post" action="/admin/settings/2fa/disable" class="form-grid" style="margin-top: 12px" data-confirm="Opravdu vypnout dvoufázové ověření? Účet bude chráněný jen heslem.">
        <?= csrf_field() ?>
        <div class="field"><label for="dis_password">Heslo</label><input class="input" type="password" id="dis_password" name="password" autocomplete="current-password" required></div>
        <div class="field"><label for="dis_code">Kód z aplikace nebo záložní kód</label><input class="input" id="dis_code" name="code" autocomplete="one-time-code" maxlength="12" required></div>
        <div class="field full"><button class="btn btn-danger">Vypnout</button></div>
      </form>
    </details>
  <?php else: ?>
    <p class="small">Kromě hesla budete při přihlášení zadávat i 6místný kód z telefonu (Google Authenticator, Microsoft Authenticator, 1Password…).
      I když někdo zjistí vaše heslo, bez telefonu se do administrace nedostane.</p>
    <div class="row"><a class="btn btn-primary" href="/admin/settings/2fa"><?= icon('shield', 18) ?>Zapnout dvoufázové ověření</a></div>
  <?php endif; ?>
</section>

<section class="card card-pad stack" style="max-width: 720px">
  <h2>Přihlášená zařízení</h2>
  <p class="small">Když jste se přihlásil na cizím počítači nebo ztratil telefon, odhlaste všechna ostatní zařízení. Tady zůstanete přihlášený.
    Změna hesla nebo zapnutí 2FA je odhlásí automaticky.</p>
  <form method="post" action="/admin/settings/logout-others" data-confirm="Odhlásit všechna ostatní zařízení?"><?= csrf_field() ?><button class="btn btn-ghost"><?= icon('logout', 18) ?>Odhlásit ostatní zařízení</button></form>
</section>
