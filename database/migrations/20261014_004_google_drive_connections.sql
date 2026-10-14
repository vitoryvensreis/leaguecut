-- Bloco 7: OAuth privado do Google Drive. Tokens nunca entram em platform_settings.
CREATE TABLE IF NOT EXISTS google_drive_connections (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  refresh_token_enc TEXT NOT NULL,
  folder_id VARCHAR(190) NOT NULL,
  connected_by BIGINT UNSIGNED NULL,
  connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_drive_connected_by FOREIGN KEY (connected_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
