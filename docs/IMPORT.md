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
        { "path": "loga/logo.png", "label": "PNG", "preview": true },
        { "path": "nahledy/vizitka.jpg", "role": "preview" }
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

- **Sekce:** `logo`, `typografie`, `tisk`, `digital`, `kancelar`, `pravidla` (barvy patří do `colors`, text barev do `texts.barvy`).
- **slug** stejný jako pozice ze šablony (`logo-primarni`, `logo-negativ`, `symbol`, `vizitka`…) tu pozici vyplní, jiný slug založí novou.
- **files[].role** `preview` = soubor jen pro náhled, klient ho nestahuje. **files[].preview** `true` = soubor ke stažení, který se zároveň použije jako náhled.
- **brand.brand_fonts** `true` (výchozí) = klientský pohled a tisková verze v písmech brandu (pozice `pismo-nadpisy` a `pismo-text`),
  `false` = v písmech MarMal. **contact_phone / contact_email** chybí → doplní se výchozí kontakt Martina, `""` = bez kontaktu.
- **Písma:** každý řez samostatný soubor s řezem v názvu (`OpenSans-SemiBold.ttf`). WOFF2 se stejným názvem s `"role": "preview"`
  použije web místo TTF (menší, rychlejší), klient stahuje TTF.
- **prune_empty** odebere prázdné pozice ze šablony, které import nevyplnil (lze je vrátit tlačítkem „Doplnit chybějící pozice ze šablony“).
- **texts** v Markdownu: `## Nadpis`, `### Podnadpis`, `- odrážka`, `1. bod`, `**tučně**`, `*kurzíva*`, `> poznámka`, tabulky `| a | b |`, barevný čtvereček `{#373F48}`, odkaz `[text](https://…)`.
- Import nejdřív zkontroluje celý balíček. Když najde chybu, nezaloží nic a vypíše, co opravit.
