<?php
$active = $section;
require APP_ROOT . '/views/admin/_brand_header.php';
$bid = (int) $brand['id'];
?>

<?php if ($section === 'barvy'): ?>
  <section class="stack">
    <div class="row-between">
      <h2 style="font-size: 24px">Barvy</h2>
      <span class="small muted">RGB se dopočítá z HEX. CMYK a Pantone doplňte z podkladů pro tisk.</span>
    </div>
    <form method="post" action="/admin/brands/<?= $bid ?>/colors" class="stack">
      <?= csrf_field() ?>
      <div class="card table-wrap">
        <table class="table" style="min-width: 820px">
          <thead><tr><th scope="col">Barva</th><th scope="col">Název</th><th scope="col">HEX</th><th scope="col">CMYK</th><th scope="col">Pantone</th><th scope="col">Role</th><th scope="col">Pořadí</th><th scope="col">Smazat</th></tr></thead>
          <tbody data-colors-body>
          <?php
          $rows = $colors ?: [['id' => 'n0', 'name' => '', 'hex' => '', 'cmyk' => '', 'pantone' => '', 'role' => 'Hlavní', 'sort' => 10]];
          foreach ($rows as $n => $c):
              $k = 'c' . $n;
          ?>
            <tr>
              <td><span class="color-row-swatch" data-swatch style="background: <?= e($c['hex'] ?: '#ffffff') ?>"></span></td>
              <td><label class="sr-only" for="<?= $k ?>-name">Název</label><input class="input input-sm" id="<?= $k ?>-name" name="colors[<?= $k ?>][name]" value="<?= e($c['name']) ?>" style="width: 130px"></td>
              <td>
                <div class="color-input">
                  <input type="color" value="<?= e(strtolower($c['hex'] ?: '#ffffff')) ?>" data-pick-for="<?= $k ?>-hex" aria-label="Vybrat barvu">
                  <label class="sr-only" for="<?= $k ?>-hex">HEX</label><input class="input input-sm mono" id="<?= $k ?>-hex" name="colors[<?= $k ?>][hex]" value="<?= e($c['hex']) ?>" data-hex style="width: 100px" placeholder="#000000">
                </div>
              </td>
              <td><label class="sr-only" for="<?= $k ?>-cmyk">CMYK</label><input class="input input-sm" id="<?= $k ?>-cmyk" name="colors[<?= $k ?>][cmyk]" value="<?= e($c['cmyk']) ?>" style="width: 120px" placeholder="0 42 64 58"></td>
              <td><label class="sr-only" for="<?= $k ?>-pms">Pantone</label><input class="input input-sm" id="<?= $k ?>-pms" name="colors[<?= $k ?>][pantone]" value="<?= e($c['pantone']) ?>" style="width: 110px"></td>
              <td><label class="sr-only" for="<?= $k ?>-role">Role</label><input class="input input-sm" id="<?= $k ?>-role" name="colors[<?= $k ?>][role]" value="<?= e($c['role']) ?>" style="width: 110px" list="color-roles"></td>
              <td><label class="sr-only" for="<?= $k ?>-sort">Pořadí</label><input class="input input-sm" id="<?= $k ?>-sort" name="colors[<?= $k ?>][sort]" value="<?= (int) $c['sort'] ?>" style="width: 70px" inputmode="numeric"></td>
              <td><label class="check" style="padding-top: 0; min-height: 0"><input type="checkbox" name="colors[<?= $k ?>][delete]" value="1"><span class="sr-only">Smazat</span></label></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <datalist id="color-roles"><option value="Hlavní"><option value="Doplňková"><option value="Akcent"><option value="Pozadí"><option value="Text"></datalist>
      <template id="color-row-template">
        <tr>
          <td><span class="color-row-swatch" data-swatch></span></td>
          <td><input class="input input-sm" name="colors[__i__][name]" aria-label="Název" style="width: 130px"></td>
          <td><div class="color-input"><input type="color" value="#ffffff" data-pick-for="__i__-hex" aria-label="Vybrat barvu"><input class="input input-sm mono" id="__i__-hex" name="colors[__i__][hex]" data-hex aria-label="HEX" style="width: 100px" placeholder="#000000"></div></td>
          <td><input class="input input-sm" name="colors[__i__][cmyk]" aria-label="CMYK" style="width: 120px"></td>
          <td><input class="input input-sm" name="colors[__i__][pantone]" aria-label="Pantone" style="width: 110px"></td>
          <td><input class="input input-sm" name="colors[__i__][role]" aria-label="Role" style="width: 110px" list="color-roles"></td>
          <td><input class="input input-sm" name="colors[__i__][sort]" aria-label="Pořadí" value="999" style="width: 70px"></td>
          <td></td>
        </tr>
      </template>
      <div class="row">
        <button type="button" class="btn btn-ghost" data-add-color><?= icon('plus', 18) ?>Přidat barvu</button>
        <button class="btn btn-primary">Uložit barvy</button>
      </div>
    </form>
  </section>

<?php else: ?>
  <section class="stack">
    <div class="row-between">
      <h2 style="font-size: 24px">Pozice v sekci <?= e(SECTIONS[$section]['title']) ?></h2>
      <span class="small muted">Prázdné pozice klient nevidí.</span>
    </div>

    <?php if (!$items): ?>
      <div class="card empty-state">V této sekci zatím nejsou žádné pozice. Přidejte vlastní níže, nebo je doplňte ze šablony v nastavení klienta.</div>
    <?php endif; ?>

    <?php foreach ($items as $idx => $it):
        $filled = (bool) $it['current_version_id'];
        $firstExt = $it['files'] ? file_ext($it['files'][0]['stored_name']) : null;
        $permalink = $firstExt ? brand_public_url($brand, '/f/' . $it['slug'] . '.' . ($firstExt === 'jpeg' ? 'jpg' : $firstExt)) : null;
    ?>
      <article class="card slot<?= $filled ? '' : ' empty' ?>" id="item-<?= (int) $it['id'] ?>">
        <div class="slot-thumb" style="<?= $it['preview_bg'] ? 'background:' . e($it['preview_bg']) : '' ?>">
          <?php if ($it['kind'] === 'font' && $it['font_file']): ?>
            <style>@font-face { font-family: "bf-<?= (int) $it['id'] ?>"; src: url("/admin/files/<?= (int) $it['font_file']['id'] ?>?inline=1") format("<?= font_format(file_ext($it['font_file']['stored_name'])) ?>"); }</style>
            <span style="font-family: 'bf-<?= (int) $it['id'] ?>'; font-size: 40px; color: var(--ink)">Aa</span>
          <?php elseif ($it['preview']): ?>
            <img src="/admin/files/<?= (int) $it['preview']['id'] ?>?inline=1" alt="">
          <?php elseif ($filled): ?>
            <?= icon('file', 32) ?>
          <?php else: ?>
            <?= icon('upload', 28) ?>
          <?php endif; ?>
        </div>
        <div class="slot-body">
          <div class="row" style="gap: 8px">
            <b><?= e($it['title']) ?></b>
            <?php if (!$filled): ?>
              <span class="tag <?= $it['required'] ? 'tag-warn' : 'tag-plain' ?>"><?= $it['required'] ? 'povinné · prázdné' : 'volitelné · prázdné' ?></span>
            <?php else: ?>
              <span class="tag"><?= $it['required'] ? 'povinné' : 'volitelné' ?></span>
            <?php endif; ?>
            <?php if ($it['specs']): ?><span class="tiny muted"><?= e($it['specs']) ?></span><?php endif; ?>
          </div>
          <?php if ($filled): ?>
            <div class="file-chips">
              <?php foreach ($it['all_files'] as $f): ?>
                <span class="tag <?= $f['role'] === 'preview' ? 'tag-plain' : 'tag-ok' ?>"><?= icon($f['role'] === 'preview' ? 'eye' : 'check', 12) ?><?= e($f['label']) ?><?= $f['role'] === 'preview' ? ' · náhled' : '' ?></span>
              <?php endforeach; ?>
            </div>
            <?php if ($permalink): ?>
              <div class="tiny muted break">Trvalý odkaz: <?= e($permalink) ?>
                <button type="button" class="link-btn" data-copy="<?= e($permalink) ?>" data-copy-msg="Trvalý odkaz je zkopírovaný">kopírovat</button>
              </div>
            <?php endif; ?>
            <div class="tiny muted">Verze <?= (int) $it['version_no'] ?>, <?= cz_date($it['version_at']) ?><?= $it['version_note'] ? ' · ' . e($it['version_note']) : '' ?> · <?= human_size($it['size_total']) ?></div>
          <?php else: ?>
            <div class="small muted"><?= e($it['description'] ?: 'Nahrajte soubory. Pozice se klientovi zobrazí hned po nahrání.') ?></div>
          <?php endif; ?>
        </div>
        <div class="slot-actions">
          <button type="button" class="btn <?= $filled ? 'btn-ghost' : 'btn-secondary' ?> btn-sm" data-toggle="up-<?= (int) $it['id'] ?>" aria-expanded="false" aria-controls="up-<?= (int) $it['id'] ?>"><?= icon('upload', 16) ?><?= $filled ? 'Nahrát' : 'Nahrát soubory' ?></button>
          <a class="btn btn-ghost btn-sm" href="/admin/items/<?= (int) $it['id'] ?>"><?= icon($filled ? 'history' : 'edit', 16) ?><?= $filled ? 'Detail a historie' : 'Upravit' ?></a>
          <form method="post" action="/admin/items/<?= (int) $it['id'] ?>/move" class="inline"><?= csrf_field() ?><input type="hidden" name="dir" value="up"><button class="btn btn-ghost btn-icon" aria-label="Posunout výš"<?= $idx === 0 ? ' disabled' : '' ?>><?= icon('up', 16) ?></button></form>
          <form method="post" action="/admin/items/<?= (int) $it['id'] ?>/move" class="inline"><?= csrf_field() ?><input type="hidden" name="dir" value="down"><button class="btn btn-ghost btn-icon" aria-label="Posunout níž"<?= $idx === count($items) - 1 ? ' disabled' : '' ?>><?= icon('down', 16) ?></button></form>
        </div>

        <div class="uploader" id="up-<?= (int) $it['id'] ?>" data-uploader data-item="<?= (int) $it['id'] ?>">
          <div class="drop" data-drop tabindex="0" role="button" aria-label="Vybrat soubory k nahrání">
            <?= icon('upload', 24) ?><br>
            Přetáhněte sem soubory, nebo klikněte a vyberte je.<br>
            <span class="tiny"><?= $it['kind'] === 'font' ? 'Fonty WOFF2, WOFF, TTF, OTF (i více řezů najednou).' : 'Např. SVG, PNG, PDF, AI, ZIP. U PDF můžete přidat obrázek jako „Jen náhled“.' ?> Velké soubory se nahrávají po částech.</span>
            <input type="file" multiple hidden>
          </div>
          <div class="queue" data-queue></div>
          <div class="form-grid">
            <div class="field"><label for="note-<?= (int) $it['id'] ?>">Poznámka k verzi (vidíte jen vy)</label><input class="input" id="note-<?= (int) $it['id'] ?>" data-note placeholder="např. upravená kresba klasu"></div>
            <?php if ($filled): ?>
              <fieldset class="field" style="border: 0; padding: 0; margin: 0">
                <legend class="label">Jak uložit</legend>
                <label class="check" style="padding-top: 4px; min-height: 0"><input type="radio" name="mode-<?= (int) $it['id'] ?>" value="new" checked><span>Jako novou verzi <span class="hint">(stávající soubory půjdou do historie)</span></span></label>
                <label class="check" style="padding-top: 4px; min-height: 0"><input type="radio" name="mode-<?= (int) $it['id'] ?>" value="append"><span>Přidat k aktuální verzi <span class="hint">(např. chybějící formát)</span></span></label>
              </fieldset>
            <?php endif; ?>
          </div>
          <div class="row">
            <button type="button" class="btn btn-primary" data-start disabled><?= icon('upload', 18) ?>Nahrát a zveřejnit</button>
            <button type="button" class="btn btn-ghost" data-toggle="up-<?= (int) $it['id'] ?>">Zavřít</button>
          </div>
          <p data-status class="small" aria-live="polite"></p>
        </div>
      </article>
    <?php endforeach; ?>

    <form method="post" action="/admin/brands/<?= $bid ?>/items" class="card card-pad-sm row" style="border-style: dashed">
      <?= csrf_field() ?>
      <input type="hidden" name="section" value="<?= e($section) ?>">
      <label for="new-title" class="label">Přidat vlastní pozici</label>
      <input class="input" id="new-title" name="title" placeholder="např. Logo pro výšivku" style="flex: 1 1 220px; width: auto" required>
      <button class="btn btn-ghost"><?= icon('plus', 18) ?>Přidat</button>
    </form>
  </section>
<?php endif; ?>
