<?php
require APP_ROOT . '/views/admin/_settings_tabs.php';
$kindLabel = fn (array $c) => $c['kind'] === 'manual' ? 'ruční token' : 'přihlášení';
$scopeLabel = fn (string $s) => str_contains($s, 'zapis') ? 'čtení a zápis' : 'jen čtení';
$groups = ['cteni' => 'Čtení', 'zapis' => 'Zápis'];
?>

<?php if (!$available): ?>
  <div class="alert alert-error" role="alert"><?= icon('alert') ?><div>AI konektor ještě nemá databázi. Dokončete aktualizaci aplikace (Aktualizace a zálohy), migrace 008 se spustí sama.</div></div>
<?php else: ?>

<section class="card card-pad stack" style="max-width: 920px">
  <div class="row-between">
    <div>
      <h2>AI konektor (MCP)</h2>
      <p class="muted small">Přes konektor si Claude, ChatGPT nebo Gemini berou z Brand Hubu loga, barvy, písma a další soubory a umí je sem i přidávat.</p>
    </div>
    <?php if ($enabled): ?>
      <span class="tag tag-ok"><?= icon('check', 16) ?>Zapnutý</span>
    <?php else: ?>
      <span class="tag tag-warn"><?= icon('alert', 16) ?>Vypnutý</span>
    <?php endif; ?>
  </div>
  <div class="field">
    <label for="mcp-url">Adresa konektoru</label>
    <div class="row" style="flex-wrap: nowrap">
      <input class="input mono" id="mcp-url" value="<?= e($url) ?>" readonly>
      <button type="button" class="btn btn-ghost" data-copy="<?= e($url) ?>" data-copy-msg="Adresa konektoru je zkopírovaná"><?= icon('copy', 18) ?>Kopírovat</button>
    </div>
    <?php if (mcp_base() !== mcp_base_from_request()): ?>
      <form method="post" action="/admin/settings/ai/base" class="row tiny muted" style="gap: 8px">
        <?= csrf_field() ?>
        <span>Stránku máte otevřenou na <?= e(mcp_base_from_request()) ?>.</span>
        <button class="link-btn">Nastavit adresu konektoru podle této stránky</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="form-grid">
    <div class="card card-pad-sm small stack" style="gap: 6px; background: var(--bg-tint); border: 0">
      <b>Claude</b>
      <ol style="margin: 0; padding-left: 18px">
        <li>claude.ai nebo aplikace → Nastavení → Konektory → <em>Přidat vlastní konektor</em></li>
        <li>Název „Brand Hub“, adresa výše → Přidat</li>
        <li>Připojit → přihlásit se sem (heslo a kód) a povolit</li>
      </ol>
    </div>
    <div class="card card-pad-sm small stack" style="gap: 6px; background: var(--bg-tint); border: 0">
      <b>ChatGPT <span class="muted" style="font-weight: 300">(placený tarif)</span></b>
      <ol style="margin: 0; padding-left: 18px">
        <li>Nastavení → Aplikace a konektory → Pokročilé → zapnout <em>Režim vývojáře</em></li>
        <li>Vytvořit → adresa výše, ověřování OAuth</li>
        <li>Přihlásit se sem a povolit</li>
      </ol>
    </div>
    <div class="card card-pad-sm small stack" style="gap: 6px; background: var(--bg-tint); border: 0">
      <b>Claude Code a jiné nástroje</b>
      <ol style="margin: 0; padding-left: 18px">
        <li><code class="mono">claude mcp add --transport http brand-hub <?= e($url) ?></code>, pak <code class="mono">/mcp</code> a přihlásit</li>
        <li>nebo níže vytvořit ruční token a poslat ho v hlavičce <code class="mono">Authorization: Bearer …</code></li>
      </ol>
    </div>
  </div>
</section>

<section class="card card-pad stack" style="max-width: 920px" id="kontrola">
  <div class="row-between">
    <div>
      <h2>Kontrola serveru</h2>
      <p class="muted small">Ověřím, že AI aplikace najdou přihlášení a že hosting předává přihlašovací token až do aplikace. Spusťte po první aktualizaci na tuto verzi.</p>
    </div>
    <a class="btn btn-ghost" href="/admin/settings/ai?kontrola=1#kontrola"><?= icon('refresh', 18) ?>Zkontrolovat</a>
  </div>
  <?php if ($diagnostics !== null): ?>
    <div class="table-wrap">
      <table class="table">
        <tbody>
        <?php foreach ($diagnostics as [$name, $ok, $text]): ?>
          <tr>
            <td class="nowrap"><?= $ok ? '<span class="tag tag-ok">' . icon('check', 14) . 'OK</span>' : '<span class="tag tag-warn">' . icon('alert', 14) . 'Chyba</span>' ?></td>
            <td class="nowrap"><b><?= e($name) ?></b></td>
            <td class="small"><?= e($text) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<form method="post" action="/admin/settings/ai" class="card card-pad stack" style="max-width: 920px">
  <?= csrf_field() ?>
  <h2>Co AI smí</h2>
  <label class="check"><input type="checkbox" name="enabled" value="1"<?= $enabled ? ' checked' : '' ?>><span><b>Konektor zapnutý</b> – vypnutím okamžitě zastavím všechna připojení i dočasné odkazy na soubory.</span></label>
  <?php foreach ($groups as $scope => $groupTitle): ?>
    <h3><?= e($groupTitle) ?></h3>
    <div class="form-grid" style="gap: 4px 20px">
      <?php foreach ($tools as $name => $t): if ($t[2] !== $scope) { continue; } ?>
        <label class="check"><input type="checkbox" name="tool[<?= e($name) ?>]" value="1"<?= mcp_tool_enabled($name) ? ' checked' : '' ?>>
          <span><b><?= e($t[0]) ?></b> <span class="tiny muted mono"><?= e($name) ?></span></span></label>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <p class="small muted">Přes konektor se nic nemaže. Nahrání do pozice založí novou verzi a předchozí zůstane v historii pozice.</p>
  <div class="row"><button class="btn btn-primary">Uložit</button></div>
</form>

<section class="card card-pad stack" style="max-width: 920px" id="pripojeni">
  <h2>Připojené aplikace</h2>
  <?php if (!$connections): ?>
    <p class="muted small">Zatím není připojená žádná aplikace.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table" style="min-width: 640px">
        <thead><tr><th scope="col">Aplikace</th><th scope="col">Oprávnění</th><th scope="col">Naposledy</th><th scope="col">Volání</th><th scope="col"><span class="sr-only">Akce</span></th></tr></thead>
        <tbody>
        <?php foreach ($connections as $c): ?>
          <tr>
            <td><b><?= e($c['client_name'] ?: $c['name'] ?: '–') ?></b><br><span class="tiny muted"><?= e($kindLabel($c)) ?>, od <?= e(cz_date($c['created_at'])) ?></span></td>
            <td class="small"><?= e($scopeLabel((string) $c['scope'])) ?></td>
            <td class="small nowrap"><?= e($c['last_used_at'] ? relative_day($c['last_used_at']) : '–') ?></td>
            <td class="small"><?= cz_num($c['calls']) ?></td>
            <td style="text-align: right">
              <form method="post" action="/admin/settings/ai/disconnect" data-confirm="Odpojit „<?= e($c['client_name'] ?: $c['name']) ?>“? Aplikace se k Brand Hubu dostane až po novém připojení.">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="btn btn-danger btn-sm">Odpojit</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="card card-pad stack" style="max-width: 920px" id="tokeny">
  <h2>Ruční token</h2>
  <p class="small muted">Pro nástroje, které se neumí přihlásit samy (skripty, testy). Token platí do odpojení výše.</p>
  <?php if ($once): ?>
    <div class="alert alert-info" style="display: block">
      <p><b>Token „<?= e($once['name']) ?>“ – zkopírujte si ho hned, víckrát ho neuvidíte.</b></p>
      <p class="mono" style="overflow-wrap: anywhere; margin: 8px 0"><?= e($once['token']) ?></p>
      <button type="button" class="btn btn-ghost btn-sm" data-copy="<?= e($once['token']) ?>" data-copy-msg="Token je zkopírovaný"><?= icon('copy', 16) ?>Kopírovat</button>
    </div>
  <?php endif; ?>
  <form method="post" action="/admin/settings/ai/token" class="row" style="align-items: flex-end">
    <?= csrf_field() ?>
    <div class="field" style="flex: 1 1 260px"><label for="token-name">K čemu token je</label><input class="input" id="token-name" name="name" placeholder="např. Claude Code na notebooku" required maxlength="255"></div>
    <label class="check" style="padding-top: 0"><input type="checkbox" name="write" value="1"><span>I zápis</span></label>
    <button class="btn btn-ghost"><?= icon('plus', 18) ?>Vytvořit token</button>
  </form>
</section>

<section class="card card-pad stack" style="max-width: 920px">
  <div class="row-between">
    <h2>Poslední volání</h2>
    <span class="small muted">Dnes <?= cz_num($callsToday) ?> <?= cz_plural($callsToday, 'volání', 'volání', 'volání') ?></span>
  </div>
  <?php if (!$log): ?>
    <p class="muted small">Zatím žádná volání.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table" style="min-width: 720px">
        <thead><tr><th scope="col">Kdy</th><th scope="col">Aplikace</th><th scope="col">Nástroj</th><th scope="col">Výsledek</th></tr></thead>
        <tbody>
        <?php foreach ($log as $l): ?>
          <tr>
            <td class="small nowrap"><?= e(cz_date($l['created_at'], true)) ?></td>
            <td class="small"><?= e($l['app'] ?: '–') ?></td>
            <td class="small"><b><?= e($tools[$l['tool']][0] ?? $l['tool']) ?></b><br><span class="tiny muted mono" style="overflow-wrap: anywhere"><?= e(mb_substr((string) $l['args'], 0, 160)) ?></span></td>
            <td class="small"><?= $l['ok'] ? '<span class="tag tag-ok">OK</span>' : '<span class="tag tag-warn">Chyba</span><br><span class="tiny">' . e((string) $l['error']) . '</span>' ?> <span class="tiny muted"><?= (int) $l['ms'] ?> ms</span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php endif; ?>
