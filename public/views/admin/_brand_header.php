<?php
/** @var array $brand */
$stats = $stats ?? brand_section_stats((int) $brand['id']);
$active = $active ?? '';
$symbol = brand_symbol((int) $brand['id']);
?>
<div class="stack" style="gap: 20px">
  <div class="breadcrumb"><a href="/admin">Klienti</a> / <?= e($brand['name']) ?></div>
  <div class="row-between">
    <div class="row" style="gap: 16px; flex-wrap: nowrap">
      <span class="avatar avatar-lg" style="background: <?= e($brand['hero_bg']) ?>; color: <?= is_dark($brand['hero_bg']) ? '#fff' : 'var(--ink)' ?>">
        <?php if ($symbol): ?><img src="/admin/files/<?= (int) $symbol['preview']['id'] ?>?inline=1" alt=""><?php else: ?><?= e(mb_strtoupper(mb_substr($brand['name'], 0, 1))) ?><?php endif; ?>
      </span>
      <div>
        <h1 style="font-size: 34px"><?= e($brand['name']) ?></h1>
        <div class="small muted break"><?= e($brand['custom_domain'] ?: config('app_host') . '/b/' . $brand['slug']) ?> · změny se u klienta projeví hned po uložení</div>
      </div>
    </div>
    <div class="row">
      <a class="btn btn-secondary" href="<?= e(brand_url($brand)) ?>" target="_blank" rel="noopener"><?= icon('eye', 18) ?>Náhled jako klient</a>
    </div>
  </div>
  <nav class="tabs" aria-label="Sekce brandu">
    <?php foreach (SECTIONS as $key => $s):
        $st = $stats[$key];
        $ok = $st['filled'] > 0 && $st['required_missing'] === 0;
        $label = $key === 'barvy' ? (string) $st['filled'] : $st['filled'] . '/' . $st['total'];
    ?>
      <a class="tab<?= $active === $key ? ' active' : '' ?>" href="/admin/brands/<?= (int) $brand['id'] ?>/s/<?= $key ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>>
        <?= e($s['title']) ?> <span class="tag <?= $ok ? 'tag-ok' : ($st['required_missing'] ? 'tag-warn' : 'tag-plain') ?>"><?= e($label) ?></span>
      </a>
    <?php endforeach; ?>
    <a class="tab<?= $active === 'settings' ? ' active' : '' ?>" href="/admin/brands/<?= (int) $brand['id'] ?>/settings"><?= icon('settings', 16) ?>Přístup a nastavení</a>
  </nav>
</div>
