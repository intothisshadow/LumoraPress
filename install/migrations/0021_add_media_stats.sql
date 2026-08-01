CREATE TABLE {prefix}media_stats (
    media_id INT UNSIGNED NOT NULL PRIMARY KEY,
    downloads INT UNSIGNED NOT NULL DEFAULT 0,
    last_downloaded_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
