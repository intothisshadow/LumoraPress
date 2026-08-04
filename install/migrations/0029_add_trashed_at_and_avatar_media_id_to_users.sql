ALTER TABLE {prefix}users ADD COLUMN trashed_at DATETIME NULL AFTER preferred_editor;
ALTER TABLE {prefix}users ADD COLUMN avatar_media_id INT UNSIGNED NULL AFTER trashed_at;
