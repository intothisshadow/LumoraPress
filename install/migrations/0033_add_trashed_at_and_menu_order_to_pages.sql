ALTER TABLE {prefix}pages ADD COLUMN trashed_at DATETIME NULL AFTER updated_at;
ALTER TABLE {prefix}pages ADD COLUMN menu_order INT NOT NULL DEFAULT 0 AFTER parent_id;
