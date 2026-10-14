CREATE TABLE {prefix}pending_registrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL,
    email VARCHAR(191) NOT NULL,
    display_name VARCHAR(191) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    selector VARCHAR(24) NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'unconfirmed',
    ip_address VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    UNIQUE KEY uniq_selector (selector),
    KEY idx_email (email),
    KEY idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE {prefix}registration_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(191) DEFAULT NULL,
    attempted_at DATETIME NOT NULL,
    KEY idx_ip_attempted (ip_address, attempted_at),
    KEY idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE {prefix}users ADD COLUMN registration_source VARCHAR(10) NOT NULL DEFAULT 'admin';
