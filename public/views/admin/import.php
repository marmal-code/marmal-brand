<div class="breadcrumb"><a href="/admin">Klienti</a> / Import</div>
<h1>Import klienta ze ZIPu</h1>
<p style="max-width: 720px">Nahrajte importní balíček (ZIP se souborem <code>brand.json</code>). Aplikace z něj založí nového klienta i s barvami, texty sekcí, logy, písmy a dalšími soubory. Heslo pro klienta se ukáže po dokončení.</p>

<section class="card card-pad stack">
  <div class="uploader open" data-uploader data-item="import" data-commit="/admin/import" style="border-top: 0; padding-top: 0">
    <div class="drop" data-drop tabindex="0" role="button" aria-label="Vybrat importní balíček">
      <?= icon('upload', 24) ?><br>
      Přetáhněte sem importní balíček, nebo klikněte a vyberte ho.<br>
      <span class="tiny">Jeden soubor .zip. Velké balíčky se nahrávají po částech.</span>
      <input type="file" accept=".zip,application/zip" hidden>
    </div>
    <div class="queue" data-queue></div>
    <div class="row">
      <button type="button" class="btn btn-primary" data-start disabled><?= icon('upload', 18) ?>Importovat</button>
    </div>
    <p data-status class="small" aria-live="polite" style="white-space: pre-line"></p>
  </div>
</section>

<section class="card card-pad stack">
  <h2>Co balíček obsahuje</h2>
  <ul class="small" style="margin: 0; padding-left: 20px">
    <li><b>brand.json</b> – název klienta, barvy, texty sekcí a seznam pozic se soubory</li>
    <li>soubory na cestách, které <b>brand.json</b> uvádí (např. <code>loga/logo.svg</code>)</li>
  </ul>
  <p class="small muted">Stejný formát vytvoří tlačítko „Exportovat brand (ZIP)“ v nastavení klienta. Hodí se jako záloha souborů klienta nebo pro přenos na jiný server. Přesný popis formátu je v repozitáři v souboru <code>docs/IMPORT.md</code>.</p>
</section>
