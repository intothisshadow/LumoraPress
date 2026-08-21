CREATE TABLE {prefix}downloads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    folder_id INT UNSIGNED NULL,
    type ENUM('file', 'url') NOT NULL,
    media_id INT UNSIGNED NULL,
    redirect_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_folder_id (folder_id),
    KEY idx_media_id (media_id),
    KEY idx_redirect_id (redirect_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
