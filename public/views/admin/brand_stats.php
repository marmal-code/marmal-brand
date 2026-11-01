<?php
/** @var array $brand @var array $views @var array $activity */
require APP_ROOT . '/views/admin/_brand_header.php';
$days = $views['days'];
$max = max(1, ...array_column($views['series'], 'views'));
$accessLabels = ['client' => 'Přihlášení heslem', 'share' => 'Sdílený odkaz'];
$actorLabels = ['client' => 'Klient', 'share' => 'Sdílený odkaz', 'public' => 'Veřejný odkaz'];
$periodLabel = 'za ' . $days . ' dní';
$maxPage = max(1, ...array_column($views['pages'] ?: [['period' => 0]], 'period'));
$dates = array_keys($views['series']);
?>
<div class="row-between">
  <h2 style="font-size: 24px">Kolikrát klient manuál otevřel</h2>
  <nav class="row" style="gap: 6px" aria-label="Období">
    <?php foreach ([7, 30, 90] as $d): ?>
      <a class="btn btn-sm <?= $d === $days ? 'btn-blue' : 'btn-ghost' ?>" href="/admin/brands/<?= (int) $brand['id'] ?>/stats?d=<?= $d ?>"<?= $d === $days ? ' aria-current="page"' : '' ?>><?= $d ?> dní</a>
    <?php endforeach; ?>
  </nav>
</div>

<div class="grid-stats">
  <div class="card card-pad-sm"><div class="stat-label">Zobrazení <?= e($periodLabel) ?></div><div class="stat-value"><?= cz_num($views['views']) ?></div></div>
  <div class="card card-pad-sm"><div class="stat-label">Návštěvy <?= e($periodLabel) ?></div><div class="stat-value"><?= cz_num($views['visits']) ?></div></div>
  <div class="card card-pad-sm"><div class="stat-label">Stažení <?= e($periodLabel) ?></div><div class="stat-value"><?= cz_num($views['downloads']) ?></div></div>
  <div class="card card-pad-sm"><div class="stat-label">Zobrazení celkem</div><div class="stat-value"><?= cz_num($brand['views_total'] ?? 0) ?></div></div>
  <div class="card card-pad-sm"><div class="stat-label">Naposledy viděno</div><div class="stat-value stat-value-sm"><?= !empty($brand['last_viewed_at']) ? e(relative_day($brand['last_viewed_at'])) : 'zatím ne' ?></div></div>
</div>

<section class="card card-pad stack" aria-labelledby="chart-title">
  <div class="row-between">
    <h3 id="chart-title" style="font-size: 18px">Zobrazení po dnech</h3>
    <?php if ($views['views'] > 0): ?><span class="small muted"><?= $views['active_days'] ?> <?= $views['active_days'] === 1 ? 'den' : ($views['active_days'] >= 2 && $views['active_days'] <= 4 ? 'dny' : 'dní') ?> s návštěvou <?= e($periodLabel) ?></span><?php endif; ?>
  </div>
  <?php if ($views['views'] === 0): ?>
    <p class="muted">Za vybrané období klient manuál neotevřel.</p>
  <?php else: ?>
    <div class="views-chart" aria-hidden="true">
      <div class="views-axis"><span><?= cz_num($max) ?></span><span>0</span></div>
      <div class="views-bars<?= $days > 30 ? ' dense' : '' ?>">
        <?php foreach ($views['series'] as $day => $v): ?>
          <div class="views-bar" title="<?= e(date('j. n.', strtotime($day))) ?>: <?= cz_num($v['views']) ?> zobrazení, <?= cz_num($v['visits']) ?> návštěv">
            <span style="height: <?= $v['views'] ? max(3, round($v['views'] / $max * 100, 1)) : 0 ?>%"></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="views-dates tiny muted" aria-hidden="true">
      <span><?= e(date('j. n.', strtotime($dates[0]))) ?></span>
      <span><?= e(date('j. n.', strtotime($dates[intdiv(count($dates), 2)]))) ?></span>
      <span>dnes</span>
    </div>
    <details>
      <summary class="small">Zobrazit jako tabulku</summary>
      <div class="table-wrap" style="margin-top: 12px">
        <table class="table">
          <caption class="sr-only">Zobrazení a návštěvy po dnech</caption>
          <thead><tr><th scope="col">Den</th><th scope="col">Zobrazení</th><th scope="col">Návštěvy</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($views['series'], true) as $day => $v): if (!$v['views']) { continue; } ?>
            <tr><td class="nowrap"><?= cz_date($day) ?></td><td><?= cz_num($v['views']) ?></td><td><?= cz_num($v['visits']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  <?php endif; ?>
</section>

<div class="grid-2">
  <section class="card stack" style="gap: 0" aria-labelledby="pages-title">
    <h3 id="pages-title" class="card-pad-sm" style="font-size: 18px; border-bottom: 1px solid var(--border)">Nejčastěji otevírané stránky</h3>
    <?php if (!$views['pages']): ?>
      <p class="muted card-pad-sm">Zatím žádná zobrazení.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th scope="col">Stránka</th><th scope="col"><?= e(ucfirst($periodLabel)) ?></th><th scope="col">Celkem</th></tr></thead>
          <tbody>
          <?php foreach ($views['pages'] as $p): ?>
            <tr>
              <td>
                <div class="stack" style="gap: 6px">
                  <span><?= e($p['label']) ?></span>
                  <div class="progress" style="width: 100%; max-width: 200px" aria-hidden="true"><span style="width: <?= round($p['period'] / $maxPage * 100) ?>%"></span></div>
                </div>
              </td>
              <td><?= cz_num($p['period']) ?></td>
              <td class="muted"><?= cz_num($p['total']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card stack" style="gap: 0" aria-labelledby="access-title">
    <h3 id="access-title" class="card-pad-sm" style="font-size: 18px; border-bottom: 1px solid var(--border)">Jak klient přichází</h3>
    <?php if (!$views['access']): ?>
      <p class="muted card-pad-sm">Za vybrané období žádná návštěva.</p>
    <?php else: ?>
      <ul class="list-plain list-lines">
        <?php foreach ($views['access'] as $key => $n): ?>
          <li class="row-between"><span><?= e($accessLabels[$key] ?? $key) ?></span><span><b><?= cz_num($n) ?></b> <span class="small muted"><?= $n === 1 ? 'návštěva' : ($n >= 2 && $n <= 4 ? 'návštěvy' : 'návštěv') ?></span></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h3 class="card-pad-sm" style="font-size: 18px; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border)">Poslední aktivita klienta</h3>
    <?php if (!$activity): ?>
      <p class="muted card-pad-sm">Zatím žádná přihlášení ani stažení.</p>
    <?php else: ?>
      <ul class="list-plain list-lines">
        <?php foreach ($activity as $a): ?>
          <li class="row-between" style="flex-wrap: nowrap; gap: 12px">
            <span class="small"><?= e($actorLabels[$a['actor']] ?? $a['actor']) ?>: <?= e($a['detail']) ?></span>
            <span class="tiny muted nowrap"><?= e(relative_day($a['created_at'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="card-pad-sm small" style="border-top: 1px solid var(--border)"><a href="/admin/activity?brand=<?= (int) $brand['id'] ?>">Celá historie klienta</a></div>
    <?php endif; ?>
  </section>
</div>

<p class="hint">
  Zobrazení = každé otevření stránky manuálu (přehled, sekce, tisková verze). Návštěva = jeden prohlížeč za den.
  Vaše náhledy jako administrátor a opakované obnovení stejné stránky do 30 sekund nepočítám. Ukládám jen souhrnné
  počty po dnech, žádné IP adresy.<?= $views['first_day'] ? ' Počítám od ' . e(cz_date($views['first_day'])) . '.' : '' ?>
</p>
