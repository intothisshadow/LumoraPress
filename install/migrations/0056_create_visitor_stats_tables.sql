CREATE TABLE {prefix}post_views (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id INT UNSIGNED NOT NULL,
    view_date DATE NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY idx_post_date (post_id, view_date),
    KEY idx_view_date (view_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE {prefix}view_stats_referrers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    view_date DATE NOT NULL,
    referrer_domain VARCHAR(191) NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY idx_date_domain (view_date, referrer_domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE {prefix}view_stats_browsers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    view_date DATE NOT NULL,
    browser VARCHAR(30) NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY idx_date_browser (view_date, browser)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE {prefix}view_stats_devices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    view_date DATE NOT NULL,
    device_type VARCHAR(30) NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY idx_date_device (view_date, device_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE {prefix}view_stats_countries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    view_date DATE NOT NULL,
    country_code CHAR(2) NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY idx_date_country (view_date, country_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE {prefix}geoip_ranges (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    network_start INT UNSIGNED NOT NULL,
    network_end INT UNSIGNED NOT NULL,
    country_code CHAR(2) NOT NULL,
    KEY idx_network_start (network_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
