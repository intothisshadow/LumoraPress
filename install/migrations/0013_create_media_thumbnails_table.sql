CREATE TABLE {prefix}media_thumbnails (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    media_id INT UNSIGNED NOT NULL,
    size_name VARCHAR(32) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_media (media_id),
    UNIQUE KEY uniq_media_size (media_id, size_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
