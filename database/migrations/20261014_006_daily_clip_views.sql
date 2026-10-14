-- Daily Instagram metrics snapshots; successful provider observations only.
CREATE TABLE IF NOT EXISTS clip_daily_views (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 clip_id BIGINT UNSIGNED NOT NULL,
 snapshot_date DATE NOT NULL,
 views BIGINT UNSIGNED NOT NULL,
 source VARCHAR(24) NOT NULL DEFAULT 'apify',
 observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_clip_day (clip_id,snapshot_date),
 KEY idx_daily_snapshot (snapshot_date,clip_id),
 CONSTRAINT fk_daily_clip FOREIGN KEY (clip_id) REFERENCES clips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-publication cooldown independent of batched paid Actor runs.
CREATE TABLE IF NOT EXISTS apify_post_checks (
 shortcode VARCHAR(80) NOT NULL PRIMARY KEY,
 last_checked_at DATETIME NOT NULL,
 last_run_id BIGINT UNSIGNED NULL,
 KEY idx_last_checked (last_checked_at),
 CONSTRAINT fk_post_run FOREIGN KEY (last_run_id) REFERENCES apify_profile_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
