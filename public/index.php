<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$path = rawurldecode($path);

/* ---------- Instalace ---------- */
if (!is_installed()) {
    start_session();
    require __DIR__ . '/app/controllers/install.php';
    install_controller($method);
    exit;
}
if ($path === '/install') {
    abort(404);
}

/* ---------- Údržba (běží aktualizace) ---------- */
if (maintenance()) {
    header('Retry-After: 60');
    abort(503, 'Aplikace se právě aktualizuje. Zkuste to prosím za minutu znovu.');
}

/* ---------- Vlastní doména klienta (např. brand.klient.cz) ---------- */
$appHost = strtolower((string) config('app_host', ''));
$host = request_host();
if ($appHost !== '' && $host !== $appHost && $host !== 'www.' . $appHost) {
    $brand = brand_by_domain($host);
    if (!$brand) {
        abort(404, 'Pro tuto adresu není nastavený žádný brand manuál.');
    }
    domain_brand($brand);
    // Na vlastní doméně je jen klientský pohled.
    $path = '/b/' . $brand['slug'] . ($path === '/' ? '' : $path);
}

start_session();

require __DIR__ . '/app/controllers/admin.php';
require __DIR__ . '/app/controllers/client.php';
require __DIR__ . '/app/controllers/settings.php';

$routes = [
    // veřejné / přihlášení
    ['GET',  '#^/$#', 'home'],
    ['GET',  '#^/login$#', 'login_form'],
    ['POST', '#^/login$#', 'login_submit'],
    ['POST', '#^/logout$#', 'logout'],

    // administrace
    ['GET',  '#^/admin$#', 'admin_dashboard'],
    ['GET',  '#^/admin/activity$#', 'admin_activity'],
    ['GET',  '#^/admin/brands/new$#', 'admin_brand_new'],
    ['POST', '#^/admin/brands/new$#', 'admin_brand_create'],
    ['GET',  '#^/admin/brands/(\d+)$#', 'admin_brand_home'],
    ['GET',  '#^/admin/brands/(\d+)/s/([a-z]+)$#', 'admin_brand_section'],
    ['GET',  '#^/admin/brands/(\d+)/settings$#', 'admin_brand_settings'],
    ['POST', '#^/admin/brands/(\d+)/settings$#', 'admin_brand_settings_save'],
    ['POST', '#^/admin/brands/(\d+)/share$#', 'admin_brand_share_regenerate'],
    ['POST', '#^/admin/brands/(\d+)/delete$#', 'admin_brand_delete'],
    ['POST', '#^/admin/brands/(\d+)/sync$#', 'admin_brand_sync'],
    ['POST', '#^/admin/brands/(\d+)/colors$#', 'admin_colors_save'],
    ['POST', '#^/admin/brands/(\d+)/items$#', 'admin_item_create'],
    ['GET',  '#^/admin/items/(\d+)$#', 'admin_item_edit'],
    ['POST', '#^/admin/items/(\d+)$#', 'admin_item_save'],
    ['POST', '#^/admin/items/(\d+)/upload$#', 'admin_item_upload'],
    ['POST', '#^/admin/items/(\d+)/move$#', 'admin_item_move'],
    ['POST', '#^/admin/items/(\d+)/delete$#', 'admin_item_delete'],
    ['POST', '#^/admin/versions/(\d+)/activate$#', 'admin_version_activate'],
    ['POST', '#^/admin/versions/(\d+)/delete$#', 'admin_version_delete'],
    ['POST', '#^/admin/files/(\d+)/delete$#', 'admin_file_delete'],
    ['GET',  '#^/admin/files/(\d+)$#', 'admin_file_get'],
    ['POST', '#^/admin/files/(\d+)/preview$#', 'admin_file_preview'],
    ['POST', '#^/admin/brands/(\d+)/s/([a-z]+)/text$#', 'admin_section_text_save'],
    ['GET',  '#^/admin/brands/(\d+)/export$#', 'admin_brand_export'],
    ['GET',  '#^/admin/import$#', 'admin_import_form'],
    ['POST', '#^/admin/import$#', 'admin_import_run'],
    ['POST', '#^/admin/upload-chunk$#', 'admin_upload_chunk'],

    // nastavení
    ['GET',  '#^/admin/settings$#', 'settings_profile'],
    ['POST', '#^/admin/settings$#', 'settings_profile_save'],
    ['GET',  '#^/admin/settings/update$#', 'settings_update'],
    ['POST', '#^/admin/settings/update/token$#', 'settings_update_token'],
    ['POST', '#^/admin/settings/update/check$#', 'settings_update_check'],
    ['POST', '#^/admin/settings/update/run$#', 'settings_update_run'],
    ['POST', '#^/admin/settings/backup$#', 'settings_backup_create'],
    ['POST', '#^/admin/settings/backup/restore$#', 'settings_backup_restore'],
    ['GET',  '#^/admin/template$#', 'settings_template'],
    ['POST', '#^/admin/template$#', 'settings_template_save'],
    ['POST', '#^/admin/template/new$#', 'settings_template_create'],
    ['POST', '#^/admin/template/(\d+)/delete$#', 'settings_template_delete'],

    // klientský pohled
    ['GET',  '#^/b/([a-z0-9-]+)$#', 'client_home'],
    ['POST', '#^/b/([a-z0-9-]+)/login$#', 'client_login'],
    ['POST', '#^/b/([a-z0-9-]+)/logout$#', 'client_logout'],
    ['GET',  '#^/b/([a-z0-9-]+)/s/([a-z]+)$#', 'client_section'],
    ['GET',  '#^/b/([a-z0-9-]+)/file/(\d+)$#', 'client_file'],
    ['GET',  '#^/b/([a-z0-9-]+)/f/([a-z0-9-]+)\.([a-z0-9]+)$#', 'client_permalink'],
    ['GET',  '#^/b/([a-z0-9-]+)/manual$#', 'client_print'],
    ['GET',  '#^/b/([a-z0-9-]+)/zip$#', 'client_zip'],
    ['GET',  '#^/b/([a-z0-9-]+)/zip/([a-z]+)$#', 'client_zip'],
];

$allowed = false;
foreach ($routes as [$m, $pattern, $handler]) {
    if (!preg_match($pattern, $path, $match)) {
        continue;
    }
    $allowed = true;
    if ($m !== $method && !($m === 'GET' && $method === 'HEAD')) {
        continue;
    }
    if ($method === 'POST') {
        csrf_check();
    }
    array_shift($match);
    try {
        $handler(...$match);
    } catch (Throwable $e) {
        error_log('[brand-hub] ' . $e);
        if (str_starts_with($path, '/admin') && current_admin()) {
            abort(500, $e->getMessage());
        }
        abort(500, 'Něco se pokazilo. Zkuste to prosím znovu.');
    }
    exit;
}
abort($allowed ? 405 : 404);
