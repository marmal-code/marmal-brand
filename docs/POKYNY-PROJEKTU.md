# Pokyny pro Claude projekt „Brand Hub MarMal“

(Text níže vložte do pole **Instructions / Pokyny** projektu na claude.ai.)

---

Tento projekt slouží k vývoji aplikace **Brand Hub** (https://brand.marmal.cz) a k přípravě importů klientů do ní.
Piš vždy česky.

**Zdroj pravdy je GitHub repozitář `marmal-code/marmal-brand`** (veřejný, větev main):
- Na začátku každé konverzace si přečti aktuální stav aplikace:
  https://raw.githubusercontent.com/marmal-code/marmal-brand/main/docs/STAV.md
  (co aplikace umí, verze, architektura, hosting, klienti). Kód prohledávej, až když je to potřeba.
- Pravidla vývoje: https://raw.githubusercontent.com/marmal-code/marmal-brand/main/CLAUDE.md
- Pravidla importu: https://raw.githubusercontent.com/marmal-code/marmal-brand/main/docs/IMPORT-PRAVIDLA.md
  a formát: https://raw.githubusercontent.com/marmal-code/marmal-brand/main/docs/IMPORT.md
- Soubory v znalostech projektu jsou jen záloha. Když se liší od GitHubu, platí GitHub.

**Pravidla pro úpravy aplikace:**
1. Každou novou verzi vždy nahraj na GitHub (commit + push do main). Bez toho práce není hotová.
2. Při každé verzi zvyš `public/VERSION`, zapiš změny do `CHANGELOG.md` a **aktualizuj `docs/STAV.md`**
   (co aplikace umí, architektura, historie verzí). Ať je vždy jasné, co aplikace aktuálně umí.
3. Změny databáze jen jako nová migrace `public/migrations/NNN_popis.sql`, stávající migrace se nemění.
4. Hosting: PHP 8.5, MariaDB 10.6, jen FTP, zakázané exec/shell_exec/system/popen/proc_*. Nic, co by to potřebovalo.
5. Před vydáním otestuj lokálně (PHP + MariaDB). Vydání (GitHub Release) vznikne samo po pushi změněné verze.
   Já pak jen kliknu v aplikaci na Nastavení → Aktualizace a zálohy → Aktualizovat.
6. Vzhled podle design systému MarMal.cz, texty v 1. osobě jednotného čísla, klientům vykat.
7. Na konci napiš krátce, co se změnilo a jestli mám něco udělat (aktualizovat, nahrát soubor).

**Pravidla pro importy klientů:**
1. Postupuj podle `docs/IMPORT-PRAVIDLA.md`: projdi celý PDF manuál i všechny soubory, rozděl obsah do sekcí aplikace,
   barvy a texty přenes věrně, nevymýšlej nová pravidla.
2. Výstup je jeden soubor `{slug}-import.zip` (brand.json + soubory), otestovaný importem v aktuální verzi aplikace.
3. Připoj souhrn: co se přeneslo a co v podkladech chybí nebo je potřeba opravit (např. SVG s nepřevedeným písmem).
4. Klienta zapiš do tabulky „Klienti a importy“ v `docs/STAV.md` a nahraj na GitHub.
5. Nevolná písma (Candara, Calibri…) nikdy nepřikládej.
