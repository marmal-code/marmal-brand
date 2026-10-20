<?php
declare(strict_types=1);

/* ---------- Načítání brandů ---------- */

function brand_by_slug(string $slug): ?array
{
    return db_one('SELECT * FROM brands WHERE slug = ?', [$slug]);
}

function brand_by_id(int $id): ?array
{
    return db_one('SELECT * FROM brands WHERE id = ?', [$id]);
}

function brand_by_domain(string $host): ?array
{
    return db_one('SELECT * FROM brands WHERE custom_domain = ? AND is_active = 1', [$host]);
}

/** Brand, jehož vlastní doména je právě otevřená (nastavuje router). */
function domain_brand(?array $set = null): ?array
{
    static $brand = null;
    if ($set !== null) {
        $brand = $set;
    }
    return $brand;
}

/** Základ URL klientského pohledu: '' na vlastní doméně, jinak /b/{slug}. */
function brand_url(array $brand, string $path = ''): string
{
    $db = domain_brand();
    $base = ($db && (int) $db['id'] === (int) $brand['id']) ? '' : '/b/' . $brand['slug'];
    $url = $base . $path;
    return $url === '' ? '/' : $url;
}

/** Absolutní veřejná adresa (pro kopírování odkazů). */
function brand_public_url(array $brand, string $path = ''): string
{
    if (!empty($brand['custom_domain'])) {
        return 'https://' . $brand['custom_domain'] . ($path === '' ? '/' : $path);
    }
    $host = (string) config('app_host', request_host());
    return 'https://' . $host . '/b/' . $brand['slug'] . $path;
}

/* ---------- Položky a soubory ---------- */

/**
 * Položky brandu včetně souborů aktuální verze.
 * Každá položka dostane klíče: files (ke stažení), preview (soubor pro náhled nebo null), version (aktuální verze nebo null).
 */
function brand_items(int $brandId, ?string $section = null, bool $onlyFilled = false): array
{
    $sql = 'SELECT i.*, v.version AS version_no, v.created_at AS version_at, v.note AS version_note
            FROM items i LEFT JOIN item_versions v ON v.id = i.current_version_id
            WHERE i.brand_id = ?';
    $params = [$brandId];
    if ($section !== null) {
        $sql .= ' AND i.section = ?';
        $params[] = $section;
    }
    if ($onlyFilled) {
        $sql .= ' AND i.current_version_id IS NOT NULL';
    }
    $sql .= ' ORDER BY FIELD(i.section, ' . implode(',', array_map(fn ($k) => "'" . $k . "'", array_keys(SECTIONS))) . '), i.sort, i.id';
    $items = db_all($sql, $params);
    return attach_files($items);
}

function attach_files(array $items): array
{
    $versionIds = array_values(array_filter(array_map(fn ($i) => (int) $i['current_version_id'], $items)));
    $byVersion = [];
    if ($versionIds) {
        $in = implode(',', array_fill(0, count($versionIds), '?'));
        foreach (db_all("SELECT * FROM item_files WHERE version_id IN ($in) ORDER BY role, id", $versionIds) as $f) {
            $byVersion[(int) $f['version_id']][] = $f;
        }
    }
    foreach ($items as &$item) {
        $files = $byVersion[(int) $item['current_version_id']] ?? [];
        $item['meta'] = json_decode((string) ($item['meta'] ?? ''), true) ?: [];
        $item['all_files'] = $files;
        $item['files'] = array_values(array_filter($files, fn ($f) => $f['role'] === 'download'));
        $item['preview'] = pick_preview($files);
        $item['font_file'] = pick_font($files);
        $item['size_total'] = array_sum(array_map(fn ($f) => (int) $f['size'], $item['files']));
    }
    unset($item);
    return $items;
}

function item_by_id(int $id): ?array
{
    $item = db_one('SELECT i.*, v.version AS version_no, v.created_at AS version_at, v.note AS version_note FROM items i LEFT JOIN item_versions v ON v.id = i.current_version_id WHERE i.id = ?', [$id]);
    return $item ? attach_files([$item])[0] : null;
}

function file_ext(string $name): string
{
    return strtolower(pathinfo($name, PATHINFO_EXTENSION));
}

function pick_preview(array $files): ?array
{
    foreach ($files as $f) {
        if ($f['role'] === 'preview' && in_array(file_ext($f['stored_name']), IMAGE_EXT, true)) {
            return $f;
        }
    }
    // Přednost má SVG, pak rastrové formáty.
    foreach (['svg', 'png', 'webp', 'jpg', 'jpeg', 'gif'] as $ext) {
        foreach ($files as $f) {
            if (file_ext($f['stored_name']) === $ext) {
                return $f;
            }
        }
    }
    return null;
}

function pick_font(array $files): ?array
{
    foreach (FONT_EXT as $ext) {
        foreach ($files as $f) {
            if (file_ext($f['stored_name']) === $ext) {
                return $f;
            }
        }
    }
    return null;
}

function font_format(string $ext): string
{
    return ['woff2' => 'woff2', 'woff' => 'woff', 'ttf' => 'truetype', 'otf' => 'opentype'][$ext] ?? 'truetype';
}

function brand_colors(int $brandId): array
{
    return db_all('SELECT * FROM colors WHERE brand_id = ? ORDER BY sort, id', [$brandId]);
}

/** Počty vyplněných položek po sekcích: [section => ['filled'=>, 'total'=>, 'required_missing'=>]] */
function brand_section_stats(int $brandId): array
{
    $stats = [];
    foreach (SECTIONS as $key => $_) {
        $stats[$key] = ['filled' => 0, 'total' => 0, 'required_missing' => 0];
    }
    foreach (db_all('SELECT section, required, current_version_id FROM items WHERE brand_id = ?', [$brandId]) as $r) {
        if (!isset($stats[$r['section']])) {
            continue;
        }
        $stats[$r['section']]['total']++;
        if ($r['current_version_id']) {
            $stats[$r['section']]['filled']++;
        } elseif ($r['required']) {
            $stats[$r['section']]['required_missing']++;
        }
    }
    $colors = (int) db_val('SELECT COUNT(*) FROM colors WHERE brand_id = ?', [$brandId]);
    $stats['barvy'] = ['filled' => $colors, 'total' => $colors, 'required_missing' => $colors ? 0 : 1];
    return $stats;
}

/** Sekce je hotová, když má obsah a nechybí v ní povinná položka. */
function brand_completeness(int $brandId): array
{
    $stats = brand_section_stats($brandId);
    $done = 0;
    foreach ($stats as $s) {
        if ($s['filled'] > 0 && $s['required_missing'] === 0) {
            $done++;
        }
    }
    return ['done' => $done, 'total' => count(SECTIONS), 'pct' => (int) round($done / count(SECTIONS) * 100), 'stats' => $stats];
}

/* ---------- Vytváření a šablona ---------- */

function unique_brand_slug(string $wanted, int $exceptId = 0): string
{
    $base = slugify($wanted);
    $slug = $base;
    $n = 2;
    while (db_val('SELECT id FROM brands WHERE slug = ? AND id <> ?', [$slug, $exceptId])) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

function unique_item_slug(int $brandId, string $wanted, int $exceptId = 0): string
{
    $base = slugify($wanted);
    $slug = $base;
    $n = 2;
    while (db_val('SELECT id FROM items WHERE brand_id = ? AND slug = ? AND id <> ?', [$brandId, $slug, $exceptId])) {
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

/** Doplní do brandu pozice ze šablony, které mu chybí. Vrací počet přidaných. */
function sync_template(int $brandId): int
{
    $added = 0;
    foreach (db_all('SELECT * FROM template_items ORDER BY section, sort, id') as $t) {
        if (db_val('SELECT id FROM items WHERE brand_id = ? AND slug = ?', [$brandId, $t['slug']])) {
            continue;
        }
        db_exec(
            'INSERT INTO items (brand_id, section, kind, title, slug, description, specs, tags, required, sort, preview_bg, meta, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$brandId, $t['section'], $t['kind'], $t['title'], $t['slug'], $t['description'], $t['specs'], $t['tags'], $t['required'], $t['sort'], $t['preview_bg'], '{}']
        );
        $added++;
    }
    return $added;
}

function touch_brand(int $brandId): void
{
    db_exec('UPDATE brands SET updated_at = NOW() WHERE id = ?', [$brandId]);
}

function new_share_token(): string
{
    return bin2hex(random_bytes(12));
}
