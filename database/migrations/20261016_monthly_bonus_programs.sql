CREATE TABLE IF NOT EXISTS monthly_bonus_programs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 campaign_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(120) NOT NULL,
 metric ENUM('verified_views','approved_clips') NOT NULL DEFAULT 'verified_views',
 amount_cents BIGINT UNSIGNED NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_monthly_bonus_campaign (campaign_id,enabled),
 CONSTRAINT fk_monthly_bonus_campaign FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE RESTRICT,
 CONSTRAINT fk_monthly_bonus_admin FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
