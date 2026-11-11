ALTER TABLE tenancies
    ADD COLUMN grace_period_days TINYINT UNSIGNED NOT NULL DEFAULT 0
    AFTER rent_due_day;

ALTER TABLE payments
    ADD COLUMN rent_charge_id INT NULL AFTER installment_id,
    ADD INDEX idx_payments_rent_charge_status (rent_charge_id, payment_status),
    ADD CONSTRAINT fk_payments_rent_charge
        FOREIGN KEY (rent_charge_id) REFERENCES rent_charges(id) ON DELETE SET NULL;

ALTER TABLE rent_payments
    ADD COLUMN payment_id INT NULL AFTER rent_charge_id,
    ADD UNIQUE INDEX uq_rent_payments_payment (payment_id),
    ADD CONSTRAINT fk_rent_payments_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL;

UPDATE payments p
JOIN rent_payments rp
    ON rp.transaction_reference = p.transaction_reference
    OR rp.transaction_reference = CONCAT('RENT-', p.id)
SET p.rent_charge_id = rp.rent_charge_id
WHERE p.tenancy_id IS NOT NULL AND p.payment_type = 'rent' AND p.rent_charge_id IS NULL;

UPDATE rent_payments rp
JOIN payments p
    ON rp.transaction_reference = p.transaction_reference
    OR rp.transaction_reference = CONCAT('RENT-', p.id)
SET rp.payment_id = p.id
WHERE rp.payment_id IS NULL AND p.tenancy_id IS NOT NULL AND p.payment_type = 'rent';

CREATE TABLE IF NOT EXISTS payment_invoices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL UNIQUE,
    installment_id INT NULL UNIQUE,
    rent_charge_id INT NULL UNIQUE,
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    amount_due DECIMAL(15, 2) NOT NULL,
    currency CHAR(3) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_invoice_installment FOREIGN KEY (installment_id) REFERENCES installments(id) ON DELETE CASCADE,
    CONSTRAINT fk_invoice_rent_charge FOREIGN KEY (rent_charge_id) REFERENCES rent_charges(id) ON DELETE CASCADE,
    CONSTRAINT chk_invoice_target CHECK (
        (installment_id IS NOT NULL AND rent_charge_id IS NULL)
        OR (installment_id IS NULL AND rent_charge_id IS NOT NULL)
    ),
    CONSTRAINT chk_invoice_amount_positive CHECK (amount_due > 0),
    INDEX idx_payment_invoices_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_reminder_dispatches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    target_type ENUM('installment', 'rent_charge') NOT NULL,
    target_id INT NOT NULL,
    event_key VARCHAR(32) NOT NULL,
    event_date DATE NOT NULL,
    email_status ENUM('pending', 'sending', 'sent', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
    sms_status ENUM('pending', 'sending', 'sent', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_reminder_event (target_type, target_id, event_key),
    INDEX idx_payment_reminder_event_date (event_date, event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
