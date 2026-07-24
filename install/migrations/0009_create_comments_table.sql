CREATE TABLE {prefix}comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id INT UNSIGNED NOT NULL,
    parent_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    guest_name VARCHAR(191) NOT NULL,
    guest_email VARCHAR(191) NOT NULL,
    guest_url VARCHAR(255) NULL,
    content TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_post_id (post_id),
    KEY idx_parent_id (parent_id),
    KEY idx_status (status),
    KEY idx_ip_created (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
