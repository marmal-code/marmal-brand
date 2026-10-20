<?php
declare(strict_types=1);

/*
 * Jednoduchý a bezpečný Markdown pro texty sekcí.
 * Umí: ## a ### nadpisy, odstavce, odrážky (- …), číslované seznamy (1. …), tabulky (| a | b |),
 * zvýrazněný blok (> …), **tučně**, *kurzívu*, `kód`, [odkaz](https://…) a barevný čtvereček {#373F48}.
 * Veškerý vstup se nejdřív escapuje, HTML v textu se tedy nikdy nevykoná.
 */

function md_inline(string $text): string
{
    $s = e($text);
    // `kód`
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s) ?? $s;
    // **tučně** a *kurzíva*
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?![\*\w])/s', '<em>$1</em>', $s) ?? $s;
    // [text](https://…)
    $s = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/', function ($m) {
        return '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
    }, $s) ?? $s;
    // {#RRGGBB} barevný čtvereček
    $s = preg_replace('/\{#([0-9A-Fa-f]{6})\}/', '<span class="md-chip" style="background:#$1" aria-hidden="true"></span>', $s) ?? $s;
    return $s;
}

function markdown(?string $src): string
{
    $src = str_replace(["\r\n", "\r"], "\n", trim((string) $src));
    if ($src === '') {
        return '';
    }
    $lines = explode("\n", $src);
    $out = [];
    $n = count($lines);
    $i = 0;
    while ($i < $n) {
        $line = $lines[$i];
        $t = trim($line);

        if ($t === '') {
            $i++;
            continue;
        }
        // nadpisy
        if (preg_match('/^(#{2,4})\s+(.+)$/', $t, $m)) {
            $level = min(4, strlen($m[1]) + 1); // ## → h3, ### → h4
            $out[] = '<h' . $level . '>' . md_inline($m[2]) . '</h' . $level . '>';
            $i++;
            continue;
        }
        // tabulka
        if (str_starts_with($t, '|')) {
            $rows = [];
            while ($i < $n && str_starts_with(trim($lines[$i]), '|')) {
                $rows[] = trim($lines[$i]);
                $i++;
            }
            $out[] = md_table($rows);
            continue;
        }
        // zvýrazněný blok
        if (str_starts_with($t, '>')) {
            $buf = [];
            while ($i < $n && str_starts_with(trim($lines[$i]), '>')) {
                $buf[] = ltrim(substr(trim($lines[$i]), 1));
                $i++;
            }
            $out[] = '<div class="md-note">' . md_inline(implode(' ', $buf)) . '</div>';
            continue;
        }
        // odrážky
        if (preg_match('/^[-*•]\s+/', $t)) {
            $items = [];
            while ($i < $n && preg_match('/^[-*•]\s+(.*)$/', trim($lines[$i]), $m)) {
                $items[] = '<li>' . md_inline($m[1]) . '</li>';
                $i++;
            }
            $out[] = '<ul>' . implode('', $items) . '</ul>';
            continue;
        }
        if (preg_match('/^\d+[.)]\s+/', $t)) {
            $items = [];
            while ($i < $n && preg_match('/^\d+[.)]\s+(.*)$/', trim($lines[$i]), $m)) {
                $items[] = '<li>' . md_inline($m[1]) . '</li>';
                $i++;
            }
            $out[] = '<ol>' . implode('', $items) . '</ol>';
            continue;
        }
        // odstavec (do prázdného řádku nebo jiného bloku)
        $buf = [];
        while ($i < $n) {
            $tt = trim($lines[$i]);
            if ($tt === '' || preg_match('/^(#{2,4}\s|\||>|[-*•]\s|\d+[.)]\s)/', $tt)) {
                break;
            }
            $buf[] = $tt;
            $i++;
        }
        $out[] = '<p>' . implode('<br>', array_map('md_inline', $buf)) . '</p>';
    }
    return implode("\n", $out);
}

function md_table(array $rows): string
{
    $cells = fn (string $r) => array_map('trim', explode('|', trim($r, " |")));
    $head = null;
    $body = [];
    foreach ($rows as $idx => $r) {
        if (preg_match('/^\|?\s*:?-{2,}/', $r)) {
            continue; // oddělovač hlavičky
        }
        if ($idx === 0 && isset($rows[1]) && preg_match('/^\|?\s*:?-{2,}/', $rows[1])) {
            $head = $cells($r);
            continue;
        }
        $body[] = $cells($r);
    }
    $html = '<div class="table-wrap md-table"><table class="table">';
    if ($head) {
        $html .= '<thead><tr>' . implode('', array_map(fn ($c) => '<th scope="col">' . md_inline($c) . '</th>', $head)) . '</tr></thead>';
    }
    $html .= '<tbody>';
    foreach ($body as $r) {
        $html .= '<tr>' . implode('', array_map(fn ($c) => '<td>' . md_inline($c) . '</td>', $r)) . '</tr>';
    }
    return $html . '</tbody></table></div>';
}
