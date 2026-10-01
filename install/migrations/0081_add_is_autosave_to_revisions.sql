ALTER TABLE {prefix}revisions
    ADD COLUMN is_autosave TINYINT(1) NOT NULL DEFAULT 0 AFTER author_id;
