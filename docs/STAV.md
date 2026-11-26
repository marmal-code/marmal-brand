# Brand Hub – aktuální stav aplikace

> Živý dokument. **Aktualizuje se při každé nové verzi** (viz CLAUDE.md). Kdo pokračuje ve vývoji, začne tady
> a nemusí procházet celý kód.

**Aktuální verze: 1.8.0** (24. 11. 2026)
Repozitář: https://github.com/marmal-code/marmal-brand (veřejný, větev `main`)
Produkce: https://brand.marmal.cz
Tento soubor online: https://raw.githubusercontent.com/marmal-code/marmal-brand/main/docs/STAV.md

## K čemu aplikace je

Online brand manuály pro klienty MarMal.cz (Martin Malý). Klient má na jednom místě logo, barvy, písma, podklady pro
tisk a digitál a pravidla použití, vždy jen v aktuální verzi. Martin (administrátor) nahrává do pevné šablony sekcí.
Klient se přihlásí heslem a prohlíží a stahuje. Jedna centrální instalace pro všechny klienty, ne kopie u každého
klienta. Plánovaný byznys model: součást balíčku logo + web, roční poplatek za „živý brand manuál“.

## Hosting a nasazení

- PHP 8.5, MariaDB 10.6.23, **jen FTP (bez SSH)**.
- Zakázané funkce: `exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_*`, `pcntl_*` → nikdy nepoužívat.
- Výchozí limity hostingu: upload/post 16 MB, memory 128 MB, čas 120 s. Ve webové složce jsou `.user.ini` a `php.ini`,
  které limity zvyšují (512 MB / 256 MB / 300 s). Nahrávání je stejně po částech (≤ 8 MB), takže funguje i bez nich.
- Databáze `marmal_brand`, uživatel `marmal_brand`. Heslo zná jen Martin, je v `brand-data/config.php` na serveru.
- Rozložení na FTP:
  ```
  brand.marmal.cz/   ← obsah složky public/ z repozitáře (webová složka)
  brand-data/        ← mimo web: config.php, files/, backups/, tmp/, logs/, maintenance.flag
  ```
- První instalace: nahrát balíček přes FTP → otevřít web → instalační průvodce. Popis v `docs/NASAZENI.md`.
- **Aktualizace:** Nastavení → Aktualizace a zálohy → Zkontrolovat → Aktualizovat. Aplikace stáhne zdrojový ZIP
  posledního GitHub Release, vezme `public/`, předtím zazálohuje DB + kód (drží 5 záloh), zapne údržbu, vymění soubory,
  spustí migrace. Při chybě se sama vrátí ze zálohy. Repo je veřejné, takže token není potřeba. U soukromého repa se vkládá
  fine-grained token (Contents: Read) a ukládá se zašifrovaně (sodium).
- Aktualizace nepřepisuje `data-path.php`, `.user.ini`, `php.ini`. Zastaralé soubory maže jen v `app/`, `views/`, `migrations/`, `assets/`.

## Co aplikace umí (1.8.0)

### Administrace (`/admin`)
- **Vlastní pořadí klientů** (od 1.8.0): v přehledu se řádek přetáhne za úchyt vlevo (myš i dotyk, pointer events v `app.js`), na úchytu fungují i šipky nahoru/dolů, Home a End. Pořadí se uloží samo (`POST /admin/brands/order`, JSON `{ids}`, sloupec `brands.sort`). Při hledání je řazení vypnuté. Nový i importovaný klient se zařadí nahoru (`brand_sort_top()`). Neaktivní klienti zůstávají tam, kam je dáte (jen jsou zašedlí).
- Přehled klientů: vyplněnost sekcí (pruh + počet sekcí), **Viděno** (očíčko + počet zobrazení celkem, den poslední návštěvy, odkaz na statistiky), poslední změna, typ přístupu, statistiky (zobrazení a stažení za 30 dní, neúplné brandy), aktivita. Akce jako ikony s popiskem: Upravit (ozubené kolo), Náhled (lupa), Kopírovat odkaz, Smazat (koš, vede na potvrzení v nastavení klienta).
- Nový klient: název, adresa (slug), úvodní text, barva úvodní plochy, kontakt, heslo (návrh se generuje).
- **Import klienta ze ZIPu** (Klienti → Importovat) a **export klienta** do stejného formátu (nastavení klienta).
- **Doplnit z balíčku** (od 1.6.0, tlačítko v hlavičce klienta a v nastavení, `/admin/brands/{id}/doplnit`): ZIP ve formátu importu
  doplní existujícího klienta – nové pozice, nové verze nebo soubory k aktuální verzi (`"mode": "append"`), úpravy popisů,
  přesun sekce, barvy (párování podle názvu, jinak HEX) a texty (`texts` nahradí, `texts_append` připojí). Nejdřív kontrola
  a přehled změn (`supplement_plan()`), po potvrzení `supplement_brand_package()` v transakci (při chybě vrátí DB i soubory).
  Nic nemaže, nastavení klienta nemění. Nepotvrzený balíček leží v `brand-data/tmp/doplneni-{brand}-{token}.zip`, maže se po dni.
- Editor brandu, záložky sekcí: Logo, Barvy, Typografie, Ikony, Web, Sociální sítě, Tisk, Kancelář, Pravidla použití + Statistiky + Přístup a nastavení.
  (Do verze 1.4 byla místo Ikon, Webu a Sociálních sítí jedna sekce „Digitál a sítě“, viz níže.)
  - **Celkem** (od 1.6.1, mezi sekcemi a Statistikami): počet a velikost souborů aktuálních verzí ke stažení, v popisku vše uložené včetně náhledů a historie (`brand_file_totals()` v `brands.php`).
  - Pozice (položky) ze šablony a vlastní pozice, řazení nahoru/dolů, povinné/volitelné.
  - Nahrávání drag & drop po částech, více souborů najednou. Soubor „ke stažení“ nebo „jen náhled“.
    Uložení jako **nová verze** (stará jde do historie), nebo **přidat k aktuální verzi**.
  - Detail pozice: název, adresa trvalého odkazu, **sekce (přesun do jiné sekce)**, popis, rozměr, štítky, pozadí náhledu, povinnost, u písem
    rodina/řezy/ukázka/Google Fonts/licence. Historie verzí: obnovit, smazat verzi, smazat soubor, „použít jako náhled“.
  - **Text sekce pro klienta** (Markdown) v každé sekci.
  - Barvy: název, HEX (RGB se dopočítá), CMYK, Pantone, role, **použití**, **odstíny** (zobrazí tóny 100–10 %), pořadí.
- **WebP** (`app/webp.php`, sekce `WEBP_SECTIONS` = logo, ikony, web, site):
  - Ke každému PNG/JPG aktuální verze vytvoří server přes GD WebP se stejným názvem (`logo.png` → `logo.webp`) a přidá ho
    k aktuální verzi (nová verze nevzniká). Popisek podle zdroje („PNG 512 px“ → „WebP 512 px“). Logo a Ikony bezeztrátově,
    JPG ztrátově q85, ostatní PNG bezeztrátově, pokud není o víc než 50 % větší než ztrátové.
  - SVG bez PNG/JPG v pozici vykreslí prohlížeč administrátora do PNG (plátno, `app.js`, ikony 512 px, jinak 2048 px delší strana),
    nahraje ho a server ho převede (`POST /admin/items/{id}/webp` s `upload_id` + `svg_file`).
  - `pick_preview()` použije WebP se stejným názvem místo PNG/JPG jako náhled.
  - **Doplnit WebP** (`/admin/webp`, `?brand=ID`): seznam pozic bez WebP a převod s průběhem (úkol po úkolu, chyby se sbírají).
    Upozornění v přehledu klientů, v sekci a odkaz v nastavení klienta.
  - Při nahrávání do těchto sekcí volba „Vytvořit i WebP“ (výchozí zapnuto), import dopočítá chybějící WebP k PNG/JPG.
  - Potřebuje GD s podporou WebP (`webp_supported()`, je v kontrole serveru jako nepovinné).
- **Statistiky klienta** (záložka v detailu klienta, `/admin/brands/{id}/stats`): zobrazení, návštěvy a stažení za 7/30/90 dní,
  zobrazení celkem, naposledy viděno, graf po dnech (CSS sloupce + tabulka), nejčastěji otevírané stránky, přístup heslem vs. sdíleným odkazem,
  poslední aktivita klienta. Počítá `record_view()` v `brands.php` při otevření přehledu, sekce a tiskové verze klientem.
  Nepočítá se administrátor, HEAD, přednačítání (`Sec-Purpose: prefetch`) ani obnovení stejné stránky do 30 s. Návštěva = jedna relace prohlížeče za den.
  Ukládají se jen denní souhrny, žádné IP adresy.
- Nastavení klienta: heslo (změna odhlásí klienta), sdílený odkaz bez hesla s platností (7/30/90/365 dní/bez omezení,
  nový token), veřejné trvalé odkazy, **zobrazovat v písmech brandu** (přepínač), kontakt (jméno, poznámka, **telefon, e-mail**),
  vlastní doména (CNAME), aktivní/neaktivní, doplnit pozice ze šablony, export, smazání (potvrzuje se opsáním adresy; odkaz „Smazat“ je i v přehledu klientů a vede na tuto kartu).
- Šablona sekcí: úprava výchozích pozic pro nové klienty.
- Aktivita: log přihlášení, stažení, ZIPů, tiskových verzí, nahrání, importů.
- Nastavení: profil a heslo, **dvoufázové ověření** (TOTP s QR kódem, 10 záložních kódů, nové kódy, vypnutí s heslem + kódem),
  odhlášení ostatních zařízení, aktualizace a zálohy (ruční záloha, obnova), kontrola serveru a limitů PHP.
- **Přihlášení administrátora:** e-mail + heslo → `/login/2fa` (kód z aplikace nebo záložní kód, 5 minut). Dokud 2FA není
  zapnuté, ukazuje administrace upozornění. Odhlášení po 12 h nečinnosti nebo při změně `users.auth_epoch`
  (změna hesla, zapnutí/vypnutí 2FA, „Odhlásit ostatní zařízení“). Nouzové vypnutí 2FA: `docs/NASAZENI.md`.
- **Bezpečnost:** audit a seznam opatření v `docs/BEZPECNOST.md` (CSP bez inline skriptů – nové skripty jen do `app.js`,
  limity pokusů na IP i celkově, relace v `brand-data/sessions`).

### AI konektor – MCP (od 1.7.0, Nastavení → AI konektor)
- Adresa `https://brand.marmal.cz/mcp`. Claude (Nastavení → Konektory → Přidat vlastní konektor), ChatGPT (režim vývojáře) i Gemini; Claude Code
  `claude mcp add --transport http brand-hub https://brand.marmal.cz/mcp`. Vzor: konektor aplikace MarMal Správa (sprava.marmal.cz).
- Transport Streamable HTTP bez stavu (POST JSON-RPC → application/json, bez SSE a session; GET = 405). Vše jako tools.
- **Přihlášení OAuth 2.1** (`app/mcp_oauth.php`): metadata `/.well-known/oauth-protected-resource` a `/.well-known/oauth-authorization-server`,
  registrace aplikace DCR `POST /oauth/register`, souhlas `/oauth/authorize` (přihlášení administrátora včetně 2FA → po přihlášení zpět přes
  `after_login`, volba čtení / zápis; stránka má CSP `form-action` rozšířené o adresu návratu do aplikace), `POST /oauth/token`
  (authorization_code s povinným PKCE S256, refresh_token s rotací – předchozí platí ještě 10 min), `POST /oauth/revoke`.
  Access token 1 h, refresh 90 dní klouzavě. V DB jen SHA-256 hashe. Ruční tokeny `mbt_…` z nastavení (platí do odpojení).
  Scope: `cteni`, `zapis`. Základní adresa `mcp.base` (settings) se uloží při prvním otevření nastavení (ochrana před podvrženým Host),
  do té doby `https://` + `app_host`. Endpointy běží jen na hlavní adrese aplikace, ne na vlastních doménách klientů.
- **Nástroje** (`app/mcp.php`, `mcp_tools()`: název => [titulek, popis, scope, parametry, povinné, funkce, idempotentní]; funkce `mcp_t_*`):
  - čtení: `klienti_seznam`, `brand_prehled` (barvy, kontakt, sekce s pozicemi a soubory aktuálních verzí, texty sekcí), `soubory_hledat`
    (slova + přípona jako filtr formátu, napříč klienty), `soubor_stahnout`, `brand_css` (CSS proměnné barev vč. odstínů, písma, Google Fonts @import)
  - zápis: `soubor_pridat` (přes `commit_uploads()`, režim nová verze / k aktuální, WebP přes `item_add_webp()`), `nahravani_pripravit`,
    `pozice_zalozit`, `pozice_upravit`, `barvy_upravit` (párování `supplement_color_match()`), `text_sekce_upravit` (vrací i předchozí text)
  - záměrně nikdy: mazání (pozic, verzí, souborů, barev, klientů), hesla klientů, sdílené odkazy, nastavení klienta
  - klient a pozice jdou zadat názvem nebo jeho částí (`mcp_brand()`, `mcp_item()` – víc shod = seznam kandidátů s ID)
- **Přenos souborů:** SVG celé jako text (oběma směry, do 600 kB). Rastr: náhled jako obrázek (`mcp_image_preview()`, GD, delší strana 1024 px,
  WebP), originál přes **dočasný podepsaný odkaz** `/mcp/soubor/{id}/{platnost}/{HMAC}/{název}` (1 h, HMAC klíčem `secret` z config.php,
  bez DB) a trvalý odkaz `/b/{slug}/f/…`, když ho klient má (`mcp_permalink()`). Malé soubory do 150 kB i jako base64 (data URI).
  Nahrání: SVG text, base64 (do 25 MB), veřejná adresa (`mcp_fetch_url()`: jen veřejné IP, spojení připnuté na ověřenou IP, přesměrování po krocích,
  max. 200 MB; pro místní test `mcp_allow_private_urls` v config.php) nebo jednorázový odkaz `PUT|POST /mcp/nahrat/{token}` (`curl -T`, max. 500 MB,
  tabulka `mcp_uploads`, soubor v `brand-data/tmp/mcp/`, použitelný 6 h). Pracovní prostředí Claude na claude.ai se na brand.marmal.cz přímo nedostane (síť jen pro vybrané domény), proto tyto cesty.
- Nastavení `/admin/settings/ai`: adresa + návody, kontrola serveru (curl na vlastní .well-known a /mcp – ověří přepis i předání Authorization;
  při chybě návod `CGIPassAuth On` v Plesku), vypínač `mcp.enabled` a nástrojů `mcp.tool.<název>`, připojené aplikace (odpojit), ruční token, log 40 volání.
- Zápisy přes konektor jdou do Aktivity jako „Vy“ s poznámkou „(AI konektor: Claude)“, každé volání do `mcp_log` (dlouhé argumenty zkrácené). Úklid `mcp_cleanup()`.

### Klientský pohled (`/b/{slug}` nebo vlastní doména)
- Přihlášení heslem, nebo sdíleným odkazem `?s=TOKEN`. Administrátor vidí vše jako náhled.
- **Písma brandu:** nadpisy v písmu z pozice `pismo-nadpisy`, texty, tlačítka a navigace v `pismo-text` (i v tiskové verzi).
  Řezy se poznají z názvu souboru (`brand_typefaces()`, `font_faces()` v `brands.php`), WOFF2 má přednost před TTF.
  Tloušťky rozhraní jsou v CSS proměnných `--fw-light` / `--fw-medium` (v písmu brandu regular / semibold).
  Prohlížeč stahuje jen řezy, které stránka použije. Přednačítají se 2 hlavní řezy. Vypnuto → MarMal (Lexend).
  Bez přihlášení písma nejdou stáhnout, proto přihlašovací stránka zůstává v písmech MarMal.
- Karta „Brand spravuje“ s telefonem a e-mailem (na mobilu dole pod obsahem).
- Přehled: úvodní plocha s logem, karty log (náhled + formáty), barvy (HEX/RGB/CMYK/Pantone, použití, kopírování),
  písma (živá ukázka všech řezů přes @font-face), dlaždice dalších sekcí.
- Sekce: soubory podle typu (logo karty, barvy + odstíny, písma, **ikony jako mřížka dlaždic**, **Web a Sociální sítě jako karty s rozměrem**,
  řádky dokumentů s náhledem) + text sekce. Formáty s variantou v popisku („SVG · na tmavý podklad“) jsou seskupené pod variantu.
  Staré adresy `/s/digital` a `/zip/digital` přesměrují na Web.
- Stažení: jednotlivé soubory, ZIP celé sekce, ZIP celého brandu (vč. barvy.txt a barvy.css).
- **Trvalé odkazy** `/b/{slug}/f/{pozice}.{přípona}` → vždy aktuální verze (lze vypnout veřejnost).
- **Cache:** náhledy a písma (`/file/{id}?inline=1`) mají cache 1 rok (soubor s daným ID se nikdy nemění), ostatní 5 minut,
  exporty a ZIPy žádnou. Server odpovídá 304, když se soubor nezměnil (ETag / Last-Modified).
- **PDF:** tlačítko „Manuál PDF“ (první PDF v sekci Pravidla použití) a „Tisková verze“ / „Stáhnout jako PDF“
  (`/b/{slug}/manual`): celý manuál na stránkách A4, prohlížeč ho uloží jako PDF. PDF se negeneruje na serveru
  (limit 128 MB, bez exec).

## Architektura (PHP bez frameworku a Composeru)

```
public/index.php              směrovač (seznam rout), CSRF u všech POST, údržba, vlastní domény
public/app/bootstrap.php      konstanty APP_ROOT, DATA_DIR, APP_VERSION, config(), session
public/app/helpers.php        SECTIONS, ALLOWED_EXT, WEBP_SECTIONS, legacy_digital_section(), split_legacy_digital_text(), e(), safe_url(), send_security_headers() (CSP…), view/render, csrf, flash, slugify, barvy, ikony
public/app/db.php             PDO helpery db_all/db_one/db_val/db_exec/db_insert
public/app/auth.php           admin login + 2FA, auth_epoch, nečinnost, limity pokusů (IP i celkově), přístup klienta (heslo / sdílený odkaz)
public/app/totp.php           TOTP (RFC 6238), base32, záložní kódy – převzato z jádra app-core
public/app/brands.php         brandy, položky + soubory aktuální verze, náhledy, statistiky, šablona, texty sekcí, odstíny
public/app/files.php          nahrávání po částech, commit_uploads, stream_file (CSP sandbox), ZIP, názvy souborů
public/app/webp.php           WebP: webp_supported, item_webp_plan, webp_encode (GD), item_add_webp, item_add_webp_from_svg, webp_missing
public/app/markdown.php       bezpečný Markdown pro texty sekcí
public/app/importer.php       import_brand_package(), upgrade_legacy_manifest() (sekce digital), supplement_plan(), supplement_brand_package() (doplnění), send_brand_export()
public/app/migrator.php       migrace z public/migrations/NNN_*.sql
public/app/backup.php         zálohy DB+kódu, obnova, install_code_tree, údržba
public/app/updater.php        GitHub API, run_update → apply_update_package, kontrola serveru
public/app/mcp_oauth.php      AI konektor – OAuth 2.1 (metadata, DCR, kódy, tokeny, ruční tokeny, připojení, úklid)
public/app/mcp.php            AI konektor – JSON-RPC, nástroje mcp_t_*, náhledy obrázků, podepsané odkazy, stahování z URL
public/app/controllers/       install.php, admin.php, client.php, settings.php, mcp.php (endpointy konektoru před směrovačem, souhlas, Nastavení → AI konektor)
public/views/                 layout_admin, layout_client, layout_plain, admin/*, client/* (home, section, print, _parts)
public/assets/                css/app.css (tokeny MarMal DS), css/print.css, js/app.js (upload, kopírování, QR, potvrzení, WebP: SVG → PNG na plátně), fonts/MarMal-Bold.woff2,
                              vendor/qrcode.js (QR kód pro 2FA, MIT, generuje se v prohlížeči)
public/migrations/            001_init, 002_template, 003_texty_a_import, 004_pisma_brandu, 005_bezpecnost_2fa, 006_zobrazeni, 007_rozdeleni_digitalu, 008_mcp, 009_poradi_klientu
.github/workflows/release.yml automatické vydání po změně public/VERSION
scripts/build-package.sh      balíček pro FTP (dist/marmal-brand-X.Y.Z.zip)
```

### Databáze
`users`, `settings` (github_repo, github_token šifrovaně, update_last_check, update_last_log), `brands` (… brand_fonts, contact_phone, contact_email, views_total, last_viewed_at, sort = vlastní pořadí v přehledu),
`template_items`, `items` (brand, sekce, kind file|font, slug, popisy, meta JSON, current_version_id),
`item_versions`, `item_files` (role download|preview, is_preview), `colors` (usage_note, tints), `section_texts`,
`activity`, `login_attempts`, `migrations`, `recovery_codes` (záložní kódy 2FA, hash),
`brand_views` (brand_id, day, page = home | sekce | manual, views), `brand_visits` (brand_id, day, access = client | share, visits). `users` má navíc
totp_secret (šifrovaně), totp_enabled_at, totp_last_step, auth_epoch.
AI konektor (008): `mcp_clients` (registrované aplikace), `mcp_codes` (autorizační kódy 10 min), `mcp_tokens` (připojení oauth | manual, hashe
access/refresh), `mcp_log` (volání nástrojů, 90 dní), `mcp_uploads` (jednorázová nahrání). Nastavení `mcp.enabled`, `mcp.base`, `mcp.tool.<název>`.

### Sekce a pozice šablony (slugy)
- `logo`: logo-primarni*, logo-negativ*, symbol*, logo-jednobarevne, logo-horizontalni
- `barvy`: jen tabulka colors + text
- `typografie` (kind font): pismo-nadpisy*, pismo-text*
- `tisk`: vizitka, hlavickovy-papir, obalka-dl, letak-a5, plakat-a3, roll-up
- `ikony`: bez výchozích pozic (vlastní `ikona-{nazev}`, `ikony-vsechny`)
- `web`: og-obrazek, favicon (+ ilustrace, maskot, grafika a fotky pro web)
- `site` (Sociální sítě): profilovy-obrazek, facebook-cover, linkedin-banner, sablona-prispevku (+ sablona-stories)
- `kancelar`: emailovy-podpis, sablona-dopisu, sablona-nabidky
- `pravidla`: pravidla-pouziti (první PDF zde = tlačítko „Manuál PDF“)

(* = povinné)

**Sekce `digital` (do verze 1.4)** už neexistuje. Migrace 007 rozdělila pozice podle slugu (`legacy_digital_section()`),
text sekce rozdělí `section_texts()` při prvním načtení podle nadpisů `##` (`split_legacy_digital_text()`).
Import starších balíčků dělá totéž (`upgrade_legacy_manifest()`). Statistiky zobrazení ze staré sekce mají popisek „Digitál a sítě (do verze 1.4)“.

### Důležité routy
Admin: `/admin`, `/admin/brands/order` (POST, pořadí klientů), `/admin/import`, `/admin/brands/{id}/doplnit` (+ `/{token}` přehled a potvrzení), `/admin/webp`, `/admin/items/{id}/webp` (POST), `/admin/brands/{id}/s/{sekce}`, `/admin/brands/{id}/stats`, `/admin/brands/{id}/settings`, `/admin/brands/{id}/export`,
`/admin/items/{id}`, `/admin/settings` (profil, 2FA), `/admin/settings/2fa`, `/admin/settings/update`, `/admin/template`, `/admin/activity`.
Přihlášení: `/login`, `/login/2fa`.
AI konektor: `/mcp`, `/.well-known/oauth-protected-resource`, `/.well-known/oauth-authorization-server`, `/oauth/register`, `/oauth/token`, `/oauth/revoke`,
`/mcp/soubor/{id}/{platnost}/{podpis}/{název}`, `/mcp/nahrat/{token}` (vše v `mcp_dispatch()` před směrovačem, bez session a CSRF),
`/oauth/authorize` a `/admin/settings/ai` (směrovač).
Klient: `/b/{slug}`, `/b/{slug}/s/{sekce}`, `/b/{slug}/file/{id}`, `/b/{slug}/f/{pozice}.{ext}`, `/b/{slug}/zip[/{sekce}]`, `/b/{slug}/manual`.

## Vzhled
Design systém MarMal.cz (artefakt „MarMal.cz“): Lexend 300/500 + MarMal Bold na nadpisy, `ink #1f2a37`,
`blue-700 #2e6395`, `orange-500 #ef9f27` jen pro hlavní CTA (jedno na obrazovku), plochy `#faf9f6` / bílá / `#eaf2fa`,
pastelové stavové barvy, 8pt mřížka, zaoblení 8/16/24. Texty v 1. osobě jednotného čísla, klientům vykat.
Mockup obrazovek: artefakt „Brand Hub – mockup“ (claude.ai).

## Klienti a importy

| Klient | Stav | Poznámka |
|---|---|---|
| Žehrovické byty (KOSTKA Holding) | importní balíček připraven 20. 10. 2026 (`zehrovicke-byty-import.zip`) | SVG loga mají slovo ŽEHROVICKÉ jako text v Candara Bold (nepřevedeno na křivky) → náhledy z PNG. Chybí jednobarevné SVG vodorovné a PNG čtvercové, vizualizace, tiskové soubory (vizitka, plachta, karta bytu). Písma Carlito + Open Sans doplněna. |
| Blue Team (Blue team, s.r.o.) | importní balíček připraven 21. 10. 2026 (`blue-team-import.zip`), otestovaný v 1.1.0 | Manuál 2.0 (26 stran). Primární = vertikální logo (manuál ho označuje jako základní verzi). 30 variant loga = 20 pozic: 4 sestavy + symbol × (světlý, Modrá, Grafit) + karta jednobarevných (černá/bílá/grafit). SVG na křivkách. Písma Ubuntu + Lexend z podkladů. Chybí: titulní fotka LinkedIn/Facebook a OG obrázek (manuál je uvádí v balíčku), tiskové PDF (vizitka, obálky, desky, razítko, výkaz, referenční list). Šablony Word neodpovídají manuálu (viz souhrn importu). |
| KOSTKA stav (KOSTKA Holding) | importní balíček připraven znovu 4. 11. 2026 (`kostka-stav-import.zip`, nahrazuje verzi z 26. 10.), otestovaný v 1.4.1 | Brand manuál 1.0 (37 stran), nové vydání s vlastními písmy skupiny. 35 pozic, 105 souborů: 3 kompozice loga (vodorovné = primární, vertikální, jednořádkové) × 4 verze (plnobarevné, na tmavý podklad, bílé, černé) + symbol × 3. SVG na křivkách. Písma **KOSTKA Heading** (Bold/Black, odvozeno z Roboto) + **KOSTKA Sans** (Light–Bold, kurzíva Regular a Bold, odvozeno z IBM Plex Sans), OFL, z podkladů, TTF + WOFF2 + ZIP webových písem s CSS; texty sekcí přepsané z Roboto/IBM Plex Sans na nová písma. 12 ikon činností (azurit/bílá/černá) a provozní ikony v Ikonách. Import zakládá nového klienta: starého klienta `kostka-stav` je před importem potřeba smazat, jinak vznikne adresa `kostka-stav-2`. Chybí: tiskové PDF (vizitka, referenční list, bannery, síť na lešení, plaketa, desky, letáky), šablony příspěvků na sítě, titulní fotky FB/LinkedIn a OG obrázek, mockupy praktického použití. Patička hlavičkového papíru (DOCX) obsahuje navíc IBAN a BIC, které manuál neuvádí; šablona Word je v Arialu (manuál uvádí KOSTKA Sans, Arial jako náhradní). |
| Kukátko do přírody (Dana Stemberková) | importní balíček připraven znovu 31. 10. 2026 (`kukatko-do-prirody-import.zip`, nahrazuje odpolední verzi), otestovaný v 1.3.1 | Brand manuál 1.0 (34 stran, čtverec 210 mm). 60 pozic, 124 souborů. Logo: vodorovné (primární) a svislé × (světlý, tmavý podklad, černé, bílé) + symbol × 4 + vodorovné v Hlubině (v podkladech navíc, manuál ho neuvádí). SVG na křivkách, barvy loga odpovídají paletě. Písma Baloo 2 (600/700/800) + Nunito (400/700/800) z podkladů (OFL). Tisk: hlavičkový papír, obálky DL a C4, vizitka, pracovní list (ukázka, šablona, Word, zadní strana), složka A4, diplom A5, jmenovky, samolepka, pozvánka + 2 dlaždice vzoru. Ikony: 8 ikon. Web: OG obrázek, ikona webu, 9 póz žabáka, 6 ilustrací (od 1.5.0, dřív v Pravidlech použití). Sociální sítě: profilovka, titulní fotka FB, příspěvek a stories (ukázka + podklad). Kancelář: e-mailový podpis, hlavičkový papír pro Word. Pravidla: manuál PDF + HTML. Import zakládá nového klienta: odpolední verzi je před importem potřeba smazat, jinak vznikne adresa `kukatko-do-prirody-2`. **Doplnění 17. 11. 2026** (`kukatko-do-prirody-doplneni-2026-11-17.zip`, otestované v 1.6.0): 8 podzimních a zimních ikon (bobule, houby, podzimní list, šiška, žalud, cesmína, zimní větvička, sněhová vločka; SVG + WebP 512 px, vločka s náhledem na Tůňce) a 2 zimní ilustrace (Zasněžená mýtina, U zamrzlého rybníka; PNG + WebP 1254 px); text Ikon doplněný, text Pravidel použití nahrazený (osm scén, řádek Zima). Samostatně `kukatko-do-prirody-doplneni-2026-11-17-zabak-404.zip`: žabák s cedulí 404 pro web (SVG + WebP 2048 px). Chybí: online adresa na str. 2 manuálu. Manuál PDF ještě uvádí, že zimní ilustrace chybí. favicon-32.png má 32 × 28 px. Obrázek v e-mailovém podpisu se načítá z kukatkodoprirody.cz/wp-content/uploads/. |
| CARGO CARE (CARGO CARE s.r.o.) | importní balíček připraven 1. 11. 2026 (`cargo-care-import.zip`), otestovaný v 1.3.1 | Brand manuál 2.0, pracovní verze (51 stran A4 na šířku; do importu jde 50 stran, poslední strana je pracovní přehled úkolů a do PDF nepatří). 46 pozic, 113 souborů. Logo: primární (vodorovné) a sekundární × (světlý podklad, Cargo modrá, černé, inverzní bílé) + piktogram modrý/žlutý/bílý, SVG na křivkách. 6 barev (Pantone 2736 C / Medium Yellow C, CMYK FOGRA39 z manuálu), odstíny Cargo modré. Písma Poppins (nadpisy webu, Light–Bold) + Raleway (text, statické řezy Light–Black vytvořené z variabilního písma Google Fonts), OFL, s WOFF2. Tisk: šablona letáku A4, desky s chlopněmi (varianty A a B), paleta .ase v ZIPu. Ikony: 12 ikon × 3 verze + sada v jednom SVG/PDF (popisky v SVG jako text). Kancelář: obchodní prezentace 2026. Pravidla: manuál PDF, mapa Česko – Benelux, 14 fotografií (u velkých náhled 1600 px). Chybí: profilová fotka, ikona webu, OG obrázek, e-mailový banner, e-mailový podpis, tiskové soubory bannerů, totemu, beach vlajek, letáků, plakátů, triček a banneru 15 let. 4 fotky mají vodoznak Galaxy S23+ nebo ikonu Instagramu, 2 fotky jsou v nízkém rozlišení. Čísla firmy a adresy na deskách klient ještě potvrdí. |
| MarMal.cz (vlastní značka Martina Malého) | importní balíček připraven 26. 11. 2026 (`marmal-import.zip`), otestovaný v 1.8.0 | Brand manuál 1.0 (36 stran A4 na šířku). 67 pozic, 317 souborů. Logo: vodorovné (primární), vertikální, piktogram × (světlý, tmavý podklad) + jednobarevné slate/černé/bílé + slovní značka, SVG na křivkách. 19 barev (8 hlavních s CMYK FOGRA39 z manuálu, 11 doplňkových a stavových bez CMYK). Písma **MarMal** + **MarMal Text** (Thin–Bold, TTF + WOFF2), vlastní písma © 2026 Martin Malý, jen pro značku MarMal.cz (nejsou OFL). Ikony: 24 ikon + 9 číslic v kroužku, každá SVG ve 4 variantách + WebP 512 px, celá sada (sprite, PDF přehled, ZIP). Web: OG, favicon (SVG, PNG 32/512, ICO), ikona aplikace, kruh a oblouk v povolených barvách, kombinace, ZIP tokenů pro Breakdance. Sociální sítě: profilovka. Tisk: vizitka, hlavičkový papír, obálky DL a C4, krycí list nabídky, 2 vzory. Kancelář: e-mailový podpis (HTML + Word), hlavičkový papír pro Word, ukázka nabídky, faktury a reportu ze sprava.marmal.cz (smyšlený klient). Pravidla: manuál PDF + HTML. Chybí: titulní fotky FB/LinkedIn, šablony příspěvků a stories, leták, plakát, roll-up. Piktogram v e-mailovém podpisu se načítá z marmal.cz/wp-content/uploads/2026/11/ (je potřeba nahrát). |

Od 1.5.0 mají všechny importy WebP u Loga, Ikon, Webu a Sociálních sítí. U klientů naimportovaných dřív je doplní **Doplnit WebP**.
Vzorové manifesty v `docs/priklady/` jsou převedené na nové sekce (bez WebP souborů, ty vzniknou v aplikaci).

Postup přípravy importů i doplnění: `docs/IMPORT-PRAVIDLA.md`. Formát balíčku: `docs/IMPORT.md` (doplnění v části „Doplnění existujícího klienta“).
Vzorové manifesty: `docs/priklady/zehrovicke-byty.brand.json`, `docs/priklady/blue-team.brand.json`, `docs/priklady/kostka-stav.brand.json`, `docs/priklady/kukatko-do-prirody.brand.json`, `docs/priklady/cargo-care.brand.json`, `docs/priklady/marmal.brand.json`.

## Historie verzí
Podrobně v `CHANGELOG.md`.
- **1.8.0** (24. 11. 2026): vlastní pořadí klientů v přehledu – přetažení za úchyt, ukládá se samo.
- **1.7.0** (20. 11. 2026): AI konektor (MCP) pro Claude, ChatGPT a Gemini – stahování a přidávání log, grafiky, barev a textů klientů, přihlášení OAuth s 2FA.
- **1.6.1** (17. 11. 2026): položka Celkem (počet a velikost souborů) v detailu klienta.
- **1.6.0** (17. 11. 2026): Doplnit z balíčku – více věcí najednou do existujícího klienta, s přehledem změn před potvrzením.
- **1.5.0** (10. 11. 2026): sekce Digitál a sítě rozdělená na Ikony, Web a Sociální sítě, přesun pozice do jiné sekce, WebP ke všem PNG/JPG v Logu, Ikonách, Webu a Sítích (+ ze SVG v prohlížeči), WebP jako náhled, stránka Doplnit WebP, mřížka ikon u klienta.
- **1.4.1** (1. 11. 2026): akce v přehledu klientů jako ikony s popiskem, sloupec Vyplněno na dva řádky.
- **1.4.0** (1. 11. 2026): počítání zobrazení klientských stránek, sloupec „Viděno“ s očíčkem v přehledu klientů, záložka „Statistiky“ v detailu klienta.
- **1.3.1** (31. 10. 2026): odkaz „Smazat“ v přehledu klientů (vede na potvrzení v nastavení klienta).
- **1.3.0** (26. 10. 2026): 2FA administrátora, odhlášení ostatních zařízení, limity pokusů ze všech IP, silnější hesla klientů, CSP a další bezpečnostní hlavičky, relace ve vlastní složce. Audit v `docs/BEZPECNOST.md`.
- **1.2.0** (26. 10. 2026): klientský pohled a tisková verze v písmech brandu (přepínač), všechny řezy písma, WOFF2, dlouhá cache + 304, telefon a e-mail v kartě kontaktu.
- **1.1.0** (20. 10. 2026): import/export ZIP, texty sekcí (Markdown), použití barev + odstíny, PDF (nahraný manuál + tisková verze), vlastní náhled souboru.
- **1.0.0** (20. 10. 2026): první verze.

## Nápady na další vývoj (zatím neschválené)
- Automatické náhledy PDF na serveru (jen pokud hosting má Imagick).
- Více administrátorů / spolupracovníků.
- AI konektor: ruční aplikace s tajným klíčem (Gemini Enterprise bez DCR), jako má Správa.
- Upozornění klientovi e-mailem, že se změnil soubor.
- Převod SVG textu na křivky nelze udělat v aplikaci, je potřeba v Affinity.
