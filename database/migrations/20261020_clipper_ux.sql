CREATE TABLE IF NOT EXISTS clipper_banners (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    cta_label VARCHAR(70) NOT NULL DEFAULT 'Saiba mais',
    cta_url VARCHAR(700) NOT NULL DEFAULT '/explorar',
    image_token CHAR(36) NULL UNIQUE,
    image_filename VARCHAR(100) NULL,
    image_mime VARCHAR(40) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_clipper_banners_active (is_active,sort_order,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS user_avatars (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    token CHAR(36) NOT NULL UNIQUE,
    filename VARCHAR(100) NOT NULL,
    mime VARCHAR(40) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_avatar_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
