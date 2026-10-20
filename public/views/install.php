<form method="post" action="/install" class="card plain-card wide stack-lg">
  <?= csrf_field() ?>
  <div class="stack" style="gap: 6px">
    <span style="font-family: var(--font-display); font-weight: 700; font-size: 24px">MarMal</span>
    <h1 style="font-size: 32px">Instalace Brand Hubu</h1>
    <p class="muted">Vyplňte přístupy k databázi a účet administrátora. Hesla se uloží jen na tomto serveru.</p>
  </div>

  <?php foreach ($errors as $err): ?>
    <div class="alert alert-error" role="alert"><?= icon('alert') ?><div><?= e($err) ?></div></div>
  <?php endforeach; ?>

  <section class="stack">
    <h2 style="font-size: 20px">Kontrola serveru</h2>
    <ul class="list-plain card list-lines">
      <?php foreach ($checks as [$label, $ok, $note]): ?>
        <li class="row-between">
          <span class="row" style="gap: 8px"><span style="color: var(<?= $ok ? '--success' : '--error' ?>)"><?= icon($ok ? 'check' : 'alert', 18) ?></span><?= e($label) ?></span>
          <span class="tiny muted break"><?= $ok ? e($note) : '<b style="color:var(--error)">Chybí</b> · ' . e($note) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="stack">
    <h2 style="font-size: 20px">Datová složka</h2>
    <div class="field">
      <label for="data_dir">Cesta na serveru</label>
      <input class="input mono" id="data_dir" name="data_dir" value="<?= e($values['data_dir']) ?>" required>
      <span class="hint">Složka mimo web (na stejné úrovni jako složka brand.marmal.cz). Ukládají se do ní konfigurace, soubory klientů a zálohy.</span>
    </div>
  </section>

  <section class="stack">
    <h2 style="font-size: 20px">Databáze</h2>
    <div class="form-grid">
      <div class="field"><label for="db_host">Server databáze</label><input class="input" id="db_host" name="db_host" value="<?= e($values['db_host']) ?>" required><span class="hint">Najdete v administraci hostingu, často „localhost“.</span></div>
      <div class="field"><label for="db_port">Port</label><input class="input" id="db_port" name="db_port" value="<?= e($values['db_port']) ?>" inputmode="numeric"></div>
      <div class="field"><label for="db_name">Název databáze</label><input class="input" id="db_name" name="db_name" value="<?= e($values['db_name']) ?>" required></div>
      <div class="field"><label for="db_user">Uživatel</label><input class="input" id="db_user" name="db_user" value="<?= e($values['db_user']) ?>" required autocomplete="off"></div>
      <div class="field full"><label for="db_pass">Heslo k databázi</label><input class="input" type="password" id="db_pass" name="db_pass" autocomplete="new-password"></div>
    </div>
  </section>

  <section class="stack">
    <h2 style="font-size: 20px">Aplikace a administrátor</h2>
    <div class="form-grid">
      <div class="field full"><label for="app_host">Adresa aplikace</label><input class="input" id="app_host" name="app_host" value="<?= e($values['app_host']) ?>" required><span class="hint">Bez https://, např. brand.marmal.cz</span></div>
      <div class="field"><label for="admin_name">Vaše jméno</label><input class="input" id="admin_name" name="admin_name" value="<?= e($values['admin_name']) ?>" required></div>
      <div class="field"><label for="admin_email">E-mail pro přihlášení</label><input class="input" type="email" id="admin_email" name="admin_email" value="<?= e($values['admin_email']) ?>" required autocomplete="username"></div>
      <div class="field full"><label for="admin_password">Heslo administrátora</label><input class="input" type="password" id="admin_password" name="admin_password" minlength="10" required autocomplete="new-password"><span class="hint">Aspoň 10 znaků.</span></div>
    </div>
  </section>

  <button class="btn btn-primary" data-busy="Instaluji…">Nainstalovat</button>
</form>
