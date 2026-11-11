-- Bank Automated Payment & Callback Migration
CREATE TABLE IF NOT EXISTS bank_callback_inbox (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_reference VARCHAR(100) NOT NULL UNIQUE,
    account_reference VARCHAR(100) DEFAULT NULL,
    amount DECIMAL(15,2) NOT NULL,
    currency VARCHAR(10) DEFAULT 'KES',
    bank_name VARCHAR(100) DEFAULT NULL,
    payment_id INT DEFAULT NULL,
    callback_payload LONGTEXT NOT NULL,
    processing_status ENUM('pending', 'processed', 'failed') DEFAULT 'pending',
    processing_error TEXT DEFAULT NULL,
    received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    INDEX idx_bank_tx_ref (transaction_reference),
    INDEX idx_bank_acc_ref (account_reference),
    INDEX idx_bank_status (processing_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Allow provider/system automated callbacks to record payments without a manual operator
ALTER TABLE payments MODIFY COLUMN recorded_by INT NULL DEFAULT NULL;

-- Support automated receipt issuance from provider/webhook callbacks
ALTER TABLE payment_receipts MODIFY COLUMN issued_by INT NULL DEFAULT NULL;
ALTER TABLE payment_receipts ADD COLUMN IF NOT EXISTS issuer_type VARCHAR(20) NOT NULL DEFAULT 'user';
