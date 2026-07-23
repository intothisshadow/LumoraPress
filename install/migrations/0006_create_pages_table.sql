CREATE TABLE {prefix}pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(191) NOT NULL,
    slug VARCHAR(191) NOT NULL,
    content LONGTEXT NOT NULL,
    excerpt TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    author_id INT UNSIGNED NOT NULL,
    parent_id INT UNSIGNED NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_slug (slug),
    KEY idx_status_published_at (status, published_at),
    KEY idx_parent (parent_id),
    KEY idx_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
