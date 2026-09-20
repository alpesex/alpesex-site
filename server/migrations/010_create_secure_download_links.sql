CREATE TABLE IF NOT EXISTS secure_download_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    issued_to_user_id BIGINT UNSIGNED NULL,
    resource_type VARCHAR(32) NOT NULL,
    resource_reference VARCHAR(190) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    max_downloads SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    download_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    last_downloaded_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_secure_download_token (token_hash),
    INDEX idx_secure_download_resource (organization_id, resource_type, resource_reference),
    INDEX idx_secure_download_expiry (expires_at, revoked_at),
    CONSTRAINT fk_secure_download_organization
        FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_secure_download_user
        FOREIGN KEY (issued_to_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
