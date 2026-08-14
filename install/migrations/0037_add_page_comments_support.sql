ALTER TABLE {prefix}comments MODIFY post_id INT UNSIGNED NULL;
ALTER TABLE {prefix}comments ADD COLUMN page_id INT UNSIGNED NULL AFTER post_id;
ALTER TABLE {prefix}comments ADD KEY idx_page_id (page_id);
