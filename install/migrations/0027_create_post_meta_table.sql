CREATE TABLE {prefix}post_meta (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id INT UNSIGNED NOT NULL,
    meta_key VARCHAR(191) NOT NULL,
    meta_value TEXT NULL,
    KEY idx_post_id (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
