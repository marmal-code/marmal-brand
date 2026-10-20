<?php
declare(strict_types=1);

/*
 * Databázové migrace: soubory migrations/NNN_nazev.sql se spouštějí podle pořadí,
 * každá jen jednou. Seznam proběhlých migrací je v tabulce `migrations`.
 * Příkazy v souboru se oddělují středníkem na konci řádku.
 */

function migrations_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
        name VARCHAR(190) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function migration_files(): array
{
    $files = glob(APP_ROOT . '/migrations/*.sql') ?: [];
    sort($files, SORT_STRING);
    return $files;
}

function pending_migrations(PDO $pdo): array
{
    migrations_table($pdo);
    $done = $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_filter(migration_files(), fn ($f) => !in_array(basename($f), $done, true)));
}

function split_sql(string $sql): array
{
    $lines = preg_split('/\r?\n/', $sql) ?: [];
    $lines = array_filter($lines, fn ($l) => !preg_match('/^\s*--/', $l));
    $parts = preg_split('/;\s*(?:\n|$)/', implode("\n", $lines)) ?: [];
    return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
}

/** Spustí čekající migrace. Vrací seznam spuštěných souborů. Při chybě vyhodí výjimku s názvem migrace. */
function run_migrations(PDO $pdo): array
{
    $ran = [];
    foreach (pending_migrations($pdo) as $file) {
        $name = basename($file);
        try {
            foreach (split_sql((string) file_get_contents($file)) as $statement) {
                $pdo->exec($statement);
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Migrace ' . $name . ' selhala: ' . $e->getMessage(), 0, $e);
        }
        $pdo->prepare('INSERT INTO migrations (name, applied_at) VALUES (?, NOW())')->execute([$name]);
        $ran[] = $name;
    }
    return $ran;
}
