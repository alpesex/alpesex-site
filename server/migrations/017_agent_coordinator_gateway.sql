ALTER TABLE agent_events
    ADD COLUMN IF NOT EXISTS event_key VARCHAR(80) NULL,
    ADD UNIQUE KEY IF NOT EXISTS uq_agent_events_event_key (event_key);

CREATE TABLE IF NOT EXISTS agent_activity (
    dossier_id VARCHAR(64) NOT NULL,
    agent VARCHAR(40) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'available',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (dossier_id, agent),
    CONSTRAINT fk_agent_activity_dossier FOREIGN KEY (dossier_id)
        REFERENCES agent_dossiers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_oauth_codes (
    code_hash CHAR(64) NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    client_id VARCHAR(250) NOT NULL,
    redirect_uri VARCHAR(500) NOT NULL,
    code_challenge VARCHAR(128) NOT NULL,
    resource VARCHAR(250) NOT NULL,
    scope VARCHAR(100) NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_agent_oauth_codes_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_oauth_tokens (
    token_hash CHAR(64) NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    client_id VARCHAR(250) NOT NULL,
    resource VARCHAR(250) NOT NULL,
    scope VARCHAR(100) NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    INDEX idx_agent_oauth_tokens_expiry (expires_at),
    INDEX idx_agent_oauth_tokens_user (user_id, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
