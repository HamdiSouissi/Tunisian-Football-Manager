-- Expérience cumulative acquise sur les minutes réellement jouées.
ALTER TABLE `players`
    ADD COLUMN `experience` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `potential`;
