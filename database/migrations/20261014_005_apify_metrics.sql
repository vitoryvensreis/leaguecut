-- Coleta de métricas observadas por provedor; valores não são créditos financeiros automáticos.
CREATE TABLE IF NOT EXISTS clip_view_samples (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clip_id BIGINT UNSIGNED NOT NULL,
 views BIGINT UNSIGNED NOT NULL,
 source VARCHAR(24) NOT NULL DEFAULT 'apify',
 observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_sample_clip (clip_id,id),
 CONSTRAINT fk_sample_clip FOREIGN KEY (clip_id) REFERENCES clips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS apify_profile_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(100) NOT NULL,
 attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 max_charge_usd DECIMAL(6,3) NOT NULL,
 status ENUM('reserved','completed','failed') NOT NULL DEFAULT 'reserved',
 matched_clips INT UNSIGNED NOT NULL DEFAULT 0,
 result_count INT UNSIGNED NOT NULL DEFAULT 0,
 error_message VARCHAR(190) NULL,
 INDEX idx_profile_attempt (username,attempted_at),
 INDEX idx_attempted (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
