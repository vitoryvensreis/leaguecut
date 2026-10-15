-- Staging only: apply after backup. Never contains raw Meta user identifiers.
CREATE TABLE IF NOT EXISTS meta_deauthorization_inbox (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 event_hash CHAR(64) NOT NULL,
 app_scoped_user_hash CHAR(64) NOT NULL,
 received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 status ENUM('pending_mapping','resolved') NOT NULL DEFAULT 'pending_mapping',
 UNIQUE KEY uq_meta_deauth_event (event_hash),
 KEY idx_meta_deauth_status (status,received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
