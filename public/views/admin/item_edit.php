<?php
$active = $item['section'];
require APP_ROOT . '/views/admin/_brand_header.php';
$m = $item['meta'];
?>
<div class="breadcrumb"><a href="/admin/brands/<?= (int) $brand['id'] ?>/s/<?= e($item['section']) ?>"><?= icon('arrow-left', 16) ?> Zpět na sekci <?= e(SECTIONS[$item['section']]['title'] ?? '') ?></a></div>

<div class="row" style="align-items: flex-start; gap: 32px">
  <form method="post" action="/admin/items/<?= (int) $item['id'] ?>" class="card card-pad stack" style="flex: 999 1 480px; min-width: 0">
    <?= csrf_field() ?>
    <h2><?= e($item['title']) ?></h2>
    <div class="form-grid">
      <div class="field"><label for="title">Název pozice</label><input class="input" id="title" name="title" value="<?= e($item['title']) ?>" required></div>
      <div class="field">
        <label for="slug">Adresa v trvalém odkazu</label>
        <input class="input mono" id="slug" name="slug" value="<?= e($item['slug']) ?>" pattern="[a-z0-9-]+">
        <span class="hint">…/f/<b><?= e($item['slug']) ?></b>.svg – změna rozbije už vložené odkazy.</span>
      </div>
      <div class="field full"><label for="description">Popis pro klienta</label><textarea class="textarea" id="description" name="description"><?= e($item['description']) ?></textarea></div>
      <div class="field"><label for="specs">Rozměr / specifikace</label><input class="input" id="specs" name="specs" value="<?= e($item['specs']) ?>" placeholder="90 × 50 mm"></div>
      <div class="field"><label for="tags">Štítky (oddělené čárkou)</label><input class="input" id="tags" name="tags" value="<?= e($item['tags']) ?>" placeholder="CMYK, spadávka 3 mm"></div>
      <div class="field">
        <label for="preview_bg">Pozadí náhledu</label>
        <div class="color-input">
          <input type="color" value="<?= e(strtolower($item['preview_bg'] ?: '#ffffff')) ?>" data-pick-for="preview_bg" aria-label="Vybrat barvu">
          <input class="input mono" id="preview_bg" name="preview_bg" value="<?= e($item['preview_bg']) ?>" data-hex placeholder="#FFFFFF" maxlength="7">
        </div>
        <span class="hint">Např. tmavé pro negativ loga.</span>
      </div>
      <div class="field"><span class="label">Povinnost</span><label class="check"><input type="checkbox" name="required" value="1"<?= $item['required'] ? ' checked' : '' ?>><span>Povinná pozice (bez ní je sekce neúplná)</span></label></div>
    </div>
    <?php if ($item['kind'] === 'font'): ?>
      <h3>Písmo</h3>
      <div class="form-grid">
        <div class="field"><label for="font_family">Název písma</label><input class="input" id="font_family" name="font_family" value="<?= e($m['family'] ?? '') ?>" placeholder="Fraunces"></div>
        <div class="field"><label for="font_weights">Řezy</label><input class="input" id="font_weights" name="font_weights" value="<?= e($m['weights'] ?? '') ?>" placeholder="Regular 400, SemiBold 600"></div>
        <div class="field full"><label for="font_sample">Ukázkový text</label><input class="input" id="font_sample" name="font_sample" value="<?= e($m['sample'] ?? '') ?>" placeholder="Příliš žluťoučký kůň úpěl ďábelské ódy"></div>
        <div class="field"><label for="font_google_url">Odkaz na Google Fonts</label><input class="input" type="url" id="font_google_url" name="font_google_url" value="<?= e($m['google_url'] ?? '') ?>"></div>
        <div class="field"><label for="font_license">Licence</label><input class="input" id="font_license" name="font_license" value="<?= e($m['license'] ?? '') ?>" placeholder="OFL – volně k použití"></div>
      </div>
    <?php endif; ?>
    <div class="row"><button class="btn btn-primary">Uložit pozici</button></div>
  </form>

  <section class="stack" style="flex: 1 1 320px; max-width: 460px">
    <h2 style="font-size: 22px">Historie verzí</h2>
    <?php if (!$versions): ?>
      <p class="muted small">Zatím nebyly nahrané žádné soubory.</p>
    <?php endif; ?>
    <?php foreach ($versions as $v): $isCurrent = (int) $v['id'] === (int) $item['current_version_id']; ?>
      <div class="history-item<?= $isCurrent ? ' current' : '' ?>">
        <div class="stack" style="gap: 6px; min-width: 0">
          <div><b>v<?= (int) $v['version'] ?></b> · <?= cz_date($v['created_at'], true) ?><?= $isCurrent ? ' · <span style="color: var(--success); font-weight: 500">aktuální</span>' : '' ?></div>
          <?php if ($v['note']): ?><div class="small"><?= e($v['note']) ?></div><?php endif; ?>
          <ul class="list-plain small stack" style="gap: 4px">
            <?php foreach ($v['files'] as $f): ?>
              <li class="row" style="gap: 8px">
                <a href="/admin/files/<?= (int) $f['id'] ?>" class="break"><?= e($f['original_name']) ?></a>
                <span class="tiny muted"><?= e($f['label']) ?><?= $f['role'] === 'preview' ? ' · náhled' : '' ?> · <?= human_size((int) $f['size']) ?></span>
                <form method="post" action="/admin/files/<?= (int) $f['id'] ?>/delete" class="inline" data-confirm="Smazat soubor <?= e($f['original_name']) ?>?"><?= csrf_field() ?><button class="link-btn danger tiny">smazat</button></form>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php if (!$isCurrent): ?>
          <div class="stack" style="gap: 6px; flex: 0 0 auto">
            <form method="post" action="/admin/versions/<?= (int) $v['id'] ?>/activate"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Obnovit</button></form>
            <form method="post" action="/admin/versions/<?= (int) $v['id'] ?>/delete" data-confirm="Smazat verzi <?= (int) $v['version'] ?> i její soubory?"><?= csrf_field() ?><button class="btn btn-danger btn-sm">Smazat</button></form>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <p class="tiny muted">Staré verze vidíte jen vy. Klient a trvalé odkazy dostávají vždy aktuální verzi.</p>

    <form method="post" action="/admin/items/<?= (int) $item['id'] ?>/delete" data-confirm="Smazat pozici „<?= e($item['title']) ?>“ včetně všech verzí a souborů?" style="margin-top: 16px">
      <?= csrf_field() ?>
      <button class="btn btn-danger"><?= icon('trash', 18) ?>Smazat celou pozici</button>
    </form>
  </section>
</div>
