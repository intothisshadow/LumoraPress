CREATE TABLE {prefix}media_folders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    parent_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE {prefix}media ADD COLUMN folder_id INT UNSIGNED NULL AFTER uploaded_by;
ALTER TABLE {prefix}media ADD COLUMN alt_text VARCHAR(255) NULL AFTER folder_id;
ALTER TABLE {prefix}media ADD COLUMN caption TEXT NULL AFTER alt_text;
ALTER TABLE {prefix}media ADD COLUMN description TEXT NULL AFTER caption;
ALTER TABLE {prefix}media ADD COLUMN notes TEXT NULL AFTER description;
ALTER TABLE {prefix}media ADD COLUMN file_hash VARCHAR(64) NULL AFTER notes;
ALTER TABLE {prefix}media ADD KEY idx_folder (folder_id);
