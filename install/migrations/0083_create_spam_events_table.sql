CREATE TABLE {prefix}spam_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(20) NOT NULL,
    comment_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    author_name VARCHAR(255) NOT NULL DEFAULT '',
    author_email VARCHAR(255) NOT NULL DEFAULT '',
    ip_address VARCHAR(45) NOT NULL,
    spam_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    triggered_rules VARCHAR(255) NOT NULL DEFAULT '',
    action_taken VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_created_at (created_at),
    KEY idx_ip_address (ip_address),
    KEY idx_comment_id (comment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
