CREATE TABLE IF NOT EXISTS account_otp_challenges (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  purpose ENUM('account_update','withdrawal') NOT NULL,
  channel ENUM('email','whatsapp') NOT NULL,
  session_hash CHAR(64) NOT NULL,
  code_hash CHAR(64) NOT NULL,
  payload_enc TEXT NOT NULL,
  status ENUM('sending','ready','used','failed','expired') NOT NULL DEFAULT 'sending',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  used_at DATETIME NULL,
  INDEX idx_otp_user_created (user_id,created_at),
  INDEX idx_otp_expires (expires_at),
  CONSTRAINT fk_account_otp_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
