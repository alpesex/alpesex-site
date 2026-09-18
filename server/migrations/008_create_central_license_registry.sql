CREATE TABLE IF NOT EXISTS organization_license_registry (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    issuer_license_id VARCHAR(100) NOT NULL,
    license_type VARCHAR(32) NOT NULL,
    license_role VARCHAR(32) NULL,
    parent_master_license_id VARCHAR(100) NULL,
    assigned_user_id BIGINT UNSIGNED NULL,
    assigned_email VARCHAR(254) NULL,
    token_hash CHAR(64) NOT NULL,
    license_token MEDIUMTEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    source VARCHAR(32) NOT NULL DEFAULT 'manual',
    expires_at DATETIME NULL,
    last_online_check_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_license_registry_issuer_id (issuer_license_id),
    UNIQUE KEY uq_license_registry_token_hash (token_hash),
    INDEX idx_license_registry_organization (organization_id, license_type, status),
    INDEX idx_license_registry_assigned_user (assigned_user_id),
    CONSTRAINT fk_license_registry_organization
        FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_license_registry_assigned_user
        FOREIGN KEY (assigned_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_license_registry_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO organization_license_registry
    (organization_id,issuer_license_id,license_type,token_hash,license_token,status,source)
SELECT organization_id,master_license_id,'master',SHA2(master_token,256),master_token,status,'legacy'
FROM organization_license_deployments;

INSERT IGNORE INTO organization_license_registry
    (organization_id,issuer_license_id,license_type,parent_master_license_id,assigned_user_id,
     assigned_email,token_hash,license_token,status,source)
SELECT l.organization_id,l.issuer_license_id,'user',l.master_license_id,l.assigned_user_id,
       u.email,SHA2(l.license_token,256),l.license_token,
       CASE WHEN l.status='assigned' THEN 'active' ELSE l.status END,'legacy'
FROM organization_licenses l
LEFT JOIN users u ON u.id=l.assigned_user_id
WHERE l.issuer_license_id IS NOT NULL AND l.license_token IS NOT NULL;
