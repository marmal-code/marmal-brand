# Změny

## 1.7.0 – 20. 11. 2026
- **AI konektor (MCP):** Brand Hub jde připojit ke Claude, ChatGPT i Gemini jako vlastní konektor. Adresa `https://brand.marmal.cz/mcp`, nastavení a návod v **Nastavení → AI konektor**. AI si pak sama bere loga, barvy, písma a další grafiku klientů a umí je sem i přidávat – soubory už není potřeba pořád nahrávat do chatu
- Přihlášení OAuth 2.1 (stejně jako konektor ve Správě): AI aplikace se zaregistruje sama, při připojení se přihlásíte heslem a kódem 2FA a povolíte **čtení**, nebo **čtení a zápis**. PKCE povinné, přístupový token platí hodinu, obnovovací 90 dní od posledního použití. V databázi jsou jen hashe tokenů
- Nástroje pro čtení: `klienti_seznam`, `brand_prehled` (barvy, písma, sekce, pozice a soubory), `soubory_hledat` (např. „kukátko logo bílé svg“), `soubor_stahnout`, `brand_css` (barvy jako CSS proměnné, písma a Google Fonts)
- `soubor_stahnout`: SVG vrátí celé jako text, PNG/JPG/WebP/GIF jako zmenšený náhled obrázku (do 1024 px, WebP), ke všemu dočasný podepsaný odkaz na originál (platí 1 hodinu) a trvalý veřejný odkaz, když ho klient má zapnutý. Malé soubory (do 150 kB) i jako base64
- Nástroje pro zápis: `soubor_pridat` (do pozice jako nová verze – předchozí zůstane v historii – nebo k aktuální verzi; zdroj SVG text, base64, veřejná adresa, nebo jednorázové nahrání), `nahravani_pripravit` (adresa pro `curl -T` pro velké soubory, platí 30 minut), `pozice_zalozit`, `pozice_upravit` (popis, rozměr, štítky, adresa, přesun sekce, údaje písma), `barvy_upravit` (úprava podle názvu nebo HEX, nové barvy na konec), `text_sekce_upravit` (nahradit nebo připojit, vrací i předchozí text)
- K PNG/JPG nahraným přes konektor do Loga, Ikon, Webu a Sociálních sítí se WebP vytvoří samo jako při běžném nahrání
- Přes konektor se nikdy nic nemaže a nevrací hesla ani sdílené odkazy klientů. Změny se zapisují do Aktivity s poznámkou „AI konektor: název aplikace“
- Nastavení → AI konektor: adresa a návody (Claude, ChatGPT, Claude Code), kontrola serveru (metadata, adresa /mcp, předání hlavičky Authorization), vypínač konektoru a jednotlivých nástrojů, připojené aplikace s odpojením, ruční tokeny a posledních 40 volání
- Stahování ze zadané adresy jen z veřejných serverů (ochrana vnitřní sítě hostingu), nejvýš 200 MB, přesměrování se ověřují po krocích
- Migrace `008_mcp.sql` (tabulky `mcp_clients`, `mcp_codes`, `mcp_tokens`, `mcp_log`, `mcp_uploads`). `.htaccess` nově předává hlavičku Authorization do PHP

## 1.6.1 – 17. 11. 2026
- Detail klienta: nová položka **Celkem** před Statistikami. Ukazuje počet a velikost souborů v aktuálních verzích ke stažení (stejné číslo, jaké vidí klient v hlavičce manuálu). Po najetí myší ukáže i vše uložené včetně náhledů a historie verzí
- Souhrn souborů počítá jedna funkce `brand_file_totals()` pro administraci i klientský pohled

## 1.6.0 – 17. 11. 2026
- **Doplnit z balíčku:** u existujícího klienta jde najednou přidat víc věcí jedním ZIPem ve stejném formátu jako import (`brand.json` + soubory). Tlačítko „Doplnit z balíčku“ je v hlavičce klienta a v nastavení klienta (Další akce), adresa `/admin/brands/{id}/doplnit`
- Po nahrání balíček nejdřív zkontroluji a ukážu přehled změn (nová pozice, vyplní prázdnou pozici, nová verze, přidá k aktuální verzi, jen úprava popisu, přesun sekce, nové a upravené barvy, texty sekcí). Klient se změní až po potvrzení, zrušení nic nemění. Nepotvrzené balíčky se mažou po jednom dni
- Nic se nemaže: pozice se stejným slugem dostane novou verzi (stará zůstane v historii), s `"mode": "append"` se soubory přidají k aktuální verzi. Pozice bez souborů jen upraví popis, rozměr, štítky apod. (mění se jen uvedená pole). Nové pozice se řadí na konec sekce
- Barvy: stejný název (jinak stejný HEX) = úprava uvedených polí, jinak nová barva na konec palety. Texty: `texts` nahradí text sekce, nové `texts_append` ho doplní na konec
- Nastavení klienta (název, heslo, kontakt…) doplnění nemění. Když je balíček připravený pro jiného klienta (`brand.slug`), upozorním na to
- Doplnění běží v transakci: při chybě se databáze i soubory vrátí do původního stavu. Chybějící WebP k PNG/JPG se dopočítá jako u importu
- Import: kontrola `texts_append` a `items[].mode`, upozornění na stránce importu, že ke stávajícímu klientovi slouží Doplnit z balíčku

## 1.5.0 – 10. 11. 2026
- **Nové sekce místo „Digitál a sítě“:** Ikony, Web a Sociální sítě. Pořadí sekcí: Logo, Barvy, Typografie, Ikony, Web, Sociální sítě, Tisk, Kancelář, Pravidla použití
- Migrace `007_rozdeleni_digitalu.sql` přesune pozice stávajících klientů: `ikona-…` → Ikony, profilovka, titulní fotky, příspěvky a stories → Sociální sítě, ostatní (ikona webu, náhled pro sdílení…) → Web. Stejně se upraví šablona sekcí
- Text staré sekce se rozdělí podle nadpisů `##` sám při prvním otevření klienta (kapitola o ikonách → Ikony, o sociálních sítích, profilovce, titulní fotce a příspěvcích → Sociální sítě, zbytek → Web)
- Kukátko do přírody: maskot žabák a ilustrace ročních období se přesunou z Pravidel použití do Webu
- Staré adresy `/s/digital` a `/zip/digital` přesměrují na Web. Import přijme i starší balíčky se sekcí `digital` a rozdělí je stejně
- **Přesun pozice do jiné sekce:** v detailu pozice nové pole „Sekce“. Soubory, verze i trvalé odkazy zůstanou
- Klientský pohled: ikony jako mřížka malých dlaždic, Web a Sociální sítě jako karty s rozměrem. Formáty s variantou v popisku („SVG · na tmavý podklad“) se seskupí pod variantu, tlačítka ukazují jen formát. Tisková verze má ikony v mřížce po šesti
- **WebP pro web a digitální výstupy:** ke každému PNG a JPG v sekcích Logo, Ikony, Web a Sociální sítě vytvoří server WebP se stejným názvem a přidá ho k aktuální verzi (nová verze nevzniká). Loga a ikony bezeztrátově, JPG ztrátově v kvalitě 85, ostatní PNG bezeztrátově, pokud není o víc než polovinu větší než ztrátové. SVG bez PNG vykreslí do PNG prohlížeč administrátora a server ho převede stejně
- WebP se použije jako náhled místo PNG/JPG se stejným názvem, takže se klientský pohled načítá rychleji. Trvalé odkazy `…/f/{pozice}.webp` fungují samy
- Stránka **Doplnit WebP** (`/admin/webp`, pro jednoho klienta `?brand=ID`) s průběhem převodu. Upozornění „WebP chybí“ v přehledu klientů a v sekci, tlačítko v nastavení klienta
- Při nahrávání do Loga, Ikon, Webu a Sociálních sítí volba „Vytvořit i WebP“ (zapnutá). Import dopočítá chybějící WebP k PNG/JPG
- Kontrola serveru ukazuje „GD s podporou WebP“ (nepovinné, bez něj WebP jen nahrát ručně)
- Pravidla importu: WebP povinně u Loga, Ikon, Webu a Sociálních sítí, soubory podle místa použití, do Pravidel použití jen manuál. Vzorové manifesty převedené na nové sekce

## 1.4.1 – 1. 11. 2026
- Přehled klientů: akce jako ikony s popiskem pod nimi. Upravit (ozubené kolo), Náhled (lupa), Kopírovat (odkaz na manuál) a Smazat (koš, červeně)
- Sloupec „Vyplněno“ na dva řádky: nahoře pruh, pod ním počet sekcí, takže tabulka je užší

## 1.4.0 – 1. 11. 2026
- Počítání zobrazení klientských stránek: přehled, sekce i tisková verze. Nepočítají se náhledy administrátora, přednačítání prohlížeče ani opakované obnovení stejné stránky do 30 sekund. Ukládají se jen souhrnné počty po dnech, žádné IP adresy
- Přehled klientů: nový sloupec „Viděno“ s očíčkem, počtem zobrazení celkem a dnem poslední návštěvy (odkaz vede na statistiky), nová karta „Zobrazení za 30 dní“
- Detail klienta: nová záložka „Statistiky“ s počtem zobrazení u záložky. Zobrazení, návštěvy a stažení za 7 / 30 / 90 dní, zobrazení celkem, naposledy viděno, graf po dnech (i jako tabulka), nejčastěji otevírané stránky, přístup heslem vs. sdíleným odkazem a poslední aktivita klienta
- Migrace `006_zobrazeni.sql`: tabulky `brand_views` a `brand_visits`, sloupce `brands.views_total` a `brands.last_viewed_at`. Při smazání klienta se statistiky smažou s ním

## 1.3.1 – 31. 10. 2026
- Odkaz „Smazat“ v přehledu klientů u každého klienta. Vede na kartu „Smazat klienta“ v nastavení klienta, kde se smazání potvrzuje opsáním adresy, takže klienta nejde smazat jedním nechtěným kliknutím
- Karta „Smazat klienta“ se po příchodu z přehledu zvýrazní a kurzor je rovnou v poli pro opsání adresy
- U smazání upozornění, že klienta lze předtím zazálohovat exportem

## 1.3.0 – 26. 10. 2026
Zabezpečení aplikace (podrobně v `docs/BEZPECNOST.md`).
- Dvoufázové ověření administrátora: kód z aplikace Authenticator (QR kód), 10 záložních kódů, nové kódy na požádání, vypnutí jen s heslem a kódem
- Změna hesla nebo 2FA odhlásí ostatní zařízení, tlačítko „Odhlásit ostatní zařízení“, odhlášení po 12 hodinách nečinnosti
- Omezení pokusů o přihlášení i ze všech IP adres dohromady (administrace i hesla klientů), neúspěšná přihlášení v Aktivitě
- Silnější generovaná hesla klientů (3 slova + číslo), minimální délka hesla klienta 8 znaků
- Bezpečnostní hlavičky (Content-Security-Policy, X-Frame-Options, HSTS na HTTPS), relace ve vlastní složce `brand-data/sessions`
- Drobné opravy: stejná doba odpovědi u neexistujícího účtu, odkazy jen http(s), nové ID relace u sdíleného odkazu, aktualizace jen přes HTTPS

## 1.2.0 – 26. 10. 2026
- Klientský pohled i tisková verze se zobrazují v písmech brandu: nadpisy v „Písmu pro nadpisy“, texty, tlačítka a navigace v „Písmu pro text“
- Přepínač „Zobrazovat manuál v písmech brandu“ v nastavení klienta (i v importu a exportu jako `brand_fonts`)
- Aplikace pozná řez písma z názvu souboru (Light, SemiBold, Bold, Italic, variabilní…) a načte každý řez zvlášť, takže tučné písmo není „dopočítané“
- WOFF2 má přednost před TTF (soubor „jen náhled“ se stejným názvem), prohlížeč stahuje jen použité řezy, dva hlavní řezy přednačítá
- Karta písma ukazuje všechny řezy a velké „Aa“ v hlavním řezu
- Náhledy a písma mají cache na rok, server vrací „nezměněno“ (304), takže se při další návštěvě nic nestahuje znovu
- Karta „Brand spravuje“ má telefon a e-mail (výchozí kontakt Martina, upravitelné u klienta) a na mobilu je vidět pod obsahem

## 1.1.0 – 20. 10. 2026
- Import klienta z jednoho ZIPu (brand.json + soubory) a export klienta do stejného formátu
- Texty sekcí pro klienta (pravidla, tabulky, poznámky) s jednoduchým formátováním
- U barev popis použití a automatické odstíny 80–10 %
- Brand manuál v PDF: tlačítko pro nahraný PDF manuál a tisková verze celého manuálu (uložení jako PDF z prohlížeče)
- Libovolný obrázek ke stažení lze nastavit jako náhled položky

## 1.0.0 – 20. 10. 2026
První verze.
- Instalační průvodce (databáze, administrátor, datová složka mimo web)
- Administrace: klienti, sekce Logo / Barvy / Typografie / Tisk / Digitál a sítě / Kancelář / Pravidla použití
- Nahrávání po částech, historie verzí, obnovení starší verze
- Klientský pohled s náhledy, kopírováním barev, živými ukázkami písem a ZIP balíčky
- Přístup heslem, sdílený odkaz s platností, veřejné trvalé odkazy, vlastní doména klienta
- Šablona sekcí, log aktivity
- Aktualizace z GitHubu přímo z aplikace se zálohou a automatickým návratem při chybě
