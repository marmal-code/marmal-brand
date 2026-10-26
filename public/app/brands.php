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
    // 1) soubor nahraný jen jako náhled, 2) soubor ke stažení označený jako náhled, 3) první obrázek
    foreach ($files as $f) {
        if ($f['role'] === 'preview' && in_array(file_ext($f['stored_name']), IMAGE_EXT, true)) {
            return $f;
        }
    }
    foreach ($files as $f) {
        if (!empty($f['is_preview']) && in_array(file_ext($f['stored_name']), IMAGE_EXT, true)) {
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

/* ---------- Písma brandu (řezy a @font-face) ---------- */

/** Adresa souboru v klientském pohledu (na vlastní doméně bez /b/{slug}). */
function client_file_url(array $brand, array $file, bool $inline = false): string
{
    return brand_url($brand, '/file/' . (int) $file['id']) . ($inline ? '?inline=1' : '');
}

/**
 * Řez písma podle názvu souboru a popisku (např. „OpenSans-SemiBold.ttf“, „TTF Bold“).
 * Vrací ['weight' => '600' nebo rozsah '100 900' u variabilního písma, 'style' => normal|italic, 'width' => bool (zúžený/rozšířený řez)].
 */
function font_face_info(array $file): array
{
    $text = strtolower(($file['original_name'] ?? '') . ' ' . ($file['stored_name'] ?? '') . ' ' . ($file['label'] ?? ''));
    $flat = preg_replace('/[^a-z]+/', '', $text) ?? '';
    $variable = str_contains($flat, 'variable') || str_contains($text, '[wght') || str_contains($flat, 'wght');
    $weights = [
        'extralight' => 200, 'ultralight' => 200, 'semibold' => 600, 'demibold' => 600,
        'extrabold' => 800, 'ultrabold' => 800, 'hairline' => 100, 'thin' => 100, 'light' => 300,
        'medium' => 500, 'black' => 900, 'heavy' => 900, 'bold' => 700, 'regular' => 400, 'book' => 400, 'normal' => 400,
    ];
    $weight = 400;
    foreach ($weights as $word => $w) {
        if (str_contains($flat, $word)) {
            $weight = $w;
            break;
        }
    }
    return [
        'weight' => $variable ? '100 900' : (string) $weight,
        'style' => (str_contains($flat, 'italic') || str_contains($flat, 'oblique')) ? 'italic' : 'normal',
        'width' => (bool) preg_match('/(condensed|cond|narrow|compressed|expanded|extended|wide)/', $flat),
    ];
}

/**
 * Řezy jedné položky písma: [['weight'=>, 'style'=>, 'file'=>], …].
 * Ke každému řezu vybere nejúspornější formát (WOFF2 > WOFF > TTF > OTF), i ze souborů „jen náhled“.
 * Zúžené a rozšířené řezy vynechá, pokud má písmo i běžné.
 */
function font_faces(array $item): array
{
    $faces = [];
    $hasNormalWidth = false;
    foreach ($item['all_files'] ?? [] as $f) {
        $ext = file_ext($f['stored_name']);
        if (!in_array($ext, FONT_EXT, true)) {
            continue;
        }
        $info = font_face_info($f);
        $hasNormalWidth = $hasNormalWidth || !$info['width'];
        $key = ($info['width'] ? 'w' : 'n') . '|' . $info['weight'] . '|' . $info['style'];
        $rank = array_search($ext, FONT_EXT, true);
        if (!isset($faces[$key]) || $rank < $faces[$key]['rank']) {
            $faces[$key] = $info + ['file' => $f, 'rank' => $rank];
        }
    }
    if ($hasNormalWidth) {
        $faces = array_filter($faces, fn ($face) => !$face['width']);
    }
    uasort($faces, fn ($a, $b) => [(int) $a['weight'], $a['style']] <=> [(int) $b['weight'], $b['style']]);
    return array_values($faces);
}

/** CSS @font-face pro všechny řezy položky pod názvem rodiny $family. */
function font_faces_css(array $faces, string $family, callable $url): string
{
    $css = '';
    foreach ($faces as $face) {
        $ext = file_ext($face['file']['stored_name']);
        $css .= '@font-face{font-family:"' . $family . '";src:url("' . $url($face['file']) . '") format("' . font_format($ext) . '");'
            . 'font-weight:' . $face['weight'] . ';font-style:' . $face['style'] . ';font-display:swap}' . "\n";
    }
    return $css;
}

/** Nejbližší dostupná tloušťka k požadované (pro mapování tlouštěk rozhraní na řezy brandu). */
function nearest_weight(array $faces, array $wanted, int $fallback): int
{
    $have = [];
    foreach ($faces as $f) {
        if ($f['style'] !== 'normal') {
            continue;
        }
        if (str_contains($f['weight'], ' ')) {
            return $wanted[0]; // variabilní písmo umí všechny tloušťky
        }
        $have[] = (int) $f['weight'];
    }
    foreach ($wanted as $w) {
        if (in_array($w, $have, true)) {
            return $w;
        }
    }
    return $fallback;
}

/**
 * Písma brandu pro klientský pohled a tiskovou verzi.
 * Nadpisy = pozice „pismo-nadpisy“ (jinak první písmo), text = „pismo-text“ (jinak druhé, jinak stejné jako nadpisy).
 * Vrací null, když je přepínač vypnutý nebo brand nemá nahrané žádné písmo.
 * Jinak ['css' => …, 'preload' => [[url, typ], …]].
 */
function brand_typefaces(array $brand, ?array $typoItems = null): ?array
{
    if (isset($brand['brand_fonts']) && !(int) $brand['brand_fonts']) {
        return null;
    }
    $typoItems ??= brand_items((int) $brand['id'], 'typografie', true);
    $withFaces = [];
    foreach ($typoItems as $it) {
        $faces = font_faces($it);
        if ($faces) {
            $withFaces[$it['slug']] = $faces;
        }
    }
    if (!$withFaces) {
        return null;
    }
    $others = array_values(array_diff_key($withFaces, ['pismo-nadpisy' => 1, 'pismo-text' => 1]));
    $display = $withFaces['pismo-nadpisy'] ?? null;
    $text = $withFaces['pismo-text'] ?? null;
    $display ??= array_shift($others) ?? $text;
    $text ??= array_shift($others) ?? $display;

    $url = fn (array $file) => client_file_url($brand, $file, true);
    $css = font_faces_css($display, 'bh-display', $url) . font_faces_css($text, 'bh-text', $url);
    // Tloušťky rozhraní: běžný text v regularu (ne v lightu jako MarMal), tlačítka a popisky v mediu/semiboldu.
    $light = nearest_weight($text, [400], 400);
    $medium = nearest_weight($text, [500, 600], 600);
    $css .= ':root{--font-display:"bh-display","Lexend",system-ui,sans-serif;--font-sans:"bh-text","Lexend",system-ui,sans-serif;'
        . '--fw-light:' . $light . ';--fw-medium:' . $medium . '}' . "\n";

    // Přednačíst řez pro nadpisy (700, jinak nejtučnější) a běžný text (400, jinak první).
    $pick = function (array $faces, int $want): array {
        $best = $faces[0];
        foreach ($faces as $f) {
            if ($f['style'] === 'normal' && (str_contains($f['weight'], ' ') || abs((int) $f['weight'] - $want) < abs((int) $best['weight'] - $want) || $best['style'] !== 'normal')) {
                $best = $f;
            }
        }
        return $best;
    };
    $preload = [];
    foreach ([$pick($display, 700), $pick($text, 400)] as $face) {
        $u = $url($face['file']);
        if (!isset($preload[$u])) {
            $preload[$u] = [$u, 'font/' . (file_ext($face['file']['stored_name']) === 'otf' ? 'otf' : file_ext($face['file']['stored_name']))];
        }
    }
    return ['css' => $css, 'preload' => array_values($preload)];
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

/* ---------- Texty sekcí ---------- */

function section_texts(int $brandId): array
{
    $out = [];
    foreach (db_all('SELECT section, body FROM section_texts WHERE brand_id = ?', [$brandId]) as $r) {
        if (trim((string) $r['body']) !== '') {
            $out[$r['section']] = (string) $r['body'];
        }
    }
    return $out;
}

function save_section_text(int $brandId, string $section, string $body): void
{
    $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
    if ($body === '') {
        db_exec('DELETE FROM section_texts WHERE brand_id = ? AND section = ?', [$brandId, $section]);
        return;
    }
    db_exec(
        'INSERT INTO section_texts (brand_id, section, body, updated_at) VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE body = VALUES(body), updated_at = NOW()',
        [$brandId, $section, $body]
    );
}

/* ---------- Odstíny barev (tóny s bílou) ---------- */

/** Vrací [[procento, HEX], …] pro 100, 80, 60, 40, 20 a 10 % plné barvy. */
function color_tints(string $hex): array
{
    $rgb = hex_to_rgb($hex);
    if ($rgb === '') {
        return [];
    }
    [$r, $g, $b] = array_map('intval', explode(' ', $rgb));
    $out = [];
    foreach ([100, 80, 60, 40, 20, 10] as $p) {
        $mix = fn (int $c) => (int) round($c * $p / 100 + 255 * (100 - $p) / 100);
        $out[] = [$p, sprintf('#%02X%02X%02X', $mix($r), $mix($g), $mix($b))];
    }
    return $out;
}

function font_weight_name(int $w): string
{
    return [100 => 'Thin', 200 => 'ExtraLight', 300 => 'Light', 400 => 'Regular', 500 => 'Medium', 600 => 'SemiBold', 700 => 'Bold', 800 => 'ExtraBold', 900 => 'Black'][$w] ?? (string) $w;
}

/** Hlavní tloušťka písma pro ukázku „Aa“: první z popisu řezů („Bold 700, Regular 400“), tenčí než regular jen když regular chybí. */
function font_main_weight(array $meta): int
{
    if (!preg_match_all('/\b([1-9]00)\b/', (string) ($meta['weights'] ?? ''), $m)) {
        return 400;
    }
    $list = array_map('intval', $m[1]);
    return ($list[0] < 400 && in_array(400, $list, true)) ? 400 : $list[0];
}
