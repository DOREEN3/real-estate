<?php

declare(strict_types=1);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(
    "CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL,
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
    CREATE TABLE rate_limit_logs (
        id INTEGER PRIMARY KEY,
        identifier TEXT NOT NULL,
        endpoint TEXT NOT NULL,
        request_count INTEGER NOT NULL DEFAULT 1,
        window_start TEXT NOT NULL,
        UNIQUE (identifier, endpoint, window_start)
    );"
);

require_once __DIR__ . '/../app/models/UserModel.php';
require_once __DIR__ . '/../app/security/JWT.php';
require_once __DIR__ . '/../app/security/Middleware.php';
require_once __DIR__ . '/../app/security/RateLimiter.php';
require_once __DIR__ . '/../app/helpers/PasswordResetMailer.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

$pdo->exec(
    "INSERT INTO users (id, full_name, email, password, role, account_status, email_verified)
     VALUES (1, 'Test User', 'user@example.com', 'old-password', 'agent', 'approved', 1)"
);
$userModel = new UserModel();

JWT::init(str_repeat('s', 64));
$accessToken = JWT::generateAccessToken(1, 'stale@example.com', 'admin');
$assert(JWT::decode($accessToken) !== null, 'A correctly signed JWT should decode.');
$assert(JWT::decode(JWT::encode(['sub' => '1'])) === null, 'JWTs without an expiration must be rejected.');
try {
    JWT::init(str_repeat('x', 31));
    $assert(false, 'Weak JWT secrets must be rejected.');
} catch (InvalidArgumentException $exception) {
    $assert(true, 'Weak JWT secrets must be rejected.');
}
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessToken;
$authenticatedUser = Middleware::authenticate();
$assert($authenticatedUser !== null, 'Approved users should authenticate.');
$assert($authenticatedUser['role'] === 'agent', 'Current database role must override stale JWT claims.');
$assert($authenticatedUser['email'] === 'user@example.com', 'Current database email must override stale JWT claims.');

$pdo->exec("UPDATE users SET account_status = 'pending' WHERE id = 1");
$assert(Middleware::authenticate() === null, 'Unapproved accounts must be denied existing access tokens.');
$pdo->exec("UPDATE users SET account_status = 'approved' WHERE id = 1");
$pdo->exec("UPDATE users SET account_status = 'suspended' WHERE id = 1");
$assert(Middleware::authenticate() === null, 'Suspended accounts must lose access immediately.');
$pdo->exec("UPDATE users SET account_status = 'approved' WHERE id = 1");

$profile = $userModel->getPublicProfileById(1);
$assert(
    array_keys($profile) === ['id', 'full_name', 'email', 'role', 'account_status', 'email_verified', 'created_at'],
    '/me must return only the approved profile fields.'
);

$assert(
    $userModel->createInitialAdmin('First Admin', 'admin@example.com', 'StrongPassword!123'),
    'A first administrator should be created when no administrator exists.'
);
$assert($userModel->hasAdminAccount(), 'The first administrator should be persisted as approved.');
try {
    $userModel->createInitialAdmin('Second Admin', 'second-admin@example.com', 'StrongPassword!123');
    $assert(false, 'Initial setup must reject a second administrator.');
} catch (DomainException $exception) {
    $assert(
        $exception->getMessage() === 'admin_exists',
        'Initial setup must report that an administrator already exists.'
    );
}

$resetToken = bin2hex(random_bytes(32));
$resetHash = hash('sha256', $resetToken);
$userModel->setPasswordResetToken(1, $resetHash, date('Y-m-d H:i:s', time() + 3600));
$assert(
    $userModel->getUserById(1)['password_reset_token'] === $resetHash,
    'Only the reset-token hash should be stored.'
);
$pdo->exec(
    "INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
     VALUES (1, 'refresh-before-reset', '2099-01-01 00:00:00')"
);
$assert($userModel->completePasswordReset($resetHash, 'New-Password-123!'), 'A valid reset token should reset the password.');
$updatedUser = $userModel->getUserById(1);
$assert(password_verify('New-Password-123!', $updatedUser['password']), 'The new password should be hashed and usable.');
$assert($updatedUser['password_reset_token'] === null, 'A used reset token must be cleared.');
$assert(
    (int) $pdo->query("SELECT COUNT(*) FROM refresh_tokens WHERE revoked_at IS NOT NULL")->fetchColumn() === 1,
    'Password reset must revoke every refresh token.'
);
$assert(!$userModel->completePasswordReset($resetHash, 'Another-Password-123!'), 'A reset token must be single-use.');

$pdo->exec(
    "INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
     VALUES (1, 'logout-refresh', '2099-01-01 00:00:00')"
);
$assert($userModel->revokeRefreshToken('logout-refresh', 1), 'Logout should revoke the current user refresh token.');
$assert(
    $pdo->query("SELECT revoked_at FROM refresh_tokens WHERE token_hash = 'logout-refresh'")->fetchColumn() !== null,
    'Logout revocation should persist in the database.'
);
$userModel->revokeRefreshToken('logout-refresh', 1);
$assert(
    $pdo->query("SELECT COUNT(*) FROM refresh_tokens WHERE token_hash = 'logout-refresh' AND revoked_at IS NULL")->fetchColumn() == 0,
    'A revoked refresh token must remain revoked.'
);

$rateLimitBucket = date('Y-m-d H:i:s', (int) (floor(time() / 60) * 60));
$seedRateLimit = $pdo->prepare(
    "INSERT INTO rate_limit_logs (identifier, endpoint, request_count, window_start)
     VALUES ('client', '/login', 1, :window_start)"
);
$seedRateLimit->execute([':window_start' => $rateLimitBucket]);
$limiter = new RateLimiter('client', '/login', 2, 60);
$assert($limiter->check(), 'Requests within the rate limit should pass.');
$assert(!(new RateLimiter('client', '/login', 2, 60))->check(), 'Rate limits should persist across limiter instances.');

$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
putenv('TRUSTED_PROXIES');
$assert(RateLimiter::getClientIp() === '203.0.113.8', 'Untrusted forwarding headers must not bypass IP rate limits.');
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
putenv('TRUSTED_PROXIES=10.0.0.1');
$assert(RateLimiter::getClientIp() === '198.51.100.9', 'Configured trusted proxies should provide the client address.');
putenv('TRUSTED_PROXIES');

$assert(
    Middleware::requiredRolesForRoute('PATCH /api/v1/properties/{id}/approve') === ['admin'],
    'Administrative routes must have explicit role requirements.'
);
$financeRoutes = [
    'GET /api/v1/admin/expenses',
    'POST /api/v1/admin/expenses',
    'GET /api/v1/admin/reports/profit-loss',
    'GET /api/v1/admin/company-profile',
    'PUT /api/v1/admin/company-profile',
    'POST /api/v1/admin/company-profile/logo',
];
foreach ($financeRoutes as $route) {
    $assert(
        Middleware::requiredRolesForRoute($route) === ['admin'],
        "Finance and company profile route {$route} must be administrator-only."
    );
}

fwrite(STDOUT, "Security checks passed ({$assertions} assertions).\n");
