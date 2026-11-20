-- 1.7.0: AI konektor (MCP) pro Claude, ChatGPT a Gemini – přihlášení OAuth 2.1, připojení, log volání a nahrávání souborů
-- V databázi jsou jen SHA-256 hashe tokenů, kódů a tajných klíčů.

CREATE TABLE IF NOT EXISTS mcp_clients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id VARCHAR(64) NOT NULL,
    secret_hash CHAR(64) NULL,
    name VARCHAR(255) NOT NULL,
    redirect_uris TEXT NOT NULL,
    auth_method VARCHAR(30) NOT NULL DEFAULT 'none',
    source VARCHAR(10) NOT NULL DEFAULT 'dcr',
    ip_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    UNIQUE KEY mcp_clients_client (client_id),
    KEY mcp_clients_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_codes (
    code_hash CHAR(64) NOT NULL PRIMARY KEY,
    client_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    redirect_uri TEXT NOT NULL,
    code_challenge VARCHAR(128) NOT NULL,
    scope VARCHAR(100) NOT NULL,
    resource VARCHAR(255) NULL,
    expires_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(10) NOT NULL DEFAULT 'oauth',
    client_id VARCHAR(64) NULL,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NULL,
    scope VARCHAR(100) NOT NULL,
    access_hash CHAR(64) NULL,
    access_expires_at DATETIME NULL,
    refresh_hash CHAR(64) NULL,
    refresh_prev_hash CHAR(64) NULL,
    refresh_prev_until DATETIME NULL,
    refresh_expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    calls INT UNSIGNED NOT NULL DEFAULT 0,
    revoked_at DATETIME NULL,
    UNIQUE KEY mcp_tokens_access (access_hash),
    UNIQUE KEY mcp_tokens_refresh (refresh_hash),
    KEY mcp_tokens_refresh_prev (refresh_prev_hash),
    KEY mcp_tokens_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    token_id INT UNSIGNED NULL,
    app VARCHAR(255) NULL,
    tool VARCHAR(60) NOT NULL,
    args TEXT NULL,
    ok TINYINT(1) NOT NULL DEFAULT 1,
    error VARCHAR(500) NULL,
    ms INT UNSIGNED NOT NULL DEFAULT 0,
    KEY mcp_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_uploads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    token_id INT UNSIGNED NULL,
    name VARCHAR(255) NULL,
    size BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    uploaded_at DATETIME NULL,
    used_at DATETIME NULL,
    UNIQUE KEY mcp_uploads_token (token_hash),
    KEY mcp_uploads_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (k, v) VALUES ('mcp.enabled', '1');
