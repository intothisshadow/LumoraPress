CREATE TABLE {prefix}content_import_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id VARCHAR(64) NOT NULL,
    source VARCHAR(40) NOT NULL,
    content_type VARCHAR(20) NOT NULL,
    content_id INT UNSIGNED NOT NULL,
    external_id VARCHAR(191) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_batch_id (batch_id),
    KEY idx_content_type_id (content_type, content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
