# Brand Hub – aktuální stav aplikace

> Živý dokument. **Aktualizuje se při každé nové verzi** (viz CLAUDE.md). Kdo pokračuje ve vývoji, začne tady
> a nemusí procházet celý kód.

**Aktuální verze: 1.1.0** (20. 10. 2026)
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

## Co aplikace umí (1.1.0)

### Administrace (`/admin`)
- Přehled klientů: vyplněnost sekcí, poslední změna, typ přístupu, statistiky (stažení za 30 dní, neúplné brandy), aktivita.
- Nový klient: název, adresa (slug), úvodní text, barva úvodní plochy, kontakt, heslo (návrh se generuje).
- **Import klienta ze ZIPu** (Klienti → Importovat) a **export klienta** do stejného formátu (nastavení klienta).
- Editor brandu, záložky sekcí: Logo, Barvy, Typografie, Tisk, Digitál a sítě, Kancelář, Pravidla použití + Přístup a nastavení.
  - Pozice (položky) ze šablony a vlastní pozice, řazení nahoru/dolů, povinné/volitelné.
  - Nahrávání drag & drop po částech, více souborů najednou. Soubor „ke stažení“ nebo „jen náhled“.
    Uložení jako **nová verze** (stará jde do historie), nebo **přidat k aktuální verzi**.
  - Detail pozice: název, adresa trvalého odkazu, popis, rozměr, štítky, pozadí náhledu, povinnost, u písem
    rodina/řezy/ukázka/Google Fonts/licence. Historie verzí: obnovit, smazat verzi, smazat soubor, „použít jako náhled“.
  - **Text sekce pro klienta** (Markdown) v každé sekci.
  - Barvy: název, HEX (RGB se dopočítá), CMYK, Pantone, role, **použití**, **odstíny** (zobrazí tóny 100–10 %), pořadí.
- Nastavení klienta: heslo (změna odhlásí klienta), sdílený odkaz bez hesla s platností (7/30/90/365 dní/bez omezení,
  nový token), veřejné trvalé odkazy, vlastní doména (CNAME), aktivní/neaktivní, doplnit pozice ze šablony, export, smazání.
- Šablona sekcí: úprava výchozích pozic pro nové klienty.
- Aktivita: log přihlášení, stažení, ZIPů, tiskových verzí, nahrání, importů.
- Nastavení: profil a heslo, aktualizace a zálohy (ruční záloha, obnova), kontrola serveru a limitů PHP.

### Klientský pohled (`/b/{slug}` nebo vlastní doména)
- Přihlášení heslem, nebo sdíleným odkazem `?s=TOKEN`. Administrátor vidí vše jako náhled.
- Přehled: úvodní plocha s logem, karty log (náhled + formáty), barvy (HEX/RGB/CMYK/Pantone, použití, kopírování),
  písma (živá ukázka přes @font-face), dlaždice dalších sekcí.
- Sekce: soubory podle typu (logo karty, barvy + odstíny, písma, řádky dokumentů s náhledem) + text sekce.
- Stažení: jednotlivé soubory, ZIP celé sekce, ZIP celého brandu (vč. barvy.txt a barvy.css).
- **Trvalé odkazy** `/b/{slug}/f/{pozice}.{přípona}` → vždy aktuální verze (lze vypnout veřejnost).
- **PDF:** tlačítko „Manuál PDF“ (první PDF v sekci Pravidla použití) a „Tisková verze“ / „Stáhnout jako PDF“
  (`/b/{slug}/manual`): celý manuál na stránkách A4, prohlížeč ho uloží jako PDF. PDF se negeneruje na serveru
  (limit 128 MB, bez exec).

## Architektura (PHP bez frameworku a Composeru)

```
public/index.php              směrovač (seznam rout), CSRF u všech POST, údržba, vlastní domény
public/app/bootstrap.php      konstanty APP_ROOT, DATA_DIR, APP_VERSION, config(), session
public/app/helpers.php        SECTIONS, ALLOWED_EXT, e(), view/render, csrf, flash, slugify, barvy (hex→rgb/cmyk), ikony
public/app/db.php             PDO helpery db_all/db_one/db_val/db_exec/db_insert
public/app/auth.php           admin login, rate limit, přístup klienta (heslo / sdílený odkaz)
public/app/brands.php         brandy, položky + soubory aktuální verze, náhledy, statistiky, šablona, texty sekcí, odstíny
public/app/files.php          nahrávání po částech, commit_uploads, stream_file (CSP sandbox), ZIP, názvy souborů
public/app/markdown.php       bezpečný Markdown pro texty sekcí
public/app/importer.php       import_brand_package(), send_brand_export()
public/app/migrator.php       migrace z public/migrations/NNN_*.sql
public/app/backup.php         zálohy DB+kódu, obnova, install_code_tree, údržba
public/app/updater.php        GitHub API, run_update → apply_update_package, kontrola serveru
public/app/controllers/       install.php, admin.php, client.php, settings.php
public/views/                 layout_admin, layout_client, layout_plain, admin/*, client/* (home, section, print, _parts)
public/assets/                css/app.css (tokeny MarMal DS), css/print.css, js/app.js (upload, kopírování), fonts/MarMal-Bold.woff2
public/migrations/            001_init, 002_template, 003_texty_a_import
.github/workflows/release.yml automatické vydání po změně public/VERSION
scripts/build-package.sh      balíček pro FTP (dist/marmal-brand-X.Y.Z.zip)
```

### Databáze
`users`, `settings` (github_repo, github_token šifrovaně, update_last_check, update_last_log), `brands`,
`template_items`, `items` (brand, sekce, kind file|font, slug, popisy, meta JSON, current_version_id),
`item_versions`, `item_files` (role download|preview, is_preview), `colors` (usage_note, tints), `section_texts`,
`activity`, `login_attempts`, `migrations`.

### Sekce a pozice šablony (slugy)
- `logo`: logo-primarni*, logo-negativ*, symbol*, logo-jednobarevne, logo-horizontalni
- `barvy`: jen tabulka colors + text
- `typografie` (kind font): pismo-nadpisy*, pismo-text*
- `tisk`: vizitka, hlavickovy-papir, obalka-dl, letak-a5, plakat-a3, roll-up
- `digital`: profilovy-obrazek, facebook-cover, linkedin-banner, sablona-prispevku, og-obrazek, favicon
- `kancelar`: emailovy-podpis, sablona-dopisu, sablona-nabidky
- `pravidla`: pravidla-pouziti (první PDF zde = tlačítko „Manuál PDF“)

(* = povinné)

### Důležité routy
Admin: `/admin`, `/admin/import`, `/admin/brands/{id}/s/{sekce}`, `/admin/brands/{id}/settings`, `/admin/brands/{id}/export`,
`/admin/items/{id}`, `/admin/settings/update`, `/admin/template`, `/admin/activity`.
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

Postup přípravy importů: `docs/IMPORT-PRAVIDLA.md`. Formát balíčku: `docs/IMPORT.md`.
Vzorový manifest: `docs/priklady/zehrovicke-byty.brand.json`.

## Historie verzí
Podrobně v `CHANGELOG.md`.
- **1.1.0** (20. 10. 2026): import/export ZIP, texty sekcí (Markdown), použití barev + odstíny, PDF (nahraný manuál + tisková verze), vlastní náhled souboru.
- **1.0.0** (20. 10. 2026): první verze.

## Nápady na další vývoj (zatím neschválené)
- Automatické náhledy PDF na serveru (jen pokud hosting má Imagick).
- Více administrátorů / spolupracovníků.
- Upozornění klientovi e-mailem, že se změnil soubor.
- Převod SVG textu na křivky nelze udělat v aplikaci, je potřeba v Affinity.
