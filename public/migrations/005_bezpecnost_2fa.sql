-- 1.3.0: dvoufázové ověření administrátora (TOTP + záložní kódy), odhlášení ostatních relací po změně hesla

ALTER TABLE users
    ADD COLUMN totp_secret VARCHAR(255) NULL AFTER password_hash,
    ADD COLUMN totp_enabled_at DATETIME NULL AFTER totp_secret,
    ADD COLUMN totp_last_step BIGINT NULL AFTER totp_enabled_at,
    ADD COLUMN auth_epoch INT UNSIGNED NOT NULL DEFAULT 1 AFTER totp_last_step;

CREATE TABLE IF NOT EXISTS recovery_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY recovery_user (user_id),
    CONSTRAINT recovery_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX attempts_scope_time ON login_attempts (scope, created_at);
