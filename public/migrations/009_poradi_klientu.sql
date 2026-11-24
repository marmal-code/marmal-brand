-- 1.8.0: vlastní pořadí klientů v přehledu administrace (přetažením)
-- Výchozí pořadí = dosavadní řazení (aktivní nahoře, naposledy změnění první).

ALTER TABLE brands ADD COLUMN sort INT NOT NULL DEFAULT 0 AFTER is_active;
SET @poradi := 0;
UPDATE brands SET sort = (@poradi := @poradi + 10) ORDER BY is_active DESC, updated_at DESC, id DESC;
