# Brand Hub (brand.marmal.cz)

Online brand manuály pro klienty MarMal.cz. Klient má na jednom místě logo, barvy, písma a podklady
pro tisk a digitál, vždy v aktuální verzi. Administrátor nahrává soubory do pevné šablony sekcí.

- **PHP 8.1+** (provozováno na 8.5), **MariaDB 10.6+ / MySQL 8**, bez Composeru a frameworku
- Nahrávání velkých souborů po částech (funguje i s limitem 16 MB)
- Trvalé odkazy na soubory: `/b/{klient}/f/{pozice}.{přípona}` vrací vždy aktuální verzi
- Historie verzí, ZIP ke stažení, sdílený odkaz bez hesla, vlastní doména klienta (CNAME)
- **Aktualizace z aplikace**: Nastavení → Aktualizace a zálohy. Stáhne nejnovější GitHub Release, zálohuje, vymění kód, spustí migrace a při chybě vše vrátí.

## Struktura

```
public/            ← obsah webové složky (brand.marmal.cz/)
  index.php        ← jediný vstupní bod (směrování)
  app/             ← logika (z webu nepřístupné)
  views/           ← šablony (z webu nepřístupné)
  migrations/      ← SQL migrace NNN_nazev.sql (z webu nepřístupné)
  assets/          ← CSS, JS, font
  VERSION          ← číslo verze, musí odpovídat tagu vydání
docs/NASAZENI.md   ← návod na první nasazení a vydávání verzí
```

Data (konfigurace, soubory klientů, zálohy, logy) leží mimo web ve složce `brand-data/`
na stejné úrovni jako webová složka.

Návod k nasazení: [docs/NASAZENI.md](docs/NASAZENI.md)
