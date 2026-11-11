-- Real Estate Backend Database Schema
-- Run this in phpMyAdmin or MySQL CLI

CREATE DATABASE IF NOT EXISTS real_estate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE real_estate;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('agent', 'owner', 'admin') NOT NULL DEFAULT 'agent',
    account_status ENUM('pending', 'approved', 'suspended', 'rejected') NOT NULL DEFAULT 'pending',
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    failed_login_attempts INT NOT NULL DEFAULT 0,
    locked_until TIMESTAMP NULL DEFAULT NULL,
    password_reset_token VARCHAR(64) NULL DEFAULT NULL,
    password_reset_expires TIMESTAMP NULL DEFAULT NULL,
    email_verification_token VARCHAR(255) NULL DEFAULT NULL,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_account_status (account_status),
    INDEX idx_password_reset_token (password_reset_token)
) ENGINE=InnoDB;

-- Properties table
CREATE TABLE IF NOT EXISTS properties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
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
    featured TINYINT(1) DEFAULT 0,
    views_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_owner_id (owner_id),
    INDEX idx_property_status (property_status),
    INDEX idx_availability_status (availability_status),
    INDEX idx_city (city),
    INDEX idx_type_transaction (property_type, transaction_type),
    INDEX idx_price (price)
) ENGINE=InnoDB;

-- Property images table
CREATE TABLE IF NOT EXISTS property_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    image_url VARCHAR(500) NOT NULL,
    alt_text VARCHAR(255),
    is_primary TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_property_id (property_id)
) ENGINE=InnoDB;

-- Private property verification documents and review workflow
CREATE TABLE IF NOT EXISTS property_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    reviewed_by INT NULL,
    verification_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    document_path VARCHAR(255) NULL,
    review_notes TEXT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_property_verifications_property (property_id),
    INDEX idx_property_verifications_status_created (verification_status, created_at),
    INDEX idx_property_verifications_reviewer (reviewed_by)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS property_verification_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    verification_id INT NOT NULL,
    assigned_to INT NOT NULL,
    scheduled_at DATETIME NULL,
    visit_status VARCHAR(30) NOT NULL,
    visit_notes TEXT NULL,
    outcome_recorded_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_verification_visits_verification (verification_id),
    INDEX idx_verification_visits_assignee_schedule (assigned_to, scheduled_at),
    INDEX idx_verification_visits_recorder (outcome_recorded_by)
) ENGINE=InnoDB;

-- Property inquiries / messages table
CREATE TABLE IF NOT EXISTS inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    user_id INT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50),
    message TEXT NOT NULL,
    status ENUM('new', 'read', 'replied', 'closed') DEFAULT 'new',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_property_id (property_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Rate limiting log table
CREATE TABLE IF NOT EXISTS rate_limit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    request_count INT NOT NULL DEFAULT 1,
    window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_endpoint (identifier, endpoint),
    INDEX idx_window_start (window_start),
    UNIQUE KEY uq_rate_limit_bucket (identifier, endpoint, window_start)
) ENGINE=InnoDB;

-- Audit log table for security tracking
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(50),
    resource_id INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    details JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

-- Refresh tokens table (for JWT refresh)
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    UNIQUE KEY uq_refresh_token_hash (token_hash)
) ENGINE=InnoDB;

-- The default administrator is provisioned out-of-band with scripts/create-admin.php.

-- Company identity and operating expense records used by branded receipts and annual P&L.
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
