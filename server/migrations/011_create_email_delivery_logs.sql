CREATE TABLE IF NOT EXISTS email_delivery_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    correlation_id CHAR(32) NOT NULL,
    message_type VARCHAR(64) NOT NULL,
    recipient VARCHAR(254) NOT NULL,
    recipient_hash CHAR(64) NOT NULL,
    delivery_status VARCHAR(32) NOT NULL,
    smtp_message_id VARCHAR(255) NULL,
    error_code VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_delivery_logs_correlation (correlation_id),
    INDEX idx_email_delivery_logs_status_date (delivery_status, created_at),
    INDEX idx_email_delivery_logs_recipient_date (recipient_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
