CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    offer_key VARCHAR(32) NOT NULL,
    subtotal_cents INT UNSIGNED NOT NULL,
    tax_cents INT UNSIGNED NOT NULL DEFAULT 0,
    total_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    expires_at DATETIME NOT NULL,
    terms_accepted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_public_id (public_id),
    INDEX idx_orders_organization_status (organization_id, status, created_at),
    CONSTRAINT fk_orders_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_orders_user FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    item_key VARCHAR(32) NOT NULL,
    description VARCHAR(190) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price_cents INT UNSIGNED NOT NULL,
    total_cents INT UNSIGNED NOT NULL,
    metadata_json JSON NULL,
    PRIMARY KEY (id),
    INDEX idx_order_items_order (order_id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
