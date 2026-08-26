ALTER TABLE {prefix}downloads ADD COLUMN category_id INT UNSIGNED NULL AFTER folder_id;
ALTER TABLE {prefix}downloads ADD KEY idx_category_id (category_id);
