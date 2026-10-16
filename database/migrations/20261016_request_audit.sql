CREATE TABLE IF NOT EXISTS request_audit (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NULL,
 actor_role VARCHAR(24) NOT NULL DEFAULT 'guest',
 method VARCHAR(8) NOT NULL,
 route_family VARCHAR(140) NOT NULL,
 status_code SMALLINT UNSIGNED NOT NULL,
 ip_hash CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_request_audit_user (user_id,created_at),
 INDEX idx_request_audit_date (created_at),
 INDEX idx_request_audit_route (route_family,created_at),
 CONSTRAINT fk_request_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
