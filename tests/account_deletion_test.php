<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Nairobi');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec(
    "CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        role TEXT NOT NULL,
        account_status TEXT NOT NULL,
        email_verified INTEGER NOT NULL DEFAULT 0,
        failed_login_attempts INTEGER NOT NULL DEFAULT 0,
        locked_until TEXT NULL,
        password_reset_token TEXT NULL,
        password_reset_expires TEXT NULL,
        email_verification_token TEXT NULL,
        last_login_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE refresh_tokens (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        revoked_at TEXT NULL
    );
    CREATE TABLE account_deletion_requests (
        id INTEGER PRIMARY KEY,
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
        id INTEGER PRIMARY KEY,
        owner_id INTEGER NOT NULL,
        managed_by INTEGER NULL,
        title TEXT NOT NULL,
        availability_status TEXT NOT NULL DEFAULT 'available',
        property_status TEXT NOT NULL DEFAULT 'approved'
    );
    CREATE TABLE inquiries (
        id INTEGER PRIMARY KEY,
        property_id INTEGER NOT NULL,
        user_id INTEGER NULL,
        name TEXT NOT NULL,
        email TEXT NULL,
        phone TEXT NULL,
        message TEXT NOT NULL,
        status TEXT DEFAULT 'new',
        created_at DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE sales (
        id INTEGER PRIMARY KEY,
        property_id INTEGER NOT NULL,
        buyer_name TEXT NOT NULL,
        buyer_email TEXT NULL,
        sale_status TEXT NOT NULL DEFAULT 'in_progress',
        created_at DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE installments (
        id INTEGER PRIMARY KEY,
        sale_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        due_date TEXT NOT NULL
    );
    CREATE TABLE audit_logs (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NULL,
        action TEXT NOT NULL,
        resource_type TEXT,
        resource_id INTEGER,
        ip_address TEXT,
        user_agent TEXT,
        details TEXT,
        created_at DEFAULT CURRENT_TIMESTAMP
    );"
);

require_once __DIR__ . '/../app/models/UserModel.php';
require_once __DIR__ . '/../app/security/JWT.php';
require_once __DIR__ . '/../app/security/Middleware.php';
require_once __DIR__ . '/../app/security/RateLimiter.php';

JWT::init(str_repeat('s', 64));

$password = 'Correct-Password-123!';
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$pdo->exec(
    "INSERT INTO users (id, full_name, email, password, role, account_status, email_verified)
     VALUES
     (1, 'Admin One', 'admin@example.com', '$passwordHash', 'admin', 'approved', 1),
     (2, 'Agent Two', 'agent@example.com', '$passwordHash', 'agent', 'approved', 1),
     (3, 'Owner Three', 'owner@example.com', '$passwordHash', 'owner', 'approved', 1),
     (4, 'Buyer Four', 'buy4@example.com', '$passwordHash', 'owner', 'approved', 1),
     (5, 'Admin Two', 'admin2@example.com', '$passwordHash', 'admin', 'pending', 1),
     (6, 'Rejectee Six', 'rejectee@example.com', '$passwordHash', 'agent', 'approved', 1),
     (7, 'Buyer Seven', 'buy7@example.com', '$passwordHash', 'owner', 'approved', 1)",
);

$userModel = new UserModel();

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException("FAILED: $message");
    }
    $assertions++;
};

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'test-runner';

// 1. Wrong password must not verify (the request endpoint confirms the password first).
$record = $userModel->getUserById(2);
$assert(password_verify('Wrong-Password-123!', $record['password']) === false, 'Wrong password must not verify.');
$assert(password_verify($password, $record['password']) === true, 'Correct password must verify.');

// 2. Agent submits a deletion request.
$requestId = $userModel->createDeletionRequest(2, 'No longer need the account', 'agent@example.com', 'Agent Two');
$assert(is_int($requestId) && $requestId > 0, 'Deletion request is created and returned.');
$assert($userModel->hasPendingDeletionRequest(2) === true, 'Pending request is recorded for the user.');
$assert($userModel->getUserById(2)['account_status'] === 'deletion_requested', 'Account moves to deletion_requested.');
$assert(
    (int) $pdo->query("SELECT COUNT(*) FROM refresh_tokens WHERE user_id = 2 AND revoked_at IS NULL")->fetchColumn() === 0,
    'All refresh tokens are revoked on deactivation.'
);
$request = $userModel->getDeletionRequest($requestId);
$assert($request['status'] === 'pending', 'Request starts as pending.');
$assert($request['user_email_snapshot'] === 'agent@example.com', 'Requester email snapshot stored.');
$assert($request['user_full_name_snapshot'] === 'Agent Two', 'Requester name snapshot stored.');

// 3. Duplicate request while one is pending must be rejected.
$assert($userModel->createDeletionRequest(2, 'again', 'agent@example.com', 'Agent Two') === false, 'A second pending deletion request is not allowed.');

// 4. Login is blocked immediately once deactivation begins.
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . JWT::generateAccessToken(2, 'agent@example.com', 'agent');
$assert(Middleware::authenticate() === null, 'A deletion_requested account cannot authenticate (login blocked).');
unset($_SERVER['HTTP_AUTHORIZATION']);

// 5. Agent obligations: owned properties and submitted enquiries are warnings, not blocks.
$pdo->exec(
    "INSERT INTO properties (id, owner_id, title, availability_status) VALUES (10, 2, 'Agent listing', 'available');
     INSERT INTO inquiries (id, property_id, user_id, name, email, phone, message)
      VALUES (100, 10, 2, 'Agent Two', 'agent@example.com', '+254700000000', 'Interested');"
);
$obligations = $userModel->assessDeletionObligations(2, 'agent');
$assert($obligations['records']['owned_properties'] === 1, 'Agent owned property is counted.');
$assert($obligations['records']['submitted_enquiries'] === 1, 'Agent submitted enquiry is counted.');
$assert(count($obligations['blocking']) === 0, 'Agent records are warnings, not blocking obligations.');
$assert(count($obligations['warnings']) === 2, 'Agent warnings are returned.');

// 6. Last administrator cannot be deleted (another authorized admin required).
$obligations = $userModel->assessDeletionObligations(1, 'admin');
$assert($obligations['records']['approved_admins'] === 1, 'Only one approved admin exists.');
$assert(count($obligations['blocking']) === 1, 'Last admin is blocked.');
$assert(strpos($obligations['blocking'][0], 'last approved administrator') !== false, 'Last-admin message is clear.');

// With two admins, the last-admin block is cleared.
$pdo->exec("UPDATE users SET account_status = 'approved' WHERE id = 5");
$obligations = $userModel->assessDeletionObligations(1, 'admin');
$assert(count($obligations['blocking']) === 0, 'With two admins, the last-admin block is cleared.');

// 7. Owner: active listings and unpaid installments block deletion.
$pdo->exec(
    "INSERT INTO properties (id, owner_id, title, availability_status) VALUES (20, 3, 'Owner home', 'available');
     INSERT INTO sales (id, property_id, buyer_name, buyer_email, sale_status) VALUES (200, 20, 'Buyer Person', 'buy4@example.com', 'in_progress');
     INSERT INTO installments (id, sale_id, status, due_date) VALUES (1000, 200, 'pending', '2026-12-01');"
);
$obligations = $userModel->assessDeletionObligations(3, 'owner');
$assert($obligations['records']['active_listings'] === 1, 'Owner active listing is counted.');
$assert($obligations['records']['unpaid_installments'] === 1, 'Owner unpaid installments are counted.');
$assert(count($obligations['blocking']) === 2, 'Active listing and unpaid installments block owner deletion.');

// 8. Buyer obligations via email match: active purchase agreement and unpaid installments block.
$obligations = $userModel->assessDeletionObligations(4, 'owner');
$assert($obligations['records']['active_purchase_agreements'] === 1, 'Buyer active purchase agreement is counted by email.');
$assert($obligations['records']['unpaid_buyer_installments'] === 1, 'Buyer unpaid installments are counted by email.');
$assert(count($obligations['blocking']) === 2, 'Active purchase agreement and unpaid installments block deletion.');

// 9. Approve agent deletion: anonymizes personal data, retains history, records audit trail.
$assert($userModel->approveDeletionRequest($requestId, 1, 'Reviewed and approved') === true, 'Agent deletion request is approved.');
$assert($userModel->getUserById(2)['account_status'] === 'deactivated', 'Account is deactivated.');
$assert($userModel->getUserById(2)['full_name'] === 'Deleted User', 'Full name is anonymized.');
$anonymizedEmail = $userModel->getUserById(2)['email'];
$assert(str_starts_with($anonymizedEmail, 'deleted_user_2_'), 'Email is anonymized and unique.');
$assert($anonymizedEmail !== 'agent@example.com', 'Original email is no longer stored.');
$assert($userModel->getUserById(2)['password_reset_token'] === null, 'Password reset token cleared.');
$request = $userModel->getDeletionRequest($requestId);
$assert($request['status'] === 'approved', 'Request marked approved.');
$assert((int) $request['processed_by'] === 1, 'Processing administrator is recorded.');
$assert($request['processed_at'] !== null, 'Processing timestamp is recorded.');
$assert(
    (int) $pdo->query("SELECT COUNT(*) FROM refresh_tokens WHERE user_id = 2")->fetchColumn() === 0,
    'Refresh tokens are revoked after approval.'
);
$inquiry = $pdo->query("SELECT name, email, phone FROM inquiries WHERE id = 100")->fetch();
$assert(
    $inquiry['name'] === 'Deleted User' && $inquiry['email'] === null && $inquiry['phone'] === null,
    'Enquiry personal data removed while business record is retained.'
);

// 10. Completed sale linked by buyer email: buyer PII anonymized, sale retained.
$pdo->exec("INSERT INTO sales (id, property_id, buyer_name, buyer_email, sale_status) VALUES (300, 20, 'Buyer Seven', 'buy7@example.com', 'completed')");
$requestId7 = $userModel->createDeletionRequest(7, 'All done', 'buy7@example.com', 'Buyer Seven');
$assert(is_int($requestId7), 'User 7 deletion request created.');
$assert($userModel->approveDeletionRequest($requestId7, 1, 'Completed sale, safe to anonymize') === true, 'User 7 deletion approved.');
$sale = $pdo->query("SELECT buyer_name, buyer_email FROM sales WHERE id = 300")->fetch();
$assert(
    $sale['buyer_name'] === 'Deleted User' && $sale['buyer_email'] === null,
    'Buyer personal identifiers anonymized; completed sale retained.'
);
$assert(
    $userModel->getUserById(7)['email'] !== $anonymizedEmail,
    'Anonymized emails are unique per user.'
);

// 11. Reject flow restores the account.
$rejectId = $userModel->createDeletionRequest(6, 'Changing my mind', 'rejectee@example.com', 'Rejectee Six');
$assert(is_int($rejectId), 'Rejectee request created.');
$assert($userModel->getUserById(6)['account_status'] === 'deletion_requested', 'Account is deactivation-pending.');
$assert($userModel->rejectDeletionRequest($rejectId, 1, 'Not today') === true, 'Request is rejected.');
$assert($userModel->getUserById(6)['account_status'] === 'approved', 'Account restored to approved after rejection.');
$assert($userModel->getDeletionRequest($rejectId)['status'] === 'rejected', 'Request marked rejected.');

// 12. Audit trail records the whole workflow.
$auditCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM audit_logs
     WHERE action IN ('account_deletion_requested', 'account_deletion_approved', 'account_deletion_rejected')"
)->fetchColumn();
$assert($auditCount >= 3, 'Audit trail records request, approval and rejection events.');

$assert($userModel->approveDeletionRequest(999, 5, 'ignored') === false, 'Non-existent request is not approved.');

fwrite(STDOUT, "Account deletion checks passed ({$assertions} assertions).\n");
