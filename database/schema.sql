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
  role ENUM('clipador','admin') NOT NULL DEFAULT 'clipador',
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
