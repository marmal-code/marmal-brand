# Bezpečnost Brand Hubu

Bezpečnostní audit celé aplikace: 26. 10. 2026, verze 1.2.0 → opravy ve verzi 1.3.0.
Při každé větší změně (přihlašování, nahrávání, nové veřejné adresy) tento soubor doplnit.

## Co je v pořádku (ověřeno)

| Oblast | Stav |
|---|---|
| SQL injection | Všechny dotazy přes PDO s parametry, `ATTR_EMULATE_PREPARES = false`. Jediné skládané části jsou konstanty a `(int)`. |
| XSS ve výstupu | Všechny proměnné v šablonách přes `e()`. Markdown nejdřív escapuje a odkazy povoluje jen `https://` a `mailto:`. |
| Nahrané soubory | Leží mimo web (`brand-data/files`), název se čistí (`safe_filename`), povolené jen vyjmenované přípony, žádné PHP. Soubory se posílají s `Content-Security-Policy: … sandbox` a `nosniff`, takže SVG ani HTML od klienta nespustí skript. |
| Import ZIP | Cesty s `..` odmítne, soubory se rozbalují přes stream do náhodného dočasného jména, ne podle cest v archivu. |
| Zálohy | ID zálohy se ověřuje regulárním výrazem, rozbalování ZIPu kontroluje `..`, `/` a `\`. |
| CSRF | Každý POST (formuláře i JSON přes hlavičku `X-CSRF-Token`), porovnání `hash_equals`. |
| Přístup klientů | Každý soubor se hledá podle ID **a** brandu, jen aktuální verze. Změna hesla klienta zneplatní jeho přihlášení. Sdílený odkaz 96 bitů, s platností. |
| Administrace | Každá akce volá `require_admin()`. Přesměrování po přihlášení jen na vlastní adresy. |
| Tajné údaje | GitHub token šifrovaně (sodium secretbox, klíč v `config.php` mimo web). Hesla `password_hash`. |
| Datová složka | Mimo web + pojistka `.htaccess` (`Require all denied`). Instalátor odmítne datovou složku uvnitř webu. |

## Nalezené slabiny a co se s nimi stalo (1.3.0)

| # | Závažnost | Nález | Oprava |
|---|---|---|---|
| 1 | vysoká | Administrátor chráněný jen heslem. | **Dvoufázové ověření** (TOTP + 10 záložních kódů, kód nejde použít dvakrát, 5 minut na zadání, limit 6 špatných kódů / 15 min). Upozornění v administraci, dokud není zapnuté. |
| 2 | střední | Slabá generovaná hesla klientů: 2 slova ze 14 + číslo = jen ~18 tisíc kombinací. | 3 slova ze 64 + číslo 100–999 ≈ 236 milionů kombinací. Minimální délka ručního hesla 8 znaků (dřív 6). Stávající hesla klientů zůstávají, doporučeno je postupně vyměnit. |
| 3 | střední | Omezení pokusů jen podle IP adresy – útok z mnoha adres nebyl omezený. | Navíc celkový limit: 40 pokusů o administraci / 15 min, 60 pokusů o heslo jednoho klienta / hodinu ze všech IP dohromady. Neúspěšná přihlášení do administrace se zapisují do Aktivity. |
| 4 | střední | Po změně hesla administrátora zůstala ostatní zařízení přihlášená, neexistoval časový limit nečinnosti. | `users.auth_epoch`: změna hesla, zapnutí/vypnutí 2FA nebo tlačítko „Odhlásit ostatní zařízení“ odhlásí všechna ostatní zařízení. Po 12 hodinách nečinnosti odhlášení. |
| 5 | střední | Relace v systémovém `/tmp` (na sdíleném hostingu ho může číst i jiný web na stejném serveru). | Relace v `brand-data/sessions/`, `use_strict_mode`, jen cookie. |
| 6 | nízká | Chyběly bezpečnostní hlavičky z PHP (spoléhalo se na `mod_headers`), viditelná verze PHP. | `Content-Security-Policy` (skripty jen z vlastní domény), `X-Frame-Options`, `Permissions-Policy`, HSTS na HTTPS, bez `X-Powered-By`. Inline `onclick`/`onchange` přepsané do `app.js`. |
| 7 | nízká | Odhad existence účtu podle doby odpovědi (u neexistujícího e-mailu se nekontrolovalo heslo). | Porovnání s náhradním hashem, odpověď trvá stejně. |
| 8 | nízká | Odkaz „Google Fonts“ mohl při ruční úpravě nebo importu obsahovat `javascript:`. | `safe_url()` – jen `http(s)://`. |
| 9 | nízká | Sdílený odkaz neobnovil ID relace. | `session_regenerate_id()` po otevření sdíleného odkazu, nový CSRF token po přihlášení. |
| 10 | nízká | Aktualizace: cURL by při přesměrování následoval i jiný protokol než HTTPS. | Jen HTTPS, ověření certifikátu. |

## Vědomá rozhodnutí (beze změny)

- **Veřejné trvalé odkazy** (`/b/{slug}/f/{pozice}.{přípona}`) jsou ve výchozím stavu zapnuté a vydají bez přihlášení
  **všechny formáty** aktuální verze (i PDF, písma, tiskové soubory), ne jen obrázky. Adresy jsou odhadnutelné
  (`logo-primarni.svg`). Pro loga v podpisech je to záměr. Pokud má klient citlivé podklady, vypnout v jeho nastavení,
  nebo do budoucna omezit veřejné odkazy jen na obrázky.
- **Instalátor** je dostupný, dokud neproběhne instalace. Po nahrání přes FTP hned dokončit.
- **Aktualizace** důvěřuje GitHubu (HTTPS). Release nemá vlastní podpis, ochranou je zabezpečení účtu na GitHubu
  (2FA na GitHubu je nutnost).
- **Administrátor vidí text chyb** (výjimky) přímo v aplikaci. Klient vidí jen obecnou hlášku.
- Nahrávání (2 GB na soubor) a import nemají limit počtu souborů – smí je jen administrátor.

## Doporučení mimo kód

1. Zapnout 2FA v Brand Hubu hned po aktualizaci a uložit záložní kódy do správce hesel.
2. Mít 2FA na GitHubu (účet `marmal-code`) a u hostingu (FTP, phpMyAdmin).
3. Ověřit, že `http://brand.marmal.cz` přesměruje na `https://`. Když ne, odkomentovat přesměrování v `.htaccess`.
4. Postupně vyměnit hesla klientů za nová generovaná (Nastavení klienta → Nové heslo).
5. Nouzové vypnutí 2FA a obnova hesla: `docs/NASAZENI.md` → Zabezpečení.
