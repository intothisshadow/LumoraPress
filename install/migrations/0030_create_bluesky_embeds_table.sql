CREATE TABLE {prefix}bluesky_embeds (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    handle VARCHAR(191) NOT NULL,
    rkey VARCHAR(191) NOT NULL,
    at_uri VARCHAR(500) NULL,
    cid VARCHAR(255) NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY idx_handle_rkey (handle, rkey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
