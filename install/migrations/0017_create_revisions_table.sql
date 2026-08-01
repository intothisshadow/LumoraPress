CREATE TABLE {prefix}revisions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_type VARCHAR(10) NOT NULL,
    content_id INT UNSIGNED NOT NULL,
    title VARCHAR(191) NOT NULL,
    content LONGTEXT NOT NULL,
    excerpt TEXT NULL,
    content_format VARCHAR(10) NOT NULL DEFAULT 'plain',
    author_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_content (content_type, content_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
