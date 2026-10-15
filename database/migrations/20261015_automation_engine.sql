CREATE TABLE IF NOT EXISTS automation_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(140) NOT NULL,
  trigger_code VARCHAR(50) NOT NULL,
  condition_field VARCHAR(30) NOT NULL DEFAULT 'always',
  condition_value VARCHAR(160) NOT NULL DEFAULT '',
  action_type ENUM('whatsapp','email','in_app') NOT NULL,
  recipient_type ENUM('user','admins') NOT NULL DEFAULT 'user',
  message_template TEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_rule_trigger (trigger_code,enabled)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS automation_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_key VARCHAR(190) NOT NULL,
  trigger_code VARCHAR(50) NOT NULL,
  subject_user_id BIGINT UNSIGNED NULL,
  context_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_automation_event_key (event_key),
  INDEX idx_automation_event_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS automation_deliveries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id BIGINT UNSIGNED NOT NULL,
  rule_id BIGINT UNSIGNED NOT NULL,
  recipient_user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','processing','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error VARCHAR(255) NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_automation_delivery (event_id,rule_id,recipient_user_id),
  INDEX idx_automation_due (status,available_at),
  CONSTRAINT fk_automation_event FOREIGN KEY (event_id) REFERENCES automation_events(id) ON DELETE CASCADE,
  CONSTRAINT fk_automation_rule FOREIGN KEY (rule_id) REFERENCES automation_rules(id) ON DELETE CASCADE,
  CONSTRAINT fk_automation_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
