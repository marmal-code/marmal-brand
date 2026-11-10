<?php
declare(strict_types=1);

/*
 * WebP pro web a digitální výstupy (sekce WEBP_SECTIONS v helpers.php).
 *
 * PNG a JPG převádí na serveru rozšíření GD. SVG, ke kterému v pozici není PNG ani JPG, vykreslí
 * prohlížeč administrátora do PNG (app.js, plátno) a server z něj udělá WebP stejně jako u ostatních.
 * Nic z toho nepotřebuje exec ani Imagick. WebP se přidává k aktuální verzi pozice (nevzniká nová verze),
 * se stejným názvem jako zdroj, takže ho pick_preview() použije jako náhled místo PNG.
 */

const WEBP_MAX_SIDE = 16383;   // největší rozměr, který formát WebP dovoluje
const WEBP_QUALITY = 85;       // ztrátový převod (fotky, JPG)
const WEBP_SVG_ICON_PX = 512;  // delší strana při vykreslení SVG ikony
const WEBP_SVG_PX = 2048;      // delší strana při vykreslení ostatních SVG

/** Umí server ukládat WebP (GD s podporou WebP, bezeztrátový režim)? */
function webp_supported(): bool
{
    static $ok = null;
    return $ok ??= function_exists('imagewebp') && function_exists('imagecreatefrompng') && defined('IMG_WEBP_LOSSLESS')
        && !empty(gd_info()['WebP Support']);
}

function file_stem(array $file): string
{
    return pathinfo((string) $file['stored_name'], PATHINFO_FILENAME);
}

/** Popisek WebP podle zdroje: „PNG“ → „WebP“, „PNG 512 px“ → „WebP 512 px“, „Favicon 32“ → „WebP Favicon 32“. */
function webp_label(array $source): string
{
    $label = trim((string) ($source['label'] ?? ''));
    $new = preg_replace('/\b(PNG|JPE?G|SVG)\b/i', 'WebP', $label);
    if ($new !== null && $new !== $label) {
        return $new;
    }
    return $label === '' ? 'WebP' : mb_substr('WebP ' . $label, 0, 40);
}

/**
 * Co v pozici chybí: ['raster' => PNG/JPG bez WebP se stejným názvem, 'svg' => SVG k vykreslení v prohlížeči].
 * SVG se vykresluje jen tehdy, když pozice nemá žádný PNG ani JPG (jinak by vznikly dvě WebP stejného obrázku).
 */
function item_webp_plan(array $item): array
{
    $plan = ['raster' => [], 'svg' => []];
    if (!in_array($item['section'], WEBP_SECTIONS, true) || $item['kind'] !== 'file' || empty($item['current_version_id'])) {
        return $plan;
    }
    $have = [];
    $rasters = 0;
    foreach ($item['all_files'] as $f) {
        $ext = file_ext($f['stored_name']);
        if ($ext === 'webp') {
            $have[file_stem($f)] = true;
        } elseif (in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
            $rasters++;
        }
    }
    foreach ($item['all_files'] as $f) {
        $ext = file_ext($f['stored_name']);
        if (isset($have[file_stem($f)])) {
            continue;
        }
        if (in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
            $plan['raster'][] = $f;
        } elseif ($ext === 'svg' && $rasters === 0) {
            $plan['svg'][] = $f;
        }
    }
    return $plan;
}

/** Úkol pro prohlížeč: vykreslit SVG do PNG a poslat ho na /admin/items/{id}/webp. */
function webp_svg_task(array $item, array $file): array
{
    return [
        'item' => (int) $item['id'],
        'file' => (int) $file['id'],
        'url' => '/admin/files/' . (int) $file['id'] . '?inline=1',
        'size' => $item['section'] === 'ikony' ? WEBP_SVG_ICON_PX : WEBP_SVG_PX,
        'name' => (string) $file['original_name'],
    ];
}

/** Stačí paměť na převod? Když ne, zkusí limit zvýšit (na sdíleném hostingu to obvykle jde). */
function webp_memory_ok(int $need): bool
{
    $limit = ini_bytes('memory_limit');
    if ($limit === PHP_INT_MAX || $limit - memory_get_usage(true) >= $need) {
        return true;
    }
    @ini_set('memory_limit', (string) (int) ceil((memory_get_usage(true) + $need) / 1048576 + 32) . 'M');
    $limit = ini_bytes('memory_limit');
    return $limit === PHP_INT_MAX || $limit - memory_get_usage(true) >= $need;
}

/**
 * Převede PNG/JPG na WebP. Vrací null, nebo důvod, proč to nešlo.
 * JPG → ztrátově (kvalita 85). PNG v Logu a Ikonách → bezeztrátově.
 * Ostatní PNG → bezeztrátově, pokud výsledek není víc než o polovinu větší než ztrátový (grafika zůstane ostrá, fotky malé).
 */
function webp_encode(string $src, string $dst, bool $lossless): ?string
{
    $info = @getimagesize($src);
    if (!$info) {
        return 'soubor není čitelný obrázek';
    }
    [$w, $h, $type] = $info;
    if (!in_array($type, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
        return 'převádím jen PNG a JPG';
    }
    if ($w > WEBP_MAX_SIDE || $h > WEBP_MAX_SIDE) {
        return 'obrázek ' . $w . ' × ' . $h . ' px je na WebP moc velký';
    }
    if (!webp_memory_ok($w * $h * 12 + 16 * 1048576)) {
        return 'na obrázek ' . $w . ' × ' . $h . ' px nestačí paměť serveru';
    }
    $im = $type === IMAGETYPE_PNG ? @imagecreatefrompng($src) : @imagecreatefromjpeg($src);
    if (!$im) {
        return 'obrázek se nepodařilo načíst';
    }
    if (!imageistruecolor($im)) {
        imagepalettetotruecolor($im);
    }
    imagealphablending($im, false);
    imagesavealpha($im, true);

    if ($type === IMAGETYPE_JPEG) {
        $ok = imagewebp($im, $dst, WEBP_QUALITY);
    } elseif ($lossless) {
        $ok = imagewebp($im, $dst, IMG_WEBP_LOSSLESS);
    } else {
        $a = $dst . '.lossless';
        $b = $dst . '.lossy';
        $okA = imagewebp($im, $a, IMG_WEBP_LOSSLESS);
        $okB = imagewebp($im, $b, WEBP_QUALITY);
        clearstatcache();
        $useA = $okA && (!$okB || filesize($a) <= filesize($b) * 1.5);
        $ok = ($useA || $okB) && rename($useA ? $a : $b, $dst);
        @unlink($a);
        @unlink($b);
    }
    unset($im);
    clearstatcache();
    if (!$ok || !is_file($dst) || filesize($dst) === 0) {
        @unlink($dst);
        return 'WebP se nepodařilo uložit';
    }
    return null;
}

/** Uloží vytvořený soubor do aktuální verze pozice (nevzniká nová verze). */
function add_generated_file(array $item, string $tmp, string $name, string $originalName, string $label, string $role): void
{
    $versionId = (int) $item['current_version_id'];
    $dir = version_dir((int) $item['brand_id'], (int) $item['id'], $versionId);
    ensure_dir($dir);
    $stored = safe_filename($name);
    $base = pathinfo($stored, PATHINFO_FILENAME);
    $ext = file_ext($stored);
    $n = 2;
    while (is_file($dir . '/' . $stored)) {
        $stored = $base . '-' . $n++ . '.' . $ext;
    }
    if (!rename($tmp, $dir . '/' . $stored)) {
        @unlink($tmp);
        throw new RuntimeException('Soubor ' . $name . ' se nepodařilo uložit.');
    }
    db_exec(
        'INSERT INTO item_files (version_id, label, original_name, stored_name, size, mime, role, is_preview, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())',
        [$versionId, mb_substr($label, 0, 40), mb_substr($originalName, 0, 255), $stored, (int) filesize($dir . '/' . $stored), mime_for($stored), $role === 'preview' ? 'preview' : 'download']
    );
}

/**
 * Doplní WebP ke všem PNG/JPG aktuální verze pozice.
 * Vrací ['added' => počet, 'skipped' => [důvody], 'svg' => [úkoly pro prohlížeč]].
 */
function item_add_webp(array $item): array
{
    $plan = item_webp_plan($item);
    $out = ['added' => 0, 'skipped' => [], 'svg' => array_map(fn ($f) => webp_svg_task($item, $f), $plan['svg'])];
    if (!$plan['raster'] || !webp_supported()) {
        return $out;
    }
    @set_time_limit(300);
    ensure_dir(data_path('tmp'));
    $lossless = in_array($item['section'], WEBP_LOSSLESS_SECTIONS, true);
    foreach ($plan['raster'] as $f) {
        $tmp = data_path('tmp/webp-' . bin2hex(random_bytes(6)) . '.webp');
        $error = webp_encode(stored_file_path($f), $tmp, $lossless);
        if ($error !== null) {
            $out['skipped'][] = $item['title'] . ' – ' . $f['original_name'] . ': ' . $error;
            continue;
        }
        add_generated_file($item, $tmp, file_stem($f) . '.webp', pathinfo((string) $f['original_name'], PATHINFO_FILENAME) . '.webp', webp_label($f), (string) $f['role']);
        $out['added']++;
    }
    if ($out['added']) {
        touch_brand((int) $item['brand_id']);
    }
    return $out;
}

/** Převede PNG vykreslené prohlížečem ze SVG souboru pozice na WebP. */
function item_add_webp_from_svg(array $item, int $svgFileId, string $pngPath): void
{
    $svg = null;
    foreach (item_webp_plan($item)['svg'] as $f) {
        if ((int) $f['id'] === $svgFileId) {
            $svg = $f;
        }
    }
    if (!$svg) {
        throw new RuntimeException('Tento soubor už WebP má, nebo nepatří k aktuální verzi.');
    }
    $info = @getimagesize($pngPath);
    if (!$info || $info[2] !== IMAGETYPE_PNG || max($info[0], $info[1]) > WEBP_SVG_PX) {
        throw new RuntimeException('Prohlížeč neposlal platný obrázek PNG.');
    }
    ensure_dir(data_path('tmp'));
    $tmp = data_path('tmp/webp-' . bin2hex(random_bytes(6)) . '.webp');
    $error = webp_encode($pngPath, $tmp, true);
    if ($error !== null) {
        throw new RuntimeException($svg['original_name'] . ': ' . $error);
    }
    add_generated_file($item, $tmp, file_stem($svg) . '.webp', pathinfo((string) $svg['original_name'], PATHINFO_FILENAME) . '.webp', webp_label($svg), (string) $svg['role']);
    touch_brand((int) $item['brand_id']);
}

/**
 * Pozice, kterým chybí WebP: [['item', 'brand_id', 'brand', 'title', 'section', 'raster' => počet, 'svg' => [úkoly]], …].
 * Bez $brandId pro všechny klienty.
 */
function webp_missing(?int $brandId = null): array
{
    $sql = 'SELECT i.*, b.name AS brand_name FROM items i JOIN brands b ON b.id = i.brand_id
            WHERE i.current_version_id IS NOT NULL AND i.kind = ? AND i.section IN (' . implode(',', array_fill(0, count(WEBP_SECTIONS), '?')) . ')';
    $params = ['file', ...WEBP_SECTIONS];
    if ($brandId !== null) {
        $sql .= ' AND i.brand_id = ?';
        $params[] = $brandId;
    }
    $sql .= ' ORDER BY b.name, FIELD(i.section, ' . implode(',', array_map(fn ($k) => "'" . $k . "'", array_keys(SECTIONS))) . '), i.sort, i.id';
    $out = [];
    foreach (attach_files(db_all($sql, $params)) as $it) {
        $plan = item_webp_plan($it);
        if (!$plan['raster'] && !$plan['svg']) {
            continue;
        }
        $out[] = [
            'item' => (int) $it['id'],
            'brand_id' => (int) $it['brand_id'],
            'brand' => (string) $it['brand_name'],
            'title' => (string) $it['title'],
            'section' => (string) $it['section'],
            'raster' => count($plan['raster']),
            'svg' => array_map(fn ($f) => webp_svg_task($it, $f), $plan['svg']),
        ];
    }
    return $out;
}
