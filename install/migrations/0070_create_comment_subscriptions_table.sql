CREATE TABLE {prefix}comment_subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id INT UNSIGNED NULL,
    page_id INT UNSIGNED NULL,
    email VARCHAR(191) NOT NULL,
    user_id INT UNSIGNED NULL,
    token CHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    source_comment_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    confirmed_at DATETIME NULL,
    UNIQUE KEY uniq_token (token),
    UNIQUE KEY uniq_post_email (post_id, email),
    UNIQUE KEY uniq_page_email (page_id, email),
    KEY idx_user_id (user_id),
    KEY idx_source_comment (source_comment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
