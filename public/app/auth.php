<?php
declare(strict_types=1);

const LOGIN_MAX_ATTEMPTS = 8;
const LOGIN_WINDOW_MIN = 15;

function current_admin(): ?array
{
    static $admin = false;
    if ($admin !== false) {
        return $admin;
    }
    $id = (int) ($_SESSION['admin_id'] ?? 0);
    $admin = $id ? db_one('SELECT id, email, name FROM users WHERE id = ?', [$id]) : null;
    return $admin;
}

function require_admin(): array
{
    $admin = current_admin();
    if (!$admin) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/admin';
        redirect('/login');
    }
    return $admin;
}

function too_many_attempts(string $scope): bool
{
    db_exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    $n = (int) db_val(
        'SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND ip = ? AND created_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MIN . ' MINUTE)',
        [$scope, client_ip()]
    );
    return $n >= LOGIN_MAX_ATTEMPTS;
}

function record_failed_attempt(string $scope): void
{
    db_exec('INSERT INTO login_attempts (scope, ip, created_at) VALUES (?, ?, NOW())', [$scope, client_ip()]);
}

function clear_attempts(string $scope): void
{
    db_exec('DELETE FROM login_attempts WHERE scope = ? AND ip = ?', [$scope, client_ip()]);
}

function admin_login(string $email, string $password): ?string
{
    if (too_many_attempts('admin')) {
        return 'Příliš mnoho pokusů o přihlášení. Zkuste to znovu za ' . LOGIN_WINDOW_MIN . ' minut.';
    }
    $user = db_one('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_failed_attempt('admin');
        return 'E-mail nebo heslo nesouhlasí.';
    }
    clear_attempts('admin');
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $user['id'];
    db_exec('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    return null;
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
    if (too_many_attempts($scope)) {
        return 'Příliš mnoho pokusů o přihlášení. Zkuste to znovu za ' . LOGIN_WINDOW_MIN . ' minut.';
    }
    if (empty($brand['password_hash']) || !password_verify($password, $brand['password_hash'])) {
        record_failed_attempt($scope);
        return 'Heslo nesouhlasí.';
    }
    clear_attempts($scope);
    session_regenerate_id(true);
    $_SESSION['brands'][(int) $brand['id']] = substr((string) $brand['password_hash'], -16);
    log_activity((int) $brand['id'], 'client', 'login', 'Klient se přihlásil');
    return null;
}
