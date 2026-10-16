CREATE TABLE IF NOT EXISTS support_ticket_tracking (
 ticket_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 assigned_admin_id BIGINT UNSIGNED NULL,
 target_at DATETIME NULL,
 updated_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_support_tracking_due (target_at),
 INDEX idx_support_tracking_assigned (assigned_admin_id,target_at),
 CONSTRAINT fk_support_tracking_ticket FOREIGN KEY(ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
 CONSTRAINT fk_support_tracking_assignee FOREIGN KEY(assigned_admin_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_support_tracking_actor FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_ticket_management_audit (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NULL,
 old_admin_id BIGINT UNSIGNED NULL,
 new_admin_id BIGINT UNSIGNED NULL,
 old_target_at DATETIME NULL,
 new_target_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_support_management_ticket (ticket_id,id),
 CONSTRAINT fk_support_management_ticket FOREIGN KEY(ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
 CONSTRAINT fk_support_management_actor FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
