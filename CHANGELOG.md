# Změny

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
