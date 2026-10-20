-- Výchozí šablona pozic. Nový brand dostane kopii těchto pozic, prázdné pozice klient nevidí.

INSERT IGNORE INTO template_items (section, kind, title, slug, description, specs, tags, required, sort, preview_bg) VALUES
('logo', 'file', 'Primární logo', 'logo-primarni', 'Výchozí volba na světlé pozadí.', NULL, NULL, 1, 10, '#FFFFFF'),
('logo', 'file', 'Negativ', 'logo-negativ', 'Na tmavé a barevné plochy.', NULL, NULL, 1, 20, '#1F2A37'),
('logo', 'file', 'Symbol', 'symbol', 'Samotná značka bez textu: favicon, profilovka, razítko.', NULL, NULL, 1, 30, '#FFFFFF'),
('logo', 'file', 'Jednobarevné logo', 'logo-jednobarevne', 'Pro gravírování, ražbu a levný jednobarevný tisk.', NULL, NULL, 0, 40, '#FFFFFF'),
('logo', 'file', 'Horizontální varianta', 'logo-horizontalni', 'Pro úzké vodorovné plochy, např. hlavičku webu.', NULL, NULL, 0, 50, '#FFFFFF'),

('typografie', 'font', 'Písmo pro nadpisy', 'pismo-nadpisy', 'Používá se pro nadpisy a výrazné texty.', NULL, NULL, 1, 10, NULL),
('typografie', 'font', 'Písmo pro text', 'pismo-text', 'Používá se pro odstavce, popisky a menší texty.', NULL, NULL, 1, 20, NULL),

('tisk', 'file', 'Vizitka', 'vizitka', 'Oboustranná vizitka připravená pro tiskárnu.', '90 × 50 mm', 'CMYK, spadávka 3 mm', 0, 10, NULL),
('tisk', 'file', 'Hlavičkový papír', 'hlavickovy-papir', 'Tisková verze pro tiskárnu a šablona pro psaní dopisů.', 'A4, 210 × 297 mm', 'CMYK, spadávka 3 mm', 0, 20, NULL),
('tisk', 'file', 'Obálka DL', 'obalka-dl', 'Obálka s logem a zpáteční adresou.', '220 × 110 mm', 'CMYK', 0, 30, NULL),
('tisk', 'file', 'Leták A5', 'letak-a5', 'Šablona letáku.', '148 × 210 mm', 'CMYK, spadávka 3 mm', 0, 40, NULL),
('tisk', 'file', 'Plakát A3', 'plakat-a3', 'Šablona plakátu.', '297 × 420 mm', 'CMYK, spadávka 3 mm', 0, 50, NULL),
('tisk', 'file', 'Roll-up', 'roll-up', 'Stojan na akce a veletrhy.', '85 × 200 cm', 'CMYK, měřítko 1:1', 0, 60, NULL),

('digital', 'file', 'Profilový obrázek', 'profilovy-obrazek', 'Profilovka pro sociální sítě.', '1080 × 1080 px', 'PNG', 0, 10, NULL),
('digital', 'file', 'Facebook cover', 'facebook-cover', 'Úvodní fotka stránky na Facebooku.', '851 × 315 px (min.)', 'PNG, JPG', 0, 20, NULL),
('digital', 'file', 'LinkedIn banner', 'linkedin-banner', 'Úvodní obrázek profilu nebo firemní stránky.', '1584 × 396 px', 'PNG, JPG', 0, 30, NULL),
('digital', 'file', 'Šablona příspěvku', 'sablona-prispevku', 'Šablona pro příspěvky na Instagram a Facebook.', '1080 × 1350 px', 'PNG', 0, 40, NULL),
('digital', 'file', 'Náhled pro sdílení webu', 'og-obrazek', 'Obrázek, který se ukáže při sdílení odkazu na web.', '1200 × 630 px', 'PNG, JPG', 0, 50, NULL),
('digital', 'file', 'Favicon', 'favicon', 'Ikona webu v záložce prohlížeče.', '512 × 512 px', 'PNG, ICO', 0, 60, NULL),

('kancelar', 'file', 'E-mailový podpis', 'emailovy-podpis', 'Podpis do e-mailu. Návod k vložení je v souboru.', NULL, 'HTML', 0, 10, NULL),
('kancelar', 'file', 'Šablona dopisu', 'sablona-dopisu', 'Šablona pro psaní dopisů ve Wordu.', 'A4', 'DOCX', 0, 20, NULL),
('kancelar', 'file', 'Šablona nabídky', 'sablona-nabidky', 'Šablona cenové nabídky nebo prezentace.', NULL, 'DOCX, PPTX', 0, 30, NULL),

('pravidla', 'file', 'Pravidla použití značky', 'pravidla-pouziti', 'Ochranná zóna, minimální velikost, nepovolené úpravy a příklady použití.', NULL, 'PDF', 0, 10, NULL);
