<?php
require APP_ROOT . '/views/admin/_settings_tabs.php';
$available = $release && version_compare($release['version'], APP_VERSION, '>');
$missing = array_filter($checks, fn ($c) => !$c[1]);
?>

<section class="card card-pad stack">
  <div class="row-between">
    <div>
      <h2>Aktualizace aplikace</h2>
      <p class="muted small">Nainstalovaná verze <b style="color: var(--ink)"><?= e(APP_VERSION) ?></b> · zdroj: github.com/<?= e($repo) ?></p>
    </div>
    <form method="post" action="/admin/settings/update/check"><?= csrf_field() ?><button class="btn btn-ghost" data-busy="Kontroluji…"><?= icon('refresh', 18) ?>Zkontrolovat aktualizace</button></form>
  </div>

  <?php if (!$hasToken): ?>
    <div class="alert alert-info"><?= icon('lock') ?><div>Token není uložený. U veřejného repozitáře to nevadí, u soukromého ho vložte níže – bez něj aplikace repozitář nevidí.</div></div>
  <?php endif; ?>

  <?php if ($release): ?>
    <?php if ($available): ?>
      <div class="alert alert-info" style="flex-direction: column; gap: 12px">
        <div class="row" style="gap: 10px"><?= icon('download') ?><b>K dispozici je verze <?= e($release['version']) ?></b><span class="small muted">vydáno <?= cz_date($release['published_at']) ?></span></div>
        <?php if (trim($release['body']) !== ''): ?>
          <div class="small" style="white-space: pre-line; max-height: 260px; overflow: auto"><?= e($release['body']) ?></div>
        <?php endif; ?>
        <form method="post" action="/admin/settings/update/run" class="row" data-confirm="Spustit aktualizaci na verzi <?= e($release['version']) ?>? Aplikace se na chvíli přepne do údržby. Před aktualizací se automaticky vytvoří záloha.">
          <?= csrf_field() ?>
          <input type="hidden" name="tag" value="<?= e($release['tag']) ?>">
          <button class="btn btn-primary" data-busy="Aktualizuji… nezavírejte stránku"<?= $missing ? ' disabled' : '' ?>><?= icon('download', 18) ?>Aktualizovat na <?= e($release['version']) ?></button>
          <span class="small muted">Záloha → stažení → výměna souborů → úprava databáze. Při chybě se vše samo vrátí.</span>
        </form>
      </div>
    <?php else: ?>
      <div class="alert alert-success"><?= icon('check') ?><div>Aplikace je aktuální. Poslední kontrola <?= cz_date($release['at'] ?? '', true) ?>.</div></div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($result): ?>
    <div class="stack" style="gap: 8px">
      <h3><?= $result['ok'] ? 'Protokol aktualizace' : 'Protokol nepovedené aktualizace' ?></h3>
      <pre class="card card-pad-sm mono" style="margin: 0; white-space: pre-wrap; <?= $result['ok'] ? '' : 'border-color: var(--error-soft)' ?>"><?= e(implode("\n", $result['log'])) ?></pre>
    </div>
  <?php elseif ($lastLog !== ''): ?>
    <details>
      <summary class="small" style="cursor: pointer">Protokol poslední aktualizace</summary>
      <pre class="card card-pad-sm mono" style="margin: 8px 0 0; white-space: pre-wrap"><?= e($lastLog) ?></pre>
    </details>
  <?php endif; ?>
</section>

<section class="card card-pad stack">
  <h2>Propojení s GitHubem</h2>
  <form method="post" action="/admin/settings/update/token" class="stack">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="field"><label for="repo">Repozitář</label><input class="input mono" id="repo" name="repo" value="<?= e($repo) ?>"></div>
      <div class="field">
        <label for="token">GitHub token<?= $hasToken ? ' (uložený)' : '' ?></label>
        <input class="input mono" type="password" id="token" name="token" placeholder="<?= $hasToken ? '•••••••• – vložte nový jen při výměně' : 'github_pat_…' ?>" autocomplete="off">
        <span class="hint">Fine-grained token jen pro tento repozitář s oprávněním <b>Contents: Read-only</b>. Ukládá se zašifrovaně.</span>
      </div>
    </div>
    <div class="row">
      <button class="btn btn-secondary">Uložit</button>
      <?php if ($hasToken): ?><label class="check" style="padding-top: 0; min-height: 0"><input type="checkbox" name="remove_token" value="1"><span class="small">Odebrat uložený token</span></label><?php endif; ?>
    </div>
  </form>
</section>

<section class="card card-pad stack">
  <div class="row-between">
    <div>
      <h2>Zálohy</h2>
      <p class="small muted">Databáze a kód aplikace. Drží se posledních <?= BACKUPS_KEEP ?>. Soubory klientů se nezálohují, aktualizace na ně nesahá.</p>
    </div>
    <form method="post" action="/admin/settings/backup"><?= csrf_field() ?><button class="btn btn-ghost" data-busy="Zálohuji…"><?= icon('database', 18) ?>Vytvořit zálohu</button></form>
  </div>
  <?php if (!$backups): ?>
    <p class="small muted">Zatím žádná záloha. První se vytvoří automaticky před aktualizací.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table" style="min-width: 560px">
        <thead><tr><th scope="col">Vytvořeno</th><th scope="col">Verze</th><th scope="col">Důvod</th><th scope="col">Velikost</th><th scope="col"></th></tr></thead>
        <tbody>
        <?php foreach ($backups as $b): ?>
          <tr>
            <td class="small nowrap"><?= cz_date($b['created_at'], true) ?></td>
            <td class="small"><?= e($b['version']) ?></td>
            <td class="small"><?= e($b['reason']) ?></td>
            <td class="small nowrap"><?= human_size($b['size']) ?></td>
            <td>
              <?php if ($b['complete']): ?>
                <form method="post" action="/admin/settings/backup/restore" data-confirm="Obnovit zálohu z <?= e(cz_date($b['created_at'], true)) ?>? Kód i databáze se vrátí do tohoto stavu (verze <?= e($b['version']) ?>). Klienti a soubory nahrané později zůstanou na disku, ale v databázi nebudou."><?= csrf_field() ?><input type="hidden" name="backup" value="<?= e($b['id']) ?>"><button class="btn btn-ghost btn-sm" data-busy="Obnovuji…">Obnovit</button></form>
              <?php else: ?><span class="tiny muted">neúplná</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="card card-pad stack">
  <h2>Kontrola serveru</h2>
  <ul class="list-plain list-lines" style="margin: 0 -24px">
    <?php foreach ($checks as [$label, $ok, $note]): ?>
      <li class="row-between" style="padding-left: 24px; padding-right: 24px">
        <span class="row" style="gap: 8px"><span style="color: var(<?= $ok ? '--success' : '--error' ?>)"><?= icon($ok ? 'check' : 'alert', 18) ?></span><?= e($label) ?></span>
        <span class="tiny muted break"><?= e($note) ?></span>
      </li>
    <?php endforeach; ?>
    <li class="row-between" style="padding-left: 24px; padding-right: 24px">
      <span>Limity PHP</span>
      <span class="tiny muted">upload <?= e(ini_get('upload_max_filesize')) ?> · post <?= e(ini_get('post_max_size')) ?> · paměť <?= e(ini_get('memory_limit')) ?> · čas <?= e(ini_get('max_execution_time')) ?> s</span>
    </li>
  </ul>
</section>
