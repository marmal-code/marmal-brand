<?php
declare(strict_types=1);

/** Pevná šablona sekcí brand manuálu. Pořadí = pořadí v navigaci. */
const SECTIONS = [
    'logo'       => ['title' => 'Logo',             'icon' => 'image',    'lead' => 'Všechny varianty loga v aktuální verzi.'],
    'barvy'      => ['title' => 'Barvy',            'icon' => 'palette',  'lead' => 'Barevná paleta značky s hodnotami pro web i tisk.'],
    'typografie' => ['title' => 'Typografie',       'icon' => 'type',     'lead' => 'Písma značky a jejich použití.'],
    'tisk'       => ['title' => 'Tisk',             'icon' => 'printer',  'lead' => 'Tiskoviny připravené pro tiskárnu.'],
    'digital'    => ['title' => 'Digitál a sítě',   'icon' => 'monitor',  'lead' => 'Podklady pro web a sociální sítě.'],
    'kancelar'   => ['title' => 'Kancelář',         'icon' => 'mail',     'lead' => 'E-mailový podpis a šablony dokumentů.'],
    'pravidla'   => ['title' => 'Pravidla použití', 'icon' => 'shield',   'lead' => 'Jak se značkou správně zacházet.'],
];

/** Přípony, které lze nahrát. Soubory leží mimo web, přesto spustitelné typy nepouštíme. */
const ALLOWED_EXT = [
    'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'ico', 'pdf', 'ai', 'eps', 'psd', 'tif', 'tiff',
    'afdesign', 'afpub', 'afphoto', 'indd', 'idml', 'ttf', 'otf', 'woff', 'woff2', 'zip',
    'doc', 'docx', 'dotx', 'xls', 'xlsx', 'ppt', 'pptx', 'potx', 'odt', 'html', 'htm', 'txt', 'mp4', 'mov', 'webm',
];
const IMAGE_EXT = ['svg', 'png', 'jpg', 'jpeg', 'webp', 'gif'];
const FONT_EXT = ['woff2', 'woff', 'ttf', 'otf'];

/** Výchozí kontakt v kartě „Brand spravuje“ (nový klient, import bez kontaktu). */
const DEFAULT_CONTACT_PHONE = '+420 724 257 563';
const DEFAULT_CONTACT_EMAIL = 'martin@marmal.cz';

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Jen odkazy http(s) – jinak prázdný řetězec (ochrana proti odkazům javascript: a data:). */
function safe_url(?string $url): string
{
    $url = trim((string) $url);
    return preg_match('#^https?://[^\s<>"]+$#i', $url) ? $url : '';
}

/** Bezpečnostní hlavičky pro stránky aplikace (soubory klientů mají vlastní, přísnější CSP ve stream_file). */
function send_security_headers(): void
{
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; object-src 'none'; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=15552000');
    }
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function request_host(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return preg_replace('/:\d+$/', '', $host) ?? $host;
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function redirect(string $to, int $code = 302): never
{
    header('Location: ' . $to, true, $code);
    exit;
}

function abort(int $code, string $message = ''): never
{
    http_response_code($code);
    $titles = [403 => 'Přístup odepřen', 404 => 'Stránka nenalezena', 405 => 'Nepovolená metoda', 419 => 'Platnost formuláře vypršela', 500 => 'Chyba aplikace', 503 => 'Probíhá údržba'];
    $title = $titles[$code] ?? 'Chyba';
    echo view('error', ['code' => $code, 'title' => $title, 'message' => $message], 'plain');
    exit;
}

function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Vykreslí šablonu z views/. $layout: 'admin', 'client', 'plain' nebo null (bez layoutu). */
function view(string $name, array $vars = [], ?string $layout = null): string
{
    $file = APP_ROOT . '/views/' . $name . '.php';
    if (!is_file($file)) {
        throw new RuntimeException('Chybí šablona ' . $name);
    }
    extract($vars, EXTR_SKIP);
    ob_start();
    require $file;
    $content = (string) ob_get_clean();
    if ($layout === null) {
        return $content;
    }
    $vars['content'] = $content;
    return view('layout_' . $layout, $vars);
}

function render(string $name, array $vars = [], string $layout = 'admin'): never
{
    echo view($name, $vars, $layout);
    exit;
}

/* ---------- CSRF a flash zprávy ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        abort(419, 'Formulář byl otevřený příliš dlouho. Načtěte stránku znovu a zkuste to ještě jednou.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function old(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

/* ---------- Formátování ---------- */

function slugify(string $text): string
{
    $map = ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ľ' => 'l', 'ĺ' => 'l', 'ŕ' => 'r', 'ô' => 'o'];
    $text = strtr(mb_strtolower($text), $map);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'polozka';
}

function human_size(int|float $bytes): string
{
    $units = ['B', 'kB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return str_replace('.', ',', (string) round($bytes, $i >= 2 ? 1 : 0)) . ' ' . $units[$i];
}

function cz_date(?string $datetime, bool $withTime = false): string
{
    if (!$datetime) {
        return '–';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '–';
    }
    return date($withTime ? 'j. n. Y H:i' : 'j. n. Y', $ts);
}

function relative_day(?string $datetime): string
{
    if (!$datetime) {
        return '–';
    }
    $ts = strtotime($datetime);
    $d = date('Y-m-d', $ts);
    if ($d === date('Y-m-d')) {
        return 'dnes ' . date('G:i', $ts);
    }
    if ($d === date('Y-m-d', strtotime('-1 day'))) {
        return 'včera ' . date('G:i', $ts);
    }
    return date('j. n.', $ts) . (date('Y', $ts) !== date('Y') ? ' ' . date('Y', $ts) : '');
}

function hex_to_rgb(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
        return '';
    }
    return hexdec(substr($hex, 0, 2)) . ' ' . hexdec(substr($hex, 2, 2)) . ' ' . hexdec(substr($hex, 4, 2));
}

/** Automatický převod do CMYK (orientační – pro tisk je lepší zadat hodnoty od grafika). */
function hex_to_cmyk(string $hex): string
{
    $rgb = hex_to_rgb($hex);
    if ($rgb === '') {
        return '';
    }
    [$r, $g, $b] = array_map(fn ($v) => (int) $v / 255, explode(' ', $rgb));
    $k = 1 - max($r, $g, $b);
    if ($k >= 1) {
        return '0 0 0 100';
    }
    $c = (1 - $r - $k) / (1 - $k);
    $m = (1 - $g - $k) / (1 - $k);
    $y = (1 - $b - $k) / (1 - $k);
    return implode(' ', array_map(fn ($v) => (string) round($v * 100), [$c, $m, $y, $k]));
}

function normalize_hex(string $hex): ?string
{
    $hex = strtoupper(trim($hex));
    if ($hex !== '' && $hex[0] !== '#') {
        $hex = '#' . $hex;
    }
    if (preg_match('/^#[0-9A-F]{3}$/', $hex)) {
        $hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
    }
    return preg_match('/^#[0-9A-F]{6}$/', $hex) ? $hex : null;
}

/** Je barva tmavá? (pro volbu barvy textu na ploše) */
function is_dark(string $hex): bool
{
    $rgb = hex_to_rgb($hex);
    if ($rgb === '') {
        return false;
    }
    [$r, $g, $b] = array_map('intval', explode(' ', $rgb));
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) < 150;
}

/* ---------- Nastavení v DB ---------- */

function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT k, v FROM settings') as $row) {
                $cache[$row['k']] = $row['v'];
            }
        } catch (Throwable) {
        }
    }
    if (func_num_args() === 3) { // interní reset
        $cache = null;
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, ?string $value): void
{
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$key, $value]);
    setting('', null, true);
}

/* ---------- Šifrování citlivých nastavení (GitHub token) ---------- */

function encrypt_secret(string $plain): string
{
    $key = hash('sha256', (string) config('secret'), true);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
}

function decrypt_secret(?string $stored): string
{
    if (!$stored) {
        return '';
    }
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return '';
    }
    $key = hash('sha256', (string) config('secret'), true);
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
    return $plain === false ? '' : $plain;
}

/* ---------- Aktivita ---------- */

function log_activity(?int $brandId, string $actor, string $action, string $detail = ''): void
{
    try {
        db()->prepare('INSERT INTO activity (brand_id, actor, action, detail, created_at) VALUES (?, ?, ?, ?, NOW())')
            ->execute([$brandId, $actor, $action, mb_substr($detail, 0, 255)]);
    } catch (Throwable) {
    }
}

/* ---------- Ikony (lineární, oblé konce, tah 1,6) ---------- */

function icon(string $name, int $size = 20, string $extra = ''): string
{
    static $paths = [
        'download' => '<path d="M12 3v12M7 10l5 5 5-5M4 20h16"/>',
        'upload'   => '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>',
        'eye'      => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'link'     => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'copy'     => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1 7 17M17 7l2.1-2.1"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.5 3.4-5.5 6.5-5.5s5.7 2 6.5 5.5M16 4.5a3.5 3.5 0 0 1 0 7M18.5 14.8c1.6.7 2.6 2.4 3 5.2"/>',
        'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'activity' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'logout'   => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l-5-5 5-5M5 12h11"/>',
        'printer'  => '<path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v7H6z"/>',
        'monitor'  => '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'phone'    => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'shield'   => '<path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z"/><path d="M9 12l2 2 4-4"/>',
        'check'    => '<path d="M5 12l5 5 9-10"/>',
        'alert'    => '<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17h.01"/>',
        'trash'    => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'history'  => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
        'refresh'  => '<path d="M20 11a8 8 0 0 0-14.9-3M4 13a8 8 0 0 0 14.9 3"/><path d="M4 4v4h4M20 20v-4h-4"/>',
        'edit'     => '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/>',
        'palette'  => '<path d="M12 3a9 9 0 0 0 0 18c1.2 0 2-.8 2-2 0-.6-.2-1-.5-1.4-.3-.4-.5-.8-.5-1.3 0-1 .8-1.8 1.8-1.8H17a4 4 0 0 0 4-4c0-4.2-4-7.5-9-7.5z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10" cy="7" r="1"/><circle cx="15" cy="7.5" r="1"/>',
        'type'     => '<path d="M4 7V5h16v2M12 5v14M9 19h6"/>',
        'image'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>',
        'external' => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'arrow-left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'lock'     => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'file'     => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>',
        'menu'     => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
        'up'       => '<path d="M12 19V5M6 11l6-6 6 6"/>',
        'down'     => '<path d="M12 5v14M6 13l6 6 6-6"/>',
    ];
    $p = $paths[$name] ?? $paths['file'];
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"' . $extra . '>' . $p . '</svg>';
}
