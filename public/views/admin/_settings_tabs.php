<h1>Nastavení</h1>
<nav class="tabs" aria-label="Nastavení">
  <a class="tab<?= $tab === 'profile' ? ' active' : '' ?>" href="/admin/settings"><?= icon('user', 16) ?>Můj profil</a>
  <a class="tab<?= $tab === 'update' ? ' active' : '' ?>" href="/admin/settings/update"><?= icon('refresh', 16) ?>Aktualizace a zálohy</a>
  <a class="tab" href="/admin/template"><?= icon('grid', 16) ?>Šablona sekcí</a>
</nav>
