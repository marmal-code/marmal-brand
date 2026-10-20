<?php
declare(strict_types=1);

/*
 * Zálohy před aktualizací: databáze (db.sql.gz) + kód aplikace (code.zip).
 * Soubory klientů se nezálohují – aktualizace na ně nesahá (leží v DATA_DIR/files).
 */

const BACKUPS_KEEP = 5;
/** Složky kódu, které aplikace spravuje sama (při aktualizaci se v nich mažou zastaralé soubory). */
const MANAGED_DIRS = ['app', 'views', 'migrations', 'assets'];
/** Soubory ve webové složce, které aktualizace nikdy nepřepíše. */
const PRESERVED_FILES = ['data-path.php', '.user.ini', 'php.ini'];

function backups_dir(): string
{
    return data_path('backups');
}

function list_backups(): array
{
    $out = [];
    foreach (glob(backups_dir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $meta = json_decode((string) @file_get_contents($dir . '/meta.json'), true) ?: [];
        $size = 0;
        foreach (['db.sql.gz', 'code.zip'] as $f) {
            $size += is_file("$dir/$f") ? (int) filesize("$dir/$f") : 0;
        }
        $out[] = [
            'id' => basename($dir),
            'version' => $meta['version'] ?? '?',
            'reason' => $meta['reason'] ?? '',
            'created_at' => $meta['created_at'] ?? date('Y-m-d H:i:s', (int) filemtime($dir)),
            'size' => $size,
            'complete' => is_file("$dir/db.sql.gz") && is_file("$dir/code.zip"),
        ];
    }
    usort($out, fn ($a, $b) => strcmp($b['id'], $a['id']));
    return $out;
}

function backup_path(string $id): string
{
    if (!preg_match('/^[0-9]{8}-[0-9]{6}-[a-z0-9.\-]+$/', $id)) {
        throw new InvalidArgumentException('Neplatná záloha');
    }
    return backups_dir() . '/' . $id;
}

function create_backup(string $reason): string
{
    @set_time_limit(300);
    ensure_dir(backups_dir());
    $id = date('Ymd-His') . '-v' . preg_replace('/[^a-z0-9.\-]/', '', strtolower(APP_VERSION));
    $dir = backups_dir() . '/' . $id;
    ensure_dir($dir);
    dump_database(db(), $dir . '/db.sql.gz');
    zip_code($dir . '/code.zip');
    file_put_contents($dir . '/meta.json', json_encode([
        'version' => APP_VERSION,
        'reason' => $reason,
        'created_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    prune_backups();
    return $id;
}

function prune_backups(): void
{
    $all = list_backups();
    foreach (array_slice($all, BACKUPS_KEEP) as $b) {
        rrmdir(backups_dir() . '/' . $b['id']);
    }
}

/* ---------- Databáze ---------- */

/** Výpis databáze. Každý příkaz končí řádkem „;;“, aby šly bezpečně oddělit i víceřádkové CREATE TABLE. */
function dump_database(PDO $pdo, string $file): void
{
    $gz = gzopen($file, 'wb6');
    if (!$gz) {
        throw new RuntimeException('Nelze zapsat zálohu databáze.');
    }
    gzwrite($gz, "-- Brand Hub záloha " . date('c') . " (verze " . APP_VERSION . ")\n");
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as [$table]) {
        $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, "DROP TABLE IF EXISTS `$table`\n;;\n$create\n;;\n");
        $st = $pdo->query('SELECT * FROM `' . $table . '`', PDO::FETCH_NUM);
        $batch = [];
        foreach ($st as $row) {
            $vals = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row);
            $batch[] = '(' . implode(',', $vals) . ')';
            if (count($batch) >= 200) {
                gzwrite($gz, 'INSERT INTO `' . $table . '` VALUES ' . implode(',', $batch) . "\n;;\n");
                $batch = [];
            }
        }
        if ($batch) {
            gzwrite($gz, 'INSERT INTO `' . $table . '` VALUES ' . implode(',', $batch) . "\n;;\n");
        }
    }
    gzclose($gz);
}

function restore_database(PDO $pdo, string $file): void
{
    $gz = gzopen($file, 'rb');
    if (!$gz) {
        throw new RuntimeException('Zálohu databáze nelze otevřít.');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    // Nejdřív pryč se vším, co mohla vytvořit nepovedená migrace.
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$t]) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
    }
    $buf = '';
    while (($line = gzgets($gz)) !== false) {
        if (rtrim($line, "\r\n") === ';;') {
            // úvodní komentářové řádky (hlavička zálohy) přeskočíme
            $sql = trim(preg_replace('/^(?:\s*--[^\n]*\n)+/', '', $buf) ?? '');
            $buf = '';
            if ($sql !== '') {
                $pdo->exec($sql);
            }
            continue;
        }
        $buf .= $line;
    }
    gzclose($gz);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/* ---------- Kód ---------- */

/** Seznam souborů kódu (relativní cesty) ve webové složce. */
function code_files(): array
{
    $out = [];
    $root = APP_ROOT;
    $data = realpath(DATA_DIR) ?: DATA_DIR;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $path = $f->getPathname();
        if (str_starts_with($path, $data . DIRECTORY_SEPARATOR) || !$f->isFile()) {
            continue;
        }
        $out[] = str_replace('\\', '/', substr($path, strlen($root) + 1));
    }
    sort($out);
    return $out;
}

function zip_code(string $file): void
{
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Nelze vytvořit zálohu kódu.');
    }
    foreach (code_files() as $rel) {
        $zip->addFile(APP_ROOT . '/' . $rel, $rel);
    }
    $zip->close();
}

function is_managed(string $rel): bool
{
    $first = explode('/', $rel)[0];
    return in_array($first, MANAGED_DIRS, true) && str_contains($rel, '/');
}

/** Nakopíruje strom souborů ze $src do webové složky a smaže spravované soubory, které v novém stromu nejsou. */
function install_code_tree(string $src): void
{
    $src = rtrim($src, '/');
    $before = code_files();
    $new = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($src) + 1));
        if (in_array($rel, PRESERVED_FILES, true)) {
            continue;
        }
        $new[] = $rel;
        $target = APP_ROOT . '/' . $rel;
        ensure_dir(dirname($target));
        if (!copy($f->getPathname(), $target . '.new') || !rename($target . '.new', $target)) {
            throw new RuntimeException('Nelze zapsat soubor ' . $rel . '. Zkontrolujte práva k zápisu ve webové složce.');
        }
    }
    foreach (array_diff($before, $new) as $rel) {
        if (is_managed($rel)) {
            @unlink(APP_ROOT . '/' . $rel);
        }
    }
    reset_opcache();
}

function reset_opcache(): void
{
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    clearstatcache(true);
}

function restore_code(string $zipFile): void
{
    $tmp = data_path('tmp/restore-' . bin2hex(random_bytes(4)));
    extract_zip_safely($zipFile, $tmp, '');
    install_code_tree($tmp);
    rrmdir($tmp);
}

/**
 * Bezpečně rozbalí ZIP (bez cest s „..“). $prefix = podsložka v archivu, jejíž obsah se má rozbalit
 * (např. „marmal-code-marmal-brand-abc123/public/“). Prázdný prefix = celý archiv.
 */
function extract_zip_safely(string $zipFile, string $target, string $prefix): int
{
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException('Archiv nelze otevřít.');
    }
    ensure_dir($target);
    $count = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if ($prefix !== '' && !str_starts_with($name, $prefix)) {
            continue;
        }
        $rel = substr($name, strlen($prefix));
        if ($rel === '' || str_ends_with($rel, '/')) {
            continue;
        }
        if (str_contains($rel, '..') || str_starts_with($rel, '/') || str_contains($rel, '\\')) {
            throw new RuntimeException('Archiv obsahuje neplatnou cestu: ' . $rel);
        }
        $dest = $target . '/' . $rel;
        ensure_dir(dirname($dest));
        $in = $zip->getStream($name);
        $out = fopen($dest, 'wb');
        if (!$in || !$out) {
            throw new RuntimeException('Nelze rozbalit ' . $rel);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        $count++;
    }
    $zip->close();
    return $count;
}

/** Obnoví celou zálohu (kód i databázi). */
function restore_backup(string $id): void
{
    @set_time_limit(300);
    $dir = backup_path($id);
    if (!is_file($dir . '/db.sql.gz') || !is_file($dir . '/code.zip')) {
        throw new RuntimeException('Záloha není kompletní.');
    }
    maintenance(true);
    try {
        restore_code($dir . '/code.zip');
        restore_database(db(), $dir . '/db.sql.gz');
    } finally {
        maintenance(false);
    }
}

/* ---------- Režim údržby ---------- */

function maintenance(?bool $on = null): bool
{
    $flag = data_path('maintenance.flag');
    if ($on === true) {
        file_put_contents($flag, date('c'));
    } elseif ($on === false) {
        @unlink($flag);
    }
    return is_file($flag) && filemtime($flag) > time() - 1800; // pojistka: po 30 min se údržba sama ukončí
}
