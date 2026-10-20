<?php $tab = 'template'; require APP_ROOT . '/views/admin/_settings_tabs.php'; ?>
<div class="alert alert-info"><?= icon('grid') ?><div>Šablona určuje, jaké pozice dostane každý nový klient. Stávajícím klientům je doplníte tlačítkem „Doplnit chybějící pozice ze šablony“ v jejich nastavení. Barvy se zadávají přímo u klienta.</div></div>

<form method="post" action="/admin/template" class="stack-lg">
  <?= csrf_field() ?>
  <?php foreach (SECTIONS as $key => $s): if ($key === 'barvy') continue; ?>
    <section class="card stack" id="s-<?= $key ?>" style="gap: 0">
      <div class="card-pad-sm row" style="gap: 10px; border-bottom: 1px solid var(--border)"><?= icon($s['icon'], 20, ' style="color: var(--blue-700)"') ?><h2 style="font-size: 20px"><?= e($s['title']) ?></h2></div>
      <?php if (empty($bySection[$key])): ?>
        <p class="card-pad-sm small muted">V této sekci šablona nemá žádné pozice.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table" style="min-width: 860px">
            <thead><tr><th scope="col">Název</th><th scope="col">Popis</th><th scope="col">Rozměr</th><th scope="col">Štítky</th><th scope="col">Povinná</th><th scope="col">Pořadí</th><th scope="col"></th></tr></thead>
            <tbody>
            <?php foreach ($bySection[$key] as $t): $p = 't[' . (int) $t['id'] . ']'; ?>
              <tr>
                <td><input class="input input-sm" name="<?= $p ?>[title]" value="<?= e($t['title']) ?>" aria-label="Název" style="min-width: 160px"></td>
                <td><input class="input input-sm" name="<?= $p ?>[description]" value="<?= e($t['description']) ?>" aria-label="Popis" style="min-width: 220px"></td>
                <td><input class="input input-sm" name="<?= $p ?>[specs]" value="<?= e($t['specs']) ?>" aria-label="Rozměr" style="width: 140px"></td>
                <td><input class="input input-sm" name="<?= $p ?>[tags]" value="<?= e($t['tags']) ?>" aria-label="Štítky" style="width: 160px"></td>
                <td><label class="check" style="padding-top: 0; min-height: 0"><input type="checkbox" name="<?= $p ?>[required]" value="1"<?= $t['required'] ? ' checked' : '' ?>><span class="sr-only">Povinná</span></label></td>
                <td><input class="input input-sm" name="<?= $p ?>[sort]" value="<?= (int) $t['sort'] ?>" aria-label="Pořadí" style="width: 70px"></td>
                <td><button class="link-btn danger small" formaction="/admin/template/<?= (int) $t['id'] ?>/delete" formmethod="post" onclick="return confirm('Odebrat pozici ze šablony?')">odebrat</button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
  <div class="row"><button class="btn btn-primary">Uložit šablonu</button></div>
</form>

<form method="post" action="/admin/template/new" class="card card-pad stack">
  <?= csrf_field() ?>
  <h2>Přidat pozici do šablony</h2>
  <div class="form-grid">
    <div class="field">
      <label for="tsection">Sekce</label>
      <select class="select" id="tsection" name="section">
        <?php foreach (SECTIONS as $key => $s): if ($key === 'barvy') continue; ?><option value="<?= $key ?>"><?= e($s['title']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label for="ttitle">Název pozice</label><input class="input" id="ttitle" name="title" required placeholder="např. Razítko"></div>
  </div>
  <div class="row"><button class="btn btn-secondary"><?= icon('plus', 18) ?>Přidat do šablony</button></div>
</form>
