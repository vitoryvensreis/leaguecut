CREATE TABLE IF NOT EXISTS asaas_customers (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  environment VARCHAR(12) NOT NULL,
  provider_customer_id VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_asaas_customer_id (environment,provider_customer_id),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS asaas_charges (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  creator_id BIGINT UNSIGNED NOT NULL,
  subscription_id BIGINT UNSIGNED NOT NULL,
  environment VARCHAR(12) NOT NULL,
  reference_key VARCHAR(90) NOT NULL UNIQUE,
  provider_payment_id VARCHAR(90) NULL UNIQUE,
  invoice_url VARCHAR(800) NULL,
  amount_cents BIGINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'creating',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_asaas_charges_subscription(subscription_id,status),
  FOREIGN KEY(creator_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(subscription_id) REFERENCES creator_subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS asaas_webhook_events (
  event_id VARCHAR(100) PRIMARY KEY,
  event_type VARCHAR(90) NOT NULL,
  object_id VARCHAR(100) NOT NULL,
  status VARCHAR(25) NOT NULL DEFAULT 'received',
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_asaas_events_created(occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS asaas_payout_intents (
  withdrawal_id BIGINT UNSIGNED PRIMARY KEY,
  amount_cents BIGINT UNSIGNED NOT NULL,
  environment VARCHAR(12) NOT NULL,
  status VARCHAR(35) NOT NULL DEFAULT 'review_required',
  provider_transfer_id VARCHAR(90) NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(withdrawal_id) REFERENCES withdrawals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;