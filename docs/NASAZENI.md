# Nasazení Brand Hubu

## Rozložení na hostingu

```
/                      (kořen FTP)
├── brand.marmal.cz/   ← webová složka, sem patří obsah balíčku (složky public/)
└── brand-data/        ← datová složka mimo web (vytvořte ji ručně, práva 775)
```

Do `brand-data/` si aplikace uloží `config.php`, soubory klientů (`files/`), zálohy (`backups/`),
dočasné soubory (`tmp/`) a logy (`logs/`). Tato složka se při aktualizaci nikdy nemaže.

## První nasazení (jednou, přes FTP)

1. Stáhněte balíček `marmal-brand-1.0.0.zip` (příloha vydání na GitHubu) a rozbalte ho.
2. Celý obsah nahrajte přes FTP do složky `brand.marmal.cz/`. Nahrajte i skryté soubory
   `.htaccess` a `.user.ini`. V některých FTP klientech je potřeba zapnout zobrazení skrytých souborů.
3. Vedle ní vytvořte prázdnou složku `brand-data/` a nastavte jí práva 775.
4. Zkontrolujte, že webová složka `brand.marmal.cz/` je zapisovatelná (775). Bez toho se aplikace nemůže sama aktualizovat.
5. Otevřete https://brand.marmal.cz – spustí se instalační průvodce:
   - zkontroluje server (PHP, rozšíření, zápis do složek),
   - zeptá se na databázi (`marmal_brand`, uživatel `marmal_brand`, heslo, server databáze z administrace hostingu),
   - založí účet administrátora.
6. Po instalaci se přihlaste a v **Nastavení → Aktualizace a zálohy** případně vložte GitHub token (viz níže).

> Instalaci dokončete hned po nahrání. Dokud aplikace není nainstalovaná, průvodce je dostupný komukoli.

## Limity PHP

Soubory `.user.ini` a `php.ini` ve webové složce zvyšují limity (nahrávání 512 MB, paměť 256 MB,
čas 300 s). Hosting je načte do několika minut. Aktuální hodnoty uvidíte v Nastavení →
Aktualizace a zálohy → Kontrola serveru. Nahrávání funguje i bez nich, protože soubory se posílají po částech.

Aktualizace tyto dva soubory nepřepisuje, takže je můžete upravit podle sebe.

## GitHub token (jen pro soukromý repozitář)

Pokud je repozitář `marmal-code/marmal-brand` veřejný, token není potřeba.
U soukromého repozitáře:

1. GitHub → Settings → Developer settings → Personal access tokens → **Fine-grained tokens** → Generate new token
2. Repository access: **Only select repositories** → `marmal-brand`
3. Permissions → Repository permissions → **Contents: Read-only**
4. Expiration: např. 1 rok
5. Token vložte v aplikaci do **Nastavení → Aktualizace a zálohy → Propojení s GitHubem**.
   Uloží se zašifrovaně v databázi.

## Jak probíhá aktualizace

1. **Zkontrolovat aktualizace**: aplikace se zeptá GitHubu na nejnovější Release.
2. **Aktualizovat na X.Y.Z**:
   - stáhne zdrojový ZIP vydání a zkontroluje, že obsahuje složku `public/`,
   - zazálohuje databázi a kód do `brand-data/backups/` (drží se 5 posledních),
   - zapne režim údržby (návštěvníci uvidí „probíhá údržba“),
   - nahradí soubory aplikace a smaže zastaralé soubory ve složkách `app/`, `views/`, `migrations/` a `assets/`,
   - spustí nové migrace databáze,
   - při jakékoli chybě obnoví kód i databázi ze zálohy.
3. Zálohu lze kdykoli obnovit ručně ve stejné sekci.

Nikdy se nepřepisují: `brand-data/` (konfigurace a soubory klientů), `data-path.php`, `.user.ini`, `php.ini`
a vaše vlastní soubory mimo spravované složky (např. ověřovací soubor pro Google).

## Vydání nové verze (vývoj)

1. Změny v `public/`, případné úpravy databáze jako nový soubor `public/migrations/NNN_popis.sql`.
   Migrace se nikdy neupravují zpětně, vždy se přidává nová.
2. Zvýšit číslo v `public/VERSION` a zapsat změny do `CHANGELOG.md`.
3. Commit, push do `main`.
4. GitHub Action **Vydání verze** sám vytvoří Release `vX.Y.Z` s popisem z `CHANGELOG.md`
   a přiloží balíček pro ruční instalaci. Popis se zobrazí v aplikaci před aktualizací.

## Vlastní doména klienta

1. U klienta v DNS: `brand.klient.cz CNAME brand.marmal.cz.`
2. Na hostingu přidat `brand.klient.cz` jako alias webu brand.marmal.cz a zapnout pro ni HTTPS.
3. V aplikaci u klienta vyplnit **Vlastní doména**.
