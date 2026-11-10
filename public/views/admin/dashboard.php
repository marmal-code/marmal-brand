<?php
$actorLabels = ['admin' => 'Vy', 'client' => 'Klient', 'share' => 'Sdílený odkaz', 'public' => 'Veřejný odkaz'];
?>
<div class="row-between">
  <h1>Klienti</h1>
  <div class="row">
    <form method="get" action="/admin" role="search">
      <label class="input row" style="gap: 8px; padding: 0 12px; min-height: 44px; width: auto">
        <?= icon('search', 18, ' style="color:var(--ink-muted)"') ?>
        <span class="sr-only">Hledat klienta</span>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Hledat klienta" style="border:0; outline:0; font: inherit; width: 180px; background: transparent">
      </label>
    </form>
    <a class="btn btn-ghost" href="/admin/import"><?= icon('upload', 18) ?>Importovat</a>
    <a class="btn btn-primary" href="/admin/brands/new"><?= icon('plus', 18) ?>Nový klient</a>
  </div>
</div>

<?php if ($updateHint): ?>
  <div class="alert alert-info"><?= icon('refresh') ?><div>Je k dispozici nová verze aplikace <b><?= e($updateHint) ?></b>. <a href="/admin/settings/update">Přejít na aktualizace</a></div></div>
<?php endif; ?>

<?php if ($webpMissing):
    $webpBrands = count(array_unique(array_column($webpMissing, 'brand_id'))); ?>
  <div class="alert alert-info"><?= icon('image') ?><div class="row-between" style="flex: 1">
    <span>WebP chybí u <b><?= count($webpMissing) ?></b> <?= count($webpMissing) === 1 ? 'pozice' : 'pozic' ?> (<?= $webpBrands ?> <?= $webpBrands === 1 ? 'klient' : ($webpBrands < 5 ? 'klienti' : 'klientů') ?>). Doplním je ke všem PNG, JPG a samostatným SVG v Logu, Ikonách, Webu a Sociálních sítích.</span>
    <a class="btn btn-secondary btn-sm" href="/admin/webp">Doplnit WebP</a>
  </div></div>
<?php endif; ?>

<div class="grid-stats">
  <div class="card card-pad-sm"><div class="stat-label">Aktivní brandy</div><div class="stat-value"><?= $stats['brands'] ?></div></div>
  <div class="card card-pad-sm"><div class="stat-label">Souborů ke stažení</div><div class="stat-value"><?= $stats['files'] ?></div></div>
  <div class="card card-pad-sm"><div class="stat-label">Zobrazení za 30 dní</div><div class="stat-value"><?= cz_num($stats['views']) ?></div></div>
  <div class="card card-pad-sm"><div class="stat-label">Stažení za 30 dní</div><div class="stat-value"><?= $stats['downloads'] ?></div></div>
  <div class="card card-pad-sm<?= $stats['incomplete'] ? ' stat-warn' : '' ?>"><div class="stat-label">Neúplné brandy</div><div class="stat-value"><?= $stats['incomplete'] ?></div></div>
</div>

<?php if (!$brands): ?>
  <div class="card empty-state stack" style="align-items: center">
    <p><?= $q !== '' ? 'Žádný klient neodpovídá hledání.' : 'Zatím tu není žádný klient.' ?></p>
    <?php if ($q === ''): ?><a class="btn btn-primary" href="/admin/brands/new"><?= icon('plus', 18) ?>Založit prvního klienta</a><?php endif; ?>
  </div>
<?php else: ?>
  <div class="card table-wrap">
    <table class="table" style="min-width: 820px">
      <thead><tr><th scope="col">Klient</th><th scope="col">Vyplněno</th><th scope="col">Viděno</th><th scope="col">Poslední změna</th><th scope="col">Přístup</th><th scope="col">Akce</th></tr></thead>
      <tbody>
      <?php foreach ($brands as $b): $c = $b['completeness']; ?>
        <tr<?= $b['is_active'] ? '' : ' style="opacity:.6"' ?>>
          <td>
            <div class="row" style="gap: 12px; flex-wrap: nowrap">
              <span class="avatar" style="background: <?= e($b['hero_bg']) ?>; color: <?= is_dark($b['hero_bg']) ? '#fff' : 'var(--ink)' ?>">
                <?php if ($b['symbol']): ?><img src="/admin/files/<?= (int) $b['symbol']['preview']['id'] ?>?inline=1" alt=""><?php else: ?><?= e(mb_strtoupper(mb_substr($b['name'], 0, 1))) ?><?php endif; ?>
              </span>
              <div>
                <a href="/admin/brands/<?= (int) $b['id'] ?>/s/logo" style="font-weight: 500; color: var(--ink); text-decoration: none"><?= e($b['name']) ?></a>
                <div class="tiny muted"><?= e($b['custom_domain'] ?: config('app_host') . '/b/' . $b['slug']) ?><?= $b['is_active'] ? '' : ' · neaktivní' ?></div>
              </div>
            </div>
          </td>
          <td>
            <div class="fill-cell">
              <div class="progress<?= $c['done'] === $c['total'] ? ' done' : ($c['pct'] < 50 ? ' low' : '') ?>" aria-hidden="true"><span style="width: <?= $c['pct'] ?>%"></span></div>
              <span class="tiny muted nowrap"><?= $c['done'] ?> / <?= $c['total'] ?> sekcí</span>
            </div>
          </td>
          <td>
            <a class="views-cell" href="/admin/brands/<?= (int) $b['id'] ?>/stats" title="Zobrazení celkem <?= cz_num($b['views_total'] ?? 0) ?>, za 30 dní <?= cz_num($b['views_30']) ?><?= ($b['last_viewed_at'] ?? null) ? ', naposledy ' . e(cz_date($b['last_viewed_at'], true)) : '' ?>" aria-label="Statistiky zobrazení klienta <?= e($b['name']) ?>: celkem <?= cz_num($b['views_total'] ?? 0) ?>, za 30 dní <?= cz_num($b['views_30']) ?>">
              <span class="views-count"><?= icon('eye', 18) ?><?= cz_num($b['views_total'] ?? 0) ?></span>
              <span class="tiny muted nowrap"><?= ($b['last_viewed_at'] ?? null) ? e(relative_day($b['last_viewed_at'])) : 'zatím ne' ?></span>
            </a>
          </td>
          <td class="small nowrap"><?= cz_date($b['updated_at']) ?></td>
          <td class="small"><?= $b['password_hash'] ? 'Heslo' : '–' ?><?= share_link_valid($b) ? ' + sdílený odkaz' : '' ?></td>
          <td>
            <div class="icon-actions">
              <a class="icon-action" href="/admin/brands/<?= (int) $b['id'] ?>/s/logo" aria-label="Upravit klienta <?= e($b['name']) ?>"><?= icon('gear', 20) ?><span>Upravit</span></a>
              <a class="icon-action" href="<?= e(brand_url($b)) ?>" target="_blank" rel="noopener" aria-label="Náhled manuálu <?= e($b['name']) ?> (nové okno)"><?= icon('search', 20) ?><span>Náhled</span></a>
              <button type="button" class="icon-action" data-copy="<?= e(brand_public_url($b)) ?>" data-copy-msg="Odkaz na brand manuál je zkopírovaný" aria-label="Kopírovat odkaz na manuál <?= e($b['name']) ?>"><?= icon('copy', 20) ?><span>Kopírovat</span></button>
              <a class="icon-action danger" href="/admin/brands/<?= (int) $b['id'] ?>/settings#smazat" aria-label="Smazat klienta <?= e($b['name']) ?>"><?= icon('trash', 20) ?><span>Smazat</span></a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<section class="stack">
  <div class="row-between"><h2 style="font-size: 24px">Poslední aktivita</h2><a class="small" href="/admin/activity">Celá historie</a></div>
  <?php if (!$activity): ?>
    <p class="muted">Zatím žádná aktivita.</p>
  <?php else: ?>
    <ul class="list-plain card list-lines">
      <?php foreach ($activity as $a): ?>
        <li class="row-between">
          <span><b><?= e($a['brand_name'] ?? 'Systém') ?></b> · <?= e($actorLabels[$a['actor']] ?? $a['actor']) ?>: <?= e($a['detail']) ?></span>
          <span class="small muted nowrap"><?= e(relative_day($a['created_at'])) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
