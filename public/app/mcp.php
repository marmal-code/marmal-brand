<?php
declare(strict_types=1);

/*
 * AI konektor (MCP – Model Context Protocol) pro Claude, ChatGPT a Gemini. Adresa https://brand.marmal.cz/mcp,
 * přihlášení app/mcp_oauth.php, obsluha adres app/controllers/mcp.php. Vzor: aplikace MarMal Správa.
 *
 * Transport „Streamable HTTP“ bez stavu: POST s JSON-RPC, odpověď application/json (bez SSE a bez session).
 * Vše je jako nástroje (tools).
 *
 * Nástroje (mcp_tools()):
 *  - čtení: klienti_seznam, brand_prehled, soubory_hledat, soubor_stahnout, brand_css
 *  - zápis (oprávnění „zapis“): soubor_pridat, nahravani_pripravit, pozice_zalozit, pozice_upravit, barvy_upravit, text_sekce_upravit
 *  - záměrně NIKDY: mazání (pozic, verzí, souborů, barev, klientů), hesla klientů, sdílené odkazy, nastavení klienta
 *
 * Přenos souborů – konektor posílá jen JSON, proto:
 *  - SVG jde celé jako text (stažení i nahrání),
 *  - rastrové obrázky: náhled jako obrázek (zmenšený GD), originál přes dočasný podepsaný odkaz /mcp/soubor/… (1 hodina),
 *    malé soubory i jako base64,
 *  - nahrání: SVG text, base64 (malé soubory), veřejná adresa (server si soubor stáhne) nebo jednorázový odkaz
 *    /mcp/nahrat/{token} (nahravani_pripravit → curl -T soubor → soubor_pridat s nahravani).
 *
 * Každý nástroj jde vypnout v Nastavení → AI konektor (settings mcp.tool.<název> = 0). Volání se logují (mcp_log).
 */

const MCP_PROTOCOLS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
const MCP_LINK_SECONDS = 3600;               // dočasný odkaz ke stažení
const MCP_UPLOAD_LINK_SECONDS = 1800;        // jednorázový odkaz pro nahrání
const MCP_UPLOAD_MAX_BYTES = 500 * 1024 * 1024;
const MCP_FETCH_MAX_BYTES = 200 * 1024 * 1024;
const MCP_INLINE_MAX_BYTES = 25 * 1024 * 1024; // base64 v požadavku
const MCP_SVG_INLINE_MAX = 600 * 1024;       // SVG vrácené jako text
const MCP_BASE64_MAX = 150 * 1024;           // originál vrácený jako base64
const MCP_PREVIEW_PX = 1024;
const MCP_PREVIEW_MAX_PX = 1600;

/** Chyba nástroje – text se vrátí AI (isError), aby mohla dotaz opravit. */
class McpError extends RuntimeException
{
}

const MCP_INSTRUCTIONS = <<<'TXT'
MarMal Brand Hub (brand.marmal.cz) je Martinova aplikace s online brand manuály klientů: logo, barvy, písma, ikony, grafika pro web a sociální sítě, tiskoviny, šablony a texty pravidel. Odpovídej česky.
- Klienta zadej názvem nebo jeho částí (`klient`, např. „Kukátko“), pozici (logo, ikonu…) názvem nebo adresou (`pozice`, např. „logo-primarni“). Když odpovídá víc možností, nástroj vrátí kandidáty.
- Začni `brand_prehled` (co klient má) nebo `soubory_hledat` (např. „logo bílé svg“). Konkrétní soubor vezmi `soubor_stahnout`.
- `soubor_stahnout`: SVG vrátí celé jako text (ulož ho do souboru nebo vlož do stránky), PNG/JPG/WebP jako náhled obrázku a dočasný odkaz na originál (platí 1 hodinu). Malé soubory i jako base64.
- `brand_css` vrátí barvy jako CSS proměnné a písma – hodí se pro web.
- Zápis: `soubor_pridat` (do existující pozice; výchozí je nová verze, předchozí zůstane v historii a jde obnovit), `pozice_zalozit`, `pozice_upravit`, `barvy_upravit`, `text_sekce_upravit`. Velké soubory: `nahravani_pripravit` → nahrát přes curl → `soubor_pridat` s `nahravani`.
- Nic se přes konektor nemaže a hesla ani sdílené odkazy klientů konektor nevrací.
- Texty pro klienty piš v 1. osobě jednotného čísla a klientům vykej (tón MarMal.cz).
TXT;

/* ================= Definice nástrojů ================= */

function mcp_p_brand(): array
{
    return [
        'klient' => ['type' => 'string', 'description' => 'Klient – název nebo jeho část (např. „Cargo“, „kostka-stav“). Místo něj lze klient_id.'],
        'klient_id' => ['type' => 'integer', 'description' => 'ID klienta (z klienti_seznam).'],
    ];
}

function mcp_p_item(): array
{
    return mcp_p_brand() + [
        'pozice' => ['type' => 'string', 'description' => 'Pozice v manuálu – název nebo adresa (např. „Logo primární“, „logo-primarni“, „ikona-houby“). Místo ní lze pozice_id.'],
        'pozice_id' => ['type' => 'integer', 'description' => 'ID pozice (z brand_prehled).'],
        'sekce' => mcp_p_section('Sekce pro upřesnění hledání pozice.'),
    ];
}

function mcp_p_section(string $description, bool $withColors = false): array
{
    $keys = array_keys(SECTIONS);
    if (!$withColors) {
        $keys = array_values(array_diff($keys, ['barvy']));
    }
    $names = implode(', ', array_map(fn ($k) => $k . ' = ' . SECTIONS[$k]['title'], $keys));
    return ['type' => 'string', 'enum' => $keys, 'description' => $description . ' (' . $names . ')'];
}

/**
 * Všechny nástroje: název => [titulek, popis, oprávnění (cteni|zapis), parametry (JSON Schema properties), povinné, funkce, idempotentní].
 */
function mcp_tools(): array
{
    $fileSource = [
        'type' => 'object',
        'properties' => [
            'nazev' => ['type' => 'string', 'description' => 'Název souboru s příponou, např. „logo-bile.svg“, „ikona-houby.png“.'],
            'svg' => ['type' => 'string', 'description' => 'Obsah SVG jako text (jen pro .svg).'],
            'base64' => ['type' => 'string', 'description' => 'Obsah souboru v base64 (jen malé soubory, do pár set kB).'],
            'url' => ['type' => 'string', 'description' => 'Veřejná adresa http(s), ze které si server soubor stáhne (např. obrázek z webu klienta).'],
            'nahravani' => ['type' => 'string', 'description' => 'Token z nahravani_pripravit, když už je soubor nahraný přes jednorázový odkaz.'],
            'popisek' => ['type' => 'string', 'description' => 'Popisek formátu pro klienta, např. „SVG · na tmavý podklad“, „PNG 512 px“ (výchozí podle přípony).'],
            'jen_nahled' => ['type' => 'boolean', 'description' => 'true = soubor slouží jen jako náhled, klient ho nestahuje.'],
            'nahled' => ['type' => 'boolean', 'description' => 'true = použít tento soubor jako náhled pozice.'],
        ],
        'required' => ['nazev'],
    ];
    return [
        // ------------------------------------------------------------ čtení
        'klienti_seznam' => ['Seznam klientů', 'Klienti (brandy) v Brand Hubu: název, adresa, vyplněnost sekcí, poslední změna, odkaz pro klienta a do administrace.',
            'cteni', ['hledat' => ['type' => 'string', 'description' => 'Část názvu nebo adresy klienta.']], [], 'mcp_t_klienti_seznam', true],
        'brand_prehled' => ['Přehled brandu klienta', 'Všechno o brandu jednoho klienta: barvy (HEX, RGB, CMYK, Pantone, použití), písma, sekce s pozicemi a soubory aktuálních verzí (ID souboru, formát, popisek, velikost). S parametrem sekce jen jedna sekce i s textem pro klienta.',
            'cteni', mcp_p_brand() + [
                'sekce' => mcp_p_section('Jen jedna sekce.', true),
                'texty' => ['type' => 'boolean', 'description' => 'Přidat texty sekcí pro klienta (Markdown). Výchozí: ano, když je zadaná sekce.'],
            ], [], 'mcp_t_brand_prehled', true],
        'soubory_hledat' => ['Hledat soubory', 'Najde soubory podle slov v názvu klienta, pozice, popisku a formátu, napříč klienty nebo u jednoho. Např. „kukátko logo bílé svg“, „ikona houby“, „favicon“. Vrací soubor_id pro soubor_stahnout.',
            'cteni', ['dotaz' => ['type' => 'string', 'description' => 'Hledaná slova; přípona (svg, png, pdf…) filtruje formát.']] + mcp_p_brand() + [
                'sekce' => mcp_p_section('Jen v sekci.'),
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Kolik nejvýš vrátit (výchozí 30).'],
            ], ['dotaz'], 'mcp_t_soubory_hledat', true],
        'soubor_stahnout' => ['Stáhnout soubor', 'Jeden soubor podle soubor_id, nebo podle klienta + pozice + formátu. SVG vrátí celé jako text. PNG/JPG/WebP/GIF vrátí jako náhled obrázku (zmenšený). Vždy přidá dočasný odkaz na originál (platí 1 hodinu) a trvalý veřejný odkaz, pokud ho klient má zapnutý. Písma, PDF a ostatní jen jako odkaz.',
            'cteni', ['soubor_id' => ['type' => 'integer', 'description' => 'ID souboru (z brand_prehled nebo soubory_hledat).']] + mcp_p_item() + [
                'format' => ['type' => 'string', 'description' => 'Přípona: svg, png, webp, jpg, pdf, woff2… (výchozí: SVG, jinak první obrázek).'],
                'varianta' => ['type' => 'string', 'description' => 'Část popisku nebo názvu souboru, když má pozice víc souborů stejného formátu (např. „tmavý“, „512“).'],
                'nahled_px' => ['type' => 'integer', 'minimum' => 64, 'maximum' => MCP_PREVIEW_MAX_PX, 'description' => 'Delší strana náhledu rastrového obrázku (výchozí ' . MCP_PREVIEW_PX . ').'],
                'base64' => ['type' => 'boolean', 'description' => 'Přidat originál v base64 (jen soubory do ' . (MCP_BASE64_MAX / 1024) . ' kB, např. favicon nebo malá ikona).'],
            ], [], 'mcp_t_soubor_stahnout', true],
        'brand_css' => ['Barvy a písma jako CSS', 'Barvy brandu jako CSS proměnné (:root, včetně odstínů, když je mají zapnuté) a písma (rodiny, řezy, Google Fonts). Pro web nebo grafiku v HTML.',
            'cteni', mcp_p_brand(), [], 'mcp_t_brand_css', true],

        // ------------------------------------------------------------ zápis
        'soubor_pridat' => ['Přidat soubory do pozice', 'Nahraje jeden nebo víc souborů do existující pozice. rezim nova_verze (výchozí) založí novou verzi – předchozí zůstane v historii a jde obnovit; k_aktualni přidá soubory k aktuální verzi (např. další formát). U Loga, Ikon, Webu a Sociálních sítí se k PNG/JPG samo vytvoří WebP. Zdroj každého souboru: svg (text), base64 (malé), url (server stáhne) nebo nahravani (token z nahravani_pripravit).',
            'zapis', mcp_p_item() + [
                'soubory' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => $fileSource, 'description' => 'Soubory k nahrání (u každého právě jeden zdroj).'],
                'rezim' => ['type' => 'string', 'enum' => ['nova_verze', 'k_aktualni'], 'description' => 'Výchozí nova_verze.'],
                'poznamka' => ['type' => 'string', 'description' => 'Poznámka k verzi (co se změnilo).'],
                'webp' => ['type' => 'boolean', 'description' => 'Vytvořit WebP k PNG/JPG (výchozí ano).'],
            ], ['soubory'], 'mcp_t_soubor_pridat', false],
        'nahravani_pripravit' => ['Odkaz pro nahrání velkého souboru', 'Vytvoří jednorázovou adresu pro nahrání souboru (platí 30 minut), např. z počítače nebo terminálu: curl -T soubor.png <adresa>. Pak zavolej soubor_pridat se soubory: [{nazev, nahravani: token}]. Pro soubory, které nejdou poslat jako svg/base64/url.',
            'zapis', ['nazev' => ['type' => 'string', 'description' => 'Název souboru (pro přehled, nepovinné).']], [], 'mcp_t_nahravani_pripravit', false],
        'pozice_zalozit' => ['Založit pozici', 'Založí novou pozici (položku) v sekci, např. novou ikonu, ilustraci nebo tiskovinu. Soubory pak přidej přes soubor_pridat.',
            'zapis', mcp_p_brand() + [
                'sekce' => mcp_p_section('Sekce nové pozice.'),
                'nazev' => ['type' => 'string', 'description' => 'Název pozice pro klienta (např. „Ikona – houby“).'],
                'adresa' => ['type' => 'string', 'description' => 'Adresa pro trvalé odkazy (např. „ikona-houby“); výchozí z názvu.'],
                'popis' => ['type' => 'string', 'description' => 'Popis pro klienta (kdy a jak pozici použít).'],
                'rozmer' => ['type' => 'string', 'description' => 'Rozměr nebo specifikace (např. „1200 × 630 px“, „A4, spadávka 3 mm“).'],
                'stitky' => ['type' => 'string', 'description' => 'Štítky oddělené čárkou.'],
                'povinna' => ['type' => 'boolean', 'description' => 'Povinná pozice (počítá se do vyplněnosti).'],
                'pozadi_nahledu' => ['type' => 'string', 'description' => 'HEX barva pozadí náhledu (např. pro bílé logo „#1F2A37“).'],
            ], ['sekce', 'nazev'], 'mcp_t_pozice_zalozit', false],
        'pozice_upravit' => ['Upravit pozici', 'Změní název, popis, rozměr, štítky, povinnost, pozadí náhledu, adresu nebo sekci pozice; u písma rodinu, řezy, ukázku, Google Fonts a licenci. Zadej jen to, co se mění. Změna adresy mění trvalé odkazy.',
            'zapis', mcp_p_item() + [
                'nazev' => ['type' => 'string'],
                'popis' => ['type' => 'string', 'description' => 'Popis pro klienta; prázdný text popis smaže.'],
                'rozmer' => ['type' => 'string'],
                'stitky' => ['type' => 'string'],
                'povinna' => ['type' => 'boolean'],
                'pozadi_nahledu' => ['type' => 'string', 'description' => 'HEX, prázdné = výchozí.'],
                'adresa' => ['type' => 'string', 'description' => 'Nová adresa trvalých odkazů.'],
                'presunout_do' => mcp_p_section('Přesunout pozici do jiné sekce (ne Barvy a Typografie).'),
                'pismo' => ['type' => 'object', 'description' => 'Jen u písem (sekce typografie).', 'properties' => [
                    'rodina' => ['type' => 'string'], 'rezy' => ['type' => 'string', 'description' => 'např. „Regular 400, Bold 700“'],
                    'ukazka' => ['type' => 'string'], 'google_fonts' => ['type' => 'string', 'description' => 'Odkaz na Google Fonts'],
                    'licence' => ['type' => 'string'],
                ]],
            ], [], 'mcp_t_pozice_upravit', true],
        'barvy_upravit' => ['Upravit nebo přidat barvy', 'Upraví barvy brandu (párování podle názvu, jinak podle HEX) nebo přidá nové na konec palety. Zadané vlastnosti se přepíšou, ostatní zůstanou. Barvy se nemažou.',
            'zapis', mcp_p_brand() + [
                'barvy' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object', 'properties' => [
                    'nazev' => ['type' => 'string'], 'hex' => ['type' => 'string', 'description' => '#RRGGBB'],
                    'cmyk' => ['type' => 'string', 'description' => 'např. „100 60 0 20“'], 'pantone' => ['type' => 'string'],
                    'role' => ['type' => 'string', 'description' => 'např. „Primární“, „Doplňková“'],
                    'pouziti' => ['type' => 'string', 'description' => 'Kdy barvu použít.'],
                    'odstiny' => ['type' => 'boolean', 'description' => 'Zobrazit odstíny 100–10 %.'],
                ]]],
            ], ['barvy'], 'mcp_t_barvy_upravit', true],
        'text_sekce_upravit' => ['Upravit text sekce', 'Nahradí nebo doplní text sekce pro klienta (Markdown: ## nadpis, - odrážka, **tučně**). Vrátí i předchozí text, aby šel případně vrátit.',
            'zapis', mcp_p_brand() + [
                'sekce' => mcp_p_section('Sekce.', true),
                'text' => ['type' => 'string', 'description' => 'Text v Markdownu.'],
                'rezim' => ['type' => 'string', 'enum' => ['nahradit', 'pripojit'], 'description' => 'Výchozí nahradit; pripojit = přidat na konec.'],
            ], ['sekce', 'text'], 'mcp_t_text_sekce_upravit', false],
    ];
}

function mcp_tool_enabled(string $name): bool
{
    return setting('mcp.tool.' . $name, '1') === '1';
}

/** Nástroje dostupné pro token (zapnuté a v rámci oprávnění). */
function mcp_tools_for(array $token): array
{
    $out = [];
    foreach (mcp_tools() as $name => $t) {
        if (mcp_tool_enabled($name) && mcp_has_scope($token, $t[2])) {
            $out[$name] = $t;
        }
    }
    return $out;
}

function mcp_tool_describe(string $name, array $t): array
{
    $schema = ['type' => 'object', 'properties' => $t[3] ?: new stdClass()];
    if ($t[4]) {
        $schema['required'] = $t[4];
    }
    $read = $t[2] === 'cteni';
    return [
        'name' => $name,
        'title' => $t[0],
        'description' => $t[1],
        'inputSchema' => $schema,
        'annotations' => ['title' => $t[0], 'readOnlyHint' => $read, 'destructiveHint' => false, 'idempotentHint' => (bool) $t[6], 'openWorldHint' => false],
    ];
}

/* ================= JSON-RPC ================= */

/** Zpracuje jednu zprávu JSON-RPC; vrací odpověď, nebo null u notifikace. */
function mcp_handle_message(mixed $msg, array $token): ?array
{
    if (!is_array($msg) || ($msg['jsonrpc'] ?? null) !== '2.0' || !isset($msg['method']) || !is_string($msg['method'])) {
        return ['jsonrpc' => '2.0', 'id' => is_array($msg) ? ($msg['id'] ?? null) : null, 'error' => ['code' => -32600, 'message' => 'Neplatný požadavek JSON-RPC.']];
    }
    if (!array_key_exists('id', $msg)) {
        return null; // notifikace (initialized, cancelled…)
    }
    $id = $msg['id'];
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
    $ok = fn (array|stdClass $result) => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];

    switch ($msg['method']) {
        case 'initialize':
            $wanted = (string) ($params['protocolVersion'] ?? '');
            return $ok([
                'protocolVersion' => in_array($wanted, MCP_PROTOCOLS, true) ? $wanted : MCP_PROTOCOLS[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'marmal-brand-hub', 'title' => 'MarMal Brand Hub', 'version' => APP_VERSION],
                'instructions' => MCP_INSTRUCTIONS,
            ]);
        case 'ping':
            return $ok(new stdClass());
        case 'tools/list':
            $tools = [];
            foreach (mcp_tools_for($token) as $name => $t) {
                $tools[] = mcp_tool_describe($name, $t);
            }
            return $ok(['tools' => $tools]);
        case 'tools/call':
            return $ok(mcp_call((string) ($params['name'] ?? ''), is_array($params['arguments'] ?? null) ? $params['arguments'] : [], $token));
        case 'resources/list':
            return $ok(['resources' => []]);
        case 'resources/templates/list':
            return $ok(['resourceTemplates' => []]);
        case 'prompts/list':
            return $ok(['prompts' => []]);
        default:
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Metoda ' . $msg['method'] . ' není podporovaná.']];
    }
}

/** Token aktuálního volání (pro log a nahrávání). */
function mcp_current_token(?array $set = null): ?array
{
    static $token = null;
    if ($set !== null) {
        $token = $set;
    }
    return $token;
}

/** Název aplikace pro záznamy v Aktivitě („AI konektor: Claude“). */
function mcp_actor_label(): string
{
    $t = mcp_current_token();
    $app = trim((string) ($t['client_name'] ?? $t['name'] ?? ''));
    return 'AI konektor' . ($app !== '' ? ': ' . mb_substr($app, 0, 40) : '');
}

/**
 * Zavolá nástroj, zaloguje a vrátí výsledek ve tvaru MCP.
 * Nástroj vrací pole dat (→ JSON v content + structuredContent), nebo ['__content' => bloky] (text, obrázky).
 */
function mcp_call(string $name, array $args, array $token): array
{
    $start = microtime(true);
    mcp_current_token($token);
    $tools = mcp_tools();
    $error = null;
    $data = null;
    if (!isset($tools[$name])) {
        $error = 'Neznámý nástroj ' . $name . '.';
    } elseif (!mcp_tool_enabled($name)) {
        $error = 'Nástroj ' . $name . ' je v Brand Hubu vypnutý (Nastavení → AI konektor).';
    } elseif (!mcp_has_scope($token, $tools[$name][2])) {
        $error = 'Připojení nemá oprávnění k zápisu. Připojte konektor znovu a povolte zápis, nebo použijte token se zápisem.';
    } else {
        try {
            $data = ($tools[$name][5])($args);
        } catch (McpError | InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[brand-hub] MCP ' . $name . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $error = 'Chyba v aplikaci při provádění nástroje (' . mb_substr($e->getMessage(), 0, 300) . ').';
        }
    }
    mcp_log($token, $name, $args, $error, (int) round((microtime(true) - $start) * 1000));
    if ($error !== null) {
        return ['content' => [['type' => 'text', 'text' => $error]], 'isError' => true];
    }
    if (isset($data['__content'])) {
        return ['content' => $data['__content'], 'isError' => false];
    }
    return [
        'content' => [['type' => 'text', 'text' => mcp_encode($data)]],
        'structuredContent' => $data,
        'isError' => false,
    ];
}

function mcp_encode(mixed $data): string
{
    return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

/** Dlouhé hodnoty (SVG, base64) se do logu zkracují. */
function mcp_log_args(mixed $v): mixed
{
    if (is_string($v)) {
        return mb_strlen($v) > 160 ? mb_substr($v, 0, 120) . '… (' . mb_strlen($v) . ' znaků)' : $v;
    }
    if (is_array($v)) {
        return array_map('mcp_log_args', $v);
    }
    return $v;
}

function mcp_log(array $token, string $tool, array $args, ?string $error, int $ms): void
{
    try {
        db_exec(
            'INSERT INTO mcp_log (token_id, app, tool, args, ok, error, ms) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [(int) $token['id'], mb_substr((string) ($token['client_name'] ?: $token['name'] ?: ''), 0, 255), mb_substr($tool, 0, 60),
                mb_substr(mcp_encode(mcp_log_args($args)), 0, 2000), $error === null ? 1 : 0, $error !== null ? mb_substr($error, 0, 500) : null, $ms]
        );
        db_exec('UPDATE mcp_tokens SET calls = calls + 1 WHERE id = ?', [(int) $token['id']]);
    } catch (Throwable $e) {
        error_log('[brand-hub] MCP log: ' . $e->getMessage());
    }
}

/* ================= Pomocné ================= */

function mcp_str(array $a, string $key, int $max = 0): ?string
{
    if (!isset($a[$key]) || is_array($a[$key])) {
        return null;
    }
    $t = trim((string) $a[$key]);
    return $t === '' ? null : ($max ? mb_substr($t, 0, $max) : $t);
}

function mcp_int(array $a, string $key): ?int
{
    return isset($a[$key]) && is_numeric($a[$key]) ? (int) $a[$key] : null;
}

function mcp_bool(array $a, string $key, ?bool $default = null): ?bool
{
    if (!array_key_exists($key, $a) || $a[$key] === null) {
        return $default;
    }
    $v = $a[$key];
    return is_bool($v) ? $v : in_array(strtolower(trim((string) $v)), ['1', 'true', 'ano', 'yes'], true);
}

/** Text pro porovnávání: malá písmena bez diakritiky, slova oddělená mezerou. */
function mcp_norm(string $text): string
{
    return trim(str_replace('-', ' ', slugify($text)));
}

function mcp_admin_url(string $path): string
{
    return mcp_base() . $path;
}

/** Klient podle klient_id nebo názvu/adresy (stačí část). */
function mcp_brand(array $a, bool $required = true): ?array
{
    $id = mcp_int($a, 'klient_id');
    if ($id) {
        return brand_by_id($id) ?? throw new McpError('Klient s ID ' . $id . ' neexistuje.');
    }
    $q = mcp_str($a, 'klient');
    if ($q === null) {
        if ($required) {
            throw new McpError('Zadejte klienta (klient = název, nebo klient_id). Seznam vrátí klienti_seznam.');
        }
        return null;
    }
    $brands = db_all('SELECT * FROM brands ORDER BY name');
    $n = mcp_norm($q);
    foreach ($brands as $b) {
        if ($b['slug'] === $q || mb_strtolower($b['name']) === mb_strtolower($q) || mcp_norm($b['name']) === $n) {
            return $b;
        }
    }
    $found = array_values(array_filter($brands, fn ($b) => str_contains(mcp_norm($b['name']), $n) || str_contains(mcp_norm($b['slug']), $n)));
    if (count($found) === 1) {
        return $found[0];
    }
    if (!$found) {
        throw new McpError('Klienta „' . $q . '“ jsem nenašel. Klienti: ' . implode(', ', array_map(fn ($b) => $b['name'] . ' (' . $b['slug'] . ')', $brands)) . '.');
    }
    throw new McpError('Klientovi „' . $q . '“ odpovídá víc možností, upřesněte: ' . implode(', ', array_map(fn ($b) => $b['name'] . ' (klient_id ' . $b['id'] . ')', $found)) . '.');
}

function mcp_section(array $a, string $key, bool $withColors = false, bool $required = false): ?string
{
    $s = mcp_str($a, $key);
    if ($s === null) {
        if ($required) {
            throw new McpError('Zadejte sekci (' . $key . ').');
        }
        return null;
    }
    $s = mb_strtolower($s);
    if (!isset(SECTIONS[$s])) {
        foreach (SECTIONS as $k => $sec) {
            if (mcp_norm($sec['title']) === mcp_norm($s)) {
                $s = $k;
                break;
            }
        }
    }
    if (!isset(SECTIONS[$s]) || (!$withColors && $s === 'barvy')) {
        throw new McpError('Neznámá sekce „' . $s . '“. Sekce: ' . implode(', ', array_keys(SECTIONS)) . '.');
    }
    return $s;
}

/** Pozice podle pozice_id nebo klienta + názvu/adresy pozice. Vrací [brand, item] (item se soubory aktuální verze). */
function mcp_item(array $a): array
{
    $id = mcp_int($a, 'pozice_id');
    if ($id) {
        $item = item_by_id($id) ?? throw new McpError('Pozice s ID ' . $id . ' neexistuje.');
        return [brand_by_id((int) $item['brand_id']), $item];
    }
    $brand = mcp_brand($a);
    $q = mcp_str($a, 'pozice') ?? throw new McpError('Zadejte pozici (pozice = název nebo adresa, nebo pozice_id). Pozice vypíše brand_prehled.');
    $section = mcp_section($a, 'sekce');
    $items = brand_items((int) $brand['id'], $section);
    $n = mcp_norm($q);
    foreach ($items as $it) {
        if ($it['slug'] === $q || mcp_norm($it['title']) === $n || $it['slug'] === slugify($q)) {
            return [$brand, $it];
        }
    }
    $words = array_filter(explode(' ', $n));
    $found = array_values(array_filter($items, function ($it) use ($words) {
        $hay = mcp_norm($it['title'] . ' ' . $it['slug']);
        foreach ($words as $w) {
            if (!str_contains($hay, $w)) {
                return false;
            }
        }
        return true;
    }));
    if (count($found) === 1) {
        return [$brand, $found[0]];
    }
    $list = fn (array $its) => implode(', ', array_map(fn ($it) => $it['title'] . ' (' . $it['slug'] . ', ' . (SECTIONS[$it['section']]['title'] ?? $it['section']) . ', pozice_id ' . $it['id'] . ')', $its));
    if (!$found) {
        throw new McpError('Pozici „' . $q . '“ jsem u klienta ' . $brand['name'] . ' nenašel' . ($section ? ' v sekci ' . SECTIONS[$section]['title'] : '')
            . '. Pozice: ' . $list(array_slice($items, 0, 60)) . (count($items) > 60 ? ' …' : '') . '. Novou založí pozice_zalozit.');
    }
    throw new McpError('Pozici „' . $q . '“ odpovídá víc možností, upřesněte: ' . $list($found) . '.');
}

/** Trvalý veřejný odkaz /b/{slug}/f/{pozice}.{přípona} – jen když vede přesně na tento soubor a klient má veřejné odkazy. */
function mcp_permalink(array $brand, array $item, array $file): ?string
{
    if (empty($brand['public_links']) || (int) $item['current_version_id'] !== (int) $file['version_id']) {
        return null;
    }
    $ext = file_ext($file['stored_name']);
    $exts = $ext === 'jpg' ? ['jpg', 'jpeg'] : [$ext];
    $pick = null;
    foreach ($item['all_files'] as $f) {
        if (in_array(file_ext($f['stored_name']), $exts, true) && ($pick === null || $f['role'] === 'download')) {
            $pick = $f;
            if ($f['role'] === 'download') {
                break;
            }
        }
    }
    return $pick && (int) $pick['id'] === (int) $file['id'] ? brand_public_url($brand, '/f/' . $item['slug'] . '.' . $ext) : null;
}

function mcp_download_sig(int $fileId, int $expires): string
{
    return substr(hash_hmac('sha256', 'mcp-download|' . $fileId . '|' . $expires, (string) config('secret')), 0, 32);
}

/** Dočasný podepsaný odkaz na soubor (bez přihlášení, platí MCP_LINK_SECONDS). */
function mcp_download_url(array $file): string
{
    $exp = time() + MCP_LINK_SECONDS;
    return mcp_base() . '/mcp/soubor/' . (int) $file['id'] . '/' . $exp . '/' . mcp_download_sig((int) $file['id'], $exp) . '/' . rawurlencode((string) $file['original_name']);
}

function mcp_file_info(array $brand, array $item, array $f, bool $links = false): array
{
    $out = [
        'soubor_id' => (int) $f['id'],
        'format' => file_ext($f['stored_name']),
        'popisek' => (string) $f['label'],
        'soubor' => (string) $f['original_name'],
        'velikost' => human_size((int) $f['size']),
    ];
    if ($f['role'] === 'preview') {
        $out['jen_nahled'] = true;
    }
    if (!empty($f['is_preview'])) {
        $out['nahled_pozice'] = true;
    }
    if ($links) {
        $perma = mcp_permalink($brand, $item, $f);
        if ($perma) {
            $out['trvaly_odkaz'] = $perma;
        }
    }
    return $out;
}

function mcp_item_info(array $brand, array $item, bool $links = false): array
{
    $out = [
        'pozice_id' => (int) $item['id'],
        'pozice' => (string) $item['slug'],
        'nazev' => (string) $item['title'],
        'sekce' => (string) $item['section'],
    ];
    foreach (['description' => 'popis', 'specs' => 'rozmer', 'tags' => 'stitky', 'preview_bg' => 'pozadi_nahledu'] as $col => $key) {
        if (trim((string) ($item[$col] ?? '')) !== '') {
            $out[$key] = (string) $item[$col];
        }
    }
    if (!empty($item['required'])) {
        $out['povinna'] = true;
    }
    if ($item['kind'] === 'font') {
        $m = $item['meta'];
        $font = array_filter([
            'rodina' => $m['family'] ?? null, 'rezy' => $m['weights'] ?? null, 'google_fonts' => $m['google_url'] ?? null,
            'licence' => $m['license'] ?? null, 'ukazka' => $m['sample'] ?? null,
        ], fn ($v) => trim((string) $v) !== '');
        if ($font) {
            $out['pismo'] = $font;
        }
    }
    if ($item['current_version_id']) {
        $out['verze'] = (int) $item['version_no'];
        $out['zmeneno'] = cz_date($item['version_at'], true);
        $out['soubory'] = array_map(fn ($f) => mcp_file_info($brand, $item, $f, $links), $item['all_files']);
    } else {
        $out['prazdna'] = true;
    }
    return $out;
}

function mcp_color_info(array $c): array
{
    return array_filter([
        'nazev' => $c['name'], 'hex' => $c['hex'], 'rgb' => hex_to_rgb($c['hex']),
        'cmyk' => $c['cmyk'] ?: hex_to_cmyk($c['hex']) . ' (orientačně, dopočteno)', 'pantone' => $c['pantone'],
        'role' => $c['role'], 'pouziti' => $c['usage_note'] ?? null, 'odstiny' => !empty($c['tints']) ? true : null,
    ], fn ($v) => $v !== null && $v !== '');
}

/* ================= Nástroje – čtení ================= */

function mcp_t_klienti_seznam(array $a): array
{
    $q = mcp_str($a, 'hledat');
    $out = [];
    foreach (db_all('SELECT * FROM brands ORDER BY name') as $b) {
        if ($q !== null && !str_contains(mcp_norm($b['name'] . ' ' . $b['slug']), mcp_norm($q))) {
            continue;
        }
        $c = brand_completeness((int) $b['id']);
        $out[] = [
            'klient_id' => (int) $b['id'],
            'nazev' => $b['name'],
            'adresa' => $b['slug'],
            'aktivni' => (bool) $b['is_active'],
            'vyplneno' => $c['done'] . ' z ' . $c['total'] . ' sekcí',
            'posledni_zmena' => cz_date($b['updated_at'], true),
            'verejne_trvale_odkazy' => (bool) $b['public_links'],
            'odkaz_pro_klienta' => brand_public_url($b),
            'administrace' => mcp_admin_url('/admin/brands/' . $b['id']),
        ];
    }
    return ['pocet' => count($out), 'klienti' => $out];
}

function mcp_t_brand_prehled(array $a): array
{
    $brand = mcp_brand($a);
    $only = mcp_section($a, 'sekce', true);
    $withTexts = mcp_bool($a, 'texty', $only !== null);
    $texts = section_texts((int) $brand['id']);
    $out = [
        'klient_id' => (int) $brand['id'],
        'nazev' => $brand['name'],
        'adresa' => $brand['slug'],
        'aktivni' => (bool) $brand['is_active'],
        'uvod' => (string) ($brand['intro'] ?? ''),
        'kontakt' => (object) array_filter(['jmeno' => $brand['contact_name'] ?? null, 'telefon' => $brand['contact_phone'] ?? null, 'email' => $brand['contact_email'] ?? null]),
        'verejne_trvale_odkazy' => (bool) $brand['public_links'],
        'odkaz_pro_klienta' => brand_public_url($brand),
        'administrace' => mcp_admin_url('/admin/brands/' . $brand['id']),
        'posledni_zmena' => cz_date($brand['updated_at'], true),
    ];
    if ($only === null || $only === 'barvy') {
        $out['barvy'] = array_map('mcp_color_info', brand_colors((int) $brand['id']));
    }
    $items = brand_items((int) $brand['id'], $only !== null && $only !== 'barvy' ? $only : null);
    $sections = [];
    foreach (SECTIONS as $key => $sec) {
        if ($only !== null && $only !== $key) {
            continue;
        }
        $s = ['sekce' => $key, 'nazev' => $sec['title']];
        if (isset($texts[$key])) {
            if ($withTexts) {
                $s['text'] = $texts[$key];
            } else {
                $s['text_znaku'] = mb_strlen($texts[$key]);
            }
        }
        if ($key !== 'barvy') {
            $s['pozice'] = array_values(array_map(fn ($it) => mcp_item_info($brand, $it, $only !== null), array_filter($items, fn ($it) => $it['section'] === $key)));
        }
        $sections[] = $s;
    }
    $out['sekce'] = $sections;
    if (!$withTexts && $texts) {
        $out['poznamka'] = 'Texty sekcí pro klienta vrátí brand_prehled se sekce, nebo texty: true.';
    }
    return $out;
}

function mcp_t_soubory_hledat(array $a): array
{
    $query = mcp_str($a, 'dotaz') ?? throw new McpError('Zadejte, co hledat (dotaz).');
    $brand = mcp_brand($a, false);
    $section = mcp_section($a, 'sekce');
    $limit = max(1, min(100, mcp_int($a, 'limit') ?? 30));
    $formats = [];
    $words = [];
    foreach (preg_split('/\s+/u', mb_strtolower($query)) ?: [] as $w) {
        $w = trim($w, " .,;:\"'");
        if ($w === '') {
            continue;
        }
        if (in_array(ltrim($w, '.'), ALLOWED_EXT, true) || ltrim($w, '.') === 'jpg') {
            $formats[] = ltrim($w, '.');
            if (ltrim($w, '.') === 'jpg') {
                $formats[] = 'jpeg';
            }
            continue;
        }
        $n = mcp_norm($w);
        if ($n !== '') {
            $words[] = $n;
        }
    }
    $brands = $brand ? [$brand] : db_all('SELECT * FROM brands ORDER BY name');
    $hits = [];
    foreach ($brands as $b) {
        foreach (brand_items((int) $b['id'], $section, true) as $it) {
            $itemHay = ' ' . mcp_norm(implode(' ', [$brand ? '' : $b['name'], $b['slug'], $it['title'], $it['slug'], $it['description'] ?? '', $it['tags'] ?? '', $it['specs'] ?? '', SECTIONS[$it['section']]['title'] ?? ''])) . ' ';
            foreach ($it['all_files'] as $f) {
                $ext = file_ext($f['stored_name']);
                if ($formats && !in_array($ext, $formats, true)) {
                    continue;
                }
                $hay = $itemHay . mcp_norm($f['label'] . ' ' . $f['original_name']) . ' ';
                $score = 0;
                foreach ($words as $w) {
                    if (str_contains($hay, $w)) {
                        $score++;
                    }
                }
                $hits[] = ['score' => $score, 'b' => $b, 'it' => $it, 'f' => $f];
            }
        }
    }
    $need = count($words);
    $exact = array_values(array_filter($hits, fn ($h) => $h['score'] === $need));
    $approx = false;
    if (!$exact && $need > 1) {
        $exact = array_values(array_filter($hits, fn ($h) => $h['score'] > 0));
        usort($exact, fn ($x, $y) => $y['score'] <=> $x['score']);
        $approx = true;
    }
    $total = count($exact);
    $out = [];
    foreach (array_slice($exact, 0, $limit) as $h) {
        $out[] = ['klient' => $h['b']['name'], 'pozice' => $h['it']['title'], 'pozice_id' => (int) $h['it']['id'], 'sekce' => $h['it']['section']]
            + mcp_file_info($h['b'], $h['it'], $h['f'], true);
    }
    $res = ['dotaz' => $query, 'nalezeno' => $total, 'soubory' => $out];
    if ($approx) {
        $res['poznamka'] = 'Nic neodpovídá všem slovům, ukazuji soubory, které odpovídají aspoň části.';
    }
    if ($total > $limit) {
        $res['poznamka'] = trim(($res['poznamka'] ?? '') . ' Ukazuji prvních ' . $limit . ', upřesněte dotaz nebo zvyšte limit.');
    }
    if (!$total) {
        $res['poznamka'] = 'Nic jsem nenašel. Zkuste jiná slova, nebo brand_prehled klienta.';
    }
    return $res;
}

/** Rozměr SVG z width/height nebo viewBox (pro informaci). */
function mcp_svg_size(string $svg): ?string
{
    if (!preg_match('/<svg\b[^>]*>/is', $svg, $m)) {
        return null;
    }
    $tag = $m[0];
    if (preg_match('/viewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $tag, $v)) {
        return round((float) $v[1], 2) . ' × ' . round((float) $v[2], 2) . ' (viewBox)';
    }
    if (preg_match('/\bwidth\s*=\s*["\']([^"\']+)/i', $tag, $w) && preg_match('/\bheight\s*=\s*["\']([^"\']+)/i', $tag, $h)) {
        return $w[1] . ' × ' . $h[1];
    }
    return null;
}

/**
 * Náhled rastrového obrázku pro AI: zmenšený na $max px delší strany, WebP (nebo PNG). Vrací null, když to nejde.
 * ['mime', 'data' (base64), 'w', 'h', 'nw', 'nh']
 */
function mcp_image_preview(string $path, int $max): ?array
{
    $info = @getimagesize($path);
    if (!$info || $info[0] < 1 || $info[1] < 1) {
        return null;
    }
    [$w, $h, $type] = $info;
    $scale = min(1, $max / max($w, $h));
    $size = (int) @filesize($path);
    $mimes = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp', IMAGETYPE_GIF => 'image/gif'];
    if (!isset($mimes[$type])) {
        return null;
    }
    if ($scale >= 1 && $size <= 700 * 1024) {
        return ['mime' => $mimes[$type], 'data' => base64_encode((string) file_get_contents($path)), 'w' => $w, 'h' => $h, 'nw' => $w, 'nh' => $h];
    }
    if ($w * $h > 60_000_000 || !webp_memory_ok($w * $h * 10 + 16 * 1048576)) {
        return null;
    }
    $src = match ($type) {
        IMAGETYPE_PNG => @imagecreatefrompng($path),
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        IMAGETYPE_GIF => @imagecreatefromgif($path),
        default => false,
    };
    if (!$src) {
        return null;
    }
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    unset($src);
    ob_start();
    $webp = webp_supported();
    $webp ? imagewebp($dst, null, 85) : imagepng($dst, null, 6);
    $bytes = (string) ob_get_clean();
    unset($dst);
    if ($bytes === '') {
        return null;
    }
    return ['mime' => $webp ? 'image/webp' : 'image/png', 'data' => base64_encode($bytes), 'w' => $w, 'h' => $h, 'nw' => $nw, 'nh' => $nh];
}

function mcp_t_soubor_stahnout(array $a): array
{
    $fileId = mcp_int($a, 'soubor_id');
    if ($fileId) {
        $file = db_one('SELECT * FROM item_files WHERE id = ?', [$fileId]) ?? throw new McpError('Soubor s ID ' . $fileId . ' neexistuje.');
        $v = db_one('SELECT * FROM item_versions WHERE id = ?', [$file['version_id']]);
        $item = item_by_id((int) $v['item_id']);
        $brand = brand_by_id((int) $item['brand_id']);
    } else {
        [$brand, $item] = mcp_item($a);
        if (!$item['current_version_id']) {
            throw new McpError('Pozice „' . $item['title'] . '“ zatím nemá žádné soubory.');
        }
        $format = strtolower(ltrim((string) mcp_str($a, 'format'), '.'));
        $variant = mcp_str($a, 'varianta');
        $cands = $item['all_files'];
        if ($format !== '') {
            $exts = in_array($format, ['jpg', 'jpeg'], true) ? ['jpg', 'jpeg'] : [$format];
            $cands = array_values(array_filter($cands, fn ($f) => in_array(file_ext($f['stored_name']), $exts, true)));
        }
        if ($variant !== null) {
            $vn = mcp_norm($variant);
            $cands = array_values(array_filter($cands, fn ($f) => str_contains(mcp_norm($f['label'] . ' ' . $f['original_name']), $vn)));
        }
        if (!$cands) {
            throw new McpError('Pozice „' . $item['title'] . '“ nemá ' . ($format !== '' ? 'formát ' . $format : 'takový soubor') . ($variant ? ' s „' . $variant . '“' : '')
                . '. Má: ' . implode(', ', array_map(fn ($f) => file_ext($f['stored_name']) . ' – ' . $f['label'] . ' (soubor_id ' . $f['id'] . ')', $item['all_files'])) . '.');
        }
        // bez formátu: SVG, pak rastr; vždy přednost soubor ke stažení
        $rank = fn ($f) => ($f['role'] === 'download' ? 0 : 10) + (array_search(file_ext($f['stored_name']), ['svg', 'png', 'webp', 'jpg', 'jpeg', 'gif'], true) === false ? 6 : (int) array_search(file_ext($f['stored_name']), ['svg', 'png', 'webp', 'jpg', 'jpeg', 'gif'], true));
        usort($cands, fn ($x, $y) => [$rank($x), (int) $x['id']] <=> [$rank($y), (int) $y['id']]);
        $file = $cands[0];
    }
    $path = stored_file_path($file);
    if (!is_file($path)) {
        throw new McpError('Soubor ' . $file['original_name'] . ' na serveru chybí.');
    }
    $ext = file_ext($file['stored_name']);
    $meta = [
        'klient' => $brand['name'],
        'pozice' => $item['title'],
        'pozice_id' => (int) $item['id'],
        'sekce' => $item['section'],
    ] + mcp_file_info($brand, $item, $file) + [
        'aktualni_verze' => (int) $item['current_version_id'] === (int) $file['version_id'],
        'odkaz' => mcp_download_url($file),
        'odkaz_plati_do' => date('j. n. Y H:i', time() + MCP_LINK_SECONDS),
    ];
    $perma = mcp_permalink($brand, $item, $file);
    if ($perma) {
        $meta['trvaly_odkaz'] = $perma;
        $meta['trvaly_odkaz_info'] = 'Veřejný odkaz vede vždy na aktuální verzi – hodí se pro web, e-mailový podpis apod.';
    }
    $others = array_values(array_filter($item['all_files'], fn ($f) => (int) $f['id'] !== (int) $file['id']));
    if ($others) {
        $meta['dalsi_soubory_pozice'] = array_map(fn ($f) => file_ext($f['stored_name']) . ' – ' . $f['label'] . ' (soubor_id ' . $f['id'] . ')', $others);
    }

    $blocks = [];
    $extra = [];
    if ($ext === 'svg') {
        $svg = (string) file_get_contents($path);
        if ($size = mcp_svg_size($svg)) {
            $meta['rozmer'] = $size;
        }
        if (preg_match('/<text\b/i', $svg)) {
            $meta['upozorneni'] = 'SVG obsahuje text v písmu (nepřevedený na křivky) – bez nainstalovaného písma se zobrazí jinak.';
        }
        if (strlen($svg) <= MCP_SVG_INLINE_MAX) {
            $meta['obsah'] = 'SVG je níže jako text.';
            $extra[] = ['type' => 'text', 'text' => $svg];
        } else {
            $meta['obsah'] = 'SVG je větší než ' . (MCP_SVG_INLINE_MAX / 1024) . ' kB, stáhněte ho přes odkaz.';
        }
        // náhled z rastru se stejným názvem (logo.svg → logo.png/webp)
        foreach ($item['all_files'] as $f) {
            if (in_array(file_ext($f['stored_name']), ['png', 'webp', 'jpg', 'jpeg'], true) && file_stem($f) === file_stem($file)) {
                $p = mcp_image_preview(stored_file_path($f), mcp_int($a, 'nahled_px') ?? MCP_PREVIEW_PX);
                if ($p) {
                    $meta['nahled'] = 'Obrázek níže je náhled ze souboru ' . $f['original_name'] . '.';
                    $extra[] = ['type' => 'image', 'data' => $p['data'], 'mimeType' => $p['mime']];
                }
                break;
            }
        }
    } elseif (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
        $p = mcp_image_preview($path, max(64, min(MCP_PREVIEW_MAX_PX, mcp_int($a, 'nahled_px') ?? MCP_PREVIEW_PX)));
        if ($p) {
            $meta['rozmer'] = $p['w'] . ' × ' . $p['h'] . ' px';
            $meta['nahled'] = $p['nw'] === $p['w'] ? 'Obrázek níže je originál.' : 'Obrázek níže je zmenšený náhled ' . $p['nw'] . ' × ' . $p['nh'] . ' px; originál je na odkazu.';
            $extra[] = ['type' => 'image', 'data' => $p['data'], 'mimeType' => $p['mime']];
        } else {
            $meta['nahled'] = 'Náhled se nepodařilo vytvořit (moc velký obrázek), použijte odkaz.';
        }
    } else {
        $meta['obsah'] = 'Tento formát se přes konektor posílá jen jako odkaz ke stažení.';
    }
    if (mcp_bool($a, 'base64', false)) {
        if ((int) $file['size'] <= MCP_BASE64_MAX) {
            $extra[] = ['type' => 'text', 'text' => 'data:' . mime_for($file['stored_name'], (string) $file['mime']) . ';base64,' . base64_encode((string) file_get_contents($path))];
            $meta['base64'] = 'Originál je níže jako data URI (base64).';
        } else {
            $meta['base64'] = 'Soubor má ' . human_size((int) $file['size']) . ', base64 posílám jen do ' . (MCP_BASE64_MAX / 1024) . ' kB – použijte odkaz.';
        }
    }
    $blocks[] = ['type' => 'text', 'text' => mcp_encode($meta)];
    return ['__content' => array_merge($blocks, $extra)];
}

function mcp_t_brand_css(array $a): array
{
    $brand = mcp_brand($a);
    $colors = brand_colors((int) $brand['id']);
    $typo = brand_items((int) $brand['id'], 'typografie');
    $imports = [];
    $vars = [];
    $fonts = [];
    foreach ($typo as $it) {
        $faces = font_faces($it);
        $family = trim((string) ($it['meta']['family'] ?? ''));
        $google = (string) ($it['meta']['google_url'] ?? '');
        if (!$faces && $family === '' && $google === '') {
            continue; // prázdná pozice písma
        }
        $family = $family ?: $it['title'];
        if (str_starts_with($google, 'https://fonts.googleapis.com/')) {
            $imports[] = '@import url("' . $google . '");';
        }
        $role = preg_replace('/^pismo-/', '', $it['slug']);
        $vars[] = '  --font-' . $role . ': "' . $family . '", system-ui, sans-serif;';
        $fonts[] = [
            'pozice' => $it['title'], 'rodina' => $family, 'rezy' => (string) ($it['meta']['weights'] ?? ''),
            'google_fonts' => $google ?: null,
            'soubory' => array_map(fn ($face) => ['soubor_id' => (int) $face['file']['id'], 'soubor' => $face['file']['original_name'], 'tloustka' => $face['weight'], 'styl' => $face['style']], $faces),
        ];
    }
    foreach ($colors as $c) {
        $slug = slugify($c['name']);
        $vars[] = '  --' . $slug . ': ' . strtolower($c['hex']) . ';' . ($c['role'] ? ' /* ' . str_replace('*/', '', $c['role']) . ' */' : '');
        if (!empty($c['tints'])) {
            foreach (color_tints($c['hex']) as [$p, $hex]) {
                if ($p < 100) {
                    $vars[] = '  --' . $slug . '-' . $p . ': ' . strtolower($hex) . ';';
                }
            }
        }
    }
    $css = '/* ' . $brand['name'] . ' – barvy a písma z Brand Hubu (' . date('j. n. Y') . ') */' . "\n"
        . ($imports ? implode("\n", array_unique($imports)) . "\n" : '')
        . ":root {\n" . implode("\n", $vars) . "\n}\n";
    $meta = ['klient' => $brand['name'], 'barev' => count($colors), 'pisma' => $fonts,
        'poznamka' => 'Soubory písem (WOFF2/TTF) vrátí soubor_stahnout podle soubor_id jako odkaz ke stažení. CSS je níže.'];
    return ['__content' => [['type' => 'text', 'text' => mcp_encode($meta)], ['type' => 'text', 'text' => $css]]];
}

/* ================= Nástroje – zápis ================= */

/** IP adresy hostitele; mimo místní test jen veřejné (ochrana před přístupem do vnitřní sítě serveru). */
function mcp_public_ips(string $host): array
{
    $host = trim($host, '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) {
        throw new McpError('Adresu ' . $host . ' se nepodařilo najít (DNS).');
    }
    if (!config('mcp_allow_private_urls')) {
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new McpError('Adresa ' . $host . ' nevede na veřejný server, z takové soubory nestahuji.');
            }
        }
    }
    return $ips;
}

/** Stáhne soubor z veřejné adresy do $target (přesměrování ověřuje po krocích). Vrací ['name', 'mime']. */
function mcp_fetch_url(string $url, string $target, int $max): array
{
    if (!function_exists('curl_init')) {
        throw new McpError('Na serveru chybí rozšíření curl.');
    }
    for ($i = 0; $i < 6; $i++) {
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || empty($p['host'])) {
            throw new McpError('Stahuji jen z adres http(s)://… (' . $url . ').');
        }
        $host = (string) $p['host'];
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = mcp_public_ips($host);
        $fh = fopen($target, 'wb');
        if (!$fh) {
            throw new McpError('Nelze zapisovat do dočasné složky.');
        }
        $headers = [];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 240,
            CURLOPT_USERAGENT => 'MarMal Brand Hub/' . APP_VERSION,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => fn ($c, $dlTotal, $dl) => $dl > $max ? 1 : 0,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ];
        if (!filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $ips[0]]; // spojení jen na ověřenou IP
        }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        $size = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        fclose($fh);
        if ($ok === false) {
            throw new McpError($size > $max ? 'Soubor je větší než ' . human_size($max) . '.' : 'Stažení z ' . $host . ' selhalo: ' . $err);
        }
        if ($code >= 300 && $code < 400 && !empty($headers['location'])) {
            $loc = $headers['location'];
            if (!preg_match('#^https?://#i', $loc)) {
                $loc = $scheme . '://' . $host . ($port !== ($scheme === 'https' ? 443 : 80) ? ':' . $port : '')
                    . (str_starts_with($loc, '/') ? $loc : rtrim(dirname((string) ($p['path'] ?? '/')), '/') . '/' . $loc);
            }
            $url = $loc;
            continue;
        }
        if ($code !== 200) {
            throw new McpError('Adresa ' . $url . ' vrátila HTTP ' . $code . '.');
        }
        $name = '';
        if (!empty($headers['content-disposition']) && preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)/i', $headers['content-disposition'], $m)) {
            $name = rawurldecode($m[1]);
        }
        if ($name === '') {
            $name = rawurldecode(basename((string) ($p['path'] ?? '')));
        }
        return ['name' => $name, 'mime' => strtolower(trim(explode(';', $headers['content-type'] ?? '')[0]))];
    }
    throw new McpError('Příliš mnoho přesměrování (' . $url . ').');
}

/** Připraví jeden soubor z požadavku do dočasného kusu (chunk_path) a vrátí položku pro commit_uploads. */
function mcp_prepare_file(array $f, int $n): array
{
    $name = trim((string) ($f['nazev'] ?? ''));
    $label = '#' . $n . ($name !== '' ? ' (' . $name . ')' : '');
    $sources = array_values(array_filter(['svg', 'base64', 'url', 'nahravani'], fn ($k) => isset($f[$k]) && is_string($f[$k]) && trim($f[$k]) !== ''));
    if (count($sources) !== 1) {
        throw new McpError('Soubor ' . $label . ': zadejte právě jeden zdroj – svg, base64, url, nebo nahravani.');
    }
    $source = $sources[0];
    $uploadId = bin2hex(random_bytes(16));
    $path = chunk_path($uploadId);
    ensure_dir(dirname($path));
    try {
        switch ($source) {
            case 'svg':
                $svg = (string) $f['svg'];
                if (strlen($svg) > MCP_INLINE_MAX_BYTES || !preg_match('/<svg\b/i', substr($svg, 0, 8192)) || !preg_match('/<\/svg\s*>\s*$/i', $svg)) {
                    throw new McpError('Soubor ' . $label . ': svg musí obsahovat celý dokument <svg>…</svg>.');
                }
                $name = $name ?: 'obrazek.svg';
                file_put_contents($path, $svg);
                break;
            case 'base64':
                $raw = preg_replace('/^data:[^,]*,/', '', trim((string) $f['base64'])) ?? '';
                $raw = preg_replace('/\s+/', '', $raw) ?? '';
                if (strlen($raw) > MCP_INLINE_MAX_BYTES * 4 / 3 + 16) {
                    throw new McpError('Soubor ' . $label . ' je na base64 moc velký – použijte nahravani_pripravit nebo url.');
                }
                $bytes = base64_decode($raw, true);
                if ($bytes === false || $bytes === '') {
                    throw new McpError('Soubor ' . $label . ': base64 není platné.');
                }
                file_put_contents($path, $bytes);
                break;
            case 'url':
                $got = mcp_fetch_url(trim((string) $f['url']), $path, MCP_FETCH_MAX_BYTES);
                if ($name === '') {
                    $name = $got['name'];
                }
                break;
            case 'nahravani':
                $u = db_one('SELECT * FROM mcp_uploads WHERE token_hash = ?', [mcp_hash(trim((string) $f['nahravani']))]);
                if (!$u || !$u['uploaded_at']) {
                    throw new McpError('Soubor ' . $label . ': na odkaz z nahravani_pripravit ještě nic nedorazilo (nebo token neplatí).');
                }
                if ($u['used_at']) {
                    throw new McpError('Soubor ' . $label . ': tento nahraný soubor už je použitý.');
                }
                if (strtotime((string) $u['created_at']) < time() - 6 * 3600) {
                    throw new McpError('Soubor ' . $label . ': nahraný soubor je starší než 6 hodin, připravte nahrání znovu.');
                }
                if (!rename(mcp_upload_path((int) $u['id']), $path)) {
                    throw new McpError('Soubor ' . $label . ': nahraný soubor se nepodařilo převzít.');
                }
                db_exec('UPDATE mcp_uploads SET used_at = NOW() WHERE id = ?', [$u['id']]);
                if ($name === '') {
                    $name = (string) $u['name'];
                }
                break;
        }
        clearstatcache(true, $path);
        if (!is_file($path) || filesize($path) === 0) {
            throw new McpError('Soubor ' . $label . ' je prázdný.');
        }
        $name = basename(str_replace('\\', '/', $name));
        $ext = file_ext($name);
        if ($ext === '' || !in_array($ext, ALLOWED_EXT, true)) {
            throw new McpError('Soubor ' . $label . ': zadejte název s povolenou příponou (' . implode(', ', ALLOWED_EXT) . ').');
        }
        if ($ext === 'svg' && !preg_match('/<svg\b/i', (string) file_get_contents($path, false, null, 0, 65536))) {
            throw new McpError('Soubor ' . $label . ' nevypadá jako SVG.');
        }
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true) && !@getimagesize($path)) {
            throw new McpError('Soubor ' . $label . ' není platný obrázek ' . strtoupper($ext) . '.');
        }
    } catch (Throwable $e) {
        @unlink($path);
        throw $e;
    }
    return [
        'upload_id' => $uploadId,
        'name' => $name,
        'label' => mb_substr(trim((string) ($f['popisek'] ?? '')), 0, 40),
        'role' => !empty($f['jen_nahled']) ? 'preview' : 'download',
        'is_preview' => !empty($f['nahled']),
    ];
}

function mcp_t_soubor_pridat(array $a): array
{
    [$brand, $item] = mcp_item($a);
    $files = $a['soubory'] ?? null;
    if (!is_array($files) || !$files || !array_is_list($files)) {
        throw new McpError('Zadejte soubory: [{nazev, svg | base64 | url | nahravani}].');
    }
    if (count($files) > 20) {
        throw new McpError('Najednou nejvýš 20 souborů.');
    }
    $mode = mcp_str($a, 'rezim') === 'k_aktualni' && $item['current_version_id'] ? 'append' : 'new';
    $uploads = [];
    @set_time_limit(300);
    try {
        foreach ($files as $n => $f) {
            if (!is_array($f)) {
                throw new McpError('Soubor #' . ($n + 1) . ' musí být objekt {nazev, …}.');
            }
            $uploads[] = mcp_prepare_file($f, $n + 1);
        }
        $versionId = commit_uploads($item, $uploads, $mode, mcp_str($a, 'poznamka', 255) ?? 'Nahráno přes AI konektor');
    } catch (Throwable $e) {
        foreach ($uploads as $u) {
            @unlink(chunk_path($u['upload_id']));
        }
        throw $e;
    }
    $version = (int) db_val('SELECT version FROM item_versions WHERE id = ?', [$versionId]);
    log_activity((int) $brand['id'], 'admin', 'upload', $item['title'] . ' – ' . ($mode === 'new' ? 'verze ' . $version : 'doplněna verze ' . $version) . ', ' . count($uploads) . ' ' . cz_plural(count($uploads), 'soubor', 'soubory', 'souborů') . ' (' . mcp_actor_label() . ')');

    $webp = ['added' => 0, 'skipped' => [], 'svg' => []];
    if (mcp_bool($a, 'webp', true) && in_array($item['section'], WEBP_SECTIONS, true) && webp_supported()) {
        $webp = item_add_webp(item_by_id((int) $item['id']));
    }
    $fresh = item_by_id((int) $item['id']);
    $out = [
        'ok' => true,
        'klient' => $brand['name'],
        'pozice' => $fresh['title'],
        'pozice_id' => (int) $fresh['id'],
        'verze' => $version,
        'rezim' => $mode === 'new' ? 'nová verze' . ($item['current_version_id'] ? ' (předchozí verze ' . (int) $item['version_no'] . ' je v historii)' : '') : 'přidáno k aktuální verzi',
        'soubory_aktualni_verze' => array_map(fn ($f) => mcp_file_info($brand, $fresh, $f, true), $fresh['all_files']),
        'administrace' => mcp_admin_url('/admin/items/' . $fresh['id']),
    ];
    if ($webp['added']) {
        $out['webp'] = 'Doplněno WebP: ' . $webp['added'] . '.';
    }
    if ($webp['skipped']) {
        $out['webp_chyby'] = $webp['skipped'];
    }
    if ($webp['svg']) {
        $out['webp_ze_svg'] = 'K SVG bez PNG vytvoří WebP prohlížeč: v Brand Hubu otevřete Doplnit WebP (' . mcp_admin_url('/admin/webp?brand=' . $brand['id']) . ').';
    }
    return $out;
}

function mcp_t_nahravani_pripravit(array $a): array
{
    $token = 'mbu_' . bin2hex(random_bytes(24));
    $name = mcp_str($a, 'nazev', 255);
    db_exec(
        'INSERT INTO mcp_uploads (token_hash, token_id, name, expires_at) VALUES (?, ?, ?, ?)',
        [mcp_hash($token), (int) (mcp_current_token()['id'] ?? 0) ?: null, $name !== null ? basename($name) : null, date('Y-m-d H:i:s', time() + MCP_UPLOAD_LINK_SECONDS)]
    );
    $url = mcp_base() . '/mcp/nahrat/' . $token;
    $file = $name !== null ? basename($name) : 'soubor.png';
    return [
        'nahravani' => $token,
        'adresa' => $url,
        'plati_do' => date('j. n. Y H:i', time() + MCP_UPLOAD_LINK_SECONDS),
        'max_velikost' => human_size(MCP_UPLOAD_MAX_BYTES),
        'jak_nahrat' => [
            'curl -T "' . $file . '" "' . $url . '"',
            'nebo formulářem: curl -F "file=@' . $file . '" "' . $url . '"',
        ],
        'dalsi_krok' => 'Po nahrání zavolej soubor_pridat se soubory: [{"nazev": "' . $file . '", "nahravani": "' . $token . '"}]. Odkaz jde použít jen jednou.',
    ];
}

function mcp_t_pozice_zalozit(array $a): array
{
    $brand = mcp_brand($a);
    $section = mcp_section($a, 'sekce', false, true);
    $title = mcp_str($a, 'nazev', 160) ?? throw new McpError('Zadejte název pozice.');
    $slug = unique_item_slug((int) $brand['id'], mcp_str($a, 'adresa', 120) ?? $title);
    $bg = mcp_str($a, 'pozadi_nahledu');
    if ($bg !== null && !normalize_hex($bg)) {
        throw new McpError('pozadi_nahledu musí být HEX barva (#RRGGBB).');
    }
    $sort = (int) db_val('SELECT COALESCE(MAX(sort), 0) + 10 FROM items WHERE brand_id = ? AND section = ?', [$brand['id'], $section]);
    $id = db_insert(
        'INSERT INTO items (brand_id, section, kind, title, slug, description, specs, tags, required, sort, preview_bg, meta, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
        [$brand['id'], $section, $section === 'typografie' ? 'font' : 'file', $title, $slug, mcp_str($a, 'popis'), mcp_str($a, 'rozmer', 190), mcp_str($a, 'stitky', 255),
            mcp_bool($a, 'povinna', false) ? 1 : 0, $sort, $bg !== null ? normalize_hex($bg) : null, '{}']
    );
    touch_brand((int) $brand['id']);
    log_activity((int) $brand['id'], 'admin', 'item', 'Nová pozice ' . $title . ' v sekci ' . SECTIONS[$section]['title'] . ' (' . mcp_actor_label() . ')');
    return ['ok' => true, 'klient' => $brand['name'], 'pozice' => mcp_item_info($brand, item_by_id($id)),
        'dalsi_krok' => 'Soubory přidejte přes soubor_pridat s pozice_id ' . $id . '.', 'administrace' => mcp_admin_url('/admin/items/' . $id)];
}

function mcp_t_pozice_upravit(array $a): array
{
    [$brand, $item] = mcp_item($a);
    $changes = [];
    $set = [];
    $params = [];
    $field = function (string $arg, string $col, int $max, string $label) use ($a, &$set, &$params, &$changes) {
        if (array_key_exists($arg, $a) && !is_array($a[$arg])) {
            $v = trim((string) $a[$arg]);
            $set[] = $col . ' = ?';
            $params[] = $v === '' ? null : mb_substr($v, 0, $max);
            $changes[] = $label;
        }
    };
    if (($t = mcp_str($a, 'nazev', 160)) !== null) {
        $set[] = 'title = ?';
        $params[] = $t;
        $changes[] = 'název';
    }
    $field('popis', 'description', 65000, 'popis');
    $field('rozmer', 'specs', 190, 'rozměr');
    $field('stitky', 'tags', 255, 'štítky');
    if (($r = mcp_bool($a, 'povinna')) !== null) {
        $set[] = 'required = ?';
        $params[] = $r ? 1 : 0;
        $changes[] = 'povinnost';
    }
    if (array_key_exists('pozadi_nahledu', $a)) {
        $bg = trim((string) $a['pozadi_nahledu']);
        if ($bg !== '' && !normalize_hex($bg)) {
            throw new McpError('pozadi_nahledu musí být HEX barva (#RRGGBB), nebo prázdné.');
        }
        $set[] = 'preview_bg = ?';
        $params[] = $bg === '' ? null : normalize_hex($bg);
        $changes[] = 'pozadí náhledu';
    }
    $slugNote = null;
    if (($s = mcp_str($a, 'adresa', 120)) !== null && slugify($s) !== $item['slug']) {
        $slug = unique_item_slug((int) $item['brand_id'], $s, (int) $item['id']);
        $set[] = 'slug = ?';
        $params[] = $slug;
        $changes[] = 'adresa';
        $slugNote = 'Změnila se adresa trvalých odkazů na „' . $slug . '“ – staré odkazy přestanou fungovat.';
    }
    $moved = null;
    if (($to = mcp_section($a, 'presunout_do')) !== null && $to !== $item['section']) {
        if ($item['kind'] !== 'file' || in_array($to, ['barvy', 'typografie'], true)) {
            throw new McpError('Písma zůstávají v Typografii a do Typografie ani Barev se soubory přesunout nedají.');
        }
        $set[] = 'section = ?';
        $params[] = $to;
        $set[] = 'sort = ?';
        $params[] = (int) db_val('SELECT COALESCE(MAX(sort), 0) + 10 FROM items WHERE brand_id = ? AND section = ?', [$item['brand_id'], $to]);
        $changes[] = 'sekce → ' . SECTIONS[$to]['title'];
        $moved = $to;
    }
    if (isset($a['pismo']) && is_array($a['pismo'])) {
        if ($item['kind'] !== 'font') {
            throw new McpError('Údaje o písmu jdou jen u pozic v Typografii.');
        }
        $meta = $item['meta'];
        foreach (['rodina' => 'family', 'rezy' => 'weights', 'ukazka' => 'sample', 'google_fonts' => 'google_url', 'licence' => 'license'] as $k => $mk) {
            if (array_key_exists($k, $a['pismo'])) {
                $meta[$mk] = $mk === 'google_url' ? safe_url((string) $a['pismo'][$k]) : trim((string) $a['pismo'][$k]);
            }
        }
        $set[] = 'meta = ?';
        $params[] = json_encode($meta, JSON_UNESCAPED_UNICODE);
        $changes[] = 'písmo';
    }
    if (!$set) {
        throw new McpError('Nic ke změně – zadejte aspoň jednu vlastnost (nazev, popis, rozmer, stitky, povinna, pozadi_nahledu, adresa, presunout_do, pismo).');
    }
    $params[] = $item['id'];
    db_exec('UPDATE items SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = ?', $params);
    touch_brand((int) $item['brand_id']);
    log_activity((int) $brand['id'], 'admin', 'item', $item['title'] . ' – upraveno: ' . implode(', ', $changes) . ' (' . mcp_actor_label() . ')');
    $out = ['ok' => true, 'zmeneno' => $changes];
    if ($moved && in_array($moved, WEBP_SECTIONS, true) && webp_supported()) {
        $w = item_add_webp(item_by_id((int) $item['id']));
        if ($w['added']) {
            $out['webp'] = 'Doplněno WebP: ' . $w['added'] . '.';
        }
    }
    if ($slugNote) {
        $out['upozorneni'] = $slugNote;
    }
    $out['pozice'] = mcp_item_info($brand, item_by_id((int) $item['id']));
    return $out;
}

function mcp_t_barvy_upravit(array $a): array
{
    $brand = mcp_brand($a);
    $rows = $a['barvy'] ?? null;
    if (!is_array($rows) || !$rows || !array_is_list($rows)) {
        throw new McpError('Zadejte barvy: [{nazev, hex, …}].');
    }
    $brandId = (int) $brand['id'];
    $report = [];
    db()->beginTransaction();
    try {
        foreach ($rows as $n => $c) {
            if (!is_array($c)) {
                throw new McpError('Barva #' . ($n + 1) . ' musí být objekt.');
            }
            $name = trim((string) ($c['nazev'] ?? ''));
            $hexIn = trim((string) ($c['hex'] ?? ''));
            $hex = $hexIn !== '' ? normalize_hex($hexIn) : null;
            if ($hexIn !== '' && !$hex) {
                throw new McpError('Barva „' . ($name ?: $hexIn) . '“ nemá platný HEX (#RRGGBB).');
            }
            $match = supplement_color_match(brand_colors($brandId), ['name' => $name, 'hex' => $hex ?? '']);
            $val = fn (string $k, int $len, ?string $old) => array_key_exists($k, $c) ? (mb_substr(trim((string) $c[$k]), 0, $len) ?: null) : $old;
            if ($match) {
                db_exec(
                    'UPDATE colors SET name = ?, hex = ?, cmyk = ?, pantone = ?, role = ?, usage_note = ?, tints = ? WHERE id = ?',
                    [
                        // stejný název jen s jinými velkými písmeny barvu nepřejmenuje
                        mb_substr($name !== '' && mb_strtolower($name) !== mb_strtolower((string) $match['name']) ? $name : (string) $match['name'], 0, 80), $hex ?? $match['hex'],
                        $val('cmyk', 40, $match['cmyk']), $val('pantone', 40, $match['pantone']), $val('role', 60, $match['role']),
                        $val('pouziti', 255, $match['usage_note']),
                        array_key_exists('odstiny', $c) ? (!empty($c['odstiny']) ? 1 : 0) : (int) $match['tints'],
                        $match['id'],
                    ]
                );
                $report[] = 'upravena ' . $match['name'];
            } else {
                if (!$hex) {
                    throw new McpError('Barvu „' . $name . '“ jsem v paletě nenašel; pro novou barvu zadejte i hex.');
                }
                $sort = (int) db_val('SELECT COALESCE(MAX(sort), 0) + 10 FROM colors WHERE brand_id = ?', [$brandId]);
                db_exec(
                    'INSERT INTO colors (brand_id, name, hex, cmyk, pantone, role, usage_note, tints, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$brandId, mb_substr($name ?: $hex, 0, 80), $hex, $val('cmyk', 40, null), $val('pantone', 40, null), $val('role', 60, null), $val('pouziti', 255, null), !empty($c['odstiny']) ? 1 : 0, $sort]
                );
                $report[] = 'přidána ' . ($name ?: $hex);
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
    touch_brand($brandId);
    log_activity($brandId, 'admin', 'colors', 'Barvy: ' . implode(', ', $report) . ' (' . mcp_actor_label() . ')');
    return ['ok' => true, 'klient' => $brand['name'], 'zmeny' => $report, 'barvy' => array_map('mcp_color_info', brand_colors($brandId))];
}

function mcp_t_text_sekce_upravit(array $a): array
{
    $brand = mcp_brand($a);
    $section = mcp_section($a, 'sekce', true, true);
    if (!isset($a['text']) || is_array($a['text'])) {
        throw new McpError('Zadejte text (Markdown).');
    }
    $text = (string) $a['text'];
    $brandId = (int) $brand['id'];
    $before = section_texts($brandId)[$section] ?? '';
    if (mcp_str($a, 'rezim') === 'pripojit') {
        append_section_text($brandId, $section, $text);
        $mode = 'připojeno na konec';
    } else {
        save_section_text($brandId, $section, $text);
        $mode = trim($text) === '' ? 'text smazán' : 'nahrazeno';
    }
    touch_brand($brandId);
    log_activity($brandId, 'admin', 'text', 'Text sekce ' . SECTIONS[$section]['title'] . ' – ' . $mode . ' (' . mcp_actor_label() . ')');
    return [
        'ok' => true,
        'klient' => $brand['name'],
        'sekce' => SECTIONS[$section]['title'],
        'zmena' => $mode,
        'text' => section_texts($brandId)[$section] ?? '',
        'predchozi_text' => $before,
        'odkaz_pro_klienta' => brand_public_url($brand, '/s/' . $section),
    ];
}
