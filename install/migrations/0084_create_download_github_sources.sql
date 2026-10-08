CREATE TABLE {prefix}download_github_sources (
    download_id INT UNSIGNED NOT NULL PRIMARY KEY,
    owner VARCHAR(100) NOT NULL,
    repo VARCHAR(100) NOT NULL,
    branch VARCHAR(255) NOT NULL,
    title_hash CHAR(64) NOT NULL,
    description_hash CHAR(64) NOT NULL,
    readme_hash CHAR(64) NOT NULL,
    synced_at DATETIME NOT NULL,
    KEY idx_repo (owner, repo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE {prefix}download_github_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    download_id INT UNSIGNED NOT NULL,
    repo_path VARCHAR(255) NOT NULL,
    blob_sha CHAR(40) NOT NULL,
    media_id INT UNSIGNED NOT NULL,
    KEY idx_download_id (download_id),
    KEY idx_media_id (media_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
