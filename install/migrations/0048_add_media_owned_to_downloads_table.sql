ALTER TABLE {prefix}downloads ADD COLUMN media_owned TINYINT(1) NOT NULL DEFAULT 1 AFTER media_id;
