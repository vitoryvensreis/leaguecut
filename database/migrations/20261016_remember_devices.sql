CREATE TABLE IF NOT EXISTS account_remember_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 selector CHAR(24) NOT NULL UNIQUE,
 token_hash CHAR(64) NOT NULL,
 mfa_version INT UNSIGNED NOT NULL DEFAULT 0,
 mfa_verified TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_used_at DATETIME NULL,
 expires_at DATETIME NOT NULL,
 INDEX idx_remember_user (user_id,expires_at),
 INDEX idx_remember_expiry (expires_at),
 CONSTRAINT fk_remember_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
