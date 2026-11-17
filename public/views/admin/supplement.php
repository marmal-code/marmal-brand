<?php
/** @var array $brand @var array|null $plan @var string|null $token */
$active = '';
require APP_ROOT . '/views/admin/_brand_header.php';
$bid = (int) $brand['id'];
$sectionTitle = fn (string $s) => SECTIONS[$s]['title'] ?? $s;
$itemActions = [
    'create'  => ['Nová pozice', 'tag-ok'],
    'fill'    => ['Vyplní prázdnou pozici', 'tag-ok'],
    'version' => ['Nová verze, stará do historie', 'tag-plain'],
    'append'  => ['Přidá k aktuální verzi', 'tag-plain'],
    'meta'    => ['Jen úprava popisu', 'tag-plain'],
];
$colorActions = ['create' => ['Nová barva', 'tag-ok'], 'update' => ['Úprava barvy', 'tag-plain']];
$textActions = [
    'create'  => ['Nový text', 'tag-ok'],
    'replace' => ['Nahradí stávající text', 'tag-warn'],
    'append'  => ['Doplní na konec textu', 'tag-plain'],
    'delete'  => ['Smaže text', 'tag-warn'],
];
?>

<?php if (!$plan): ?>
  <section class="card card-pad stack">
    <div>
      <h2 style="font-size: 24px">Doplnit z balíčku</h2>
      <p class="small muted" style="max-width: 720px">Nahrajte ZIP s doplněním (stejný formát jako import, soubor <code>brand.json</code> + soubory). Nejdřív vám ukážu, co přesně se doplní, a teprve po potvrzení klienta změním. Nic se nemaže: pozice se stejnou adresou dostane novou verzi a stará zůstane v historii.</p>
    </div>
    <div class="uploader open" data-uploader data-item="doplneni" data-commit="/admin/brands/<?= $bid ?>/doplnit" data-commit-status="Kontroluji balíček…" style="border-top: 0; padding-top: 0">
      <div class="drop" data-drop tabindex="0" role="button" aria-label="Vybrat balíček s doplněním">
        <?= icon('upload', 24) ?><br>
        Přetáhněte sem balíček s doplněním, nebo klikněte a vyberte ho.<br>
        <span class="tiny">Jeden soubor .zip. Velké balíčky se nahrávají po částech.</span>
        <input type="file" accept=".zip,application/zip" hidden>
      </div>
      <div class="queue" data-queue></div>
      <div class="row">
        <button type="button" class="btn btn-primary" data-start disabled><?= icon('search', 18) ?>Zkontrolovat balíček</button>
      </div>
      <p data-status class="small" aria-live="polite" style="white-space: pre-line"></p>
    </div>
  </section>

  <section class="card card-pad stack">
    <h2>Co doplnění umí</h2>
    <ul class="small" style="margin: 0; padding-left: 20px">
      <li><b>Nové pozice</b> v libovolné sekci (nová adresa pozice = nová pozice na konci sekce).</li>
      <li><b>Nové verze</b> stávajících pozic (stejná adresa), stará verze zůstane v historii. Případně soubory jen <b>přidám k aktuální verzi</b>.</li>
      <li><b>Barvy</b>: barvu se stejným názvem upravím, jinou přidám na konec palety.</li>
      <li><b>Texty sekcí</b>: nahradím, nebo doplním na konec stávajícího textu.</li>
      <li>Nastavení klienta (název, heslo, kontakt) doplnění nemění.</li>
    </ul>
    <p class="small muted">Balíček se připravuje stejně jako import (<code>docs/IMPORT-PRAVIDLA.md</code>), popis formátu je v <code>docs/IMPORT.md</code>, část Doplnění existujícího klienta.</p>
  </section>

<?php else: ?>
  <section class="card card-pad stack">
    <div>
      <h2 style="font-size: 24px">Co se doplní</h2>
      <p class="small muted">Balíček je zkontrolovaný. Projděte si změny a potvrďte je. Dokud nepotvrdíte, u klienta se nic nezmění.</p>
    </div>

    <?php foreach ($plan['warnings'] as $w): ?>
      <div class="alert alert-error" role="alert"><?= icon('alert', 20) ?><div><?= e($w) ?></div></div>
    <?php endforeach; ?>

    <?php if ($plan['items']): ?>
      <h3>Pozice a soubory (<?= count($plan['items']) ?>)</h3>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Sekce</th><th>Pozice</th><th>Soubory</th><th>Co se stane</th></tr></thead>
          <tbody>
            <?php foreach ($plan['items'] as $it): [$label, $cls] = $itemActions[$it['action']]; ?>
              <tr>
                <td><?= e($sectionTitle($it['section'])) ?></td>
                <td><b><?= e($it['title']) ?></b><br><span class="tiny muted"><?= e($it['slug']) ?></span></td>
                <td><?= (int) $it['files'] ?></td>
                <td>
                  <span class="tag <?= $cls ?>"><?= e($label) ?></span>
                  <?php if ($it['from_section']): ?><br><span class="tiny muted">přesun ze sekce <?= e($sectionTitle($it['from_section'])) ?></span><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($plan['colors']): ?>
      <h3>Barvy (<?= count($plan['colors']) ?>)</h3>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Barva</th><th>HEX</th><th>Co se stane</th></tr></thead>
          <tbody>
            <?php foreach ($plan['colors'] as $c): [$label, $cls] = $colorActions[$c['action']]; ?>
              <tr>
                <td><span class="swatch-dot" style="display: inline-block; width: 16px; height: 16px; border-radius: 4px; border: 1px solid var(--border); vertical-align: -3px; background: <?= e((string) $c['hex']) ?>"></span> <?= e($c['name']) ?></td>
                <td><?= e((string) $c['hex']) ?><?php if ($c['old_hex']): ?> <span class="tiny muted">(dřív <?= e($c['old_hex']) ?>)</span><?php endif; ?></td>
                <td><span class="tag <?= $cls ?>"><?= e($label) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($plan['texts']): ?>
      <h3>Texty sekcí (<?= count($plan['texts']) ?>)</h3>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Sekce</th><th>Co se stane</th></tr></thead>
          <tbody>
            <?php foreach ($plan['texts'] as $t): [$label, $cls] = $textActions[$t['action']]; ?>
              <tr><td><?= e($sectionTitle($t['section'])) ?></td><td><span class="tag <?= $cls ?>"><?= e($label) ?></span></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <form method="post" action="/admin/brands/<?= $bid ?>/doplnit/<?= e($token) ?>" class="row">
      <?= csrf_field() ?>
      <button class="btn btn-primary" name="action" value="run" data-busy="Doplňuji… může to chvíli trvat."><?= icon('check', 18) ?>Doplnit do klienta</button>
      <button class="btn btn-ghost" name="action" value="cancel">Zrušit</button>
    </form>
  </section>
<?php endif; ?>
