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
