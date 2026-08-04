ALTER TABLE {prefix}posts ADD COLUMN visibility VARCHAR(20) NOT NULL DEFAULT 'public' AFTER status;
ALTER TABLE {prefix}posts ADD COLUMN is_sticky TINYINT(1) NOT NULL DEFAULT 0 AFTER visibility;
ALTER TABLE {prefix}posts ADD COLUMN unpublish_at DATETIME NULL AFTER published_at;
