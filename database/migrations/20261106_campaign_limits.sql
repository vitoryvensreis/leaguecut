-- Aditiva: NULL = ilimitado. Nenhum dado existente é alterado.
ALTER TABLE campaigns ADD COLUMN max_clippers INT UNSIGNED NULL DEFAULT NULL AFTER ends_at;
ALTER TABLE campaigns ADD COLUMN max_approved_per_week SMALLINT UNSIGNED NULL DEFAULT NULL AFTER max_clippers
