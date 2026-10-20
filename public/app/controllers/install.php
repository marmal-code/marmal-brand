<?php
declare(strict_types=1);

/* Průvodce první instalací. Běží jen dokud neexistuje config.php v datové složce. */

function install_controller(string $method): void
{
    $errors = [];
    $values = [
        'data_dir' => DATA_DIR,
        'db_host' => 'localhost',
        'db_port' => '3306',
        'db_name' => 'marmal_brand',
        'db_user' => 'marmal_brand',
        'app_host' => request_host() ?: 'brand.marmal.cz',
        'admin_name' => 'Martin Malý',
        'admin_email' => '',
    ];

    if ($method === 'POST') {
        csrf_check();
        foreach ($values as $k => $_) {
            $values[$k] = trim((string) ($_POST[$k] ?? ''));
        }
        $dbPass = (string) ($_POST['db_pass'] ?? '');
        $adminPass = (string) ($_POST['admin_password'] ?? '');

        // Datová složka
        $dataDir = rtrim($values['data_dir'], '/\\');
        if ($dataDir === '') {
            $errors[] = 'Vyplňte cestu k datové složce.';
        } else {
            if (!is_dir($dataDir)) {
                @mkdir($dataDir, 0775, true);
            }
            if (!is_dir($dataDir) || !is_writable($dataDir)) {
                $errors[] = 'Do datové složky „' . $dataDir . '“ nelze zapisovat. Vytvořte ji přes FTP a nastavte jí práva k zápisu (775).';
            } elseif (str_starts_with(realpath($dataDir) . '/', realpath(APP_ROOT) . '/')) {
                $errors[] = 'Datová složka nesmí být uvnitř webové složky – soubory klientů by byly dostupné z internetu.';
            }
        }
        if (!filter_var($values['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Zadejte platný e-mail administrátora.';
        }
        if (mb_strlen($adminPass) < 10) {
            $errors[] = 'Heslo administrátora musí mít aspoň 10 znaků.';
        }
        if ($values['admin_name'] === '') {
            $errors[] = 'Vyplňte jméno administrátora.';
        }

        $cfg = [
            'db_host' => $values['db_host'] ?: 'localhost',
            'db_port' => (int) ($values['db_port'] ?: 3306),
            'db_name' => $values['db_name'],
            'db_user' => $values['db_user'],
            'db_pass' => $dbPass,
            'app_host' => strtolower($values['app_host']),
            'secret' => bin2hex(random_bytes(32)),
            'installed_at' => date('c'),
        ];

        $pdo = null;
        if (!$errors) {
            try {
                $pdo = db_connect($cfg);
            } catch (Throwable $e) {
                $errors[] = 'K databázi se nepodařilo připojit: ' . $e->getMessage();
            }
        }

        if (!$errors && $pdo) {
            try {
                foreach (['files', 'tmp', 'tmp/chunks', 'backups', 'logs'] as $sub) {
                    ensure_dir($dataDir . '/' . $sub);
                }
                // Pojistka pro případ, že by datová složka přece jen byla dostupná z webu.
                file_put_contents($dataDir . '/.htaccess', "Require all denied\n");
                run_migrations($pdo);
                $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                $exists->execute([mb_strtolower($values['admin_email'])]);
                if (!$exists->fetchColumn()) {
                    $pdo->prepare('INSERT INTO users (email, name, password_hash, created_at) VALUES (?, ?, ?, NOW())')
                        ->execute([mb_strtolower($values['admin_email']), $values['admin_name'], password_hash($adminPass, PASSWORD_DEFAULT)]);
                }
                $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
                    ->execute(['github_repo', DEFAULT_REPO]);

                if (realpath($dataDir) !== realpath(dirname(APP_ROOT) . '/brand-data')) {
                    file_put_contents(APP_ROOT . '/data-path.php', "<?php\nreturn " . var_export(realpath($dataDir) ?: $dataDir, true) . ";\n");
                }
                $php = "<?php\n// Brand Hub – konfigurace (vytvořeno instalací " . date('j. n. Y H:i') . ")\nreturn " . var_export($cfg, true) . ";\n";
                if (file_put_contents($dataDir . '/config.php', $php) === false) {
                    throw new RuntimeException('Nelze zapsat config.php do datové složky.');
                }
                @chmod($dataDir . '/config.php', 0640);
                flash('success', 'Instalace proběhla. Přihlaste se e-mailem a heslem, které jste právě zadali.');
                redirect('/login');
            } catch (Throwable $e) {
                $errors[] = 'Instalace se nepovedla: ' . $e->getMessage();
            }
        }
    }

    render('install', ['errors' => $errors, 'values' => $values, 'checks' => requirements()], 'plain');
}
