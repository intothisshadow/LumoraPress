ALTER TABLE {prefix}redirects ADD COLUMN folder_id INT UNSIGNED NULL AFTER status_code;
ALTER TABLE {prefix}redirects ADD KEY idx_folder_id (folder_id);
