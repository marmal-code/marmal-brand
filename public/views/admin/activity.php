<?php $actorLabels = ['admin' => 'Vy', 'client' => 'Klient', 'share' => 'Sdílený odkaz', 'public' => 'Veřejný odkaz']; ?>
<div class="row-between">
  <h1>Aktivita</h1>
  <form method="get" action="/admin/activity" class="row">
    <label class="sr-only" for="brand">Klient</label>
    <select class="select" id="brand" name="brand" style="width: auto" data-autosubmit>
      <option value="0">Všichni klienti</option>
      <?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>"<?= $brandId === (int) $b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-ghost">Filtrovat</button></noscript>
  </form>
</div>
<?php if (!$rows): ?>
  <p class="muted">Zatím žádná aktivita.</p>
<?php else: ?>
  <div class="card table-wrap">
    <table class="table" style="min-width: 640px">
      <thead><tr><th scope="col">Kdy</th><th scope="col">Klient</th><th scope="col">Kdo</th><th scope="col">Co</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $a): ?>
        <tr><td class="small nowrap"><?= cz_date($a['created_at'], true) ?></td><td><?= e($a['brand_name'] ?? '–') ?></td><td class="small"><?= e($actorLabels[$a['actor']] ?? $a['actor']) ?></td><td class="small"><?= e($a['detail']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
