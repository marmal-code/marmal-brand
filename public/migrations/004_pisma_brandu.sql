-- 1.2.0: klientský pohled v písmech brandu (přepínač v nastavení klienta), telefon a e-mail v kartě „Brand spravuje“

ALTER TABLE brands
    ADD COLUMN brand_fonts TINYINT(1) NOT NULL DEFAULT 1 AFTER public_links,
    ADD COLUMN contact_phone VARCHAR(40) NULL AFTER contact_note,
    ADD COLUMN contact_email VARCHAR(190) NULL AFTER contact_phone;

UPDATE brands SET contact_phone = '+420 724 257 563', contact_email = 'martin@marmal.cz';
