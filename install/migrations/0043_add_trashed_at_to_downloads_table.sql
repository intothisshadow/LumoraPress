ALTER TABLE {prefix}downloads ADD COLUMN trashed_at DATETIME NULL AFTER updated_at;
