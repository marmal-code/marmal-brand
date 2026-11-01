-- 1.4.0: počítání zobrazení klientských stránek (souhrny po dnech, bez osobních údajů)

ALTER TABLE brands
    ADD COLUMN views_total INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active,
    ADD COLUMN last_viewed_at DATETIME NULL AFTER views_total;

CREATE TABLE IF NOT EXISTS brand_views (
    brand_id INT UNSIGNED NOT NULL,
    day DATE NOT NULL,
    page VARCHAR(20) NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (brand_id, day, page),
    KEY brand_views_day (day),
    CONSTRAINT brand_views_brand_fk FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brand_visits (
    brand_id INT UNSIGNED NOT NULL,
    day DATE NOT NULL,
    access VARCHAR(10) NOT NULL,
    visits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (brand_id, day, access),
    CONSTRAINT brand_visits_brand_fk FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
