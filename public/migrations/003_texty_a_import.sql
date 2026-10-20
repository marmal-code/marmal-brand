-- 1.1.0: texty sekcí, použití barev a odstíny, vlastní náhled souboru

CREATE TABLE IF NOT EXISTS section_texts (
    brand_id INT UNSIGNED NOT NULL,
    section VARCHAR(30) NOT NULL,
    body MEDIUMTEXT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (brand_id, section),
    CONSTRAINT section_texts_brand_fk FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE colors
    ADD COLUMN usage_note VARCHAR(255) NULL AFTER role,
    ADD COLUMN tints TINYINT(1) NOT NULL DEFAULT 0 AFTER usage_note;

ALTER TABLE item_files
    ADD COLUMN is_preview TINYINT(1) NOT NULL DEFAULT 0 AFTER role;
