CREATE TABLE IF NOT EXISTS homepage_banners (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 eyebrow VARCHAR(160) NOT NULL,
 title_top VARCHAR(160) NOT NULL,
 title_accent VARCHAR(160) NOT NULL,
 title_bottom VARCHAR(160) NOT NULL,
 subtitle TEXT NOT NULL,
 cta_primary_text VARCHAR(80) NOT NULL,
 cta_primary_url VARCHAR(600) NOT NULL,
 cta_secondary_text VARCHAR(80) NOT NULL,
 cta_secondary_url VARCHAR(600) NOT NULL,
 media_type ENUM('default','image','video') NOT NULL DEFAULT 'default',
 media_url VARCHAR(1000) NULL,
 media_filename VARCHAR(128) NULL,
 media_mime VARCHAR(80) NULL,
 overlay_opacity TINYINT UNSIGNED NOT NULL DEFAULT 50,
 text_alignment ENUM('left','center') NOT NULL DEFAULT 'left',
 updated_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO homepage_banners
(id,enabled,eyebrow,title_top,title_accent,title_bottom,subtitle,
cta_primary_text,cta_primary_url,cta_secondary_text,cta_secondary_url,media_type,
overlay_opacity,text_alignment)
VALUES
(1,1,'Criadores no centro de tudo','Seu talento.','Mais alcance.','Novas conquistas.',
'O espaço de quem transforma vídeos em oportunidades. Participe de campanhas, publique seus conteúdos e acompanhe recompensas verificadas conforme as regras.',
'Quero ser clipador','/cadastrar','Conhecer campanhas','/explorar','default',50,'left')
ON DUPLICATE KEY UPDATE id=id;
