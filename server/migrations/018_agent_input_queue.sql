CREATE TABLE IF NOT EXISTS agent_inputs (
    id VARCHAR(80) NOT NULL PRIMARY KEY,
    source VARCHAR(40) NOT NULL,
    external_reference VARCHAR(190) NULL,
    title VARCHAR(190) NOT NULL,
    summary VARCHAR(1000) NOT NULL,
    payload_json JSON NULL,
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    claim_key VARCHAR(80) NULL,
    dossier_id VARCHAR(64) NULL,
    result_note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claimed_at DATETIME NULL,
    completed_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_agent_inputs_source_reference (source, external_reference),
    INDEX idx_agent_inputs_status (status, priority, created_at),
    INDEX idx_agent_inputs_dossier (dossier_id, updated_at),
    CONSTRAINT fk_agent_input_dossier FOREIGN KEY (dossier_id)
        REFERENCES agent_dossiers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
