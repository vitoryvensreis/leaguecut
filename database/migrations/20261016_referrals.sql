CREATE TABLE IF NOT EXISTS referral_codes (
 user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 code CHAR(16) NOT NULL UNIQUE,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_ref_code_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referral_rules (
 referred_role ENUM('clipador','criador') NOT NULL PRIMARY KEY,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 mode ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
 value_int INT UNSIGNED NOT NULL DEFAULT 0,
 updated_by BIGINT UNSIGNED NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_ref_rule_admin FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referrals (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 referrer_user_id BIGINT UNSIGNED NOT NULL,
 referred_user_id BIGINT UNSIGNED NOT NULL UNIQUE,
 referred_role ENUM('clipador','criador') NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_referrer_user (referrer_user_id,created_at),
 CONSTRAINT fk_ref_parent FOREIGN KEY(referrer_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT fk_ref_child FOREIGN KEY(referred_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referral_commissions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 referral_id BIGINT UNSIGNED NOT NULL,
 referrer_user_id BIGINT UNSIGNED NOT NULL,
 referred_user_id BIGINT UNSIGNED NOT NULL,
 source_type ENUM('clip_credit','creator_cycle') NOT NULL,
 source_key VARCHAR(120) NOT NULL UNIQUE,
 base_amount_cents BIGINT UNSIGNED NOT NULL,
 amount_cents BIGINT UNSIGNED NOT NULL,
 mode ENUM('fixed','percent') NOT NULL,
 rate_value INT UNSIGNED NOT NULL,
 status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 reviewed_by BIGINT UNSIGNED NULL,
 reviewed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_commission_status (status,created_at),
 INDEX idx_commission_referrer (referrer_user_id,created_at),
 INDEX idx_commission_referral (referral_id),
 CONSTRAINT fk_ref_comm_ref FOREIGN KEY(referral_id) REFERENCES referrals(id) ON DELETE RESTRICT,
 CONSTRAINT fk_ref_comm_parent FOREIGN KEY(referrer_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT fk_ref_comm_child FOREIGN KEY(referred_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT fk_ref_comm_admin FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
