<?php
/* Sdílené kousky klientského pohledu. */

function client_format_buttons(array $brand, array $item): string
{
    $out = '';
    foreach ($item['files'] as $f) {
        $out .= '<a class="btn btn-ghost btn-sm" href="' . e(client_file_url($brand, $f)) . '" title="' . e($f['original_name'] . ' · ' . human_size((int) $f['size'])) . '">'
            . icon('download', 16) . e($f['label']) . '</a>';
    }
    return $out;
}

function client_asset_card(array $brand, array $item): string
{
    $bg = $item['preview_bg'] ?: '#FFFFFF';
    ob_start(); ?>
    <article class="card asset-card">
      <div class="asset-preview" style="background: <?= e($bg) ?>">
        <?php if ($item['preview']): ?>
          <img src="<?= e(client_file_url($brand, $item['preview'], true)) ?>" alt="<?= e($item['title']) ?>" loading="lazy">
        <?php else: ?>
          <span class="muted"><?= icon('file', 40) ?></span>
        <?php endif; ?>
      </div>
      <div class="asset-body">
        <div>
          <div style="font-weight: var(--fw-medium)"><?= e($item['title']) ?></div>
          <?php if ($item['description']): ?><div class="small muted"><?= e($item['description']) ?></div><?php endif; ?>
        </div>
        <div class="formats"><?= client_format_buttons($brand, $item) ?></div>
      </div>
    </article>
    <?php return (string) ob_get_clean();
}

function client_color_card(array $c): string
{
    $rgb = hex_to_rgb($c['hex']);
    $cmyk = $c['cmyk'] ?: hex_to_cmyk($c['hex']);
    ob_start(); ?>
    <article class="card" style="overflow: hidden">
      <div class="swatch-top" style="background: <?= e($c['hex']) ?>"></div>
      <div class="asset-body" style="gap: 8px">
        <div style="font-weight: var(--fw-medium)"><?= e($c['name']) ?><?php if ($c['role']): ?> <span class="tiny muted">· <?= e($c['role']) ?></span><?php endif; ?></div>
        <dl class="color-meta">
          <dt>HEX</dt><dd><?= e($c['hex']) ?></dd>
          <dt>RGB</dt><dd><?= e($rgb) ?></dd>
          <dt>CMYK</dt><dd><?= e($cmyk) ?><?= $c['cmyk'] ? '' : ' <span class="tiny muted">(orientačně)</span>' ?></dd>
          <?php if ($c['pantone']): ?><dt>Pantone</dt><dd><?= e($c['pantone']) ?></dd><?php endif; ?>
        </dl>
        <?php if (!empty($c['usage_note'])): ?><p class="small" style="border-top: 1px solid var(--border); padding-top: 8px"><?= e($c['usage_note']) ?></p><?php endif; ?>
        <div class="row" style="gap: 8px">
          <button type="button" class="btn btn-ghost btn-sm" data-copy="<?= e($c['hex']) ?>">Kopírovat HEX</button>
          <button type="button" class="btn btn-ghost btn-sm" data-copy="<?= e($rgb) ?>">RGB</button>
        </div>
      </div>
    </article>
    <?php return (string) ob_get_clean();
}

function client_font_card(array $brand, array $item): string
{
    $m = $item['meta'];
    $family = 'bf' . (int) $item['id'];
    $sample = ($m['sample'] ?? '') ?: 'Příliš žluťoučký kůň úpěl ďábelské ódy';
    ob_start(); ?>
    <article class="card card-pad stack">
      <?php if ($faces = font_faces($item)): ?>
        <style><?= font_faces_css($faces, $family, fn ($f) => client_file_url($brand, $f, true)) ?></style>
      <?php endif; ?>
      <div class="row-between" style="align-items: baseline">
        <div style="font-weight: var(--fw-medium)"><?= !empty($m['family']) ? e($m['family']) . ' <span class="muted" style="font-weight: var(--fw-light)">· ' . e(mb_strtolower($item['title'])) . '</span>' : e($item['title']) ?></div>
        <?php if (!empty($m['weights'])): ?><div class="small muted"><?= e($m['weights']) ?></div><?php endif; ?>
      </div>
      <?php
        $mainWeight = font_main_weight($m);
        $normalFaces = array_values(array_filter($faces ?: [], fn ($f) => $f['style'] === 'normal' && !str_contains($f['weight'], ' ')));
      ?>
      <div class="font-big" style="font-family: '<?= $family ?>', var(--font-sans); font-weight: <?= e($mainWeight) ?>; color: var(--ink)">Aa</div>
      <?php if (count($normalFaces) > 1): ?>
        <div class="font-weights" style="font-family: '<?= $family ?>', var(--font-sans)">
          <?php foreach ($normalFaces as $f): ?><span style="font-weight: <?= (int) $f['weight'] ?>"><?= e(font_weight_name((int) $f['weight'])) ?> <?= (int) $f['weight'] ?></span><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="font-sample" style="font-family: '<?= $family ?>', var(--font-sans)"><?= e($sample) ?></div>
      <div class="small muted" style="font-family: '<?= $family ?>', var(--font-sans)">ABCDEFGHIJKLMNOPQRSTUVWXYZ abcdefghijklmnopqrstuvwxyz 0123456789 ěščřžýáíéůú</div>
      <?php if ($item['description']): ?><p class="small"><?= e($item['description']) ?></p><?php endif; ?>
      <?php if (!empty($m['license'])): ?><p class="tiny muted">Licence: <?= e($m['license']) ?></p><?php endif; ?>
      <div class="formats">
        <?= client_format_buttons($brand, $item) ?>
        <?php if (safe_url($m['google_url'] ?? '') !== ''): ?><a class="btn btn-ghost btn-sm" href="<?= e(safe_url($m['google_url'])) ?>" target="_blank" rel="noopener"><?= icon('external', 16) ?>Google Fonts</a><?php endif; ?>
      </div>
    </article>
    <?php return (string) ob_get_clean();
}

function client_doc_row(array $brand, array $item): string
{
    $tags = array_filter(array_map('trim', explode(',', (string) $item['tags'])));
    $main = $item['files'][0] ?? null;
    ob_start(); ?>
    <article class="card doc-row" id="<?= e($item['slug']) ?>">
      <div class="doc-thumb" style="<?= $item['preview_bg'] ? 'background:' . e($item['preview_bg']) : '' ?>">
        <?php if ($item['preview']): ?>
          <img src="<?= e(client_file_url($brand, $item['preview'], true)) ?>" alt="Náhled: <?= e($item['title']) ?>" loading="lazy">
        <?php else: ?>
          <?= icon('file', 40) ?>
        <?php endif; ?>
      </div>
      <div class="doc-body">
        <div class="row" style="gap: 8px 12px; align-items: baseline">
          <h2 style="font-size: 22px"><?= e($item['title']) ?></h2>
          <?php if ($item['specs']): ?><span class="small muted"><?= e($item['specs']) ?></span><?php endif; ?>
        </div>
        <?php if ($item['description']): ?><p style="font-size: 15px"><?= e($item['description']) ?></p><?php endif; ?>
        <?php if ($tags): ?><div class="row" style="gap: 8px"><?php foreach ($tags as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach; ?></div><?php endif; ?>
        <div class="tiny muted">Verze <?= (int) $item['version_no'] ?> · aktualizováno <?= cz_date($item['version_at']) ?></div>
      </div>
      <div class="doc-actions">
        <?php if ($main): ?>
          <a class="btn btn-blue" href="<?= e(client_file_url($brand, $main)) ?>"><?= icon('download', 18) ?><?= e($main['label']) ?> · <?= human_size((int) $main['size']) ?></a>
        <?php endif; ?>
        <?php foreach (array_slice($item['files'], 1) as $f): ?>
          <a class="btn btn-ghost" href="<?= e(client_file_url($brand, $f)) ?>"><?= icon('download', 18) ?><?= e($f['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </article>
    <?php return (string) ob_get_clean();
}
