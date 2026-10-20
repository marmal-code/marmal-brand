# Pravidla pro přípravu importu klienta

Cíl: z podkladů klienta (PDF manuál, loga, písma, tiskoviny) vytvořit jeden soubor `{slug}-import.zip`,
který Martin nahraje přes **Klienti → Importovat**. Formát `brand.json` je v `docs/IMPORT.md`,
vzor v `docs/priklady/zehrovicke-byty.brand.json`.

## Co chci od Martina (podklady)

```
nazev-klienta/
  manual.pdf          ← hotový brand manuál, pokud existuje
  loga/               ← SVG + PNG ke každé variantě (stejný název, jiná přípona)
  pisma/              ← TTF/OTF/WOFF2, jen volně šiřitelná
  tisk/               ← tiskové PDF (spadávka 3 mm) + náhled JPG/PNG
  digital/            ← profilovka, covery, šablony příspěvků
  kancelar/           ← e-mailový podpis, šablona dopisu (DOCX)
```
Bez manuálu stačí textový soubor s barvami (název, HEX, CMYK, případně Pantone, jedna věta o použití)
a pár vět o značce. Na nic se zbytečně nedoptávám. Co chybí, napíšu do souhrnu na konci.

## Postup

1. **Projít všechno.** Celé PDF (po 20 stranách) a seznam souborů v ZIPu. Rozměry PNG zjistit z obrázků, ne odhadem.
2. **Zkontrolovat SVG.** Pokud obsahují `<text>` (nepřevedené písmo), u položky použít PNG jako náhled
   (`"preview": true`) a v souhrnu upozornit, že je potřeba převést text na křivky v Affinity.
3. **Mapovat na sekce a pozice šablony.** Hlavní vodorovné logo pro světlý podklad → `logo-primarni`, inverzní →
   `logo-negativ`, symbol → `symbol`, jednobarevné → `logo-jednobarevne` (+ vlastní slugy pro další barevné verze),
   písmo nadpisů → `pismo-nadpisy`, písmo textu → `pismo-text`, PDF manuál → `pravidla-pouziti` (titul „Brand manuál (PDF)“).
   Další varianty dostanou vlastní slug (např. `logo-ctvercove`, `logo-ctvercove-negativ`).
4. **Popisy pozic.** Převzít z manuálu, k čemu varianta slouží (1–2 věty), do `specs` rozměr nebo formát,
   `preview_bg` podle podkladu varianty (tmavá varianta = tmavé pozadí náhledu).
5. **Barvy.** Všechny z manuálu včetně odvozených, CMYK přesně podle manuálu (nepřepočítávat), `role` krátce,
   `usage` jednou větou, `tints: true` u hlavních barev, pokud manuál odstíny používá nebo je to užitečné.
6. **Texty sekcí** (Markdown, `texts`): kapitoly manuálu rozdělit do sekcí aplikace:
   - `logo`: význam značky, části loga, varianty, ochranná zóna, minimální velikost (tabulka), logo na podkladech (tabulka ✓/✗), nesprávné použití
   - `barvy`: logika palety, profil CMYK, poměr barev, kontrasty
   - `typografie`: rodiny písem, náhradní písma, typografická škála (tabulka), zásady / nepoužívat
   - `tisk`: společná pravidla, vizitka, plachta, leták, další tiskoviny
   - `digital`: sociální sítě, web (tlačítka, štítky), ikony, formáty vizualizací
   - `kancelar`: e-mailový podpis, dokumenty
   - `pravidla`: grafické prvky, fotografie a vizualizace, obecná pravidla
   Texty věrně podle manuálu, bez vymýšlení nových pravidel. Barvy v tabulkách lze značit `{#HEX}`.
7. **Písma.** Jen volně šiřitelná (OFL, Apache). Pokud v podkladech chybí a jsou volná, doplnit (např. přes balíčky
   `fonts-*` nebo Google Fonts) a uvést licenci. Nevolná písma (např. Candara, Calibri) **nikdy** nepřikládat, jen zmínit v textu.
8. **Náhledy.** K PDF tiskovinám a manuálu vytvořit náhled první strany (`pdftoppm -png -r 60 -f 1 -l 1`)
   a přidat ho s `"role": "preview"`.
9. **`prune_empty: true`**, aby po importu nezůstaly prázdné pozice ze šablony.
10. **Hlavička brandu.** `hero_bg` světlé (Vápenec, bílá…), pokud je primární logo pro světlý podklad. `intro` 1–2 věty
    o projektu + „Vše je tu vždy v aktuální verzi.“ Kontakt: „Martin Malý · MarMal.cz“, poznámka „Potřebujete nový podklad?
    Ozvu se do 2 pracovních dnů.“ Heslo nevyplňovat, vygeneruje se při importu.
11. **Otestovat** lokálně (PHP + MariaDB, aktuální verze aplikace): import projde bez chyb, prohlédnout klientský
    pohled a tiskovou verzi. Chybový balíček import odmítne celý a vypíše důvody.
12. **Předat** `{slug}-import.zip` + krátký souhrn: co se přeneslo, co chybí nebo je potřeba opravit v podkladech.
13. **Zapsat** klienta do tabulky „Klienti a importy“ v `docs/STAV.md` a commitnout.
