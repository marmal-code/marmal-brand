# Brand Hub – pokyny pro práci na repozitáři

- Komunikace s Martinem vždy česky. UI texty česky, v 1. osobě jednotného čísla a s vykáním vůči klientům (tón MarMal.cz).
- Vzhled podle design systému MarMal.cz (tokeny v `public/assets/css/app.css`): Lexend + MarMal Bold, oranžová jen pro hlavní CTA, jedno primární tlačítko na obrazovku.
- Hosting: PHP 8.5, MariaDB 10.6, jen FTP (bez SSH). `exec`, `shell_exec`, `system`, `proc_*` a `popen` jsou zakázané, nepoužívat je.
- Webová složka = obsah `public/`. Data mimo web v `../brand-data/` (`DATA_DIR`).

## Každá změna, která se má dostat na server

1. Kód jen v `public/`. Změna schématu = **nový** soubor `public/migrations/NNN_popis.sql`, příkazy oddělené `;` na konci řádku, uvnitř řetězců nesmí řádek končit středníkem. Stávající migrace se nemění.
2. Zvýšit `public/VERSION` (semver) a doplnit `CHANGELOG.md`.
3. Otestovat lokálně (`php -S` + MariaDB), commit, push do `main`.
4. Vydání vznikne samo: workflow `.github/workflows/release.yml` po pushi změněného `public/VERSION` vytvoří Release `vX.Y.Z` s poznámkami z odpovídající sekce `CHANGELOG.md` (nadpis `## X.Y.Z – datum`) a přiloží balíček pro FTP.
5. Martin pak v aplikaci klikne Nastavení → Aktualizace a zálohy → Aktualizovat.

Aktualizace nepřepisuje `data-path.php`, `.user.ini`, `php.ini` a maže zastaralé soubory jen v `app/`, `views/`, `migrations/`, `assets/`.
