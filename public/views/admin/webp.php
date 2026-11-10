<?php
$byBrand = [];
foreach ($missing as $m) {
    $byBrand[$m['brand_id']]['name'] = $m['brand'];
    $byBrand[$m['brand_id']]['items'][] = $m;
}
$svgCount = array_sum(array_map(fn ($m) => count($m['svg']), $missing));
$rasterCount = array_sum(array_column($missing, 'raster'));
$tasks = array_map(fn ($m) => ['item' => $m['item'], 'title' => $m['brand'] . ' – ' . $m['title'], 'raster' => $m['raster'], 'svg' => $m['svg']], $missing);
?>
<div class="breadcrumb">
  <?php if ($brand): ?><a href="/admin">Klienti</a> / <a href="/admin/brands/<?= (int) $brand['id'] ?>/settings"><?= e($brand['name']) ?></a> / Doplnit WebP
  <?php else: ?><a href="/admin">Klienti</a> / Doplnit WebP<?php endif; ?>
</div>
<h1>Doplnit WebP<?= $brand ? ' · ' . e($brand['name']) : '' ?></h1>
<p class="muted" style="max-width: 720px">WebP je menší než PNG a JPG, a proto se hodí na web a do všech digitálních výstupů. Vytvořím ho ke každému PNG a JPG v sekcích Logo, Ikony, Web a Sociální sítě a přidám ho k aktuální verzi pozice (nevznikne nová verze). Loga a ikony ukládám bezeztrátově, fotky ztrátově v kvalitě 85. Klient uvidí WebP jako další formát ke stažení a náhledy se mu načtou rychleji.</p>

<?php if (!$supported): ?>
  <div class="alert alert-error" role="alert"><?= icon('alert') ?><div>Server neumí ukládat WebP: chybí rozšíření PHP <b>GD s podporou WebP</b>. Požádejte hosting o jeho zapnutí. Do té doby můžete WebP nahrát ručně jako „Přidat k aktuální verzi“.</div></div>
<?php elseif (!$missing): ?>
  <div class="alert alert-success" role="status"><?= icon('check') ?><div>Všechny pozice <?= $brand ? 'tohoto klienta ' : '' ?>už WebP mají.</div></div>
<?php else: ?>
  <section class="card card-pad stack" data-webp-runner data-tasks="<?= e(json_encode($tasks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
    <div class="row-between">
      <div>
        <h2 style="font-size: 22px">WebP chybí u <?= count($missing) ?> <?= count($missing) === 1 ? 'pozice' : 'pozic' ?></h2>
        <p class="small muted"><?= $rasterCount ?> PNG/JPG převede server<?= $svgCount ? ', ' . $svgCount . ' SVG bez PNG vykreslí váš prohlížeč (stránku nechte otevřenou)' : '' ?>.</p>
      </div>
      <button type="button" class="btn btn-primary" data-webp-start><?= icon('image', 18) ?>Doplnit WebP</button>
    </div>
    <div class="progress" aria-hidden="true"><span data-webp-bar></span></div>
    <p class="small" data-webp-status aria-live="polite"></p>
    <ul class="list-plain small stack" style="gap: 4px" data-webp-log></ul>
  </section>

  <?php foreach ($byBrand as $bid => $b): ?>
    <section class="card stack" style="gap: 0">
      <div class="card-pad-sm row-between" style="border-bottom: 1px solid var(--border)">
        <h2 style="font-size: 20px"><?= e($b['name']) ?></h2>
        <?php if (!$brand): ?><a class="small" href="/admin/webp?brand=<?= (int) $bid ?>">jen tento klient</a><?php endif; ?>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th scope="col">Pozice</th><th scope="col">Sekce</th><th scope="col">Převede</th></tr></thead>
          <tbody>
          <?php foreach ($b['items'] as $m): ?>
            <tr>
              <td><a href="/admin/items/<?= (int) $m['item'] ?>"><?= e($m['title']) ?></a></td>
              <td><?= e(SECTIONS[$m['section']]['title'] ?? $m['section']) ?></td>
              <td class="small"><?= e(implode(' · ', array_filter([$m['raster'] ? $m['raster'] . '× PNG/JPG (server)' : '', $m['svg'] ? count($m['svg']) . '× SVG (prohlížeč)' : '']))) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endforeach; ?>
<?php endif; ?>
