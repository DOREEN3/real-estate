CREATE TABLE IF NOT EXISTS company_profile (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    company_name VARCHAR(150) NOT NULL DEFAULT 'Real Estate Portal',
    logo_url VARCHAR(255) NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_company_profile_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO company_profile (id, company_name)
VALUES (1, 'Real Estate Portal')
ON DUPLICATE KEY UPDATE id = VALUES(id);

CREATE TABLE IF NOT EXISTS expenses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    expense_date DATE NOT NULL,
    category VARCHAR(30) NOT NULL,
    vendor VARCHAR(150) NULL,
    description VARCHAR(500) NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    currency VARCHAR(10) NOT NULL,
    recorded_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_expenses_date_currency (expense_date, currency),
    INDEX idx_expenses_category_date (category, expense_date),
    CONSTRAINT fk_expenses_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_expenses_amount_positive CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
