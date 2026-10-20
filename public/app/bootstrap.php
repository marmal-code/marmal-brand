<?php
declare(strict_types=1);

/*
 * Brand Hub – zavaděč aplikace.
 * APP_ROOT  = webová složka (např. brand.marmal.cz/)
 * DATA_DIR  = datová složka mimo web (výchozí ../brand-data), drží config, soubory klientů, zálohy.
 */

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', trim((string) @file_get_contents(APP_ROOT . '/VERSION')) ?: '0.0.0');

$dataDir = null;
if (is_file(APP_ROOT . '/data-path.php')) {
    $dataDir = require APP_ROOT . '/data-path.php';
}
if (!is_string($dataDir) || $dataDir === '') {
    $dataDir = dirname(APP_ROOT) . '/brand-data';
}
define('DATA_DIR', rtrim($dataDir, '/\\'));
define('CONFIG_FILE', DATA_DIR . '/config.php');

date_default_timezone_set('Europe/Prague');
mb_internal_encoding('UTF-8');

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/brands.php';
require __DIR__ . '/files.php';
require __DIR__ . '/migrator.php';
require __DIR__ . '/backup.php';
require __DIR__ . '/updater.php';

/** Konfigurace z DATA_DIR/config.php (null = aplikace ještě není nainstalovaná). */
function config(?string $key = null, mixed $default = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = is_file(CONFIG_FILE) ? (require CONFIG_FILE) : [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
    }
    if ($key === null) {
        return $cfg;
    }
    return $cfg[$key] ?? $default;
}

function is_installed(): bool
{
    return is_file(CONFIG_FILE);
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('brandhub');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
