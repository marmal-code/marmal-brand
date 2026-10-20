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
  <div class="row"><button class="btn btn-primary">Uložit profil</button></div>
</form>
