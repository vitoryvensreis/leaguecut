CREATE TABLE IF NOT EXISTS account_mfa (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  secret_enc TEXT NOT NULL,
  recovery_json JSON NOT NULL,
  last_used_step BIGINT NOT NULL DEFAULT -1,
  session_version INT UNSIGNED NOT NULL DEFAULT 0,
  enabled_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_mfa_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_mfa_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mfa_attempts_user (user_id,created_at),
  INDEX idx_mfa_attempts_ip (ip_hash,created_at),
  CONSTRAINT fk_mfa_attempt_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_security_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(70) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_account_security_events_user (user_id,created_at),
  INDEX idx_account_security_events_time (created_at),
  CONSTRAINT fk_account_security_event_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
