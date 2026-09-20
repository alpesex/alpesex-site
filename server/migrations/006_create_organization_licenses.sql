CREATE TABLE IF NOT EXISTS organization_licenses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    license_number VARCHAR(40) NOT NULL,
    license_type VARCHAR(32) NOT NULL DEFAULT 'user',
    assigned_user_id BIGINT UNSIGNED NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'available',
    assigned_at DATETIME NULL,
    released_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_organization_licenses_number (license_number),
    UNIQUE KEY uq_organization_licenses_assigned_user (assigned_user_id),
    INDEX idx_organization_licenses_pool (organization_id, license_type, status),
    CONSTRAINT fk_organization_licenses_organization
        FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_organization_licenses_user
        FOREIGN KEY (assigned_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
