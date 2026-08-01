CREATE TABLE {prefix}redirects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_path VARCHAR(191) NOT NULL,
    target_url VARCHAR(500) NOT NULL,
    status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
    hit_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY idx_source_path (source_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
