CREATE TABLE IF NOT EXISTS account_admin_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id BIGINT UNSIGNED NOT NULL,
  subject_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(90) NOT NULL,
  details_json JSON NULL,
  ip_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_account_admin_audit_actor (actor_id,created_at),
  INDEX idx_account_admin_audit_subject (subject_user_id,created_at),
  INDEX idx_account_admin_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
