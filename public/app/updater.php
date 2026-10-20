<?php
declare(strict_types=1);

/*
 * Aktualizace z GitHubu.
 * Nová verze = GitHub Release s tagem vX.Y.Z. Aplikace stáhne zdrojový ZIP daného tagu,
 * vezme z něj složku public/ a nahradí jí svůj kód. Pak spustí migrace databáze.
 */

const DEFAULT_REPO = 'marmal-code/marmal-brand';

function update_repo(): string
{
    $repo = trim((string) setting('github_repo', DEFAULT_REPO));
    return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) ? $repo : DEFAULT_REPO;
}

function github_token(): string
{
    return decrypt_secret(setting('github_token'));
}

/** GET na GitHub API. Vrací [status, body]. S $saveTo se tělo ukládá rovnou do souboru. */
function github_get(string $url, ?string $saveTo = null, string $accept = 'application/vnd.github+json'): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Na serveru chybí PHP rozšíření curl.');
    }
    $headers = [
        'Accept: ' . $accept,
        'User-Agent: marmal-brand-updater',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    $token = github_token();
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $ch = curl_init($url);
    $fh = null;
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 240,
        CURLOPT_FAILONERROR => false,
    ]);
    if ($saveTo !== null) {
        $fh = fopen($saveTo, 'wb');
        if (!$fh) {
            throw new RuntimeException('Nelze zapsat stahovaný soubor.');
        }
        curl_setopt($ch, CURLOPT_FILE, $fh);
    } else {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    if ($fh) {
        fclose($fh);
    }
    if ($body === false) {
        throw new RuntimeException('Spojení s GitHubem selhalo: ' . $err);
    }
    if ($saveTo !== null && $status >= 400) {
        $body = (string) @file_get_contents($saveTo);
        @unlink($saveTo);
    }
    return [$status, $saveTo !== null && $status < 400 ? '' : (string) $body];
}

function github_error(int $status, string $body): string
{
    $msg = json_decode($body, true)['message'] ?? '';
    return match (true) {
        $status === 401 => 'GitHub odmítl token (401). Zkontrolujte, že je token platný a nevypršel.',
        $status === 403 => 'GitHub odepřel přístup (403). ' . $msg,
        $status === 404 => 'Repozitář nebo vydání nenalezeno (404). U soukromého repozitáře je potřeba token s právem Contents: Read.',
        default => 'GitHub vrátil chybu ' . $status . ($msg ? ': ' . $msg : '.'),
    };
}

/** Nejnovější vydání. Vrací ['tag','version','name','body','published_at','html_url'] nebo vyhodí výjimku. */
function latest_release(): array
{
    [$status, $body] = github_get('https://api.github.com/repos/' . update_repo() . '/releases/latest');
    if ($status !== 200) {
        throw new RuntimeException($status === 404
            ? 'Na GitHubu zatím není žádné vydání (nebo token nemá přístup k repozitáři).'
            : github_error($status, $body));
    }
    $r = json_decode($body, true);
    if (!is_array($r) || empty($r['tag_name'])) {
        throw new RuntimeException('Neočekávaná odpověď GitHubu.');
    }
    $info = [
        'tag' => (string) $r['tag_name'],
        'version' => ltrim((string) $r['tag_name'], 'vV'),
        'name' => (string) ($r['name'] ?: $r['tag_name']),
        'body' => (string) ($r['body'] ?? ''),
        'published_at' => (string) ($r['published_at'] ?? ''),
        'html_url' => (string) ($r['html_url'] ?? ''),
    ];
    set_setting('update_last_check', json_encode(['at' => date('Y-m-d H:i:s')] + $info, JSON_UNESCAPED_UNICODE));
    return $info;
}

function update_available(array $release): bool
{
    return version_compare($release['version'], APP_VERSION, '>');
}

/**
 * Provede aktualizaci na zadaný tag. Vrací protokol kroků.
 * Při chybě obnoví kód i databázi ze zálohy, kterou si na začátku vytvořila.
 */
function run_update(string $tag): array
{
    if (!preg_match('/^v?\d+\.\d+\.\d+([.-][A-Za-z0-9.]+)?$/', $tag)) {
        throw new InvalidArgumentException('Neplatná verze.');
    }
    @set_time_limit(600);
    ignore_user_abort(true);
    foreach (['zip' => 'ZipArchive', 'curl' => 'curl_init'] as $ext => $fn) {
        if (!class_exists($fn) && !function_exists($fn)) {
            throw new RuntimeException('Na serveru chybí PHP rozšíření ' . $ext . '.');
        }
    }

    $work = data_path('tmp/update-' . bin2hex(random_bytes(4)));
    ensure_dir($work);
    $log = ['' . date('H:i:s') . '  Stahuji verzi ' . $tag . ' z GitHubu…'];
    [$status, $body] = github_get('https://api.github.com/repos/' . update_repo() . '/zipball/' . rawurlencode($tag), $work . '/package.zip');
    if ($status >= 400) {
        rrmdir($work);
        throw new RuntimeException(github_error($status, $body));
    }
    return apply_update_package($work, $log);
}

/**
 * Nainstaluje stažený balíček $work/package.zip (zdrojový ZIP repozitáře se složkou public/).
 * Odděleno od stahování, aby šlo otestovat i bez GitHubu.
 */
function apply_update_package(string $work, array $log = []): array
{
    $step = function (string $msg) use (&$log) {
        $log[] = date('H:i:s') . '  ' . $msg;
    };
    $fromVersion = APP_VERSION;

    // 1) Kontrola balíčku – zatím se ničeho nedotýkáme.
    $zip = new ZipArchive();
    if ($zip->open($work . '/package.zip') !== true || $zip->numFiles < 1) {
        rrmdir($work);
        throw new RuntimeException('Stažený balíček je poškozený.');
    }
    $top = explode('/', (string) $zip->getNameIndex(0))[0] . '/';
    $zip->close();
    $count = extract_zip_safely($work . '/package.zip', $work . '/public', $top . 'public/');
    if ($count === 0 || !is_file($work . '/public/index.php') || !is_file($work . '/public/VERSION')) {
        rrmdir($work);
        throw new RuntimeException('Balíček neobsahuje složku public/ s aplikací.');
    }
    $newVersion = trim((string) file_get_contents($work . '/public/VERSION'));
    $step('Balíček v pořádku (' . $count . ' souborů, verze ' . $newVersion . ').');

    // 2) Záloha.
    $step('Zálohuji databázi a kód…');
    $backupId = create_backup('Před aktualizací na ' . $newVersion);
    $step('Záloha uložena: ' . $backupId);

    // 3) Výměna kódu a migrace v režimu údržby.
    maintenance(true);
    try {
        $step('Nahrazuji soubory aplikace…');
        install_code_tree($work . '/public');
        $step('Spouštím migrace databáze…');
        $ran = run_migrations(db());
        $step($ran ? 'Proběhlé migrace: ' . implode(', ', $ran) : 'Databáze nepotřebovala změny.');
    } catch (Throwable $e) {
        $step('CHYBA: ' . $e->getMessage());
        $step('Vracím aplikaci do stavu před aktualizací…');
        try {
            $dir = backup_path($backupId);
            restore_code($dir . '/code.zip');
            restore_database(db(), $dir . '/db.sql.gz');
            $step('Obnova ze zálohy proběhla. Aplikace běží ve verzi ' . $fromVersion . '.');
        } catch (Throwable $e2) {
            $step('Obnova selhala: ' . $e2->getMessage() . ' – obnovte zálohu ' . $backupId . ' ručně v Nastavení → Aktualizace a zálohy.');
        }
        maintenance(false);
        rrmdir($work);
        write_update_log($log);
        throw new UpdateFailed(implode("\n", $log), 0, $e);
    }
    maintenance(false);
    rrmdir($work);
    $step('Hotovo. Aplikace běží ve verzi ' . $newVersion . '.');
    write_update_log($log);
    return $log;
}

final class UpdateFailed extends RuntimeException
{
}

function write_update_log(array $log): void
{
    ensure_dir(data_path('logs'));
    file_put_contents(data_path('logs/update.log'), "==== " . date('c') . " ====\n" . implode("\n", $log) . "\n\n", FILE_APPEND);
    set_setting('update_last_log', implode("\n", $log));
}

/** Kontrola prostředí (zobrazuje instalace i nastavení). */
function requirements(): array
{
    $writable = fn (string $p) => is_dir($p) && is_writable($p);
    return [
        ['PHP 8.1 nebo novější', version_compare(PHP_VERSION, '8.1.0', '>='), 'Máte ' . PHP_VERSION],
        ['Rozšíření pdo_mysql', extension_loaded('pdo_mysql'), 'Připojení k databázi'],
        ['Rozšíření zip', class_exists('ZipArchive'), 'Balíčky ZIP a aktualizace'],
        ['Rozšíření curl', function_exists('curl_init'), 'Stahování aktualizací z GitHubu'],
        ['Rozšíření fileinfo', class_exists('finfo'), 'Rozpoznání typu souborů'],
        ['Rozšíření sodium', function_exists('sodium_crypto_secretbox'), 'Šifrování tokenu'],
        ['Rozšíření mbstring', function_exists('mb_strtolower'), 'Práce s češtinou'],
        ['Zápis do datové složky', $writable(DATA_DIR), DATA_DIR],
        ['Zápis do webové složky', is_writable(APP_ROOT), 'Potřeba pro aktualizace'],
    ];
}
