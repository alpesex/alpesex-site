CREATE TABLE IF NOT EXISTS organization_license_deployments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    master_license_id VARCHAR(100) NOT NULL,
    deployment_id VARCHAR(100) NOT NULL,
    master_token MEDIUMTEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_license_deployment_organization (organization_id),
    UNIQUE KEY uq_license_deployment_master (master_license_id),
    UNIQUE KEY uq_license_deployment_id (deployment_id),
    CONSTRAINT fk_license_deployment_organization
        FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE organization_licenses
    ADD COLUMN IF NOT EXISTS issuer_license_id VARCHAR(100) NULL AFTER license_number,
    ADD COLUMN IF NOT EXISTS license_token MEDIUMTEXT NULL AFTER issuer_license_id,
    ADD COLUMN IF NOT EXISTS master_license_id VARCHAR(100) NULL AFTER license_token,
    ADD COLUMN IF NOT EXISTS deployment_id VARCHAR(100) NULL AFTER master_license_id;

CREATE UNIQUE INDEX IF NOT EXISTS uq_organization_licenses_issuer_id
    ON organization_licenses (issuer_license_id);
