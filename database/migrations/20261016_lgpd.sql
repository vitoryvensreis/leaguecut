CREATE TABLE IF NOT EXISTS privacy_cookie_choices (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 visitor_hash CHAR(64) NOT NULL,
 user_id BIGINT UNSIGNED NULL,
 policy_version VARCHAR(20) NOT NULL,
 choice ENUM('accept','reject','custom') NOT NULL,
 analytics_enabled TINYINT(1) NOT NULL DEFAULT 0,
 marketing_enabled TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_privacy_cookie_lookup (visitor_hash,id),
 INDEX idx_privacy_cookie_user (user_id,created_at),
 CONSTRAINT fk_privacy_cookie_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS privacy_subject_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 request_kind ENUM('access','correction','deletion','portability','opposition','consent_withdrawal','information') NOT NULL,
 details TEXT NOT NULL,
 status ENUM('received','in_review','completed','denied') NOT NULL DEFAULT 'received',
 response_text TEXT NULL,
 handled_by BIGINT UNSIGNED NULL,
 handled_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_privacy_request_owner (user_id,id),
 INDEX idx_privacy_request_queue (status,created_at),
 CONSTRAINT fk_privacy_request_owner FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_privacy_request_handler FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS privacy_request_audit (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NULL,
 action ENUM('created','reviewed','responded','denied') NOT NULL,
 status_after VARCHAR(24) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_privacy_req_audit (request_id,id),
 CONSTRAINT fk_privacy_req_audit_request FOREIGN KEY (request_id) REFERENCES privacy_subject_requests(id) ON DELETE CASCADE,
 CONSTRAINT fk_privacy_req_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
