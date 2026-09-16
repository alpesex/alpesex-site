ALTER TABLE organizations
    ADD COLUMN license_organization_id VARCHAR(100) NULL AFTER status,
    ADD UNIQUE KEY uq_organizations_license_id (license_organization_id);

ALTER TABLE organization_licenses
    MODIFY license_number VARCHAR(64) NOT NULL,
    DROP INDEX uq_organization_licenses_assigned_user,
    ADD COLUMN license_id VARCHAR(100) NULL AFTER license_number,
    ADD COLUMN signed_token TEXT NULL AFTER license_id,
    ADD COLUMN order_id BIGINT UNSIGNED NULL AFTER organization_id,
    ADD COLUMN authority_id VARCHAR(100) NULL AFTER license_type,
    ADD COLUMN issued_at DATETIME NULL AFTER assigned_at,
    ADD COLUMN expires_at DATETIME NULL AFTER issued_at,
    ADD COLUMN revoked_at DATETIME NULL AFTER released_at,
    ADD UNIQUE KEY uq_organization_licenses_license_id (license_id),
    ADD INDEX idx_organization_licenses_assigned_user (assigned_user_id),
    ADD INDEX idx_organization_licenses_order (order_id),
    ADD CONSTRAINT fk_organization_licenses_order
        FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT;

ALTER TABLE orders
    ADD COLUMN paid_at DATETIME NULL AFTER terms_accepted_at,
    ADD COLUMN invoice_number VARCHAR(64) NULL AFTER paid_at,
    ADD COLUMN invoice_url VARCHAR(500) NULL AFTER invoice_number,
    ADD COLUMN licenses_provisioned_at DATETIME NULL AFTER invoice_url,
    ADD UNIQUE KEY uq_orders_invoice_number (invoice_number);

CREATE TABLE IF NOT EXISTS license_revocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    license_id VARCHAR(100) NOT NULL,
    reason VARCHAR(100) NOT NULL,
    revoked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_license_revocations_license_id (license_id),
    INDEX idx_license_revocations_organization (organization_id, revoked_at),
    CONSTRAINT fk_license_revocations_organization
        FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
