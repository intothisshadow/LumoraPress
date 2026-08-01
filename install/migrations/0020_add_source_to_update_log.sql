ALTER TABLE {prefix}update_log ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER to_version;
