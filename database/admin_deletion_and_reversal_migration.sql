-- Migration: Administrative Account Management & Transaction Reversal/Cancellation
-- Supports:
-- 1. Soft-deletion, deactivation, suspension, and property reassignment on users
-- 2. Sale cancellation, draft deletion, and installment cancellation/adjustments
-- 3. Payment reversal, refund, and void tracking (preserving posted ledger history)
-- 4. Tenancy termination and draft deletion
-- 5. Rent charge voiding and corrections
-- 6. Tenant archival

-- 1. Users table: Soft deletion & status reason tracking
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at,
    ADD COLUMN IF NOT EXISTS deleted_by INT NULL DEFAULT NULL AFTER deleted_at,
    ADD COLUMN IF NOT EXISTS status_reason VARCHAR(500) NULL DEFAULT NULL AFTER deleted_by;

ALTER TABLE users
    MODIFY COLUMN account_status ENUM(
        'pending',
        'approved',
        'suspended',
        'rejected',
        'deletion_requested',
        'deactivated'
    ) NOT NULL DEFAULT 'pending';

-- 2. Sales table: Cancellation metadata & status
ALTER TABLE sales
    MODIFY COLUMN sale_status ENUM('in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'in_progress',
    ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMP NULL DEFAULT NULL AFTER created_at,
    ADD COLUMN IF NOT EXISTS cancelled_by INT NULL DEFAULT NULL AFTER cancelled_at,
    ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(500) NULL DEFAULT NULL AFTER cancelled_by;

-- 3. Installments table: Status extension & cancellation
ALTER TABLE installments
    MODIFY COLUMN status ENUM('pending', 'partial', 'paid', 'cancelled') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMP NULL DEFAULT NULL AFTER created_at,
    ADD COLUMN IF NOT EXISTS cancelled_by INT NULL DEFAULT NULL AFTER cancelled_at,
    ADD COLUMN IF NOT EXISTS adjustment_reason VARCHAR(500) NULL DEFAULT NULL AFTER cancelled_by;

-- 4. Payments table: Reversal, refund, and void tracking
ALTER TABLE payments
    MODIFY COLUMN payment_status ENUM('completed', 'pending', 'failed', 'reversed', 'refunded', 'voided') NOT NULL DEFAULT 'completed',
    ADD COLUMN IF NOT EXISTS reversed_at TIMESTAMP NULL DEFAULT NULL AFTER created_at,
    ADD COLUMN IF NOT EXISTS reversed_by INT NULL DEFAULT NULL AFTER reversed_at,
    ADD COLUMN IF NOT EXISTS reversal_reason VARCHAR(500) NULL DEFAULT NULL AFTER reversed_by;

-- 5. Tenancies table: Termination metadata & status
ALTER TABLE tenancies
    MODIFY COLUMN tenancy_status ENUM('active', 'ended', 'terminated') NOT NULL DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS terminated_at TIMESTAMP NULL DEFAULT NULL AFTER created_at,
    ADD COLUMN IF NOT EXISTS terminated_by INT NULL DEFAULT NULL AFTER terminated_at,
    ADD COLUMN IF NOT EXISTS termination_reason VARCHAR(500) NULL DEFAULT NULL AFTER terminated_by;

-- 6. Rent charges table: Void & adjustment status
ALTER TABLE rent_charges
    ADD COLUMN IF NOT EXISTS charge_status ENUM('active', 'voided', 'adjusted') NOT NULL DEFAULT 'active' AFTER due_date,
    ADD COLUMN IF NOT EXISTS voided_at TIMESTAMP NULL DEFAULT NULL AFTER created_at,
    ADD COLUMN IF NOT EXISTS voided_by INT NULL DEFAULT NULL AFTER voided_at,
    ADD COLUMN IF NOT EXISTS void_reason VARCHAR(500) NULL DEFAULT NULL AFTER voided_by;

-- 7. Tenants table: Archival support
ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS status ENUM('active', 'archived') NOT NULL DEFAULT 'active' AFTER national_id,
    ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP NULL DEFAULT NULL AFTER created_at,
    ADD COLUMN IF NOT EXISTS archived_by INT NULL DEFAULT NULL AFTER archived_at,
    ADD COLUMN IF NOT EXISTS archive_reason VARCHAR(500) NULL DEFAULT NULL AFTER archived_by;
