CREATE TABLE {prefix}comment_meta (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    comment_id INT UNSIGNED NOT NULL,
    meta_key VARCHAR(191) NOT NULL,
    meta_value LONGTEXT NULL,
    UNIQUE KEY uniq_comment_key (comment_id, meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
