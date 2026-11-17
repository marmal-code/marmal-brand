# Formát importního balíčku

Import klienta: **Klienti → Importovat**. Balíček je ZIP, v jehož kořeni (nebo v jediné složce) leží `brand.json`.
Stejný formát vytváří **Exportovat brand (ZIP)** v nastavení klienta.

```json
{
  "format": "brand-hub",
  "version": 1,
  "brand": {
    "name": "Žehrovické byty",
    "slug": "zehrovicke-byty",
    "intro": "Úvodní text pro klienta",
    "hero_bg": "#F4F4EF",
    "contact_name": "Martin Malý · MarMal.cz",
    "contact_note": "Potřebujete nový podklad? Ozvu se do 2 pracovních dnů.",
    "contact_phone": "+420 724 257 563",
    "contact_email": "martin@marmal.cz",
    "public_links": true,
    "brand_fonts": true,
    "password": "volitelné – jinak se vygeneruje"
  },
  "texts": {
    "logo": "## Ochranná zóna\nText v Markdownu…",
    "pravidla": "…"
  },
  "colors": [
    { "name": "Břidlice", "hex": "#373F48", "cmyk": "79 60 46 58", "pantone": "", "role": "Hlavní", "usage": "Kresba loga, nadpisy", "tints": true }
  ],
  "items": [
    {
      "section": "logo",
      "slug": "logo-primarni",
      "title": "Vodorovné · světlý podklad",
      "description": "Web, dopisy, inzerce…",
      "specs": "3200 × 1600 px",
      "tags": "SVG, PNG",
      "required": true,
      "preview_bg": "#FFFFFF",
      "files": [
        { "path": "loga/logo.svg", "label": "SVG" },
        { "path": "loga/logo.png", "label": "PNG" },
        { "path": "loga/logo.webp", "label": "WebP" }
      ]
    },
    {
      "section": "ikony",
      "slug": "ikona-klic",
      "title": "Ikona · Klíč",
      "files": [
        { "path": "ikony/klic.svg", "label": "SVG" },
        { "path": "ikony/klic.webp", "label": "WebP" }
      ]
    },
    {
      "section": "typografie",
      "slug": "pismo-nadpisy",
      "title": "Písmo pro nadpisy",
      "meta": { "family": "Carlito", "weights": "Bold 700", "sample": "Žehrovické byty", "google_url": "https://fonts.google.com/specimen/Carlito", "license": "SIL OFL 1.1" },
      "files": [ { "path": "pisma/Carlito-Bold.ttf" } ]
    }
  ],
  "prune_empty": true
}
```

- **Sekce:** `logo`, `typografie`, `ikony`, `web`, `site` (Sociální sítě), `tisk`, `kancelar`, `pravidla` (barvy patří do `colors`,
  text barev do `texts.barvy`). Starší sekce `digital` (do verze 1.4) import ještě přijme a rozdělí: pozice podle slugu
  (`ikona-…` → `ikony`, profilovka, titulní fotky, příspěvky a stories → `site`, zbytek → `web`), text podle nadpisů `##`.
- **WebP:** ke každému PNG a JPG v sekcích `logo`, `ikony`, `web` a `site` patří WebP se stejným názvem souboru
  (`logo.png` + `logo.webp`). Aplikace ho použije jako náhled místo PNG. Chybějící WebP k PNG/JPG import dopočítá sám.
- **slug** stejný jako pozice ze šablony (`logo-primarni`, `logo-negativ`, `symbol`, `vizitka`…) tu pozici vyplní, jiný slug založí novou.
- **files[].role** `preview` = soubor jen pro náhled, klient ho nestahuje (např. `{ "path": "nahledy/vizitka.png", "role": "preview" }`).
  **files[].preview** `true` = soubor ke stažení, který se zároveň použije jako náhled (např. PNG u SVG s nepřevedeným písmem).
- **files[].label** s variantou za tečkou (`"SVG · na tmavý podklad"`, `"WebP · na tmavý podklad"`) klient uvidí seskupené:
  varianta jako popisek a pod ní tlačítka jen s formátem.
- **brand.brand_fonts** `true` (výchozí) = klientský pohled a tisková verze v písmech brandu (pozice `pismo-nadpisy` a `pismo-text`),
  `false` = v písmech MarMal. **contact_phone / contact_email** chybí → doplní se výchozí kontakt Martina, `""` = bez kontaktu.
- **Písma:** každý řez samostatný soubor s řezem v názvu (`OpenSans-SemiBold.ttf`). WOFF2 se stejným názvem s `"role": "preview"`
  použije web místo TTF (menší, rychlejší), klient stahuje TTF.
- **prune_empty** odebere prázdné pozice ze šablony, které import nevyplnil (lze je vrátit tlačítkem „Doplnit chybějící pozice ze šablony“).
- **texts** v Markdownu: `## Nadpis`, `### Podnadpis`, `- odrážka`, `1. bod`, `**tučně**`, `*kurzíva*`, `> poznámka`, tabulky `| a | b |`, barevný čtvereček `{#373F48}`, odkaz `[text](https://…)`.
- Import nejdřív zkontroluje celý balíček. Když najde chybu, nezaloží nic a vypíše, co opravit.

## Doplnění existujícího klienta (od 1.6.0)

Detail klienta → **Doplnit z balíčku** (nebo Přístup a nastavení → Další akce). Stejný formát ZIP + `brand.json`, ale:

- `brand` je nepovinné. Doplnění nemění název, heslo, kontakt ani jiné nastavení klienta. `brand.slug` se jen porovná
  s vybraným klientem, při neshodě aplikace upozorní. Název souboru: `{slug}-doplneni-{RRRR-MM-DD}.zip`.
- Aplikace nejdřív ukáže přehled změn a klienta změní až po potvrzení. Nic se nemaže, `prune_empty` se ignoruje.
- **items** se párují podle `slug` (jinak podle názvu):
  - nový slug → **nová pozice** na konci sekce,
  - existující prázdná pozice → **vyplní ji**,
  - existující pozice se soubory → **nová verze** (stará jde do historie), nebo s `"mode": "append"` se soubory
    **přidají k aktuální verzi** (např. doplněný formát, WebP, PDF k existujícímu SVG),
  - pozice bez `files` → **jen úprava** uvedených polí (`title`, `description`, `specs`, `tags`, `required`, `preview_bg`, `meta`),
  - jiná `section` než u existující pozice → pozice se **přesune** (soubory, verze i trvalé odkazy zůstanou).
  U existujících pozic se mění jen pole, která balíček uvádí. `note` = poznámka k verzi (výchozí „Doplnění“).
- **colors**: barva se stejným názvem (bez ohledu na velikost písmen), jinak se stejným HEX → úprava uvedených polí
  (lze tak změnit i HEX). Jiná barva → nová na konci palety.
- **texts** nahradí celý text sekce (prázdný řetězec text smaže). **texts_append** text připojí na konec stávajícího
  (oddělený prázdným řádkem), u sekce bez textu ho založí.
- Každá pozice se smí v balíčku objevit jen jednou. Balíček bez `items`, `colors` i `texts` aplikace odmítne.

```json
{
  "format": "brand-hub",
  "version": 1,
  "brand": { "name": "Žehrovické byty", "slug": "zehrovicke-byty" },
  "texts_append": { "tisk": "## Plachta na plot\nRozměr 3 × 1 m, spadávka 5 cm." },
  "colors": [ { "name": "Pískovec", "hex": "#D8C9A7", "usage": "Podklad karet bytů" } ],
  "items": [
    { "section": "tisk", "slug": "plachta-plot", "title": "Plachta na plot", "specs": "3 × 1 m",
      "files": [ { "path": "tisk/plachta.pdf", "label": "PDF tisk" }, { "path": "tisk/plachta.png", "role": "preview" } ] },
    { "section": "logo", "slug": "logo-primarni", "mode": "append",
      "files": [ { "path": "loga/logo-primarni.pdf", "label": "PDF" } ] },
    { "section": "logo", "slug": "symbol", "note": "Upravená kresba",
      "files": [ { "path": "loga/symbol.svg", "label": "SVG" }, { "path": "loga/symbol.png", "label": "PNG" }, { "path": "loga/symbol.webp", "label": "WebP" } ] },
    { "section": "web", "slug": "og-obrazek", "description": "Obrázek při sdílení odkazu na web." }
  ]
}
```
