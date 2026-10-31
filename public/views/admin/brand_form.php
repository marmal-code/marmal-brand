<?php
$isNew = $brand === null;
$b = $brand ?? ['name' => old('name'), 'slug' => old('slug'), 'intro' => old('intro'), 'contact_name' => old('contact_name', 'Martin Malý · MarMal.cz'), 'contact_note' => old('contact_note', 'Potřebujete nový podklad? Ozvu se do 2 pracovních dnů.'), 'contact_phone' => old('contact_phone', DEFAULT_CONTACT_PHONE), 'contact_email' => old('contact_email', DEFAULT_CONTACT_EMAIL), 'hero_bg' => old('hero_bg', '#EAF2FA'), 'custom_domain' => old('custom_domain'), 'public_links' => 1, 'brand_fonts' => 1, 'share_enabled' => 0, 'is_active' => 1];
$action = $isNew ? '/admin/brands/new' : '/admin/brands/' . (int) $b['id'] . '/settings';
if (!$isNew) {
    $active = 'settings';
    require APP_ROOT . '/views/admin/_brand_header.php';
} else {
    echo '<div class="breadcrumb"><a href="/admin">Klienti</a> / Nový klient</div><h1>Nový klient</h1>';
}
$shareUrl = !$isNew && $b['share_token'] ? brand_public_url($b, '?s=' . $b['share_token']) : '';
?>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-error" role="alert"><?= icon('alert') ?><div><?= e($err) ?></div></div>
<?php endforeach; ?>

<form method="post" action="<?= e($action) ?>" class="stack-lg">
  <?= csrf_field() ?>

  <section class="card card-pad stack">
    <h2>Základní údaje</h2>
    <div class="form-grid">
      <div class="field"><label for="name">Název klienta</label><input class="input" id="name" name="name" value="<?= e($b['name']) ?>" required></div>
      <div class="field">
        <label for="slug">Adresa v aplikaci</label>
        <input class="input" id="slug" name="slug" value="<?= e($b['slug']) ?>" pattern="[a-z0-9-]*" placeholder="<?= $isNew ? 'vytvoří se z názvu' : '' ?>">
        <span class="hint"><?= e(config('app_host')) ?>/b/<b><?= e($b['slug'] ?: 'nazev-klienta') ?></b><?= $isNew ? '' : ' · změna adresy zneplatní dosavadní odkazy' ?></span>
      </div>
      <div class="field full"><label for="intro">Úvodní text pro klienta</label><textarea class="textarea" id="intro" name="intro" placeholder="Soubory jsou tu vždy v aktuální verzi. Když něco upravím, starý soubor se automaticky nahradí."><?= e($b['intro']) ?></textarea></div>
      <div class="field">
        <label for="hero_bg">Barva úvodní plochy</label>
        <div class="color-input">
          <input type="color" value="<?= e(strtolower($b['hero_bg'])) ?>" data-pick-for="hero_bg" aria-label="Vybrat barvu">
          <input class="input" id="hero_bg" name="hero_bg" value="<?= e($b['hero_bg']) ?>" data-hex maxlength="7">
        </div>
        <span class="hint">Podklad pod logem na úvodní stránce klienta.</span>
      </div>
      <div class="field"><label for="contact_name">Kontakt pro klienta</label><input class="input" id="contact_name" name="contact_name" value="<?= e($b['contact_name']) ?>"></div>
      <div class="field full"><label for="contact_note">Poznámka ke kontaktu</label><input class="input" id="contact_note" name="contact_note" value="<?= e($b['contact_note']) ?>"></div>
      <div class="field"><label for="contact_phone">Telefon</label><input class="input" id="contact_phone" name="contact_phone" type="tel" value="<?= e($b['contact_phone'] ?? '') ?>" placeholder="<?= e(DEFAULT_CONTACT_PHONE) ?>"></div>
      <div class="field"><label for="contact_email">E-mail</label><input class="input" id="contact_email" name="contact_email" type="email" value="<?= e($b['contact_email'] ?? '') ?>" placeholder="<?= e(DEFAULT_CONTACT_EMAIL) ?>"></div>
    </div>
  </section>

  <section class="card card-pad stack">
    <h2>Přístup klienta</h2>
    <div class="form-grid">
      <div class="field">
        <label for="password"><?= $isNew ? 'Heslo klienta' : 'Nové heslo klienta' ?></label>
        <input class="input" type="text" id="password" name="password" value="<?= $isNew ? e($suggestedPassword) : '' ?>" autocomplete="off" placeholder="<?= $isNew ? '' : 'nechte prázdné, pokud se nemění' ?>" <?= $isNew ? 'required' : '' ?>>
        <span class="hint"><?= $isNew ? 'Heslo pošlete klientovi. Uloží se zašifrovaně, později ho uvidíte jen tak, že nastavíte nové.' : 'Návrh: ' . e($suggestedPassword) . '. Po změně se klient musí přihlásit znovu.' ?></span>
      </div>
      <div class="field">
        <span class="label">Sdílený odkaz bez hesla</span>
        <label class="check"><input type="checkbox" name="share_enabled" value="1"<?= $b['share_enabled'] ? ' checked' : '' ?>><span>Povolit odkaz pro tiskárnu nebo externího grafika</span></label>
      </div>
      <div class="field">
        <label for="share_days">Platnost sdíleného odkazu</label>
        <select class="select" id="share_days" name="share_days">
          <?php if (!$isNew): ?><option value="-1">Ponechat současnou</option><?php endif; ?>
          <?php foreach ([30 => '30 dní', 7 => '7 dní', 90 => '90 dní', 365 => '1 rok', 0 => 'Bez omezení'] as $d => $l): ?>
            <option value="<?= $d ?>"><?= $l ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint"><?php if (!$isNew && $b['share_enabled']): ?>Platí <?= $b['share_expires_at'] ? 'do ' . cz_date($b['share_expires_at']) : 'bez omezení' ?>. Novou platnost lze nastavit od dneška.<?php else: ?>Počítá se ode dne uložení.<?php endif; ?></span>
      </div>
      <?php if ($shareUrl): ?>
        <div class="field full">
          <span class="label">Sdílený odkaz</span>
          <div class="row" style="flex-wrap: nowrap">
            <code class="input mono break" style="display:flex; align-items:center; background: var(--bg-tint); border-color: var(--bg-tint)"><?= e($shareUrl) ?></code>
            <button type="button" class="btn btn-ghost" data-copy="<?= e($shareUrl) ?>" data-copy-msg="Sdílený odkaz je zkopírovaný"><?= icon('copy', 18) ?>Kopírovat</button>
          </div>
          <?php if (!share_link_valid($b)): ?><span class="hint" style="color: var(--error)">Odkaz je teď vypnutý nebo vypršel.</span><?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="field full">
        <label class="check"><input type="checkbox" name="public_links" value="1"<?= $b['public_links'] ? ' checked' : '' ?>><span><b>Veřejné trvalé odkazy na soubory</b><br><span class="hint">Odkazy typu …/f/logo-primarni.svg půjde vložit do webu nebo e-mailového podpisu bez přihlášení.</span></span></label>
      </div>
      <div class="field full">
        <label class="check"><input type="checkbox" name="brand_fonts" value="1"<?= !empty($b['brand_fonts']) ? ' checked' : '' ?>><span><b>Zobrazovat manuál v písmech brandu</b><br><span class="hint">Klient uvidí nadpisy a texty v písmech z pozic „Písmo pro nadpisy“ a „Písmo pro text“. Vypněte u ozdobných písem, která se na tlačítka a navigaci nehodí.</span></span></label>
      </div>
    </div>
  </section>

  <section class="card card-pad stack">
    <h2>Vlastní doména</h2>
    <div class="form-grid">
      <div class="field">
        <label for="custom_domain">Doména klienta</label>
        <input class="input" id="custom_domain" name="custom_domain" value="<?= e($b['custom_domain']) ?>" placeholder="brand.klient.cz">
      </div>
      <div class="field full hint">
        U klienta v DNS nastavte záznam <b>CNAME</b> z této domény na <b><?= e(config('app_host')) ?></b> a na hostingu přidejte doménu jako alias webu
        <?= e(config('app_host')) ?> (včetně HTTPS certifikátu). Poté se na ní zobrazí klientský pohled.
      </div>
    </div>
  </section>

  <?php if (!$isNew): ?>
    <section class="card card-pad stack">
      <label class="check"><input type="checkbox" name="is_active" value="1"<?= $b['is_active'] ? ' checked' : '' ?>><span><b>Brand je aktivní</b><br><span class="hint">Neaktivní brand klient neuvidí, vy ano.</span></span></label>
    </section>
  <?php endif; ?>

  <div class="row">
    <button class="btn btn-primary"><?= $isNew ? 'Založit klienta' : 'Uložit nastavení' ?></button>
    <a class="btn btn-ghost" href="<?= $isNew ? '/admin' : '/admin/brands/' . (int) $b['id'] . '/s/logo' ?>">Zpět</a>
  </div>
</form>

<?php if (!$isNew): ?>
  <section class="card card-pad stack">
    <h2>Další akce</h2>
    <div class="row">
      <form method="post" action="/admin/brands/<?= (int) $b['id'] ?>/share" class="inline" data-confirm="Starý sdílený odkaz přestane fungovat. Pokračovat?"><?= csrf_field() ?><button class="btn btn-ghost"><?= icon('refresh', 18) ?>Vytvořit nový sdílený odkaz</button></form>
      <form method="post" action="/admin/brands/<?= (int) $b['id'] ?>/sync" class="inline"><?= csrf_field() ?><input type="hidden" name="back" value="/admin/brands/<?= (int) $b['id'] ?>/settings"><button class="btn btn-ghost"><?= icon('grid', 18) ?>Doplnit chybějící pozice ze šablony</button></form>
      <a class="btn btn-ghost" href="/admin/brands/<?= (int) $b['id'] ?>/export"><?= icon('download', 18) ?>Exportovat brand (ZIP)</a>
    </div>
  </section>
  <section class="card card-pad stack danger-zone" id="smazat">
    <h2>Smazat klienta</h2>
    <p class="small">Smaže brand, všechny jeho soubory i historii verzí. Nejde to vrátit. Pokud si chcete klienta schovat, exportujte ho předtím (Exportovat brand výše).</p>
    <form method="post" action="/admin/brands/<?= (int) $b['id'] ?>/delete" class="row" data-confirm="Opravdu smazat klienta <?= e($b['name']) ?> včetně všech souborů?">
      <?= csrf_field() ?>
      <label class="sr-only" for="confirm">Pro potvrzení opište adresu</label>
      <input class="input" id="confirm" name="confirm" placeholder="opište: <?= e($b['slug']) ?>" style="max-width: 260px" autocomplete="off">
      <button class="btn btn-danger"><?= icon('trash', 18) ?>Smazat natrvalo</button>
    </form>
  </section>
<?php endif; ?>
