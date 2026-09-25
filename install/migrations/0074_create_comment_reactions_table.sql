CREATE TABLE {prefix}comment_reactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    comment_id INT UNSIGNED NOT NULL,
    reaction VARCHAR(20) NOT NULL,
    voter_key VARCHAR(66) NOT NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_comment_voter (comment_id, voter_key),
    KEY idx_comment_reaction (comment_id, reaction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
