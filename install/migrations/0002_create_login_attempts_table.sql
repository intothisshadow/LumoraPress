CREATE TABLE {prefix}login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(60) NULL,
    attempted_at DATETIME NOT NULL,
    KEY idx_ip_address (ip_address),
    KEY idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
