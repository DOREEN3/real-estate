-- ==============================================================================
-- GoldERP Real Estate Management System
-- Complete Production Database Schema for TiDB Cloud Serverless / MySQL 8.0+
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('agent', 'owner', 'admin') NOT NULL DEFAULT 'agent',
    account_status ENUM('pending', 'approved', 'suspended', 'rejected', 'deletion_requested', 'deactivated') NOT NULL DEFAULT 'pending',
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    failed_login_attempts INT NOT NULL DEFAULT 0,
    locked_until TIMESTAMP NULL DEFAULT NULL,
    password_reset_token VARCHAR(64) NULL DEFAULT NULL,
    password_reset_expires TIMESTAMP NULL DEFAULT NULL,
    email_verification_token VARCHAR(255) NULL DEFAULT NULL,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    deleted_by INT NULL DEFAULT NULL,
    status_reason VARCHAR(500) NULL DEFAULT NULL,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_account_status (account_status),
    INDEX idx_password_reset_token (password_reset_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Account Deletion Requests
CREATE TABLE IF NOT EXISTS account_deletion_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    reason TEXT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    user_email_snapshot VARCHAR(255) NOT NULL,
    user_full_name_snapshot VARCHAR(100) NOT NULL,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_by INT NULL DEFAULT NULL,
    processed_at TIMESTAMP NULL DEFAULT NULL,
    admin_notes TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_requested_at (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Properties Table
CREATE TABLE IF NOT EXISTS properties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    managed_by INT NULL DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    property_type ENUM('apartment', 'house', 'villa', 'condo', 'townhouse', 'commercial', 'land') NOT NULL,
    transaction_type ENUM('sale', 'rent') NOT NULL,
    price DECIMAL(15, 2) NOT NULL,
    currency VARCHAR(10) DEFAULT 'USD',
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(100),
    country VARCHAR(100),
    postal_code VARCHAR(20),
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    bedrooms INT DEFAULT 0,
    bathrooms INT DEFAULT 0,
    square_feet INT DEFAULT 0,
    property_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    availability_status ENUM('available', 'sold', 'rented', 'reserved', 'occupied', 'unavailable') NOT NULL DEFAULT 'available',
    views_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (managed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_owner (owner_id),
    INDEX idx_managed_by (managed_by),
    INDEX idx_status (property_status),
    INDEX idx_availability (availability_status),
    INDEX idx_type (property_type),
    INDEX idx_transaction (transaction_type),
    INDEX idx_city (city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Property Images
CREATE TABLE IF NOT EXISTS property_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    image_url VARCHAR(500) NOT NULL,
    is_primary TINYINT(1) DEFAULT 0,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_property (property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Property Verifications
CREATE TABLE IF NOT EXISTS property_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    requested_by INT NOT NULL,
    verification_status ENUM('pending', 'in_progress', 'verified', 'rejected') DEFAULT 'pending',
    notes TEXT,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_prop_verify (property_id),
    INDEX idx_prop_verify_status (verification_status),
    INDEX idx_property_verifications_status_created (verification_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Property Verification Documents
CREATE TABLE IF NOT EXISTS property_verification_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    verification_id INT NOT NULL,
    document_type VARCHAR(100) NOT NULL,
    document_url VARCHAR(500) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (verification_id) REFERENCES property_verifications(id) ON DELETE CASCADE,
    INDEX idx_prop_doc (verification_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Property Verification Visits
CREATE TABLE IF NOT EXISTS property_verification_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    verification_id INT NOT NULL,
    assigned_to INT NOT NULL,
    scheduled_at TIMESTAMP NOT NULL,
    status ENUM('scheduled', 'completed', 'cancelled') DEFAULT 'scheduled',
    outcome ENUM('passed', 'failed', 'requires_followup') NULL DEFAULT NULL,
    notes TEXT,
    outcome_recorded_by INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (verification_id) REFERENCES property_verifications(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (outcome_recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_prop_visit (verification_id),
    INDEX idx_verification_visits_assignee_schedule (assigned_to, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Lead Enquiries (CRM Leads)
CREATE TABLE IF NOT EXISTS enquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_name VARCHAR(100) NOT NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    enquiry_source VARCHAR(50) NULL,
    preferred_contact VARCHAR(50) NULL,
    listing_type VARCHAR(50) NULL,
    property_type VARCHAR(50) NULL,
    country VARCHAR(100) NULL,
    city VARCHAR(100) NULL,
    area VARCHAR(100) NULL,
    budget_currency VARCHAR(10) DEFAULT 'KES',
    min_budget DECIMAL(15, 2) NULL,
    max_budget DECIMAL(15, 2) NULL,
    message TEXT NULL,
    enquiry_status VARCHAR(50) NOT NULL DEFAULT 'NEW',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_enquiry_status (enquiry_status),
    INDEX idx_enquiry_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Enquiry Follow-ups
CREATE TABLE IF NOT EXISTS enquiry_follow_ups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enquiry_id INT NOT NULL,
    user_id INT NOT NULL,
    follow_up_type VARCHAR(50) NOT NULL,
    follow_up_status VARCHAR(50) NULL,
    notes TEXT NULL,
    next_follow_up_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (enquiry_id) REFERENCES enquiries(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_followup_enquiry (enquiry_id),
    INDEX idx_followup_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Property Matches
CREATE TABLE IF NOT EXISTS property_matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enquiry_id INT NOT NULL,
    property_id INT NOT NULL,
    match_status VARCHAR(50) NOT NULL DEFAULT 'NEW',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_enquiry_property_match (enquiry_id, property_id),
    FOREIGN KEY (enquiry_id) REFERENCES enquiries(id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Direct Property Inquiries
CREATE TABLE IF NOT EXISTS inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    user_id INT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    message TEXT NOT NULL,
    status ENUM('new', 'contacted', 'resolved', 'closed') DEFAULT 'new',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_inq_property (property_id),
    INDEX idx_inq_user (user_id),
    INDEX idx_inq_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Sales Table
CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    agent_id INT NOT NULL,
    enquiry_id INT NULL,
    buyer_name VARCHAR(100) NOT NULL,
    buyer_email VARCHAR(255) NULL,
    buyer_phone VARCHAR(50) NULL,
    sale_price DECIMAL(15, 2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'KES',
    deposit_amount DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    installment_amount DECIMAL(15, 2) NULL,
    number_of_installments INT NOT NULL DEFAULT 0,
    next_installment_due DATE NULL,
    sale_status ENUM('in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'in_progress',
    cancelled_at TIMESTAMP NULL DEFAULT NULL,
    cancelled_by INT NULL DEFAULT NULL,
    cancellation_reason VARCHAR(500) NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE RESTRICT,
    FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (enquiry_id) REFERENCES enquiries(id) ON DELETE SET NULL,
    FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sale_property (property_id),
    INDEX idx_sale_agent (agent_id),
    INDEX idx_sale_status (sale_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Installments Table
CREATE TABLE IF NOT EXISTS installments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    installment_number INT NOT NULL,
    amount_due DECIMAL(15, 2) NOT NULL,
    paid_amount DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    due_date DATE NOT NULL,
    status ENUM('pending', 'partial', 'paid', 'cancelled') NOT NULL DEFAULT 'pending',
    cancelled_at TIMESTAMP NULL DEFAULT NULL,
    cancelled_by INT NULL DEFAULT NULL,
    adjustment_reason VARCHAR(500) NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sale_installment (sale_id, installment_number),
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_installment_sale (sale_id),
    INDEX idx_installment_status (status),
    INDEX idx_installment_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Tenants Table
CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NOT NULL,
    national_id VARCHAR(50) NULL,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    archived_at TIMESTAMP NULL DEFAULT NULL,
    archived_by INT NULL DEFAULT NULL,
    archive_reason VARCHAR(500) NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_tenant_status (status),
    INDEX idx_tenant_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Tenancies Table
CREATE TABLE IF NOT EXISTS tenancies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    property_id INT NOT NULL,
    monthly_rent DECIMAL(15, 2) NOT NULL,
    security_deposit DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) NOT NULL DEFAULT 'KES',
    rent_due_day INT NOT NULL DEFAULT 1,
    grace_period_days TINYINT UNSIGNED NOT NULL DEFAULT 0,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    tenancy_status ENUM('active', 'ended', 'terminated') NOT NULL DEFAULT 'active',
    terminated_at TIMESTAMP NULL DEFAULT NULL,
    terminated_by INT NULL DEFAULT NULL,
    termination_reason VARCHAR(500) NULL DEFAULT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (terminated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_tenancy_tenant (tenant_id),
    INDEX idx_tenancy_property (property_id),
    INDEX idx_tenancy_status (tenancy_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Rent Charges Table
CREATE TABLE IF NOT EXISTS rent_charges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenancy_id INT NOT NULL,
    rent_month VARCHAR(7) NOT NULL,
    amount_due DECIMAL(15, 2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'KES',
    due_date DATE NOT NULL,
    charge_status ENUM('active', 'voided', 'adjusted') NOT NULL DEFAULT 'active',
    voided_at TIMESTAMP NULL DEFAULT NULL,
    voided_by INT NULL DEFAULT NULL,
    void_reason VARCHAR(500) NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenancy_id) REFERENCES tenancies(id) ON DELETE CASCADE,
    FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_rent_charge_tenancy (tenancy_id),
    INDEX idx_rent_charge_month (rent_month),
    INDEX idx_rent_charge_status (charge_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. BnB Bookings Table
CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    guest_name VARCHAR(150) NOT NULL,
    guest_email VARCHAR(150) NOT NULL,
    guest_phone VARCHAR(30) NOT NULL,
    check_in_date DATE NOT NULL,
    check_out_date DATE NOT NULL,
    nightly_rate DECIMAL(15, 2) NOT NULL,
    number_of_nights INT NOT NULL,
    accommodation_total DECIMAL(15, 2) NOT NULL,
    required_booking_deposit DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    required_damage_deposit DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) NOT NULL DEFAULT 'KES',
    booking_status ENUM('confirmed', 'checked_in', 'checked_out', 'cancelled') NOT NULL DEFAULT 'confirmed',
    special_requests TEXT DEFAULT NULL,
    created_by INT NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_booking_property_dates (property_id, check_in_date, check_out_date),
    INDEX idx_booking_status (booking_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Payments Table (Unified Ledger)
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NULL DEFAULT NULL,
    installment_id INT NULL DEFAULT NULL,
    tenancy_id INT NULL DEFAULT NULL,
    rent_charge_id INT NULL DEFAULT NULL,
    booking_id INT NULL DEFAULT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    payment_type VARCHAR(50) NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    payment_source VARCHAR(50) NOT NULL DEFAULT 'manual',
    payment_status ENUM('completed', 'pending', 'failed', 'reversed', 'refunded', 'voided') NOT NULL DEFAULT 'completed',
    provider VARCHAR(100) NULL DEFAULT NULL,
    provider_reference VARCHAR(150) NULL DEFAULT NULL,
    payer_phone VARCHAR(50) NULL DEFAULT NULL,
    transaction_reference VARCHAR(100) NULL DEFAULT NULL,
    payment_date DATE NOT NULL,
    recorded_by INT NULL DEFAULT NULL,
    reversed_at TIMESTAMP NULL DEFAULT NULL,
    reversed_by INT NULL DEFAULT NULL,
    reversal_reason VARCHAR(500) NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    FOREIGN KEY (installment_id) REFERENCES installments(id) ON DELETE SET NULL,
    FOREIGN KEY (tenancy_id) REFERENCES tenancies(id) ON DELETE SET NULL,
    FOREIGN KEY (rent_charge_id) REFERENCES rent_charges(id) ON DELETE SET NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reversed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_pay_sale (sale_id),
    INDEX idx_pay_installment (installment_id),
    INDEX idx_pay_tenancy (tenancy_id),
    INDEX idx_pay_rent_charge (rent_charge_id),
    INDEX idx_pay_booking (booking_id),
    INDEX idx_pay_status (payment_status),
    INDEX idx_payments_rent_charge_status (rent_charge_id, payment_status),
    UNIQUE KEY unique_payment_provider_reference (provider, provider_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Rent Payments Link Table
CREATE TABLE IF NOT EXISTS rent_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rent_charge_id INT NOT NULL,
    amount_paid DECIMAL(15, 2) NOT NULL,
    payment_id INT NULL DEFAULT NULL,
    transaction_reference VARCHAR(100) NULL DEFAULT NULL,
    UNIQUE KEY uq_rent_payments_payment (payment_id),
    UNIQUE KEY unique_rent_transaction_reference (transaction_reference),
    FOREIGN KEY (rent_charge_id) REFERENCES rent_charges(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. Payment Receipts
CREATE TABLE IF NOT EXISTS payment_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL UNIQUE,
    receipt_number VARCHAR(100) NOT NULL UNIQUE,
    issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    issued_by INT NULL DEFAULT NULL,
    issuer_type VARCHAR(20) NOT NULL DEFAULT 'user',
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Payment Invoices
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
    FOREIGN KEY (installment_id) REFERENCES installments(id) ON DELETE CASCADE,
    FOREIGN KEY (rent_charge_id) REFERENCES rent_charges(id) ON DELETE CASCADE,
    INDEX idx_payment_invoices_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. Payment Reminder Dispatches
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

-- 23. Bank Automated Callbacks Inbox
CREATE TABLE IF NOT EXISTS bank_callback_inbox (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_reference VARCHAR(100) NOT NULL UNIQUE,
    account_reference VARCHAR(100) DEFAULT NULL,
    amount DECIMAL(15, 2) NOT NULL,
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

-- 24. Company Profile
CREATE TABLE IF NOT EXISTS company_profile (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    company_name VARCHAR(150) NOT NULL DEFAULT 'GoldERP Real Estate',
    logo_url VARCHAR(255) NULL,
    physical_address VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    phone_number VARCHAR(50) NULL,
    whatsapp_number VARCHAR(50) NULL,
    email VARCHAR(150) NULL,
    kra_pin VARCHAR(50) NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO company_profile (id, company_name)
VALUES (1, 'GoldERP Real Estate')
ON DUPLICATE KEY UPDATE id = VALUES(id);

-- 25. Expenses Table
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
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_expenses_date_currency (expense_date, currency),
    INDEX idx_expenses_category_date (category, expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 26. Rate Limit Logs
CREATE TABLE IF NOT EXISTS rate_limit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(100) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    requests_count INT DEFAULT 1,
    UNIQUE KEY uq_rate_limit_bucket (identifier, endpoint, window_start),
    INDEX idx_rate_limit (identifier, endpoint, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 27. Audit Logs
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(50),
    resource_id INT,
    details TEXT,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 28. Refresh Tokens
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_refresh_token_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 29. Notification Logs
CREATE TABLE IF NOT EXISTS notification_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_name VARCHAR(150) NULL,
    recipient_contact VARCHAR(255) NOT NULL,
    channel ENUM('email', 'sms', 'whatsapp') NOT NULL,
    notification_type VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    delivery_status ENUM('pending', 'queued', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    provider VARCHAR(100) NULL,
    provider_reference VARCHAR(150) NULL,
    error_message TEXT NULL,
    retry_count INT NOT NULL DEFAULT 0,
    enquiry_id INT NULL,
    property_id INT NULL,
    sale_id INT NULL,
    installment_id INT NULL,
    rent_charge_id INT NULL,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    delivered_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (enquiry_id) REFERENCES enquiries(id) ON DELETE SET NULL,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    FOREIGN KEY (installment_id) REFERENCES installments(id) ON DELETE SET NULL,
    FOREIGN KEY (rent_charge_id) REFERENCES rent_charges(id) ON DELETE SET NULL,
    INDEX idx_notify_channel (channel),
    INDEX idx_notify_status (delivery_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 30. Seed Initial System Administrator
-- Credentials: admin@goldleafvalley.com / AdminPassword123!
INSERT INTO users (full_name, email, password, role, account_status, email_verified)
VALUES (
    'System Administrator',
    'admin@goldleafvalley.com',
    '$2y$10$13uLBq2OB3Xju7u95FGFAuQeskZXNTApQncFFPDnVNKsQnu/xy/ve',
    'admin',
    'approved',
    1
)
ON DUPLICATE KEY UPDATE id = id;

SET FOREIGN_KEY_CHECKS = 1;
