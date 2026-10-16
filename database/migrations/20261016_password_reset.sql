CREATE TABLE IF NOT EXISTS password_reset_challenges (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 channel ENUM('email','whatsapp') NOT NULL,
 session_hash CHAR(64) NOT NULL,
 code_hash CHAR(64) NOT NULL,
 status ENUM('sending','ready','used','failed','expired') NOT NULL DEFAULT 'sending',
 attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 used_at DATETIME NULL,
 INDEX idx_reset_user_created (user_id,created_at),
 INDEX idx_reset_expires (expires_at),
 CONSTRAINT fk_password_reset_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
