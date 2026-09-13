ALTER TABLE {prefix}tags ADD COLUMN trashed_at DATETIME NULL AFTER updated_at;
