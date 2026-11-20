<?php
declare(strict_types=1);

/*
 * AI konektor (MCP) – obsluha adres. Logika: app/mcp.php (nástroje) a app/mcp_oauth.php (přihlášení).
 *
 * Bez relace a bez CSRF (autorizace tokenem), volá je index.php ještě před směrovačem:
 *   POST /mcp                                   MCP server (JSON-RPC)
 *   GET  /.well-known/oauth-protected-resource  metadata (RFC 9728)
 *   GET  /.well-known/oauth-authorization-server metadata (RFC 8414)
 *   POST /oauth/register | /oauth/token | /oauth/revoke
 *   GET  /mcp/soubor/{id}/{platnost}/{podpis}/{název}   dočasný podepsaný odkaz na soubor
 *   PUT|POST /mcp/nahrat/{token}                jednorázové nahrání souboru
 * Se session přes směrovač: /oauth/authorize (souhlas), /admin/settings/ai (nastavení).
 */

/** Je cesta adresou konektoru, kterou obsluhuje mcp_dispatch()? */
function mcp_is_endpoint(string $path): bool
{
    return (bool) preg_match('#^/(mcp(/.*)?|oauth/(token|register|revoke)|\.well-known/oauth-(protected-resource|authorization-server)(/.*)?)$#', $path);
}

function mcp_dispatch(string $method, string $path): void
{
    try {
        if (preg_match('#^/\.well-known/oauth-(protected-resource|authorization-server)#', $path, $m)) {
            mcp_preflight();
            mcp_require_enabled();
            mcp_json($m[1] === 'protected-resource' ? mcp_metadata_resource() : mcp_metadata_server());
            return;
        }
        if ($path === '/oauth/register') {
            mcp_endpoint_register($method);
            return;
        }
        if ($path === '/oauth/token' || $path === '/oauth/revoke') {
            mcp_endpoint_token($method, $path === '/oauth/revoke');
            return;
        }
        if ($path === '/mcp') {
            mcp_endpoint_server($method);
            return;
        }
        if (preg_match('#^/mcp/soubor/(\d+)/(\d+)/([a-f0-9]{32})(/.*)?$#', $path, $m)) {
            mcp_endpoint_download((int) $m[1], (int) $m[2], $m[3]);
            return;
        }
        if (preg_match('#^/mcp/nahrat/(mbu_[a-f0-9]{48})$#', $path, $m)) {
            mcp_endpoint_upload($method, $m[1]);
            return;
        }
        mcp_json(['error' => 'not_found'], 404);
    } catch (Throwable $e) {
        error_log('[brand-hub] MCP ' . $path . ': ' . $e);
        if (!headers_sent()) {
            mcp_json(['error' => 'server_error', 'error_description' => 'Chyba aplikace.'], 500);
        }
    }
}

function mcp_endpoint_register(string $method): void
{
    mcp_preflight();
    mcp_require_enabled();
    if ($method !== 'POST') {
        mcp_json(['error' => 'invalid_request', 'error_description' => 'Použijte POST s JSON.'], 405, ['Allow: POST, OPTIONS']);
        return;
    }
    $req = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($req)) {
        mcp_json(['error' => 'invalid_client_metadata', 'error_description' => 'Tělo požadavku není JSON.'], 400);
        return;
    }
    try {
        mcp_json(mcp_register($req, client_ip()), 201);
    } catch (McpOAuthError $e) {
        mcp_json(['error' => $e->errorCode, 'error_description' => $e->getMessage()], $e->status);
    }
}

function mcp_endpoint_token(string $method, bool $revoke): void
{
    mcp_preflight();
    mcp_require_enabled();
    if ($method !== 'POST') {
        mcp_json(['error' => 'invalid_request', 'error_description' => 'Použijte POST.'], 405, ['Allow: POST, OPTIONS']);
        return;
    }
    $post = $_POST;
    if (!$post && str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'json')) {
        $post = json_decode((string) file_get_contents('php://input'), true) ?: [];
    }
    try {
        if ($revoke) {
            mcp_revoke($post);
            mcp_json(new stdClass());
        } else {
            mcp_json(mcp_token_endpoint($post));
        }
    } catch (McpOAuthError $e) {
        mcp_json(['error' => $e->errorCode, 'error_description' => $e->getMessage()], $e->status, $e->status === 401 ? ['WWW-Authenticate: Basic realm="MarMal Brand Hub"'] : []);
    }
}

function mcp_endpoint_server(string $method): void
{
    mcp_preflight();
    mcp_require_enabled();
    if ($method !== 'POST') {
        // bez SSE streamu a bez session – server odpovídá jen na POST (Streamable HTTP to dovoluje)
        mcp_json(['nazev' => 'MarMal Brand Hub – AI konektor (MCP)', 'adresa' => mcp_url(),
            'navod' => 'Tuto adresu vložte do Claude, ChatGPT nebo Gemini jako vlastní konektor. Nastavení: ' . mcp_base() . '/admin/settings/ai'],
            405, ['Allow: POST, OPTIONS']);
        return;
    }
    $reason = null;
    $token = mcp_verify_token($reason);
    if (!$token) {
        mcp_unauthorized((string) $reason);
        return;
    }
    if (mt_rand(1, 200) === 1) {
        mcp_cleanup();
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if ($body === null) {
        mcp_json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Tělo požadavku není platný JSON.']], 400);
        return;
    }
    $batch = is_array($body) && array_is_list($body) && $body !== [];
    $replies = [];
    foreach ($batch ? $body : [$body] as $msg) {
        $r = mcp_handle_message($msg, $token);
        if ($r !== null) {
            $replies[] = $r;
        }
    }
    if (!$replies) {
        http_response_code(202); // jen notifikace
        mcp_cors();
        return;
    }
    mcp_json($batch ? $replies : $replies[0]);
}

/** Dočasný odkaz na soubor: podpis HMAC tajným klíčem aplikace, platnost v adrese. */
function mcp_endpoint_download(int $fileId, int $expires, string $sig): void
{
    if (!mcp_enabled()) {
        abort(403, 'AI konektor je vypnutý.');
    }
    if (!hash_equals(mcp_download_sig($fileId, $expires), $sig)) {
        abort(403, 'Odkaz je neplatný.');
    }
    if ($expires < time()) {
        abort(410, 'Platnost odkazu vypršela. Vyžádejte si nový.');
    }
    $file = db_one('SELECT * FROM item_files WHERE id = ?', [$fileId]) ?? abort(404, 'Soubor už neexistuje.');
    header('Access-Control-Allow-Origin: *');
    header('X-Robots-Tag: noindex');
    stream_file(stored_file_path($file), (string) $file['original_name'], !empty($_GET['inline']), (string) $file['mime'], 0);
}

/** Jednorázové nahrání souboru (PUT tělo, nebo POST multipart s polem „file“). */
function mcp_endpoint_upload(string $method, string $token): void
{
    mcp_preflight();
    mcp_require_enabled();
    if (!in_array($method, ['PUT', 'POST'], true)) {
        mcp_json(['ok' => false, 'error' => 'Nahrávejte metodou PUT (curl -T soubor adresa) nebo POST s polem file.'], 405, ['Allow: PUT, POST, OPTIONS']);
        return;
    }
    $row = db_one('SELECT * FROM mcp_uploads WHERE token_hash = ?', [mcp_hash($token)]);
    if (!$row || $row['expires_at'] < date('Y-m-d H:i:s')) {
        mcp_json(['ok' => false, 'error' => 'Odkaz pro nahrání neplatí nebo vypršel. Připravte nový (nahravani_pripravit).'], 404);
        return;
    }
    if ($row['uploaded_at']) {
        mcp_json(['ok' => false, 'error' => 'Na tento odkaz už soubor nahraný je, odkaz je jednorázový.'], 409);
        return;
    }
    @set_time_limit(0);
    ensure_dir(data_path('tmp/mcp'));
    $target = mcp_upload_path((int) $row['id']);
    $name = trim((string) ($_GET['nazev'] ?? $_SERVER['HTTP_X_FILENAME'] ?? ''));
    try {
        if (!empty($_FILES['file'])) {
            $f = $_FILES['file'];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Soubor se nenahrál (chyba ' . ($f['error'] ?? '?') . ', limit serveru ' . ini_get('upload_max_filesize') . '). Zkuste curl -T.');
            }
            if ($f['size'] > MCP_UPLOAD_MAX_BYTES || !move_uploaded_file($f['tmp_name'], $target)) {
                throw new RuntimeException('Soubor se nepodařilo uložit.');
            }
            $name = $name ?: (string) $f['name'];
        } else {
            $in = fopen('php://input', 'rb');
            $out = fopen($target, 'wb');
            if (!$in || !$out) {
                throw new RuntimeException('Nelze zapisovat do dočasné složky.');
            }
            $size = 0;
            while (!feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false) {
                    break;
                }
                $size += strlen($chunk);
                if ($size > MCP_UPLOAD_MAX_BYTES) {
                    fclose($out);
                    @unlink($target);
                    throw new RuntimeException('Soubor je větší než ' . human_size(MCP_UPLOAD_MAX_BYTES) . '.');
                }
                fwrite($out, $chunk);
            }
            fclose($in);
            fclose($out);
        }
        clearstatcache(true, $target);
        $size = is_file($target) ? (int) filesize($target) : 0;
        if ($size === 0) {
            @unlink($target);
            throw new RuntimeException('Nepřišla žádná data.');
        }
    } catch (RuntimeException $e) {
        mcp_json(['ok' => false, 'error' => $e->getMessage()], 400);
        return;
    }
    $name = $name !== '' ? mb_substr(basename(str_replace('\\', '/', $name)), 0, 255) : (string) $row['name'];
    db_exec('UPDATE mcp_uploads SET size = ?, name = ?, uploaded_at = NOW() WHERE id = ?', [$size, $name ?: null, $row['id']]);
    mcp_json(['ok' => true, 'nahravani' => $token, 'nazev' => $name, 'velikost' => human_size($size),
        'dalsi_krok' => 'Soubor je nahraný. Přidejte ho přes soubor_pridat: soubory: [{"nazev": "' . ($name ?: 'soubor.png') . '", "nahravani": "' . $token . '"}].']);
}

/* ================= Souhlas s připojením (OAuth authorize) ================= */

/** Stránka souhlasu smí po odeslání přesměrovat zpět do AI aplikace (CSP form-action). */
function oauth_consent_csp(?string $redirectUri): void
{
    $origin = '';
    if ($redirectUri) {
        $p = parse_url($redirectUri);
        if (!empty($p['scheme']) && !empty($p['host'])) {
            $origin = ' ' . $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        }
    }
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; object-src 'none'; "
        . "base-uri 'self'; form-action 'self'" . $origin . "; frame-ancestors 'none'");
    header('Cache-Control: no-store');
}

function oauth_authorize(): void
{
    oauth_consent_csp(null);
    if (!mcp_enabled()) {
        render('oauth_authorize', ['error' => mcp_available() ? 'AI konektor je vypnutý. Zapnete ho v Nastavení → AI konektor.' : 'AI konektor ještě nemá databázi – dokončete aktualizaci aplikace.'], 'plain');
    }
    if (!current_admin()) {
        $_SESSION['after_login'] = (string) ($_SERVER['REQUEST_URI'] ?? '/oauth/authorize');
        flash('success', 'Pro připojení AI aplikace se nejdřív přihlaste.');
        redirect('/login');
    }
    try {
        [$client, $p] = mcp_authorize_params($_GET);
    } catch (McpOAuthError $e) {
        render('oauth_authorize', ['error' => $e->getMessage()], 'plain');
    }
    if ($p['error'] !== null) {
        redirect(mcp_redirect_url($p['redirect_uri'], ['error' => $p['error'][0], 'error_description' => $p['error'][1], 'state' => $p['state'], 'iss' => mcp_base()]));
    }
    $rid = bin2hex(random_bytes(12));
    $_SESSION['mcp_consent'] = array_slice($_SESSION['mcp_consent'] ?? [], -4, null, true);
    $_SESSION['mcp_consent'][$rid] = $p + ['at' => time()];
    oauth_consent_csp($p['redirect_uri']);
    render('oauth_authorize', [
        'error' => null,
        'client' => $client,
        'rid' => $rid,
        'returnHost' => (string) parse_url($p['redirect_uri'], PHP_URL_HOST),
        'writeDefault' => $p['scope_requested'] === '' || str_contains($p['scope'], 'zapis'),
    ], 'plain');
}

function oauth_authorize_submit(): void
{
    oauth_consent_csp(null);
    $admin = current_admin();
    if (!$admin) {
        redirect('/login');
    }
    $rid = (string) ($_POST['rid'] ?? '');
    $p = $_SESSION['mcp_consent'][$rid] ?? null;
    unset($_SESSION['mcp_consent'][$rid]);
    if (!is_array($p) || ($p['at'] ?? 0) < time() - 1800) {
        render('oauth_authorize', ['error' => 'Žádost o připojení vypršela. Začněte připojení v AI aplikaci znovu.'], 'plain');
    }
    if (($_POST['action'] ?? '') !== 'allow') {
        redirect(mcp_redirect_url($p['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'Připojení nebylo povoleno.', 'state' => $p['state'], 'iss' => mcp_base()]));
    }
    $scope = 'cteni' . (!empty($_POST['write']) ? ' zapis' : '');
    $code = mcp_code_create($p, (int) $admin['id'], $scope);
    redirect(mcp_redirect_url($p['redirect_uri'], ['code' => $code, 'state' => $p['state'], 'iss' => mcp_base()]));
}

/* ================= Nastavení → AI konektor ================= */

function settings_ai(): void
{
    $admin = require_admin();
    $available = mcp_available();
    // adresa aplikace pro metadata – uloží se při prvním otevření (ochrana před podvrženou hlavičkou Host)
    if ($available && (string) setting('mcp.base', '') === '') {
        set_setting('mcp.base', mcp_base_from_request());
    }
    $once = $_SESSION['mcp_once'] ?? null;
    unset($_SESSION['mcp_once']);
    render('admin/settings_ai', [
        'title' => 'AI konektor', 'nav' => 'settings', 'tab' => 'ai', 'me' => $admin,
        'available' => $available,
        'enabled' => mcp_enabled(),
        'url' => $available ? mcp_url() : '',
        'tools' => mcp_tools(),
        'connections' => $available ? mcp_connections() : [],
        'log' => $available ? db_all('SELECT * FROM mcp_log ORDER BY id DESC LIMIT 40') : [],
        'callsToday' => $available ? (int) db_val('SELECT COUNT(*) FROM mcp_log WHERE created_at >= CURDATE()') : 0,
        'diagnostics' => $available && isset($_GET['kontrola']) ? mcp_diagnostics() : null,
        'once' => is_array($once) ? $once : null,
    ]);
}

function settings_ai_save(): void
{
    require_admin();
    if (!mcp_available()) {
        redirect('/admin/settings/ai');
    }
    set_setting('mcp.enabled', !empty($_POST['enabled']) ? '1' : '0');
    $on = (array) ($_POST['tool'] ?? []);
    foreach (array_keys(mcp_tools()) as $name) {
        set_setting('mcp.tool.' . $name, !empty($on[$name]) ? '1' : '0');
    }
    log_activity(null, 'admin', 'ai', 'Upraveno nastavení AI konektoru' . (empty($_POST['enabled']) ? ' (vypnutý)' : ''));
    flash('success', 'Nastavení AI konektoru je uložené.');
    redirect('/admin/settings/ai');
}

function settings_ai_disconnect(): void
{
    require_admin();
    $id = (int) ($_POST['id'] ?? 0);
    $row = db_one('SELECT t.*, c.name AS client_name FROM mcp_tokens t LEFT JOIN mcp_clients c ON c.client_id = t.client_id WHERE t.id = ?', [$id]);
    if ($row) {
        mcp_disconnect($id);
        log_activity(null, 'admin', 'ai', 'Odpojena AI aplikace ' . ($row['client_name'] ?: $row['name'] ?: '#' . $id));
        flash('success', 'Připojení „' . ($row['client_name'] ?: $row['name']) . '“ je odpojené. Aplikace se k Brand Hubu dostane až po novém připojení.');
    }
    redirect('/admin/settings/ai#pripojeni');
}

function settings_ai_token(): void
{
    $admin = require_admin();
    try {
        $token = mcp_manual_token((string) ($_POST['name'] ?? ''), !empty($_POST['write']) ? 'cteni zapis' : 'cteni', (int) $admin['id']);
        $_SESSION['mcp_once'] = ['token' => $token, 'name' => trim((string) $_POST['name'])];
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/settings/ai#tokeny');
}

function settings_ai_base(): void
{
    require_admin();
    set_setting('mcp.base', mcp_base_from_request());
    flash('success', 'Adresa konektoru je nastavená na ' . mcp_url() . '.');
    redirect('/admin/settings/ai');
}

/**
 * Kontrola serveru: dotazy na vlastní adresy jako AI aplikace – metadata přes .well-known, /mcp bez přihlášení
 * (401 s odkazem na metadata) a s testovacím tokenem (dojde hlavička Authorization do PHP?).
 */
function mcp_diagnostics(): array
{
    $b = mcp_base();
    $request = function (string $url, string $method = 'GET', array $headers = []): array {
        if (!function_exists('curl_init')) {
            return ['code' => 0, 'headers' => '', 'json' => null, 'error' => 'chybí curl'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json, text/event-stream'], $headers),
            CURLOPT_POSTFIELDS => $method === 'POST' ? '{"jsonrpc":"2.0","id":1,"method":"ping"}' : null,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err = curl_error($ch);
        return ['code' => $code, 'headers' => $res !== false ? substr((string) $res, 0, $hs) : '', 'json' => $res !== false ? json_decode(substr((string) $res, $hs), true) : null, 'error' => $err];
    };
    $out = [];
    $out[] = ['HTTPS', str_starts_with($b, 'https://'), str_starts_with($b, 'https://') ? $b : 'Aplikace neběží přes HTTPS – AI aplikace se připojí jen k adrese https://.'];
    $r = $request($b . '/.well-known/oauth-protected-resource');
    $out[] = ['Metadata konektoru', $r['code'] === 200 && ($r['json']['resource'] ?? '') === mcp_url(),
        $r['code'] === 200 ? 'V pořádku (' . ($r['json']['resource'] ?? '?') . ')' : 'HTTP ' . $r['code'] . ' ' . $r['error'] . ' – nefunguje přepis adres v .htaccess.'];
    $r = $request($b . '/.well-known/oauth-authorization-server');
    $out[] = ['Metadata přihlášení', $r['code'] === 200 && ($r['json']['issuer'] ?? '') === $b,
        $r['code'] === 200 ? 'V pořádku' : 'HTTP ' . $r['code'] . ' ' . $r['error'] . ' – nefunguje přepis adres v .htaccess.'];
    $r = $request($b . '/mcp', 'POST');
    $out[] = ['Adresa /mcp', $r['code'] === 401 && stripos($r['headers'], 'resource_metadata=') !== false,
        $r['code'] === 401 ? 'V pořádku – bez přihlášení odmítne a ukáže cestu k přihlášení' : 'HTTP ' . $r['code'] . ' ' . $r['error'] . ' (čekal jsem 401).'];
    $r = $request($b . '/mcp', 'POST', ['Authorization: Bearer test-diagnostika']);
    $out[] = ['Předání tokenu do PHP', $r['code'] === 401 && ($r['json']['reason'] ?? '') === 'invalid',
        ($r['json']['reason'] ?? '') === 'invalid' ? 'V pořádku – hlavička Authorization dojde až do aplikace'
            : 'Server hlavičku Authorization zahodil. V Plesku: Weby a domény → brand.marmal.cz → Nastavení Apache a nginx → do „Další direktivy“ pro HTTP i HTTPS doplňte: CGIPassAuth On'];
    return $out;
}
