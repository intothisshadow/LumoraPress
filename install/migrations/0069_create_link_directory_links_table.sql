CREATE TABLE {prefix}link_directory_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    description TEXT NULL,
    description_format VARCHAR(20) NOT NULL DEFAULT 'plain',
    category_id INT UNSIGNED NULL,
    thumbnail_media_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    trashed_at DATETIME NULL,
    KEY idx_category_id (category_id),
    KEY idx_thumbnail_media_id (thumbnail_media_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
