ALTER TABLE users
    ADD COLUMN IF NOT EXISTS team_manager_id BIGINT UNSIGNED NULL AFTER organization_id,
    ADD INDEX IF NOT EXISTS idx_users_team_manager (team_manager_id),
    ADD CONSTRAINT fk_users_team_manager FOREIGN KEY (team_manager_id) REFERENCES users (id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS application_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    license_registry_id BIGINT UNSIGNED NOT NULL,
    device_identifier CHAR(64) NOT NULL,
    device_name VARCHAR(190) NOT NULL,
    platform VARCHAR(32) NOT NULL DEFAULT 'web',
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_application_device_license (license_registry_id, device_identifier),
    INDEX idx_application_devices_active (license_registry_id, status),
    CONSTRAINT fk_application_devices_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_application_devices_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_application_devices_license FOREIGN KEY (license_registry_id) REFERENCES organization_license_registry (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_projects (
    id CHAR(36) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    local_id VARCHAR(120) NOT NULL,
    name VARCHAR(190) NOT NULL,
    project_data LONGTEXT NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_application_project_local (organization_id, local_id),
    INDEX idx_application_projects_owner (organization_id, owner_user_id),
    CONSTRAINT fk_application_projects_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_application_projects_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_documents (
    id CHAR(36) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    project_id CHAR(36) NOT NULL,
    reference_code VARCHAR(64) NOT NULL,
    family_code VARCHAR(64) NULL,
    file_name VARCHAR(255) NOT NULL,
    media_type VARCHAR(150) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    storage_name CHAR(64) NOT NULL,
    uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_application_document_reference (project_id, reference_code),
    CONSTRAINT fk_application_documents_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_application_documents_project FOREIGN KEY (project_id) REFERENCES application_projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_application_documents_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
