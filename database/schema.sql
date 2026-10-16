-- Selecione o banco de dados existente 'leaguecut' no CloudPanel/phpMyAdmin antes de importar.
-- Não utiliza CREATE DATABASE para funcionar com o usuário MySQL isolado do site.

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  whatsapp VARCHAR(24) NOT NULL,
  instagram VARCHAR(80) NOT NULL,
  portfolio_url VARCHAR(500) NULL,
  pix_key_enc TEXT NOT NULL,
  role ENUM('clipador','admin','criador') NOT NULL DEFAULT 'clipador',
  status ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
  approved_at DATETIME NULL,
  approved_by BIGINT UNSIGNED NULL,
  consent_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_status (status, created_at),
  INDEX idx_users_role (role)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS campaigns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(210) NOT NULL UNIQUE,
  description TEXT NOT NULL,
  category VARCHAR(70) NOT NULL DEFAULT 'Destaques',
  cover_url VARCHAR(800) NULL,
  status ENUM('draft','active','paused','ended') NOT NULL DEFAULT 'draft',
  min_videos_week SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  payment_per_video_cents INT UNSIGNED NOT NULL DEFAULT 1500,
  milestone_views BIGINT UNSIGNED NOT NULL DEFAULT 1000000,
  milestone_bonus_cents INT UNSIGNED NOT NULL DEFAULT 2000,
  monthly_leader_bonus_cents INT UNSIGNED NOT NULL DEFAULT 2000,
  starts_at DATE NULL,
  ends_at DATE NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_campaigns_status (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS social_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  network ENUM('instagram','tiktok') NOT NULL,
  username VARCHAR(100) NOT NULL,
  profile_url VARCHAR(500) NOT NULL,
  oauth_verified TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_account (user_id, network, username),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clips (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  social_account_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  published_url VARCHAR(800) NOT NULL,
  drive_file_id VARCHAR(180) NULL,
  original_filename VARCHAR(255) NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  verified_views BIGINT UNSIGNED NOT NULL DEFAULT 0,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  INDEX idx_clips_campaign_date (campaign_id, submitted_at),
  INDEX idx_clips_user_date (user_id, submitted_at),
  INDEX idx_clips_status (status, submitted_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id),
  FOREIGN KEY (social_account_id) REFERENCES social_accounts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ledger_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NULL,
  clip_id BIGINT UNSIGNED NULL,
  kind ENUM('clip','views_bonus','monthly_leader','adjustment') NOT NULL,
  amount_cents BIGINT NOT NULL,
  dedupe_key VARCHAR(190) NOT NULL UNIQUE,
  description VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ledger_user (user_id, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id),
  FOREIGN KEY (clip_id) REFERENCES clips(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS withdrawals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  amount_cents BIGINT UNSIGNED NOT NULL,
  status ENUM('requested','processing','paid','rejected') NOT NULL DEFAULT 'requested',
  pix_provider_reference VARCHAR(180) NULL,
  admin_note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  INDEX idx_withdraw_user_status (user_id, status),
  INDEX idx_withdraw_status (status, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_outbox (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('email','whatsapp') NOT NULL,
  template_name VARCHAR(80) NOT NULL,
  payload_json JSON NOT NULL,
  status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_outbox_delivery (status, available_at),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_attempts (ip_hash, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS platform_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT INTO platform_settings (setting_key,setting_value) VALUES
('brand_name','LeagueCut'),
('primary_color','#e50914'),
('logo_url','')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

-- Bloco 3: editor seguro do banner principal; dados existentes preservados.
CREATE TABLE IF NOT EXISTS homepage_banners (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 eyebrow VARCHAR(160) NOT NULL,
 title_top VARCHAR(160) NOT NULL,
 title_accent VARCHAR(160) NOT NULL,
 title_bottom VARCHAR(160) NOT NULL,
 subtitle TEXT NOT NULL,
 cta_primary_text VARCHAR(80) NOT NULL,
 cta_primary_url VARCHAR(600) NOT NULL,
 cta_secondary_text VARCHAR(80) NOT NULL,
 cta_secondary_url VARCHAR(600) NOT NULL,
 media_type ENUM('default','image','video') NOT NULL DEFAULT 'default',
 media_url VARCHAR(1000) NULL,
 media_filename VARCHAR(128) NULL,
 media_mime VARCHAR(80) NULL,
 overlay_opacity TINYINT UNSIGNED NOT NULL DEFAULT 50,
 text_alignment ENUM('left','center') NOT NULL DEFAULT 'left',
 updated_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO homepage_banners
(id,enabled,eyebrow,title_top,title_accent,title_bottom,subtitle,
cta_primary_text,cta_primary_url,cta_secondary_text,cta_secondary_url,media_type,
overlay_opacity,text_alignment)
VALUES
(1,1,'Criadores no centro de tudo','Seu talento.','Mais alcance.','Novas conquistas.',
'O espaço de quem transforma vídeos em oportunidades. Participe de campanhas, publique seus conteúdos e acompanhe recompensas verificadas conforme as regras.',
'Quero ser clipador','/cadastrar','Conhecer campanhas','/explorar','default',50,'left')
ON DUPLICATE KEY UPDATE id=id;

-- Bloco 5: streams privados de eventos para websocket e fallback HTTP.
CREATE TABLE IF NOT EXISTS realtime_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 scope ENUM('admin','user') NOT NULL,
 user_id BIGINT UNSIGNED NULL,
 event_type VARCHAR(70) NOT NULL,
 entity_type VARCHAR(70) NULL,
 entity_id BIGINT UNSIGNED NULL,
 payload_json JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_stream_id (id),
 INDEX idx_stream_scope (scope, id),
 INDEX idx_stream_user (user_id, id),
 CONSTRAINT fk_realtime_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bloco 7: OAuth privado do Google Drive. Tokens nunca entram em platform_settings.
CREATE TABLE IF NOT EXISTS google_drive_connections (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  refresh_token_enc TEXT NOT NULL,
  folder_id VARCHAR(190) NOT NULL,
  connected_by BIGINT UNSIGNED NULL,
  connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_drive_connected_by FOREIGN KEY (connected_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Daily Instagram metrics snapshots; successful provider observations only.
CREATE TABLE IF NOT EXISTS clip_daily_views (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clip_id BIGINT UNSIGNED NOT NULL,
 snapshot_date DATE NOT NULL,
 views BIGINT UNSIGNED NOT NULL,
 source VARCHAR(24) NOT NULL DEFAULT 'apify',
 observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_clip_day (clip_id,snapshot_date),
 KEY idx_daily_snapshot (snapshot_date,clip_id),
 CONSTRAINT fk_daily_clip FOREIGN KEY (clip_id) REFERENCES clips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-publication cooldown independent of batched paid Actor runs.
CREATE TABLE IF NOT EXISTS apify_post_checks (
 shortcode VARCHAR(80) NOT NULL PRIMARY KEY,
 last_checked_at DATETIME NOT NULL,
 last_run_id BIGINT UNSIGNED NULL,
 KEY idx_last_checked (last_checked_at),
 CONSTRAINT fk_post_run FOREIGN KEY (last_run_id) REFERENCES apify_profile_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- LeagueCut creator subscription foundation, additive and without billing activation.
-- Existing clipador/admin records remain untouched.
CREATE TABLE IF NOT EXISTS creator_packages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(140) NOT NULL,
 slug VARCHAR(170) NOT NULL UNIQUE,
 description TEXT NOT NULL,
 fulfillment_mode ENUM('download','production') NOT NULL,
 monthly_clips SMALLINT UNSIGNED NOT NULL,
 monthly_price_cents BIGINT UNSIGNED NOT NULL,
 status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_packages_type_status (fulfillment_mode,status),
 CONSTRAINT fk_creator_package_admin FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS creator_subscriptions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 creator_id BIGINT UNSIGNED NOT NULL,
 package_id BIGINT UNSIGNED NOT NULL,
 status ENUM('pending','active','past_due','cancelled','expired') NOT NULL DEFAULT 'pending',
 price_cents_snapshot BIGINT UNSIGNED NOT NULL,
 monthly_clips_snapshot SMALLINT UNSIGNED NOT NULL,
 fulfillment_mode_snapshot ENUM('download','production') NOT NULL,
 started_at DATETIME NULL,
 next_renewal_at DATETIME NULL,
 cancelled_at DATETIME NULL,
 payment_provider VARCHAR(60) NULL,
 payment_reference VARCHAR(190) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_creator_subscriptions (creator_id,status),
 KEY idx_subscription_payments (payment_provider,payment_reference),
 CONSTRAINT fk_sub_creator FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT fk_sub_package FOREIGN KEY (package_id) REFERENCES creator_packages(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS creator_subscription_cycles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 subscription_id BIGINT UNSIGNED NOT NULL,
 cycle_start DATE NOT NULL,
 cycle_end DATE NOT NULL,
 clips_included SMALLINT UNSIGNED NOT NULL,
 clips_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 payment_status ENUM('pending','confirmed','failed','refunded') NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_subscription_cycle (subscription_id,cycle_start),
 KEY idx_cycles_period (cycle_end,payment_status),
 CONSTRAINT fk_creator_cycle_sub FOREIGN KEY (subscription_id) REFERENCES creator_subscriptions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS creator_deliveries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 subscription_id BIGINT UNSIGNED NOT NULL,
 cycle_id BIGINT UNSIGNED NOT NULL,
 creator_id BIGINT UNSIGNED NOT NULL,
 source_clip_id BIGINT UNSIGNED NULL,
 title VARCHAR(180) NOT NULL,
 drive_file_id VARCHAR(190) NULL,
 publication_url VARCHAR(800) NULL,
 status ENUM('queued','production','ready','delivered','posted','cancelled') NOT NULL DEFAULT 'queued',
 delivered_at DATETIME NULL,
 posted_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_creator_deliveries (creator_id,status,created_at),
 KEY idx_delivery_subscription (subscription_id,cycle_id),
 CONSTRAINT fk_delivery_sub FOREIGN KEY (subscription_id) REFERENCES creator_subscriptions(id) ON DELETE RESTRICT,
 CONSTRAINT fk_delivery_cycle FOREIGN KEY (cycle_id) REFERENCES creator_subscription_cycles(id) ON DELETE RESTRICT,
 CONSTRAINT fk_delivery_creator FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT fk_delivery_source FOREIGN KEY (source_clip_id) REFERENCES clips(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Hybrid clip verification, audit-only.
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
