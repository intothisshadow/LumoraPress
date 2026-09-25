CREATE TABLE {prefix}comment_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    comment_id INT UNSIGNED NOT NULL,
    reason VARCHAR(20) NOT NULL,
    reporter_key VARCHAR(66) NOT NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_comment_reporter (comment_id, reporter_key),
    KEY idx_comment_id (comment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
