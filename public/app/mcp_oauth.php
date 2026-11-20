<?php
declare(strict_types=1);

/*
 * AI konektor (MCP) – přihlášení aplikací Claude, ChatGPT a Gemini přes OAuth 2.1 (migrace 008_mcp.sql).
 * Převzato z aplikace MarMal Správa (sprava.marmal.cz, includes/mcp_oauth.php) a upraveno pro Brand Hub.
 *
 *  - metadata: /.well-known/oauth-protected-resource (RFC 9728) a /.well-known/oauth-authorization-server (RFC 8414)
 *  - registrace aplikace: POST /oauth/register (Dynamic Client Registration, RFC 7591) – Claude a ChatGPT se registrují samy
 *  - souhlas: /oauth/authorize (přihlášení administrátora včetně 2FA + volba „čtení“ / „zápis“), PKCE S256 povinné
 *  - tokeny: POST /oauth/token (authorization_code, refresh_token s rotací; předchozí refresh platí ještě 10 minut),
 *    POST /oauth/revoke (RFC 7009)
 *  - ruční tokeny (Claude Code, testy): vytvoří se v Nastavení → AI konektor, platí do odvolání
 *
 * V databázi jsou jen SHA-256 hashe tokenů, kódů a tajných klíčů.
 */

const MCP_SCOPES = [
    'cteni' => 'Čtení – klienti, logo, barvy, písma, soubory a texty sekcí (stažení souborů)',
    'zapis' => 'Zápis – nahrání souborů (nová verze, stará zůstane v historii), nové pozice, úpravy popisů, barev a textů sekcí. Nic se nemaže.',
];
const MCP_ACCESS_SECONDS = 3600;          // platnost přístupového tokenu
const MCP_REFRESH_DAYS = 90;              // obnovovací token (klouzavě od posledního použití)
const MCP_CODE_SECONDS = 600;             // autorizační kód
const MCP_REFRESH_OVERLAP_SECONDS = 600;  // předchozí obnovovací token platí ještě chvíli (souběžné požadavky aplikace)
const MCP_DCR_PER_HOUR = 20;              // nejvýš registrací aplikací z jedné IP za hodinu

/** Chyba OAuth s kódem podle RFC 6749 (invalid_request, invalid_grant…) a HTTP stavem. */
class McpOAuthError extends RuntimeException
{
    public function __construct(public string $errorCode, string $description, public int $status = 400)
    {
        parent::__construct($description);
    }
}

/** Je databáze konektoru (migrace 008)? */
function mcp_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool) db_val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mcp_tokens'");
        } catch (Throwable) {
            $ok = false;
        }
    }
    return $ok;
}

/** Konektor zapnutý v Nastavení → AI konektor (po migraci zapnutý). */
function mcp_enabled(): bool
{
    return mcp_available() && setting('mcp.enabled', '1') === '1';
}

/** Běží požadavek na hlavní adrese aplikace (ne na vlastní doméně klienta)? */
function mcp_on_app_host(): bool
{
    $appHost = strtolower((string) config('app_host', ''));
    $host = request_host();
    return $appHost === '' || $host === $appHost || $host === 'www.' . $appHost || $host === 'localhost' || $host === '127.0.0.1';
}

/**
 * Základní adresa aplikace (https://brand.marmal.cz). Uloží se při prvním otevření nastavení konektoru,
 * aby metadata nešla podvrhnout hlavičkou Host. Do té doby z config app_host, nakonec z požadavku.
 */
function mcp_base(): string
{
    $saved = (string) setting('mcp.base', '');
    if ($saved !== '') {
        return rtrim($saved, '/');
    }
    $appHost = (string) config('app_host', '');
    if ($appHost !== '' && request_host() === strtolower($appHost)) {
        return mcp_base_from_request();
    }
    return $appHost !== '' ? 'https://' . $appHost : mcp_base_from_request();
}

function mcp_base_from_request(): string
{
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return (is_https() ? 'https' : 'http') . '://' . $host;
}

/** Adresa MCP serveru, kterou se aplikace připojují (resource). */
function mcp_url(): string
{
    return mcp_base() . '/mcp';
}

/** Je adresa resource (RFC 8707) tahle aplikace? Přijme základ i /mcp, s lomítkem i bez. */
function mcp_resource_ours(string $resource): bool
{
    $r = rtrim(strtolower(trim($resource)), '/');
    $base = strtolower(mcp_base());
    return in_array($r, [$base, $base . '/mcp'], true);
}

function mcp_hash(string $secret): string
{
    return hash('sha256', $secret);
}

function mcp_random(string $prefix): string
{
    return $prefix . bin2hex(random_bytes(32));
}

/** Platné scope z textu („cteni zapis“); prázdné = vše. */
function mcp_scope_normalize(?string $scope): string
{
    $parts = array_values(array_intersect(array_keys(MCP_SCOPES), preg_split('/\s+/', trim((string) $scope)) ?: []));
    return implode(' ', $parts ?: array_keys(MCP_SCOPES));
}

function mcp_has_scope(array $token, string $scope): bool
{
    return in_array($scope, explode(' ', (string) $token['scope']), true);
}

/* ---------- Metadata ---------- */

/** RFC 9728 – kdo vydává tokeny pro MCP server. */
function mcp_metadata_resource(): array
{
    return [
        'resource' => mcp_url(),
        'authorization_servers' => [mcp_base()],
        'scopes_supported' => array_keys(MCP_SCOPES),
        'bearer_methods_supported' => ['header'],
        'resource_name' => 'MarMal Brand Hub',
    ];
}

/** RFC 8414 – adresy OAuth serveru. */
function mcp_metadata_server(): array
{
    $b = mcp_base();
    return [
        'issuer' => $b,
        'authorization_endpoint' => $b . '/oauth/authorize',
        'token_endpoint' => $b . '/oauth/token',
        'registration_endpoint' => $b . '/oauth/register',
        'revocation_endpoint' => $b . '/oauth/revoke',
        'scopes_supported' => array_keys(MCP_SCOPES),
        'response_types_supported' => ['code'],
        'response_modes_supported' => ['query'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
        'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
        'service_documentation' => $b . '/admin/settings/ai',
    ];
}

/** JSON odpověď s CORS (autorizace je jen přes Bearer, ne přes cookies). */
function mcp_json(array|stdClass $data, int $status = 200, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    mcp_cors();
    foreach ($headers as $h) {
        header($h);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function mcp_cors(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID, X-Filename');
    header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id');
    header('Access-Control-Max-Age: 86400');
}

/** Preflight OPTIONS – odpoví a skončí. */
function mcp_preflight(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        mcp_cors();
        exit;
    }
}

/** Konektor vypnutý nebo bez migrace – odpoví chybou a skončí. */
function mcp_require_enabled(): void
{
    if (!mcp_enabled()) {
        mcp_json(['error' => 'temporarily_unavailable', 'error_description' => mcp_available()
            ? 'AI konektor je v Brand Hubu vypnutý (Nastavení → AI konektor).'
            : 'AI konektor ještě nemá databázi – dokončete aktualizaci aplikace.'], 503);
        exit;
    }
}

/* ---------- Aplikace (OAuth klienti) ---------- */

function mcp_client(string $clientId): ?array
{
    if ($clientId === '' || strlen($clientId) > 64) {
        return null;
    }
    $c = db_one('SELECT * FROM mcp_clients WHERE client_id = ?', [$clientId]);
    if (!$c) {
        return null;
    }
    $c['redirect_uris'] = json_decode((string) $c['redirect_uris'], true) ?: [];
    return $c;
}

/** Povolená návratová adresa: https, nebo http jen na tomto počítači (localhost – nástroje v terminálu). */
function mcp_redirect_valid(string $uri): bool
{
    if ($uri === '' || strlen($uri) > 2000 || str_contains($uri, '#')) {
        return false;
    }
    $p = parse_url($uri);
    if (!$p || empty($p['scheme']) || empty($p['host'])) {
        return false;
    }
    $scheme = strtolower($p['scheme']);
    if ($scheme === 'https') {
        return true;
    }
    return $scheme === 'http' && in_array(strtolower($p['host']), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
}

/** Shoda návratové adresy s registrací (u localhost se nepočítá port – RFC 8252). */
function mcp_redirect_matches(array $client, string $uri): bool
{
    foreach ($client['redirect_uris'] as $r) {
        if (hash_equals((string) $r, $uri)) {
            return true;
        }
        $a = parse_url((string) $r);
        $b = parse_url($uri);
        if ($a && $b && ($a['scheme'] ?? '') === 'http' && in_array($a['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)
            && ($a['host'] ?? '') === ($b['host'] ?? '') && ($b['scheme'] ?? '') === 'http'
            && ($a['path'] ?? '/') === ($b['path'] ?? '/') && ($a['query'] ?? '') === ($b['query'] ?? '')) {
            return true;
        }
    }
    return false;
}

/** Dynamická registrace aplikace (RFC 7591). Vrací odpověď pro aplikaci. */
function mcp_register(array $req, string $ip): array
{
    $uris = $req['redirect_uris'] ?? null;
    if (!is_array($uris) || !$uris || count($uris) > 10) {
        throw new McpOAuthError('invalid_redirect_uri', 'Chybí redirect_uris.');
    }
    foreach ($uris as $u) {
        if (!is_string($u) || !mcp_redirect_valid($u)) {
            throw new McpOAuthError('invalid_redirect_uri', 'Nepovolená návratová adresa: ' . (is_string($u) ? $u : '?'));
        }
    }
    $method = (string) ($req['token_endpoint_auth_method'] ?? 'none');
    if (!in_array($method, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
        throw new McpOAuthError('invalid_client_metadata', 'Nepodporovaná metoda ověření: ' . $method);
    }
    foreach ((array) ($req['grant_types'] ?? ['authorization_code']) as $g) {
        if (!in_array($g, ['authorization_code', 'refresh_token'], true)) {
            throw new McpOAuthError('invalid_client_metadata', 'Nepodporovaný grant_type: ' . (string) $g);
        }
    }
    $ipHash = mcp_hash('mcp-ip:' . $ip);
    if ((int) db_val('SELECT COUNT(*) FROM mcp_clients WHERE ip_hash = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$ipHash]) >= MCP_DCR_PER_HOUR) {
        throw new McpOAuthError('invalid_request', 'Příliš mnoho registrací, zkuste to za hodinu.', 429);
    }
    mcp_cleanup();

    $name = mb_substr(trim(strip_tags((string) ($req['client_name'] ?? ''))), 0, 255);
    if ($name === '') {
        $name = parse_url((string) $uris[0], PHP_URL_HOST) ?: 'Neznámá aplikace';
    }
    $clientId = 'mcp_' . bin2hex(random_bytes(16));
    $secret = $method === 'none' ? null : mcp_random('mbs_');
    db_exec(
        'INSERT INTO mcp_clients (client_id, secret_hash, name, redirect_uris, auth_method, source, ip_hash) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$clientId, $secret ? mcp_hash($secret) : null, $name, json_encode(array_values($uris), JSON_UNESCAPED_SLASHES), $method, 'dcr', $ipHash]
    );
    $out = [
        'client_id' => $clientId,
        'client_id_issued_at' => time(),
        'client_name' => $name,
        'redirect_uris' => array_values($uris),
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => $method,
    ];
    if ($secret) {
        $out['client_secret'] = $secret;
        $out['client_secret_expires_at'] = 0;
    }
    if (isset($req['scope'])) {
        $out['scope'] = mcp_scope_normalize((string) $req['scope']);
    }
    return $out;
}

/** Ověření aplikace na token endpointu: client_secret_basic, client_secret_post nebo veřejná (jen client_id + PKCE). */
function mcp_verify_client(array $post): array
{
    $clientId = (string) ($post['client_id'] ?? '');
    $secret = isset($post['client_secret']) ? (string) $post['client_secret'] : null;
    $auth = mcp_authorization_header();
    if (stripos($auth, 'basic ') === 0) {
        $decoded = base64_decode(substr($auth, 6), true);
        if ($decoded !== false && str_contains($decoded, ':')) {
            [$id, $sec] = explode(':', $decoded, 2);
            $clientId = urldecode($id);
            $secret = urldecode($sec);
        }
    }
    $client = mcp_client($clientId);
    if (!$client) {
        throw new McpOAuthError('invalid_client', 'Neznámá aplikace – přidejte konektor znovu.', 401);
    }
    if ($client['secret_hash'] !== null && ($secret === null || !hash_equals((string) $client['secret_hash'], mcp_hash($secret)))) {
        throw new McpOAuthError('invalid_client', 'Neplatný tajný klíč aplikace.', 401);
    }
    return $client;
}

/* ---------- Autorizační kód ---------- */

/**
 * Ověří parametry žádosti o souhlas; vrací [aplikace, parametry]. Chyby před ověřením redirect_uri (výjimka) se nesmí
 * přesměrovat do aplikace – ukážou se uživateli; ostatní jsou v parametry['error'] a vrací se aplikaci.
 */
function mcp_authorize_params(array $q): array
{
    $client = mcp_client((string) ($q['client_id'] ?? ''));
    if (!$client) {
        throw new McpOAuthError('invalid_client', 'Neznámá aplikace. Odeberte konektor v aplikaci (Claude, ChatGPT…) a přidejte ho znovu.');
    }
    $redirect = (string) ($q['redirect_uri'] ?? '');
    if ($redirect === '' && count($client['redirect_uris']) === 1) {
        $redirect = (string) $client['redirect_uris'][0];
    }
    if (!mcp_redirect_matches($client, $redirect)) {
        throw new McpOAuthError('invalid_request', 'Návratová adresa neodpovídá registraci aplikace.');
    }
    $p = [
        'client_id' => (string) $client['client_id'],
        'redirect_uri' => $redirect,
        'state' => isset($q['state']) ? (string) $q['state'] : null,
        'scope' => mcp_scope_normalize($q['scope'] ?? null),
        'scope_requested' => trim((string) ($q['scope'] ?? '')),
        'code_challenge' => (string) ($q['code_challenge'] ?? ''),
        'code_challenge_method' => (string) ($q['code_challenge_method'] ?? ''),
        'resource' => isset($q['resource']) ? (string) $q['resource'] : null,
        'response_type' => (string) ($q['response_type'] ?? ''),
        'error' => null,
    ];
    if ($p['response_type'] !== 'code') {
        $p['error'] = ['unsupported_response_type', 'Podporovaný je jen response_type=code.'];
    } elseif ($p['code_challenge'] === '' || $p['code_challenge_method'] !== 'S256' || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $p['code_challenge'])) {
        $p['error'] = ['invalid_request', 'Chybí PKCE (code_challenge s metodou S256).'];
    } elseif ($p['resource'] !== null && $p['resource'] !== '' && !mcp_resource_ours($p['resource'])) {
        $p['error'] = ['invalid_target', 'Neznámý resource: ' . $p['resource']];
    }
    return [$client, $p];
}

/** Adresa návratu do aplikace s parametry (code/state/iss nebo error). */
function mcp_redirect_url(string $redirect, array $params): string
{
    $params = array_filter($params, fn ($v) => $v !== null);
    return $redirect . (str_contains($redirect, '?') ? '&' : '?') . http_build_query($params);
}

function mcp_code_create(array $p, int $userId, string $scope): string
{
    $code = mcp_random('mbc_');
    db_exec(
        'INSERT INTO mcp_codes (code_hash, client_id, user_id, redirect_uri, code_challenge, scope, resource, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [mcp_hash($code), $p['client_id'], $userId, $p['redirect_uri'], $p['code_challenge'], $scope, $p['resource'] ?: null, date('Y-m-d H:i:s', time() + MCP_CODE_SECONDS)]
    );
    return $code;
}

/* ---------- Tokeny ---------- */

/** Odpověď token endpointu (POST form-urlencoded). */
function mcp_token_endpoint(array $post): array
{
    $grant = (string) ($post['grant_type'] ?? '');
    $client = mcp_verify_client($post);
    db_exec('UPDATE mcp_clients SET last_used_at = NOW() WHERE id = ?', [$client['id']]);

    if ($grant === 'authorization_code') {
        $code = db_one('SELECT * FROM mcp_codes WHERE code_hash = ?', [mcp_hash((string) ($post['code'] ?? ''))]);
        if ($code) {
            db_exec('DELETE FROM mcp_codes WHERE code_hash = ?', [$code['code_hash']]); // jednorázový
        }
        if (!$code || $code['expires_at'] < date('Y-m-d H:i:s') || !hash_equals((string) $code['client_id'], (string) $client['client_id'])) {
            throw new McpOAuthError('invalid_grant', 'Autorizační kód je neplatný nebo vypršel.');
        }
        if (isset($post['redirect_uri']) && (string) $post['redirect_uri'] !== (string) $code['redirect_uri']) {
            throw new McpOAuthError('invalid_grant', 'Návratová adresa nesouhlasí.');
        }
        $verifier = (string) ($post['code_verifier'] ?? '');
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) || !hash_equals((string) $code['code_challenge'], $computed)) {
            throw new McpOAuthError('invalid_grant', 'PKCE ověření selhalo (code_verifier).');
        }
        if (isset($post['resource']) && $post['resource'] !== '' && !mcp_resource_ours((string) $post['resource'])) {
            throw new McpOAuthError('invalid_target', 'Neznámý resource.');
        }
        $access = mcp_random('mba_');
        $refresh = mcp_random('mbr_');
        db_exec(
            'INSERT INTO mcp_tokens (kind, client_id, user_id, name, scope, access_hash, access_expires_at, refresh_hash, refresh_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['oauth', $client['client_id'], (int) $code['user_id'], $client['name'], $code['scope'], mcp_hash($access),
                date('Y-m-d H:i:s', time() + MCP_ACCESS_SECONDS), mcp_hash($refresh), date('Y-m-d H:i:s', time() + MCP_REFRESH_DAYS * 86400)]
        );
        log_activity(null, 'admin', 'ai', 'Připojena AI aplikace ' . $client['name'] . ' (' . ($code['scope'] === 'cteni' ? 'čtení' : 'čtení a zápis') . ')');
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => MCP_ACCESS_SECONDS, 'refresh_token' => $refresh, 'scope' => $code['scope']];
    }

    if ($grant === 'refresh_token') {
        $h = mcp_hash((string) ($post['refresh_token'] ?? ''));
        $now = date('Y-m-d H:i:s');
        $t = db_one(
            "SELECT * FROM mcp_tokens WHERE kind = 'oauth' AND revoked_at IS NULL AND client_id = ? AND refresh_expires_at > ?
             AND (refresh_hash = ? OR (refresh_prev_hash = ? AND refresh_prev_until > ?)) LIMIT 1",
            [$client['client_id'], $now, $h, $h, $now]
        );
        if (!$t) {
            throw new McpOAuthError('invalid_grant', 'Obnovovací token je neplatný, vypršel nebo bylo připojení odpojeno – připojte konektor znovu.');
        }
        $scope = (string) $t['scope'];
        if (!empty($post['scope'])) {
            // zúžení oprávnění je dovolené, rozšíření ne
            $wanted = explode(' ', mcp_scope_normalize((string) $post['scope']));
            $scope = implode(' ', array_intersect($wanted, explode(' ', $scope))) ?: $scope;
        }
        $access = mcp_random('mba_');
        $refresh = mcp_random('mbr_');
        // rotace: nový refresh; ten, který přišel, platí ještě chvíli (souběžné obnovení v aplikaci)
        db_exec(
            'UPDATE mcp_tokens SET access_hash = ?, access_expires_at = ?, refresh_prev_hash = ?, refresh_prev_until = ?, refresh_hash = ?,
             refresh_expires_at = ?, scope = ? WHERE id = ?',
            [mcp_hash($access), date('Y-m-d H:i:s', time() + MCP_ACCESS_SECONDS), $h, date('Y-m-d H:i:s', time() + MCP_REFRESH_OVERLAP_SECONDS),
                mcp_hash($refresh), date('Y-m-d H:i:s', time() + MCP_REFRESH_DAYS * 86400), $scope, $t['id']]
        );
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => MCP_ACCESS_SECONDS, 'refresh_token' => $refresh, 'scope' => $scope];
    }

    throw new McpOAuthError('unsupported_grant_type', 'Podporované jsou authorization_code a refresh_token.');
}

/** Odvolání tokenu (RFC 7009) – vždy 200, i když token neexistuje. */
function mcp_revoke(array $post): void
{
    $client = mcp_verify_client($post);
    $h = mcp_hash((string) ($post['token'] ?? ''));
    db_exec(
        'UPDATE mcp_tokens SET revoked_at = NOW(), access_hash = NULL, refresh_hash = NULL, refresh_prev_hash = NULL
         WHERE client_id = ? AND revoked_at IS NULL AND (access_hash = ? OR refresh_hash = ? OR refresh_prev_hash = ?)',
        [$client['client_id'], $h, $h, $h]
    );
}

/** Hodnota hlavičky Authorization (Apache s PHP-FPM ji předá jen přes .htaccess – proto víc zdrojů). */
function mcp_authorization_header(): string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'REDIRECT_REDIRECT_HTTP_AUTHORIZATION'] as $k) {
        if (!empty($_SERVER[$k])) {
            return trim((string) $_SERVER[$k]);
        }
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower((string) $name) === 'authorization') {
                return trim((string) $value);
            }
        }
    }
    return '';
}

/**
 * Přístupový token z hlavičky → řádek připojení (s názvem aplikace), nebo null.
 * $reason: missing | invalid
 */
function mcp_verify_token(?string &$reason = null): ?array
{
    if (!preg_match('/^Bearer\s+(\S+)$/i', mcp_authorization_header(), $m)) {
        $reason = 'missing';
        return null;
    }
    $t = db_one(
        'SELECT t.*, c.name AS client_name FROM mcp_tokens t LEFT JOIN mcp_clients c ON c.client_id = t.client_id
         WHERE t.access_hash = ? AND t.revoked_at IS NULL AND (t.access_expires_at IS NULL OR t.access_expires_at > ?)',
        [mcp_hash($m[1]), date('Y-m-d H:i:s')]
    );
    if (!$t) {
        $reason = 'invalid';
        return null;
    }
    // naposledy použito (nejvýš 1× za minutu) + klouzavá platnost obnovovacího tokenu
    if (empty($t['last_used_at']) || strtotime((string) $t['last_used_at']) < time() - 60) {
        db_exec(
            'UPDATE mcp_tokens SET last_used_at = NOW(), refresh_expires_at = IF(refresh_hash IS NULL, refresh_expires_at, ?) WHERE id = ?',
            [date('Y-m-d H:i:s', time() + MCP_REFRESH_DAYS * 86400), $t['id']]
        );
    }
    $reason = null;
    return $t;
}

/** Odpověď 401 s odkazem na metadata (podle ní si aplikace najde přihlášení). */
function mcp_unauthorized(string $reason): void
{
    $value = 'Bearer resource_metadata="' . mcp_base() . '/.well-known/oauth-protected-resource"';
    if ($reason === 'invalid') {
        $value .= ', error="invalid_token", error_description="Token je neplatny nebo vyprsel"';
    }
    mcp_json(
        ['error' => $reason === 'invalid' ? 'invalid_token' : 'unauthorized', 'reason' => $reason,
            'error_description' => $reason === 'invalid' ? 'Token je neplatný nebo vypršel.' : 'Chybí přihlášení (Authorization: Bearer).'],
        401,
        ['WWW-Authenticate: ' . $value]
    );
}

/* ---------- Ruční tokeny a správa připojení ---------- */

/** Ruční token (platí do odvolání). Vrací token – ukáže se jen jednou. */
function mcp_manual_token(string $name, string $scope, int $userId): string
{
    $name = mb_substr(trim($name), 0, 255);
    if ($name === '') {
        throw new InvalidArgumentException('Zadejte název tokenu (k čemu ho použijete).');
    }
    $token = mcp_random('mbt_');
    db_exec('INSERT INTO mcp_tokens (kind, user_id, name, scope, access_hash) VALUES (?, ?, ?, ?, ?)', ['manual', $userId, $name, mcp_scope_normalize($scope), mcp_hash($token)]);
    log_activity(null, 'admin', 'ai', 'Vytvořen ruční token AI konektoru „' . $name . '“');
    return $token;
}

/** Aktivní připojení (aplikace přes přihlášení i ruční tokeny), naposledy použité nahoře. */
function mcp_connections(): array
{
    return db_all(
        "SELECT t.id, t.kind, t.client_id, t.name, t.scope, t.created_at, t.last_used_at, t.calls, t.refresh_expires_at, c.name AS client_name
         FROM mcp_tokens t LEFT JOIN mcp_clients c ON c.client_id = t.client_id
         WHERE t.revoked_at IS NULL AND (t.kind = 'manual' OR t.refresh_expires_at > NOW())
         ORDER BY COALESCE(t.last_used_at, t.created_at) DESC"
    );
}

function mcp_disconnect(int $id): void
{
    db_exec('UPDATE mcp_tokens SET revoked_at = NOW(), access_hash = NULL, refresh_hash = NULL, refresh_prev_hash = NULL WHERE id = ?', [$id]);
}

/** Úklid: prošlé kódy, stará zrušená připojení, nepoužité registrace aplikací, starý log a nahrávání. */
function mcp_cleanup(): void
{
    try {
        db_exec('DELETE FROM mcp_codes WHERE expires_at < NOW()');
        db_exec("DELETE FROM mcp_tokens WHERE (revoked_at IS NOT NULL AND revoked_at < NOW() - INTERVAL 30 DAY)
                 OR (kind = 'oauth' AND refresh_expires_at < NOW() - INTERVAL 30 DAY)");
        db_exec("DELETE c FROM mcp_clients c LEFT JOIN mcp_tokens t ON t.client_id = c.client_id AND t.revoked_at IS NULL
                 WHERE c.source = 'dcr' AND t.id IS NULL AND COALESCE(c.last_used_at, c.created_at) < NOW() - INTERVAL 30 DAY");
        db_exec('DELETE FROM mcp_log WHERE created_at < NOW() - INTERVAL 90 DAY');
        foreach (db_all('SELECT id FROM mcp_uploads WHERE expires_at < NOW() - INTERVAL 1 DAY') as $u) {
            @unlink(mcp_upload_path((int) $u['id']));
            db_exec('DELETE FROM mcp_uploads WHERE id = ?', [$u['id']]);
        }
    } catch (Throwable $e) {
        error_log('[brand-hub] MCP úklid: ' . $e->getMessage());
    }
}

/** Soubor nahraný přes jednorázový odkaz (čeká, až ho AI přidá k pozici). */
function mcp_upload_path(int $id): string
{
    return data_path('tmp/mcp/upload-' . $id . '.bin');
}
