CREATE TABLE {prefix}api_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(191) NOT NULL,
    selector VARCHAR(24) NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY uniq_selector (selector),
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
