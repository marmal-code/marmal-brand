<?php
declare(strict_types=1);

/*
 * Dvoufázové ověření (TOTP, RFC 6238) pro Google Authenticator, Microsoft Authenticator, 1Password apod.
 * SHA1, 6 číslic, 30 s. Převzato z jádra app-core (Martinovy PHP aplikace), aby fungovalo všude stejně.
 */

const TOTP_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp_generate_secret(int $bytes = 20): string
{
    return base32_encode(random_bytes($bytes));
}

/** Adresa pro QR kód (otpauth://). */
function totp_uri(string $secret, string $account, string $issuer): string
{
    $issuer = str_replace(':', '', $issuer);
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
        . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

function totp_code(string $secret, int $timeStep): string
{
    $key = base32_decode($secret);
    $hash = hash_hmac('sha1', pack('J', $timeStep), $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $num = ((ord($hash[$offset]) & 0x7f) << 24)
        | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8)
        | ord($hash[$offset + 3]);
    return str_pad((string) ($num % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Ověří kód s tolerancí ±1 interval (rozdíl hodin telefonu a serveru).
 * Vrací použitý časový krok (ukládá se, aby stejný kód nešel použít dvakrát), nebo null.
 */
function totp_verify(string $secret, string $code, ?int $lastUsedStep = null, int $window = 1): ?int
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6 || $secret === '') {
        return null;
    }
    $now = intdiv(time(), 30);
    for ($i = -$window; $i <= $window; $i++) {
        $step = $now + $i;
        if ($lastUsedStep !== null && $step <= $lastUsedStep) {
            continue;
        }
        if (hash_equals(totp_code($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}

function base32_encode(string $data): string
{
    $bits = '';
    foreach (str_split($data) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= TOTP_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode(string $data): string
{
    $data = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $data) ?? '');
    $bits = '';
    foreach (str_split($data) as $c) {
        $bits .= str_pad(decbin((int) strpos(TOTP_ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr((int) bindec($byte));
        }
    }
    return $out;
}

/* ---------- Záložní kódy ---------- */

/** 10 jednorázových záložních kódů ve tvaru xxxx-xxxx. */
function generate_recovery_codes(int $count = 10): array
{
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = strtolower(base32_encode(random_bytes(5)));
        $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    }
    return $codes;
}

/** Uloží nové záložní kódy (staré přestanou platit). Vrací kódy v čitelné podobě – ukážou se jen jednou. */
function replace_recovery_codes(int $userId): array
{
    $codes = generate_recovery_codes();
    db_exec('DELETE FROM recovery_codes WHERE user_id = ?', [$userId]);
    foreach ($codes as $c) {
        db_exec('INSERT INTO recovery_codes (user_id, code_hash, created_at) VALUES (?, ?, NOW())', [$userId, password_hash($c, PASSWORD_DEFAULT)]);
    }
    return $codes;
}

/** Ověří a spotřebuje záložní kód. */
function use_recovery_code(int $userId, string $code): bool
{
    $code = strtolower(preg_replace('/[^a-z2-7]/i', '', $code) ?? '');
    if (strlen($code) !== 8) {
        return false;
    }
    $code = substr($code, 0, 4) . '-' . substr($code, 4, 4);
    foreach (db_all('SELECT id, code_hash FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]) as $row) {
        if (password_verify($code, $row['code_hash'])) {
            db_exec('UPDATE recovery_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL', [$row['id']]);
            return true;
        }
    }
    return false;
}

function recovery_codes_left(int $userId): int
{
    return (int) db_val('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);
}
