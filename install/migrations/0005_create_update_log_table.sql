CREATE TABLE {prefix}update_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_version VARCHAR(32) NOT NULL,
    to_version VARCHAR(32) NOT NULL,
    status VARCHAR(20) NOT NULL,
    message TEXT NULL,
    backup_files_path VARCHAR(255) NULL,
    backup_database_path VARCHAR(255) NULL,
    performed_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
