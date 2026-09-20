CREATE TABLE IF NOT EXISTS organization_team_hierarchy_versions (
 organization_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
 CONSTRAINT fk_team_version_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS organization_team_hierarchy (
 organization_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 parent_user_id BIGINT UNSIGNED NOT NULL,
 access_mode VARCHAR(16) NOT NULL DEFAULT 'viewer',
 updated_by_user_id BIGINT UNSIGNED NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (organization_id,user_id),
 INDEX idx_hierarchy_parent (organization_id,parent_user_id),
 CONSTRAINT fk_hierarchy_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_hierarchy_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_hierarchy_parent FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_hierarchy_actor FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
