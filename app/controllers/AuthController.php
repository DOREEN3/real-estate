<?php

require_once __DIR__ . "/../models/UserModel.php";
require_once __DIR__ . "/../helpers/response.php";
require_once __DIR__ . "/../security/JWT.php";
require_once __DIR__ . "/../security/Middleware.php";
require_once __DIR__ . "/../security/Validator.php";
require_once __DIR__ . "/../helpers/PasswordResetMailer.php";

class AuthController
{
    private UserModel $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    public function setupStatus(): void
    {
        sendSuccess("System setup status retrieved", [
            'admin_exists' => $this->userModel->hasAdminAccount(),
        ]);
    }

    public function setupInitialAdmin(): void
    {
        Middleware::checkRateLimit('/api/v1/auth/setup-admin', 5, 300);

        if ($this->userModel->hasAdminAccount()) {
            sendError(
                "Initial administrator account has already been configured. Sign in as an administrator to add more admins.",
                403
            );
        }

        $data = getJsonBody();
        $expectedSetupKey = getenv('ADMIN_SETUP_KEY');
        $providedSetupKey = $data['setup_key'] ?? null;
        if (
            !is_string($expectedSetupKey)
            || strlen($expectedSetupKey) < 32
            || !is_string($providedSetupKey)
            || !hash_equals($expectedSetupKey, $providedSetupKey)
        ) {
            sendError('Invalid or unavailable initial administrator setup key', 403);
        }

        $v = new Validator();

        $fullName = Validator::sanitizeInput($data['full_name'] ?? '');
        $email = Validator::sanitizeInput(strtolower($data['email'] ?? ''));
        $password = $data['password'] ?? '';

        $v->required($fullName, 'full_name');
        $v->length($fullName, 2, 100, 'full_name');
        $v->required($email, 'email');
        $v->email($email, 'email');
        $v->required($password, 'password');
        $v->password($password, 'password');

        if ($v->hasErrors()) {
            sendError("Validation failed", 400, ['errors' => $v->getErrors()]);
        }

        try {
            $this->userModel->createInitialAdmin($fullName, $email, $password);
        } catch (DomainException $exception) {
            if ($exception->getMessage() === 'admin_exists') {
                sendError(
                    "Initial administrator account has already been configured. Sign in as an administrator to add more admins.",
                    403
                );
            }
            if ($exception->getMessage() === 'email_exists') {
                sendError("Email is already registered", 409);
            }
            throw $exception;
        }

        $user = $this->userModel->getUserByEmail($email);
        Middleware::auditLog((int) $user['id'], 'initial_admin_created', 'users', (int) $user['id'], [
            'email' => $email,
        ]);

        sendSuccess("Primary administrator account created! You can now sign in.", [
            'user' => [
                'id' => $user['id'],
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'account_status' => $user['account_status'],
            ],
        ], 201);
    }

    public function register()
    {
        Middleware::checkRateLimit('/api/v1/auth/register', 5, 300);

        $data = getJsonBody();
        $v = new Validator();

        $fullName = Validator::sanitizeInput($data['full_name'] ?? '');
        $email = Validator::sanitizeInput(strtolower($data['email'] ?? ''));
        $password = $data['password'] ?? '';
        $role = $data['role'] ?? '';

        $v->required($fullName, 'full_name');
        $v->length($fullName, 2, 100, 'full_name');
        $v->required($email, 'email');
        $v->email($email, 'email');
        $v->required($password, 'password');
        $v->password($password, 'password');
        $v->required($role, 'role');
        $v->inArray($role, ['agent', 'owner'], 'role');

        if ($v->hasErrors()) {
            sendError("Validation failed", 400, ['errors' => $v->getErrors()]);
        }

        if ($this->userModel->getUserByEmail($email)) {
            Middleware::auditLog(null, 'registration_failed', 'users', null, [
                'reason' => 'email_exists',
                'email' => $email,
            ]);
            sendError("Email is already registered", 409);
        }

        $created = $this->userModel->createUser(
            $fullName,
            $email,
            $password,
            $role,
            'pending'
        );

        if (!$created) {
            Middleware::auditLog(null, 'registration_failed', 'users', null, [
                'reason' => 'db_error',
                'email' => $email,
            ]);
            sendError("Registration failed", 500);
        }

        $user = $this->userModel->getUserByEmail($email);

        Middleware::auditLog($user['id'], 'user_registered', 'users', $user['id'], [
            'role' => $role,
        ]);

        sendSuccess("Registration submitted successfully. Awaiting admin approval.", [
            'user' => [
                'id' => $user['id'],
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'account_status' => $user['account_status'],
            ],
        ], 201);
    }

    public function login()
    {
        Middleware::checkRateLimit('/api/v1/auth/login', 10, 300);

        $data = getJsonBody();
        $v = new Validator();

        $email = Validator::sanitizeInput(strtolower($data['email'] ?? ''));
        $password = $data['password'] ?? '';

        $v->required($email, 'email');
        $v->email($email, 'email');
        $v->required($password, 'password');

        if ($v->hasErrors()) {
            sendError("Validation failed", 400, ['errors' => $v->getErrors()]);
        }

        $user = $this->userModel->getUserByEmail($email);

        if (!$user) {
            sendError("Invalid email or password", 401);
        }

        if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            sendError("Account is temporarily locked", 403);
        }

        if ($user['account_status'] !== 'approved') {
            sendError("Account not approved. Status: " . ucfirst($user['account_status']), 403);
        }

        if (!password_verify($password, $user['password'])) {
            $this->userModel->incrementFailedLogin($user['id']);
            sendError("Invalid email or password", 401);
        }

        $this->userModel->resetFailedLogin($user['id']);
        $this->userModel->updateLastLogin($user['id']);

        $accessToken = JWT::generateAccessToken($user['id'], $user['email'], $user['role']);
        $refreshToken = JWT::generateRefreshToken($user['id']);

        $refreshTokenHash = hash('sha256', $refreshToken);
        $expiresAt = date('Y-m-d H:i:s', time() + 604800);
        if (!$this->userModel->storeRefreshToken($user['id'], $refreshTokenHash, $expiresAt)) {
            sendError("Unable to create a refresh token", 500);
        }

        sendSuccess("Login successful", [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'user' => [
                'id' => $user['id'],
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'role' => $user['role'],
            ],
        ]);
    }

    public function refresh()
    {
        Middleware::checkRateLimit('/api/v1/auth/refresh', 20, 300);

        $data = getJsonBody();
        $refreshToken = $data['refresh_token'] ?? '';

        if (empty($refreshToken)) {
            sendError("Refresh token is required", 400);
        }

        $payload = JWT::decode($refreshToken);

        if (!$payload || !isset($payload['type']) || $payload['type'] !== 'refresh') {
            sendError("Invalid refresh token", 401);
        }

        $userId = (int) $payload['sub'];
        $tokenHash = hash('sha256', $refreshToken);

        $stored = $this->userModel->getRefreshTokenByHash($tokenHash);
        if (
            !$stored
            || (int) $stored['user_id'] !== $userId
            || !empty($stored['revoked_at'])
            || strtotime($stored['expires_at']) <= time()
        ) {
            sendError("Invalid or expired refresh token", 401);
        }

        $user = $this->userModel->getUserById($userId);
        if (!$user || $user['account_status'] !== 'approved') {
            sendError("User not found or not approved", 401);
        }

        if (!$this->userModel->revokeRefreshToken($tokenHash, $userId)) {
            sendError("Refresh token has already been used", 401);
        }

        $accessToken = JWT::generateAccessToken($user['id'], $user['email'], $user['role']);
        $newRefreshToken = JWT::generateRefreshToken($user['id']);
        $newRefreshTokenHash = hash('sha256', $newRefreshToken);
        $expiresAt = date('Y-m-d H:i:s', time() + 604800);
        if (!$this->userModel->storeRefreshToken($user['id'], $newRefreshTokenHash, $expiresAt)) {
            sendError("Unable to create a refresh token", 500);
        }

        sendSuccess("Token refreshed", [
            'access_token' => $accessToken,
            'refresh_token' => $newRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 900,
        ]);
    }

    public function logout()
    {
        $user = Middleware::requireAuth();

        $data = getJsonBody();
        $refreshToken = $data['refresh_token'] ?? '';

        if (!empty($refreshToken)) {
            $tokenHash = hash('sha256', $refreshToken);
            $this->userModel->revokeRefreshToken($tokenHash, (int) $user['sub']);
        }

        sendSuccess("Logged out successfully");
    }

    public function forgotPassword()
    {
        Middleware::checkRateLimit('/api/v1/auth/forgot-password', 3, 3600);

        $data = getJsonBody();
        $v = new Validator();

        $email = Validator::sanitizeInput(strtolower($data['email'] ?? ''));
        $v->required($email, 'email');
        $v->email($email, 'email');

        if ($v->hasErrors()) {
            sendError("Validation failed", 400, ['errors' => $v->getErrors()]);
        }

        try {
            PasswordResetMailer::assertConfigured();
        } catch (RuntimeException $exception) {
            error_log($exception->getMessage());
            sendError("Password reset email is not configured", 503);
        }

        $user = $this->userModel->getUserByEmail($email);
        $devResetUrl = null;
        $mailDelivered = false;
        $mailNotice = null;
        $mailError = null;

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);

            if (!$this->userModel->setPasswordResetToken(
                (int) $user['id'],
                hash('sha256', $token),
                $expires
            )) {
                sendError("Unable to create a password reset request", 500);
            }

            $resetBaseUrl = getenv('PASSWORD_RESET_URL') ?: 'http://localhost:5173/reset-password';
            $separator = strpos($resetBaseUrl, '?') === false ? '?' : '&';
            $devResetUrl = $resetBaseUrl . $separator . 'token=' . rawurlencode($token);

            try {
                $mailResult = PasswordResetMailer::send($email, $token);
                $mailDelivered = !empty($mailResult['success']) && ($mailResult['status'] ?? '') !== 'logged_dev_offline';
                if (!$mailDelivered) {
                    $mailNotice = $mailResult['notice'] ?? ($mailResult['error'] ?? 'Mail dispatch simulated locally (SMTP auth required for live delivery).');
                } else {
                    $mailNotice = "Password reset email dispatched via SMTP to {$email}.";
                }
            } catch (RuntimeException $exception) {
                $mailError = $exception->getMessage();
                // In production, clear unreached reset tokens to prevent stale exposure.
                // In development, preserve token so testing can proceed via direct link.
                if (strtolower((string) (getenv('APP_ENV') ?: 'development')) !== 'development') {
                    if (!$this->userModel->clearPasswordResetToken((int) $user['id'])) {
                        error_log('Unable to clear an undelivered password reset token.');
                    }
                }
                error_log("Password reset mail dispatch notice: " . $exception->getMessage());
            }
        }

        $responseData = [];
        if (strtolower((string) (getenv('APP_ENV') ?: 'development')) === 'development') {
            if ($user && $devResetUrl) {
                $responseData['dev_reset_url'] = $devResetUrl;
                $responseData['mail_status'] = $mailDelivered ? 'delivered' : 'simulated_or_smtp_failed';
                $responseData['mail_notice'] = $mailNotice;
                if ($mailError) {
                    $responseData['mail_error'] = $mailError;
                }
            }
        }

        // Keep the response message standard for security while exposing dev tools when in dev mode
        sendSuccess("If the email exists, a password reset link has been sent", $responseData);
    }

    public function resetPassword()
    {
        Middleware::checkRateLimit('/api/v1/auth/reset-password', 5, 3600);

        $data = getJsonBody();
        $v = new Validator();

        $token = $data['token'] ?? '';
        $password = $data['password'] ?? '';

        $v->required($token, 'token');
        $v->required($password, 'password');
        $v->password($password, 'password');

        if ($v->hasErrors()) {
            sendError("Validation failed", 400, ['errors' => $v->getErrors()]);
        }

        if (!$this->userModel->completePasswordReset(hash('sha256', $token), $password)) {
            sendError("Invalid or expired reset token", 400);
        }

        sendSuccess("Password has been reset successfully");
    }

    public function me()
    {
        $user = Middleware::requireAuth();

        $userData = $this->userModel->getPublicProfileById((int) $user['sub']);
        if (!$userData) {
            sendError("User not found", 404);
        }

        sendSuccess("User profile retrieved", ['user' => $userData]);
    }

    // Request account deletion with password confirmation (authenticated user).
    public function requestAccountDeletion(): void
    {
        $user = Middleware::requireAuth();
        Middleware::checkRateLimit('/api/v1/auth/account/delete-request', 3, 3600);

        $data = getJsonBody();
        $v = new Validator();

        $password = $data['password'] ?? '';
        $reason = Validator::sanitizeInput($data['reason'] ?? '');

        $v->required($password, 'password');

        if ($v->hasErrors()) {
            sendError("Validation failed", 400, ['errors' => $v->getErrors()]);
        }

        $userId = (int) $user['sub'];
        $record = $this->userModel->getUserById($userId);
        if (!$record || $record['account_status'] !== 'approved') {
            sendError(
                "Account deletion is only available for active approved accounts",
                403
            );
        }

        if ($this->userModel->hasPendingDeletionRequest($userId)) {
            sendError(
                "A deletion request for this account is already under review",
                409
            );
        }

        if (!password_verify($password, $record['password'])) {
            $this->userModel->incrementFailedLogin($userId);
            sendError("Incorrect password", 401);
        }

        $requestId = $this->userModel->createDeletionRequest(
            $userId,
            $reason,
            $record['email'],
            $record['full_name']
        );

        if (!$requestId) {
            sendError("Unable to submit deletion request", 500);
        }

        Middleware::auditLog(
            $userId,
            'account_deletion_requested',
            'users',
            $userId,
            [
                'deletion_request_id' => $requestId,
                'role' => $record['role'],
            ]
        );

        $obligations = $this->userModel->assessDeletionObligations(
            $userId,
            $record['role']
        );

        sendSuccess(
            "Deletion request submitted. Your account has been deactivated and login is blocked while an administrator reviews the request.",
            [
                'deletion_request_id' => $requestId,
                'status' => 'pending',
                'obligations' => $obligations,
            ],
            202
        );
    }

    // Current user's deletion request status (if any).
    public function getMyDeletionRequest(): void
    {
        $user = Middleware::requireAuth();

        $request = $this->userModel->getDeletionRequestByUser((int) $user['sub']);

        sendSuccess("Deletion request status retrieved", [
            'deletion_request' => $request,
        ]);
    }
}
