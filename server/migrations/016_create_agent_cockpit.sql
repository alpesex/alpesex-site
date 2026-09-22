CREATE TABLE IF NOT EXISTS agent_dossiers (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    client VARCHAR(190) NULL,
    version VARCHAR(80) NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'open',
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    current_agent VARCHAR(40) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_agent_dossiers_status (status, updated_at),
    INDEX idx_agent_dossiers_agent (current_agent, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dossier_id VARCHAR(64) NULL,
    source_agent VARCHAR(40) NOT NULL,
    target_agent VARCHAR(40) NULL,
    event_type VARCHAR(40) NOT NULL,
    summary VARCHAR(500) NOT NULL,
    payload_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_agent_events_created (created_at),
    INDEX idx_agent_events_dossier (dossier_id, created_at),
    CONSTRAINT fk_agent_event_dossier FOREIGN KEY (dossier_id)
        REFERENCES agent_dossiers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_decisions (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    dossier_id VARCHAR(64) NULL,
    requester_agent VARCHAR(40) NOT NULL,
    question VARCHAR(500) NOT NULL,
    why_now VARCHAR(500) NULL,
    options_json JSON NOT NULL,
    impacts_json JSON NULL,
    recommendation VARCHAR(500) NULL,
    urgency VARCHAR(20) NOT NULL DEFAULT 'normal',
    deadline DATETIME NULL,
    blocked_work VARCHAR(500) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    answer VARCHAR(500) NULL,
    decision_note VARCHAR(1000) NULL,
    decided_by VARCHAR(190) NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    INDEX idx_agent_decisions_status (status, urgency, requested_at),
    INDEX idx_agent_decisions_dossier (dossier_id, requested_at),
    CONSTRAINT fk_agent_decision_dossier FOREIGN KEY (dossier_id)
        REFERENCES agent_dossiers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
