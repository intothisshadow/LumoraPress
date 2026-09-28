CREATE TABLE {prefix}search_terms (
    term VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
    weight INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE {prefix}search_queries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    query VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    searches INT UNSIGNED NOT NULL DEFAULT 0,
    last_result_count INT UNSIGNED NOT NULL DEFAULT 0,
    first_searched_at DATETIME NOT NULL,
    last_searched_at DATETIME NOT NULL,
    UNIQUE KEY uniq_query (query),
    KEY idx_last_searched (last_searched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
