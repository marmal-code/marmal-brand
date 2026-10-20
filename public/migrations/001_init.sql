-- Brand Hub: základní schéma

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(100) NOT NULL PRIMARY KEY,
    v MEDIUMTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brands (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    intro TEXT NULL,
    contact_name VARCHAR(160) NULL,
    contact_note VARCHAR(255) NULL,
    hero_bg CHAR(7) NOT NULL DEFAULT '#EAF2FA',
    password_hash VARCHAR(255) NULL,
    share_enabled TINYINT(1) NOT NULL DEFAULT 0,
    share_token VARCHAR(64) NULL,
    share_expires_at DATETIME NULL,
    public_links TINYINT(1) NOT NULL DEFAULT 1,
    custom_domain VARCHAR(190) NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS template_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section VARCHAR(30) NOT NULL,
    kind VARCHAR(10) NOT NULL DEFAULT 'file',
    title VARCHAR(160) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    description TEXT NULL,
    specs VARCHAR(190) NULL,
    tags VARCHAR(255) NULL,
    required TINYINT(1) NOT NULL DEFAULT 0,
    sort INT NOT NULL DEFAULT 0,
    preview_bg CHAR(7) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brand_id INT UNSIGNED NOT NULL,
    section VARCHAR(30) NOT NULL,
    kind VARCHAR(10) NOT NULL DEFAULT 'file',
    title VARCHAR(160) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    description TEXT NULL,
    specs VARCHAR(190) NULL,
    tags VARCHAR(255) NULL,
    required TINYINT(1) NOT NULL DEFAULT 0,
    sort INT NOT NULL DEFAULT 0,
    preview_bg CHAR(7) NULL,
    meta TEXT NULL,
    current_version_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY items_brand_slug (brand_id, slug),
    KEY items_brand_section (brand_id, section),
    CONSTRAINT items_brand_fk FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    KEY versions_item (item_id),
    CONSTRAINT versions_item_fk FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS item_files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version_id INT UNSIGNED NOT NULL,
    label VARCHAR(40) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mime VARCHAR(120) NULL,
    role VARCHAR(10) NOT NULL DEFAULT 'download',
    created_at DATETIME NOT NULL,
    KEY files_version (version_id),
    CONSTRAINT files_version_fk FOREIGN KEY (version_id) REFERENCES item_versions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS colors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brand_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    hex CHAR(7) NOT NULL,
    cmyk VARCHAR(40) NULL,
    pantone VARCHAR(40) NULL,
    role VARCHAR(60) NULL,
    sort INT NOT NULL DEFAULT 0,
    KEY colors_brand (brand_id),
    CONSTRAINT colors_brand_fk FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brand_id INT UNSIGNED NULL,
    actor VARCHAR(10) NOT NULL,
    action VARCHAR(40) NOT NULL,
    detail VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    KEY activity_brand (brand_id, created_at),
    KEY activity_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(60) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY attempts_lookup (scope, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
