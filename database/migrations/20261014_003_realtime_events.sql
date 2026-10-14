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
