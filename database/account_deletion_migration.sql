-- Migration: Account deletion request workflow
-- Additive only: no existing records are deleted or modified by this migration.
--
-- Workflow:
-- 1. User requests deletion with password confirmation -> account_status = 'deletion_requested'
--    (login is blocked immediately; a deletion request record is created).
-- 2. Administrator reviews role-based obligations and approves or rejects.
-- 3. On approval: account_status = 'deactivated', personal data is anonymized,
--    financial/transaction records are retained, audit trail is written.

-- 1. Allow deactivation-related statuses on the users table.
ALTER TABLE users
    MODIFY COLUMN account_status ENUM(
        'pending',
        'approved',
        'suspended',
        'rejected',
        'deletion_requested',
        'deactivated'
    ) NOT NULL DEFAULT 'pending';

-- 2. Deletion request tracking record (one per user at a time).
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

-- 3. Allow inquiries.email to be NULL when personal identifiers are anonymized.
ALTER TABLE inquiries MODIFY COLUMN email VARCHAR(255) NULL;
