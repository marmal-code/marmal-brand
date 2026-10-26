<?php
declare(strict_types=1);

const CHUNK_MAX_BYTES = 12 * 1024 * 1024;      // jeden kus (prohlížeč posílá 8 MB, rezerva pro limit 16 MB)
const UPLOAD_MAX_BYTES = 2 * 1024 * 1024 * 1024; // 2 GB na soubor

function data_path(string $sub = ''): string
{
    return DATA_DIR . ($sub === '' ? '' : '/' . ltrim($sub, '/'));
}

function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nelze vytvořit složku ' . $dir);
    }
}

function version_dir(int $brandId, int $itemId, int $versionId): string
{
    return data_path("files/$brandId/$itemId/$versionId");
}

function stored_file_path(array $file): string
{
    $row = db_one('SELECT i.brand_id, i.id AS item_id FROM item_versions v JOIN items i ON i.id = v.item_id WHERE v.id = ?', [$file['version_id']]);
    if (!$row) {
        throw new RuntimeException('Soubor nemá položku');
    }
    return version_dir((int) $row['brand_id'], (int) $row['item_id'], (int) $file['version_id']) . '/' . $file['stored_name'];
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

function safe_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $ext = file_ext($name);
    $stem = pathinfo($name, PATHINFO_FILENAME);
    $stem = slugify($stem);
    return $ext !== '' ? $stem . '.' . $ext : $stem;
}

/* ---------- Nahrávání po částech ---------- */

function chunk_path(string $uploadId): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
        throw new InvalidArgumentException('Neplatné ID nahrávání');
    }
    return data_path('tmp/chunks/' . $uploadId . '.part');
}

/** Přijme jeden kus souboru. Kusy musí chodit postupně od indexu 0. */
function receive_chunk(string $uploadId, int $index, array $file): int
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Kus souboru se nepodařilo přijmout (chyba ' . ($file['error'] ?? '?') . ').');
    }
    if ($file['size'] > CHUNK_MAX_BYTES) {
        throw new RuntimeException('Kus souboru je příliš velký.');
    }
    ensure_dir(data_path('tmp/chunks'));
    $target = chunk_path($uploadId);
    if ($index === 0 && is_file($target)) {
        unlink($target);
    }
    if ($index > 0 && !is_file($target)) {
        throw new RuntimeException('Nahrávání bylo přerušeno, začněte prosím znovu.');
    }
    $in = fopen($file['tmp_name'], 'rb');
    $out = fopen($target, 'ab');
    if (!$in || !$out) {
        throw new RuntimeException('Nelze zapisovat do dočasné složky.');
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    clearstatcache(true, $target);
    $size = (int) filesize($target);
    if ($size > UPLOAD_MAX_BYTES) {
        unlink($target);
        throw new RuntimeException('Soubor je větší než 2 GB.');
    }
    return $size;
}

/** Úklid nedokončených nahrávání starších než 1 den. */
function cleanup_chunks(): void
{
    foreach (glob(data_path('tmp/chunks/*.part')) ?: [] as $f) {
        if (filemtime($f) < time() - 86400) {
            @unlink($f);
        }
    }
}

/**
 * Uloží nahrané soubory k položce.
 * $mode 'new' = nová verze (stará zůstane v historii), 'append' = přidat k aktuální verzi.
 * $uploads = [['upload_id'=>, 'name'=>, 'role'=>'download'|'preview', 'label'=>?]]
 */
function commit_uploads(array $item, array $uploads, string $mode, string $note): int
{
    if (!$uploads) {
        throw new RuntimeException('Nejsou vybrané žádné soubory.');
    }
    foreach ($uploads as $u) {
        $ext = file_ext((string) ($u['name'] ?? ''));
        if (!in_array($ext, ALLOWED_EXT, true)) {
            throw new RuntimeException('Typ souboru „' . ($u['name'] ?? '') . '“ nelze nahrát.');
        }
        if (!is_file(chunk_path((string) ($u['upload_id'] ?? '')))) {
            throw new RuntimeException('Soubor „' . ($u['name'] ?? '') . '“ se nenahrál celý.');
        }
    }

    $itemId = (int) $item['id'];
    $brandId = (int) $item['brand_id'];
    $versionId = (int) $item['current_version_id'];

    if ($mode !== 'append' || !$versionId) {
        $next = (int) db_val('SELECT COALESCE(MAX(version), 0) + 1 FROM item_versions WHERE item_id = ?', [$itemId]);
        $versionId = db_insert('INSERT INTO item_versions (item_id, version, note, created_at) VALUES (?, ?, ?, NOW())', [$itemId, $next, mb_substr($note, 0, 255)]);
    } elseif ($note !== '') {
        db_exec('UPDATE item_versions SET note = ? WHERE id = ?', [mb_substr($note, 0, 255), $versionId]);
    }

    $dir = version_dir($brandId, $itemId, $versionId);
    ensure_dir($dir);
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    foreach ($uploads as $u) {
        $src = chunk_path((string) $u['upload_id']);
        $stored = safe_filename((string) $u['name']);
        $base = pathinfo($stored, PATHINFO_FILENAME);
        $ext = file_ext($stored);
        $n = 2;
        while (is_file($dir . '/' . $stored)) {
            $stored = $base . '-' . $n++ . '.' . $ext;
        }
        if (!rename($src, $dir . '/' . $stored)) {
            throw new RuntimeException('Soubor se nepodařilo uložit.');
        }
        $role = ($u['role'] ?? '') === 'preview' ? 'preview' : 'download';
        $label = trim((string) ($u['label'] ?? '')) ?: strtoupper($ext === 'jpeg' ? 'jpg' : $ext);
        db_exec(
            'INSERT INTO item_files (version_id, label, original_name, stored_name, size, mime, role, is_preview, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$versionId, mb_substr($label, 0, 40), mb_substr(basename((string) $u['name']), 0, 255), $stored, (int) filesize($dir . '/' . $stored), (string) $finfo->file($dir . '/' . $stored), $role, !empty($u['is_preview']) ? 1 : 0]
        );
    }

    db_exec('UPDATE items SET current_version_id = ?, updated_at = NOW() WHERE id = ?', [$versionId, $itemId]);
    touch_brand($brandId);
    return $versionId;
}

function delete_version(array $version): void
{
    $item = db_one('SELECT * FROM items WHERE id = ?', [$version['item_id']]);
    if (!$item) {
        return;
    }
    rrmdir(version_dir((int) $item['brand_id'], (int) $item['id'], (int) $version['id']));
    db_exec('DELETE FROM item_versions WHERE id = ?', [$version['id']]);
}

/* ---------- Odesílání souborů ---------- */

function mime_for(string $name, string $fallback = 'application/octet-stream'): string
{
    $map = [
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
        'gif' => 'image/gif', 'ico' => 'image/x-icon', 'pdf' => 'application/pdf', 'woff2' => 'font/woff2', 'woff' => 'font/woff',
        'ttf' => 'font/ttf', 'otf' => 'font/otf', 'zip' => 'application/zip', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
    ];
    return $map[file_ext($name)] ?? ($fallback ?: 'application/octet-stream');
}

/**
 * Pošle soubor prohlížeči.
 * $cacheSeconds: jak dlouho si ho prohlížeč smí držet. Soubory adresované přes ID se nemění (nová verze = nové ID),
 * takže náhledy a písma můžou mít cache dlouhou. Prohlížeč se navíc může zeptat, jestli se soubor změnil (304).
 */
function stream_file(string $path, string $downloadName, bool $inline = false, string $mime = '', int $cacheSeconds = 300): never
{
    if (!is_file($path)) {
        abort(404, 'Soubor už na serveru není.');
    }
    @set_time_limit(0);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    session_write_close();
    $mime = mime_for($downloadName, $mime);
    $size = (int) filesize($path);
    $mtime = (int) filemtime($path);
    $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
    $cache = $cacheSeconds > 0
        ? 'private, max-age=' . $cacheSeconds . ($cacheSeconds >= 86400 ? ', immutable' : '')
        : 'private, no-store';
    header_remove('Expires'); // posílá je session_start(), u souborů by pletly cache
    header_remove('Pragma');
    header('Cache-Control: ' . $cache);
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    $ifNone = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    $ifSince = strtotime((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
    if ($cacheSeconds > 0 && (($ifNone !== '' && in_array($etag, array_map('trim', explode(',', $ifNone)), true)) || ($ifNone === '' && $ifSince >= $mtime))) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('X-Content-Type-Options: nosniff');
    // Nahrané soubory nesmí na naší doméně spouštět skripty (hlavně SVG a HTML).
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; font-src 'self' data:; sandbox");
    $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);
    $disposition = $inline && (in_array(file_ext($downloadName), array_merge(IMAGE_EXT, FONT_EXT, ['pdf']), true)) ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $disposition . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }
    $fh = fopen($path, 'rb');
    while ($fh && !feof($fh)) {
        echo fread($fh, 1024 * 512);
        flush();
    }
    if ($fh) {
        fclose($fh);
    }
    exit;
}

/** Název souboru pro klienta: brand-polozka.pripona (při více souborech stejného typu se přidá původní název). */
function download_name(array $brand, array $item, array $file): string
{
    $ext = file_ext($file['stored_name']);
    $nice = $brand['slug'] . '-' . $item['slug'];
    $same = (int) db_val('SELECT COUNT(*) FROM item_files WHERE version_id = ? AND role = ? AND stored_name LIKE ?', [$file['version_id'], 'download', '%.' . $ext]);
    if ($same <= 1) {
        return $nice . '.' . $ext;
    }
    return $nice . '-' . pathinfo($file['stored_name'], PATHINFO_FILENAME) . '.' . $ext;
}

/** Vytvoří ZIP s aktuálními soubory brandu (nebo jedné sekce) a pošle ho. */
function send_brand_zip(array $brand, ?string $section = null): never
{
    @set_time_limit(0);
    $items = brand_items((int) $brand['id'], $section, true);
    ensure_dir(data_path('tmp'));
    $tmp = data_path('tmp/zip-' . bin2hex(random_bytes(6)) . '.zip');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        abort(500, 'ZIP se nepodařilo vytvořit.');
    }
    $count = 0;
    foreach ($items as $item) {
        $folder = (SECTIONS[$item['section']]['title'] ?? $item['section']) . '/' . $item['title'];
        foreach ($item['files'] as $f) {
            $path = stored_file_path($f);
            if (is_file($path)) {
                $entry = $folder . '/' . download_name($brand, $item, $f);
                $zip->addFile($path, $entry);
                $zip->setCompressionName($entry, ZipArchive::CM_STORE); // soubory jsou většinou už zkomprimované
                $count++;
            }
        }
    }
    if (!$section || $section === 'barvy') {
        $colors = brand_colors((int) $brand['id']);
        if ($colors) {
            $zip->addFromString('Barvy/barvy.txt', colors_as_text($brand, $colors));
            $zip->addFromString('Barvy/barvy.css', colors_as_css($colors));
            $count++;
        }
    }
    if ($count === 0) {
        $zip->addFromString('README.txt', 'Zatím zde nejsou žádné soubory.');
    }
    $zip->close();
    $name = $brand['slug'] . '-' . ($section ? slugify(SECTIONS[$section]['title'] ?? $section) : 'brand-manual') . '.zip';
    register_shutdown_function(fn () => @unlink($tmp));
    stream_file($tmp, $name, false, '', 0);
}

function colors_as_text(array $brand, array $colors): string
{
    $out = 'Barvy značky ' . $brand['name'] . "\r\n\r\n";
    foreach ($colors as $c) {
        $out .= $c['name'] . ($c['role'] ? ' (' . $c['role'] . ')' : '') . "\r\n";
        $out .= '  HEX: ' . $c['hex'] . "\r\n  RGB: " . hex_to_rgb($c['hex']) . "\r\n";
        $out .= '  CMYK: ' . ($c['cmyk'] ?: hex_to_cmyk($c['hex']) . ' (orientačně)') . "\r\n";
        if ($c['pantone']) {
            $out .= '  Pantone: ' . $c['pantone'] . "\r\n";
        }
        $out .= "\r\n";
    }
    return $out;
}

function colors_as_css(array $colors): string
{
    $out = ":root {\n";
    foreach ($colors as $c) {
        $out .= '  --' . slugify($c['name']) . ': ' . strtolower($c['hex']) . ";\n";
    }
    return $out . "}\n";
}

function ini_bytes(string $key): int
{
    $v = trim((string) ini_get($key));
    if ($v === '' || $v === '-1' || $v === '0') {
        return PHP_INT_MAX;
    }
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

/** Velikost kusu pro nahrávání: max. 8 MB a vždy pod limity serveru. */
function upload_chunk_bytes(): int
{
    $limit = min(ini_bytes('post_max_size'), ini_bytes('upload_max_filesize'));
    return (int) max(256 * 1024, min(8 * 1024 * 1024, $limit - 512 * 1024));
}
