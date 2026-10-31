CREATE TABLE IF NOT EXISTS kcrm_documents (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(240) NOT NULL,
    category VARCHAR(80) NOT NULL,
    current_version INT UNSIGNED NOT NULL,
    created_by VARCHAR(64) NOT NULL,
    created_by_name VARCHAR(200) NOT NULL,
    updated_by VARCHAR(64) NOT NULL,
    updated_by_name VARCHAR(200) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY kcrm_documents_category (category),
    KEY kcrm_documents_updated (updated_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kcrm_document_versions (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    document_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    title VARCHAR(240) NOT NULL,
    category VARCHAR(80) NOT NULL,
    original_name VARCHAR(200) NOT NULL,
    extension VARCHAR(8) CHARACTER SET ascii NOT NULL,
    mime VARCHAR(100) CHARACTER SET ascii NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    storage_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    uploaded_by VARCHAR(64) NOT NULL,
    uploaded_by_name VARCHAR(200) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY kcrm_document_version_number (document_id, version_no),
    UNIQUE KEY kcrm_document_storage_key (storage_key),
    CONSTRAINT kcrm_document_version_parent FOREIGN KEY (document_id)
        REFERENCES kcrm_documents (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
