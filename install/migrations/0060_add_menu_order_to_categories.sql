ALTER TABLE {prefix}categories ADD COLUMN menu_order INT NOT NULL DEFAULT 0 AFTER parent_id;
