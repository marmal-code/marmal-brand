<?php
declare(strict_types=1);

const LOGIN_MAX_ATTEMPTS = 8;          // pokusů z jedné IP adresy za LOGIN_WINDOW_MIN
const LOGIN_WINDOW_MIN = 15;
const ADMIN_GLOBAL_MAX_ATTEMPTS = 40;  // pokusů o účet administrátora ze všech IP za LOGIN_WINDOW_MIN
const BRAND_GLOBAL_MAX_ATTEMPTS = 60;  // pokusů o heslo jednoho klienta ze všech IP za hodinu
const TWOFA_MAX_ATTEMPTS = 6;          // špatných kódů 2FA z jedné IP za LOGIN_WINDOW_MIN
const TWOFA_PENDING_SECONDS = 300;     // na zadání kódu je 5 minut od zadání hesla
const ADMIN_IDLE_SECONDS = 12 * 3600;  // po 12 hodinách nečinnosti se administrátor odhlásí

/** Hash pro porovnání, když účet nebo heslo neexistuje – odpověď pak trvá stejně dlouho a neprozradí, jestli účet existuje. */
function dummy_password_hash(): string
{
    static $h = null;
    return $h ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
}

function current_admin(): ?array
{
    static $admin = false;
    if ($admin !== false) {
        return $admin;
    }
    $admin = null;
    $id = (int) ($_SESSION['admin_id'] ?? 0);
    if (!$id) {
        return null;
    }
    $user = db_one('SELECT id, email, name, totp_enabled_at, auth_epoch FROM users WHERE id = ?', [$id]);
    $idle = time() - (int) ($_SESSION['admin_seen'] ?? 0);
    // Změna hesla nebo 2FA odhlásí ostatní zařízení (auth_epoch), dlouhá nečinnost odhlásí také.
    if (!$user || (int) ($_SESSION['admin_epoch'] ?? 0) !== (int) $user['auth_epoch'] || $idle > ADMIN_IDLE_SECONDS) {
        unset($_SESSION['admin_id'], $_SESSION['admin_epoch'], $_SESSION['admin_seen']);
        return null;
    }
    $_SESSION['admin_seen'] = time();
    unset($user['auth_epoch']);
    $admin = $user;
    return $admin;
}

function require_admin(): array
{
    $admin = current_admin();
    if (!$admin) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/admin';
        }
        redirect('/login');
    }
    return $admin;
}

function too_many_attempts(string $scope, int $max = LOGIN_MAX_ATTEMPTS, int $minutes = LOGIN_WINDOW_MIN, bool $allIps = false): bool
{
    db_exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    $sql = 'SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND created_at > (NOW() - INTERVAL ' . (int) $minutes . ' MINUTE)';
    $params = [$scope];
    if (!$allIps) {
        $sql .= ' AND ip = ?';
        $params[] = client_ip();
    }
    return (int) db_val($sql, $params) >= $max;
}

function record_failed_attempt(string $scope): void
{
    db_exec('INSERT INTO login_attempts (scope, ip, created_at) VALUES (?, ?, NOW())', [$scope, client_ip()]);
}

function clear_attempts(string $scope): void
{
    db_exec('DELETE FROM login_attempts WHERE scope = ? AND ip = ?', [$scope, client_ip()]);
}

/**
 * První krok přihlášení (e-mail + heslo). Vrací chybu, nebo null.
 * Má-li účet zapnuté 2FA, administrátor ještě přihlášený není: v session čeká krok s kódem (admin_2fa_pending()).
 */
function admin_login(string $email, string $password): ?string
{
    if (too_many_attempts('admin') || too_many_attempts('admin', ADMIN_GLOBAL_MAX_ATTEMPTS, LOGIN_WINDOW_MIN, true)) {
        return 'Příliš mnoho pokusů o přihlášení. Zkuste to znovu za ' . LOGIN_WINDOW_MIN . ' minut.';
    }
    $user = db_one('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
    $ok = password_verify($password, $user ? (string) $user['password_hash'] : dummy_password_hash());
    if (!$user || !$ok) {
        record_failed_attempt('admin');
        log_activity(null, 'admin', 'login_failed', 'Neúspěšné přihlášení do administrace (' . client_ip() . ')');
        return 'E-mail nebo heslo nesouhlasí.';
    }
    clear_attempts('admin');
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    if (!empty($user['totp_enabled_at']) && !empty($user['totp_secret'])) {
        session_regenerate_id(true);
        $_SESSION['admin_2fa'] = ['uid' => (int) $user['id'], 'at' => time()];
        return null;
    }
    complete_admin_login($user, 'heslo');
    return null;
}

/** ID uživatele, který zadal správné heslo a čeká na kód 2FA (nebo null). */
function admin_2fa_pending(): ?int
{
    $p = $_SESSION['admin_2fa'] ?? null;
    if (!is_array($p) || time() - (int) $p['at'] > TWOFA_PENDING_SECONDS) {
        unset($_SESSION['admin_2fa']);
        return null;
    }
    return (int) $p['uid'];
}

/** Druhý krok přihlášení: kód z aplikace Authenticator nebo záložní kód. Vrací chybu, nebo null. */
function admin_login_2fa(string $code): ?string
{
    $uid = admin_2fa_pending();
    if (!$uid) {
        return 'Na zadání kódu uplynulo moc času. Přihlaste se prosím znovu.';
    }
    if (too_many_attempts('admin2fa', TWOFA_MAX_ATTEMPTS) || too_many_attempts('admin2fa', ADMIN_GLOBAL_MAX_ATTEMPTS, LOGIN_WINDOW_MIN, true)) {
        return 'Příliš mnoho špatných kódů. Zkuste to znovu za ' . LOGIN_WINDOW_MIN . ' minut.';
    }
    $user = db_one('SELECT * FROM users WHERE id = ?', [$uid]);
    if (!$user) {
        unset($_SESSION['admin_2fa']);
        return 'Účet neexistuje.';
    }
    $method = admin_check_second_factor($user, $code);
    if ($method === null) {
        record_failed_attempt('admin2fa');
        log_activity(null, 'admin', 'login_failed', 'Špatný kód dvoufázového ověření (' . client_ip() . ')');
        return 'Kód nesouhlasí. Zkontrolujte, že má telefon správný čas, nebo použijte záložní kód.';
    }
    clear_attempts('admin2fa');
    unset($_SESSION['admin_2fa']);
    complete_admin_login($user, $method);
    if ($method === 'záložní kód') {
        flash('success', 'Přihlásil jste se záložním kódem. Zbývá jich ' . recovery_codes_left((int) $user['id']) . '. Nové vytvoříte v Nastavení → Můj profil.');
    }
    return null;
}

/** Ověří kód 2FA uživatele. Vrací 'aplikace', 'záložní kód', nebo null. TOTP kód nejde použít dvakrát. */
function admin_check_second_factor(array $user, string $code): ?string
{
    $secret = decrypt_secret((string) $user['totp_secret']);
    $digits = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($digits) === 6 && $secret !== '') {
        $step = totp_verify($secret, $digits, $user['totp_last_step'] !== null ? (int) $user['totp_last_step'] : null);
        if ($step !== null) {
            db_exec('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $user['id']]);
            return 'aplikace';
        }
        return null;
    }
    return use_recovery_code((int) $user['id'], $code) ? 'záložní kód' : null;
}

function complete_admin_login(array $user, string $method): void
{
    session_regenerate_id(true);
    unset($_SESSION['csrf']); // nový token po přihlášení
    $_SESSION['admin_id'] = (int) $user['id'];
    $_SESSION['admin_epoch'] = (int) $user['auth_epoch'];
    $_SESSION['admin_seen'] = time();
    db_exec('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
    log_activity(null, 'admin', 'login', 'Přihlášení do administrace (' . $method . ', ' . client_ip() . ')');
}

/** Odhlásí ostatní zařízení (po změně hesla nebo 2FA); aktuální relace zůstane přihlášená. */
function bump_auth_epoch(int $userId): void
{
    db_exec('UPDATE users SET auth_epoch = auth_epoch + 1 WHERE id = ?', [$userId]);
    if ((int) ($_SESSION['admin_id'] ?? 0) === $userId) {
        $_SESSION['admin_epoch'] = (int) db_val('SELECT auth_epoch FROM users WHERE id = ?', [$userId]);
        session_regenerate_id(true);
    }
}

/* ---------- Klientský přístup k brandu ---------- */

/** Vrací 'admin', 'client', 'share' nebo null podle toho, jak má návštěvník k brandu přístup. */
function brand_access(array $brand): ?string
{
    if (current_admin()) {
        return 'admin';
    }
    $id = (int) $brand['id'];
    if (!empty($_SESSION['brands'][$id])) {
        // Při změně hesla se staré přihlášení zneplatní.
        if ($_SESSION['brands'][$id] === substr((string) $brand['password_hash'], -16)) {
            return 'client';
        }
        unset($_SESSION['brands'][$id]);
    }
    if (!empty($_SESSION['share'][$id]) && share_link_valid($brand) && hash_equals((string) $brand['share_token'], (string) $_SESSION['share'][$id])) {
        return 'share';
    }
    return null;
}

function share_link_valid(array $brand): bool
{
    if (empty($brand['share_enabled']) || empty($brand['share_token'])) {
        return false;
    }
    if (!empty($brand['share_expires_at']) && strtotime($brand['share_expires_at']) < time()) {
        return false;
    }
    return true;
}

function brand_login(array $brand, string $password): ?string
{
    $scope = 'brand:' . $brand['id'];
    if (too_many_attempts($scope) || too_many_attempts($scope, BRAND_GLOBAL_MAX_ATTEMPTS, 60, true)) {
        return 'Příliš mnoho pokusů o přihlášení. Zkuste to znovu za ' . LOGIN_WINDOW_MIN . ' minut.';
    }
    if (!password_verify($password, (string) ($brand['password_hash'] ?: dummy_password_hash())) || empty($brand['password_hash'])) {
        record_failed_attempt($scope);
        return 'Heslo nesouhlasí.';
    }
    clear_attempts($scope);
    session_regenerate_id(true);
    $_SESSION['brands'][(int) $brand['id']] = substr((string) $brand['password_hash'], -16);
    log_activity((int) $brand['id'], 'client', 'login', 'Klient se přihlásil');
    return null;
}
