<?php

declare(strict_types=1);

date_default_timezone_set('Africa/Nairobi');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Create test schema with all tables and columns needed
$pdo->exec(
    "CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'agent',
        account_status TEXT NOT NULL DEFAULT 'pending',
        email_verified INTEGER NOT NULL DEFAULT 0,
        failed_login_attempts INTEGER NOT NULL DEFAULT 0,
        locked_until TEXT NULL,
        password_reset_token TEXT NULL,
        password_reset_expires TEXT NULL,
        email_verification_token TEXT NULL,
        last_login_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT NULL,
        deleted_by INTEGER NULL,
        status_reason TEXT NULL
    );
    CREATE TABLE refresh_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        revoked_at TEXT NULL
    );
    CREATE TABLE account_deletion_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        reason TEXT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        user_email_snapshot TEXT NOT NULL,
        user_full_name_snapshot TEXT NOT NULL,
        requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_by INTEGER NULL,
        processed_at TEXT NULL,
        admin_notes TEXT NULL
    );
    CREATE TABLE properties (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_id INTEGER NOT NULL,
        managed_by INTEGER NULL,
        title TEXT NOT NULL,
        description TEXT NULL,
        property_type TEXT NOT NULL DEFAULT 'apartment',
        transaction_type TEXT NOT NULL DEFAULT 'sale',
        price NUMERIC NOT NULL DEFAULT 0,
        currency TEXT NOT NULL DEFAULT 'KES',
        address TEXT NULL,
        property_status TEXT NOT NULL DEFAULT 'approved',
        availability_status TEXT NOT NULL DEFAULT 'available',
        featured INTEGER DEFAULT 0,
        views_count INTEGER DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        property_id INTEGER NOT NULL,
        enquiry_id INTEGER NULL,
        buyer_name TEXT NOT NULL,
        buyer_email TEXT NULL,
        sale_price NUMERIC NOT NULL,
        currency TEXT NOT NULL DEFAULT 'KES',
        deposit_amount NUMERIC NOT NULL DEFAULT 0,
        installment_amount NUMERIC NULL,
        number_of_installments INTEGER NOT NULL DEFAULT 0,
        next_installment_due TEXT NULL,
        sale_status TEXT NOT NULL DEFAULT 'in_progress',
        cancelled_at TEXT NULL,
        cancelled_by INTEGER NULL,
        cancellation_reason TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE installments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        installment_number INTEGER NOT NULL,
        amount_due NUMERIC NOT NULL,
        due_date TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        cancelled_at TEXT NULL,
        cancelled_by INTEGER NULL,
        adjustment_reason TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (sale_id, installment_number)
    );
    CREATE TABLE payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NULL,
        tenancy_id INTEGER NULL,
        booking_id INTEGER NULL,
        amount NUMERIC NOT NULL,
        payment_type TEXT NOT NULL,
        payment_method TEXT NOT NULL,
        payment_source TEXT NOT NULL DEFAULT 'manual',
        payment_status TEXT NOT NULL DEFAULT 'completed',
        provider TEXT NULL,
        provider_reference TEXT NULL,
        payer_phone TEXT NULL,
        transaction_reference TEXT NULL,
        payment_date TEXT NOT NULL,
        recorded_by INTEGER NOT NULL,
        installment_id INTEGER NULL,
        rent_charge_id INTEGER NULL,
        reversed_at TEXT NULL,
        reversed_by INTEGER NULL,
        reversal_reason TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE payment_receipts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        payment_id INTEGER NOT NULL UNIQUE,
        receipt_number TEXT NOT NULL UNIQUE,
        issued_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        issued_by INTEGER NULL,
        issuer_type TEXT NOT NULL DEFAULT 'user'
    );
    CREATE TABLE payment_invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_number TEXT NOT NULL UNIQUE,
        installment_id INTEGER UNIQUE,
        rent_charge_id INTEGER UNIQUE,
        invoice_date TEXT NOT NULL,
        due_date TEXT NOT NULL,
        amount_due NUMERIC NOT NULL,
        currency TEXT NOT NULL
    );
    CREATE TABLE inquiries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        property_id INTEGER NOT NULL,
        user_id INTEGER NULL,
        name TEXT NOT NULL,
        email TEXT NULL,
        phone TEXT NULL,
        message TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'new',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        action TEXT NOT NULL,
        resource_type TEXT NULL,
        resource_id INTEGER NULL,
        ip_address TEXT NULL,
        user_agent TEXT NULL,
        details TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE tenants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        email TEXT NULL,
        phone TEXT NOT NULL,
        national_id TEXT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        archived_at TEXT NULL,
        archived_by INTEGER NULL,
        archive_reason TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE tenancies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenant_id INTEGER NOT NULL,
        property_id INTEGER NOT NULL,
        monthly_rent NUMERIC NOT NULL,
        security_deposit NUMERIC NOT NULL DEFAULT 0,
        currency TEXT NOT NULL DEFAULT 'KES',
        rent_due_day INTEGER NOT NULL,
        grace_period_days INTEGER NOT NULL DEFAULT 0,
        start_date TEXT NOT NULL,
        end_date TEXT NULL,
        tenancy_status TEXT NOT NULL DEFAULT 'active',
        terminated_at TEXT NULL,
        terminated_by INTEGER NULL,
        termination_reason TEXT NULL,
        created_by INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE rent_charges (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenancy_id INTEGER NOT NULL,
        rent_month TEXT NOT NULL,
        amount_due NUMERIC NOT NULL,
        currency TEXT NOT NULL DEFAULT 'KES',
        due_date TEXT NOT NULL,
        charge_status TEXT NOT NULL DEFAULT 'active',
        voided_at TEXT NULL,
        voided_by INTEGER NULL,
        void_reason TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE rent_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        rent_charge_id INTEGER NOT NULL,
        amount_paid NUMERIC NOT NULL,
        payment_id INTEGER NULL
    );
    CREATE TABLE bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        property_id INTEGER NOT NULL,
        guest_name TEXT NOT NULL,
        check_in_date TEXT NOT NULL,
        check_out_date TEXT NOT NULL,
        booking_status TEXT NOT NULL DEFAULT 'confirmed',
        currency TEXT NOT NULL DEFAULT 'KES'
    );
    CREATE TABLE enquiries (id INTEGER PRIMARY KEY AUTOINCREMENT);"
);

require_once __DIR__ . '/../app/helpers/PropertyAvailabilityHelper.php';
require_once __DIR__ . '/../app/security/JWT.php';
require_once __DIR__ . '/../app/security/RateLimiter.php';
require_once __DIR__ . '/../app/security/Middleware.php';
require_once __DIR__ . '/../app/models/UserModel.php';
require_once __DIR__ . '/../app/models/SaleModel.php';
require_once __DIR__ . '/../app/models/PaymentModel.php';
require_once __DIR__ . '/../app/models/RentalModel.php';
require_once __DIR__ . '/../app/controllers/AuditLogController.php';

JWT::init(str_repeat('s', 64));

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

// Seed baseline users
// 1: Admin, 2: Agent A, 3: Agent B, 4: Owner A, 5: Owner B
$passwordHash = password_hash('Secret123!', PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (id, full_name, email, password, role, account_status) VALUES
    (1, 'Super Admin', 'admin@example.com', :pwd, 'admin', 'approved'),
    (2, 'Agent Alice', 'alice@agency.com', :pwd, 'agent', 'approved'),
    (3, 'Agent Bob', 'bob@agency.com', :pwd, 'agent', 'approved'),
    (4, 'Owner Charlie', 'charlie@owner.com', :pwd, 'owner', 'approved'),
    (5, 'Owner David', 'david@owner.com', :pwd, 'owner', 'approved')
")->execute([':pwd' => $passwordHash]);

$userModel = new UserModel();
$saleModel = new SaleModel();
$paymentModel = new PaymentModel();
$rentalModel = new RentalModel();

// =========================================================================
// STAGE 1 TESTS: Admin Account Management
// =========================================================================

// 1. Suspend User
$assert($userModel->suspendUser(2, 1, 'Policy violation investigation') === true, 'Agent Alice should be suspended.');
$suspendedUser = $userModel->getUserById(2);
$assert($suspendedUser['account_status'] === 'suspended', 'User status should be suspended in DB.');

// Audit log check for suspend
$audit = $pdo->query("SELECT * FROM audit_logs WHERE action = 'user_suspended' AND resource_id = 2")->fetch();
$assert($audit !== false && (int)$audit['user_id'] === 1, 'Audit log should record admin 1 suspending user 2.');

// 2. Reactivate User
$assert($userModel->reactivateUser(2, 1, 'Cleared from investigation') === true, 'Agent Alice should be reactivated.');
$reactivatedUser = $userModel->getUserById(2);
$assert($reactivatedUser['account_status'] === 'approved', 'User status should be approved.');
$audit = $pdo->query("SELECT * FROM audit_logs WHERE action = 'user_reactivated' AND resource_id = 2")->fetch();
$assert($audit !== false, 'Audit log should record reactivation.');

// 3. Deactivate User
$deactivateRes = $userModel->deactivateUser(2, 1, 'Agent retired from firm');
$assert($deactivateRes['success'] === true, 'Agent Alice should be deactivated directly.');
$deactivatedUser = $userModel->getUserById(2);
$assert($deactivatedUser['account_status'] === 'deactivated', 'User status should be deactivated.');

// Guard check: cannot deactivate the last approved admin
$lastAdminCheck = $userModel->deactivateUser(1, 1, 'Trying to deactivate only admin');
$assert($lastAdminCheck['success'] === false && $lastAdminCheck['status_code'] === 409, 'Cannot deactivate the last admin.');

// 4. Property Transfer
// Seed properties for Owner 4 and managed by Agent 3
$pdo->prepare("INSERT INTO properties (id, owner_id, managed_by, title, transaction_type, availability_status) VALUES
    (10, 4, 3, 'Villa Sunset', 'sale', 'available'),
    (11, 4, 3, 'Apartment Sunrise', 'rent', 'available')
")->execute();

// Cannot transfer to self
$selfTransfer = $userModel->transferProperties(4, 4, 1, 'Self transfer test');
$assert($selfTransfer['success'] === false, 'Transfer to same user ID must fail.');

// Transfer managed properties from Agent 3 to Agent 2 (reactivate agent 2 first for test)
$userModel->reactivateUser(2, 1, 'Restored for property management');
$transferManaged = $userModel->transferProperties(3, 2, 1, 'Reassigning management', 'managed');
$assert($transferManaged['success'] === true && $transferManaged['managed_transferred'] === 2, 'Should transfer 2 managed properties.');
$prop10 = $pdo->query("SELECT managed_by FROM properties WHERE id = 10")->fetchColumn();
$assert((int)$prop10 === 2, 'Property 10 should now be managed by Agent 2.');

// Transfer owned properties from Owner 4 to Owner 5
$transferOwned = $userModel->transferProperties(4, 5, 1, 'Owner sold portfolio to Owner 5', 'owned');
$assert($transferOwned['success'] === true && $transferOwned['owned_transferred'] === 2, 'Should transfer 2 owned properties.');
$prop10Owner = $pdo->query("SELECT owner_id FROM properties WHERE id = 10")->fetchColumn();
$assert((int)$prop10Owner === 5, 'Property 10 should now be owned by Owner 5.');

// 5. Safe Account Deletion
// Owner 4 has no more active listings or obligations now
$delRes = $userModel->deleteUserSafely(4, 1, 'GDPR right to be forgotten');
$assert($delRes['success'] === true, 'Owner 4 should be safely deleted and anonymized.');
$deletedUser = $userModel->getUserById(4);
$assert($deletedUser['full_name'] === 'Deleted User', 'Full name should be anonymized.');
$assert(str_contains($deletedUser['email'], '@deleted.local'), 'Email should be anonymized to @deleted.local.');

// Deleting Owner 5 who has active listings should be BLOCKED
$blockDel = $userModel->deleteUserSafely(5, 1, 'Attempt delete owner with listings');
$assert($blockDel['success'] === false && !empty($blockDel['blocking']), 'Owner 5 deletion must be blocked due to active listings.');


// =========================================================================
// STAGE 2 TESTS: Transaction Management
// =========================================================================

// 1. Sale Cancellation
// Create a property and sale with installments
$pdo->prepare("INSERT INTO properties (id, owner_id, title, transaction_type, availability_status, property_status)
    VALUES (20, 5, 'Downtown Condo', 'sale', 'available', 'approved')")->execute();

$saleId = $saleModel->create([
    'property_id' => 20,
    'enquiry_id' => null,
    'buyer_name' => 'Buyer Jane',
    'buyer_email' => 'jane@buyer.com',
    'sale_price' => '100000.00',
    'deposit_amount' => '20000.00',
    'number_of_installments' => 2,
    'schedule_start_date' => '2026-12-01',
]);
$assert($saleId > 0, 'Sale should be created.');
$installments = $saleModel->listInstallments($saleId);
$assert(count($installments) === 3, 'Deposit + 2 installments created.');

// Cancel the sale
$cancelRes = $saleModel->cancelSale($saleId, 1, 'Buyer financing fell through');
$assert($cancelRes['success'] === true, 'Sale cancellation should succeed.');

$cancelledSale = $saleModel->getById($saleId);
$assert($cancelledSale['sale_status'] === 'cancelled', 'Sale status should be cancelled.');

$cancelledInsts = $saleModel->listInstallments($saleId);
foreach ($cancelledInsts as $inst) {
    $assert($inst['status'] === 'cancelled', 'Pending installments must be cancelled.');
}

// Property should be available again
$prop20 = $pdo->query("SELECT availability_status FROM properties WHERE id = 20")->fetchColumn();
$assert($prop20 === 'available', 'Property should be available after sale cancellation.');

// 2. Draft Sale Deletion
// Create a new draft sale with 0 payments
$draftSaleId = $saleModel->create([
    'property_id' => 20,
    'enquiry_id' => null,
    'buyer_name' => 'Draft Buyer',
    'buyer_email' => 'draft@buyer.com',
    'sale_price' => '50000.00',
    'deposit_amount' => '0.00',
    'number_of_installments' => 1,
    'schedule_start_date' => '2026-12-01',
]);
$delDraftRes = $saleModel->deleteDraftSale($draftSaleId, 1, 'Created by mistake');
$assert($delDraftRes['success'] === true, 'Draft sale deletion should succeed.');
$assert($saleModel->getById($draftSaleId) === null, 'Draft sale row should be deleted.');

// 3. Payment Reversal, Void, and Refund
// Create another sale and record a completed payment
$saleId3 = $saleModel->create([
    'property_id' => 20,
    'enquiry_id' => null,
    'buyer_name' => 'Mary Wambui',
    'buyer_email' => 'mary@buyer.com',
    'sale_price' => '60000.00',
    'deposit_amount' => '10000.00',
    'number_of_installments' => 1,
    'schedule_start_date' => '2026-12-01',
]);
$sale3Insts = $saleModel->listInstallments($saleId3);
$depositInstId = (int) $sale3Insts[0]['id'];

$paymentId = $paymentModel->recordSalePayment($saleId3, 1, [
    'amount' => '10000.00',
    'payment_type' => 'deposit',
    'payment_method' => 'bank_transfer',
    'payment_source' => 'manual',
    'provider' => null,
    'provider_reference' => null,
    'transaction_reference' => 'TXN-BANK-10000',
    'payment_date' => '2026-11-11 08:00:00',
    'installment_id' => $depositInstId,
]);
$assert($paymentId > 0, 'Payment should be recorded.');

// Check installment is paid
$instBeforeRev = $pdo->query("SELECT status FROM installments WHERE id = $depositInstId")->fetchColumn();
$assert($instBeforeRev === 'paid', 'Installment should be marked paid.');

// Cannot delete draft sale now because completed payment exists
$deleteAttempt = $saleModel->deleteDraftSale($saleId3, 1, 'Try delete sale with payment');
$assert($deleteAttempt['success'] === false && $deleteAttempt['status_code'] === 409, 'Sale with payments cannot be draft-deleted.');

// Reverse the payment
$revRes = $paymentModel->reversePayment($paymentId, 1, 'reverse', 'Mistaken deposit credited to wrong account');
$assert($revRes['success'] === true, 'Payment reversal should succeed.');

$reversedPayment = $paymentModel->getPayment($paymentId);
$assert($reversedPayment['payment_status'] === 'reversed', 'Payment status should be reversed.');

// Installment status must revert to pending
$instAfterRev = $pdo->query("SELECT status FROM installments WHERE id = $depositInstId")->fetchColumn();
$assert($instAfterRev === 'pending', 'Installment status should revert to pending.');

// Sale total_paid and outstanding_balance must NOT count reversed payment
$saleAfterRev = $saleModel->getById($saleId3);
$assert((float)$saleAfterRev['total_paid'] === 0.0, 'Total paid on sale must be 0 after payment reversal.');
$assert((float)$saleAfterRev['outstanding_balance'] === 60000.0, 'Outstanding balance must be full 60000.');

// Test idempotency: cannot reverse an already reversed payment
$revAgain = $paymentModel->reversePayment($paymentId, 1, 'reverse', 'Try reverse again');
$assert($revAgain['success'] === false && $revAgain['status_code'] === 409, 'Cannot reverse already reversed payment.');

// 4. Tenancies: Termination & Draft Deletion
$pdo->prepare("INSERT INTO properties (id, owner_id, title, transaction_type, availability_status, property_status)
    VALUES (30, 5, 'Rental House 30', 'rent', 'available', 'approved')")->execute();

$pdo->prepare("INSERT INTO tenants (id, full_name, email, phone) VALUES (1, 'Tenant Tom', 'tom@tenant.com', '+254700000000')")->execute();

$tenancyId = $rentalModel->createTenancy(1, [
    'tenant_id' => 1,
    'property_id' => 30,
    'monthly_rent' => '25000.00',
    'rent_due_day' => 1,
    'start_date' => '2026-11-01',
    'end_date' => '2027-10-31',
]);
$assert($tenancyId > 0, 'Tenancy should be created.');
$prop30Status = $pdo->query("SELECT availability_status FROM properties WHERE id = 30")->fetchColumn();
$assert($prop30Status === 'rented', 'Property availability should be rented.');

// Terminate tenancy
$termRes = $rentalModel->terminateTenancy($tenancyId, 1, '2026-11-15', 'Tenant breach of contract');
$assert($termRes['success'] === true, 'Tenancy termination should succeed.');
$tenancyRow = $rentalModel->getTenancy($tenancyId);
$assert($tenancyRow['tenancy_status'] === 'terminated', 'Tenancy status should be terminated.');

// Property availability should resync to available
$prop30AfterTerm = $pdo->query("SELECT availability_status FROM properties WHERE id = 30")->fetchColumn();
$assert($prop30AfterTerm === 'available', 'Property availability should resync to available.');

// Draft tenancy deletion
$tenancyId2 = $rentalModel->createTenancy(1, [
    'tenant_id' => 1,
    'property_id' => 30,
    'monthly_rent' => '25000.00',
    'rent_due_day' => 1,
    'start_date' => '2026-12-01',
    'end_date' => '2027-11-30',
]);
$delTenancyRes = $rentalModel->deleteDraftTenancy($tenancyId2, 1, 'Created duplicate draft lease');
$assert($delTenancyRes['success'] === true, 'Draft tenancy should be deleted.');
$assert($rentalModel->getTenancy($tenancyId2) === null, 'Draft tenancy should be removed.');

// 5. Installment Cancellation & Adjustment
$instRes = $saleModel->cancelInstallment($depositInstId, 1, 'Waiving deposit requirement');
$assert($instRes['success'] === true, 'Unpaid installment cancellation should succeed.');
$instStatus = $pdo->query("SELECT status FROM installments WHERE id = $depositInstId")->fetchColumn();
$assert($instStatus === 'cancelled', 'Installment status should be cancelled.');

// Adjust installment
$adjustRes = $saleModel->adjustInstallment($depositInstId, 1, [
    'amount_due' => '15000.00',
    'due_date' => '2026-12-15',
    'reason' => 'Adjusted per agreement',
]);
$assert($adjustRes['success'] === true, 'Installment adjustment should succeed.');
$adjustedInst = $pdo->query("SELECT amount_due, due_date FROM installments WHERE id = $depositInstId")->fetch();
$assert((float)$adjustedInst['amount_due'] === 15000.0 && $adjustedInst['due_date'] === '2026-12-15', 'Installment values should be adjusted.');

// 6. Rent Charge Void & Adjust
$charges = $rentalModel->listRentCharges([], 1, 1);
if (!empty($charges)) {
    $chargeId = (int) $charges[0]['id'];
    $voidRes = $rentalModel->voidRentCharge($chargeId, 1, 'Incorrect charge month');
    $assert($voidRes['success'] === true, 'Rent charge void should succeed.');
    $chargeStatus = $pdo->query("SELECT charge_status FROM rent_charges WHERE id = $chargeId")->fetchColumn();
    $assert($chargeStatus === 'voided', 'Charge status should be voided.');
}

// 7. Tenant Archival
$archRes = $rentalModel->archiveTenant(1, 1, 'Tenant moved out permanently');
$assert($archRes['success'] === true, 'Tenant archival should succeed.');
$tenantStatus = $pdo->query("SELECT status FROM tenants WHERE id = 1")->fetchColumn();
$assert($tenantStatus === 'archived', 'Tenant status should be archived.');

// 8. Audit Logs Query
$_GET = [
    'page' => '1',
    'limit' => '10',
];
$auditController = new AuditLogController();
$logCount = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
$assert($logCount >= 10, 'Audit logs should contain all sensitive admin operations.');

fwrite(STDOUT, "All admin deletion, account management, and transaction reversal checks passed ({$assertions} assertions).\n");
