-- Apply only after backing up MySQL. Additive, rerunnable; no historical clip changes.
CREATE TABLE IF NOT EXISTS clip_verification_state (
 clip_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 network VARCHAR(20) NOT NULL,
 external_id VARCHAR(100) NULL,
 canonical_url VARCHAR(800) NULL,
 status ENUM('pending','observed','manual_review','unavailable') NOT NULL DEFAULT 'pending',
 last_observed_views BIGINT UNSIGNED NULL,
 last_observed_at DATETIME NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_verification_status (status,updated_at),
 CONSTRAINT fk_verification_clip FOREIGN KEY (clip_id) REFERENCES clips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clip_verification_observations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 clip_id BIGINT UNSIGNED NOT NULL,
 source VARCHAR(40) NOT NULL,
 status ENUM('observed','manual_review','unavailable') NOT NULL,
 observed_views BIGINT UNSIGNED NULL,
 owner_authorized TINYINT(1) NOT NULL DEFAULT 0,
 evidence_ref VARCHAR(190) NULL,
 observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_observation_clip_date (clip_id, observed_at, id),
 CONSTRAINT fk_hybrid_obs_clip FOREIGN KEY (clip_id) REFERENCES clips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_oauth_connections (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 social_account_id BIGINT UNSIGNED NOT NULL,
 provider VARCHAR(32) NOT NULL,
 provider_user_id VARCHAR(190) NOT NULL,
 access_token_enc TEXT NOT NULL,
 refresh_token_enc TEXT NULL,
 scopes TEXT NOT NULL,
 expires_at DATETIME NULL,
 connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 revoked_at DATETIME NULL,
 UNIQUE KEY uq_social_provider_account (social_account_id,provider),
 KEY idx_oauth_provider_user (provider,provider_user_id),
 CONSTRAINT fk_hybrid_oauth_social FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
