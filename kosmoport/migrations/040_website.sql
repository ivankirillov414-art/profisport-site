CREATE TABLE IF NOT EXISTS kcrm_website (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    draft LONGTEXT NOT NULL,
    published LONGTEXT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 0,
    published_revision INT UNSIGNED NOT NULL DEFAULT 0,
    updated_by VARCHAR(64) NULL,
    updated_at DATETIME NOT NULL,
    published_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
