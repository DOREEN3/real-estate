<?php

require_once __DIR__ . "/../models/UserModel.php";
require_once __DIR__ . "/../helpers/response.php";
require_once __DIR__ . "/../security/Validator.php";
require_once __DIR__ . "/../security/Middleware.php";
require_once __DIR__ . "/../services/EmailService.php";

class UserController
{
    private UserModel $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    // Create an additional administrator account (Admin-only)
    public function createAdminUser(): void
    {
        $data = getJsonBody();
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

        if ($this->userModel->getUserByEmail($email)) {
            sendError("An account with this email is already registered", 409);
        }

        if (!$this->userModel->createAdmin($fullName, $email, $password)) {
            sendError("Unable to create administrator account", 500);
        }

        $created = $this->userModel->getUserByEmail($email);

        sendSuccess("Administrator account created for {$email}", [
            "user" => [
                "id" => $created['id'],
                "full_name" => $created['full_name'],
                "email" => $created['email'],
                "role" => $created['role'],
                "account_status" => $created['account_status'],
            ],
        ], 201);
    }

    // Send a self-registration form link via email to an agent or property owner
    public function inviteUser(): void
    {
        $data = getJsonBody();
        $v = new Validator();

        $email = Validator::sanitizeInput(strtolower($data['email'] ?? ''));
        $role = Validator::sanitizeInput(strtolower($data['role'] ?? 'agent'));
        $fullName = Validator::sanitizeInput($data['full_name'] ?? '');

        $v->required($email, 'email');
        $v->email($email, 'email');
        $v->required($role, 'role');
        $v->inArray($role, ['agent', 'owner'], 'role');

        if ($v->hasErrors()) {
            sendError("Validation failed", 400, ['errors' => $v->getErrors()]);
        }

        if ($this->userModel->getUserByEmail($email)) {
            sendError("An account with this email is already registered", 409);
        }

        try {
            $emailService = new EmailService();
            $result = $emailService->sendRegistrationInvite($email, $role, $fullName);
        } catch (Throwable $e) {
            error_log("Registration invite email failed: " . $e->getMessage());
            sendError("Unable to send registration invite email", 500);
        }

        $mailDelivered = !empty($result['success']) && ($result['status'] ?? '') !== 'logged_dev_offline';
        $mailNotice = $mailDelivered
            ? "Invite email dispatched to {$email}."
            : ($result['notice'] ?? ($result['error'] ?? "Delivery simulated locally. Direct registration link is available."));

        sendSuccess("Registration form generated for {$email}", [
            "email" => $email,
            "role" => $role,
            "registration_url" => $result['registration_url'] ?? null,
            "mail_delivered" => $mailDelivered,
            "mail_notice" => $mailNotice,
        ]);
    }

    // Get all pending agent and owner accounts
    public function getPendingUsers(): void
    {
        $users = $this->userModel->getPendingUsers();

        sendSuccess("Pending users retrieved successfully", [
            "users" => $users
        ]);
    }

    // Approve an agent or owner account
    public function approveUser(string $id): void
    {
        $userId = filter_var($id, FILTER_VALIDATE_INT);

        if ($userId === false || $userId <= 0) {
            sendError("Invalid user ID", 400);
        }

        $user = $this->userModel->getUserById($userId);
        $approved = $this->userModel->approveUser($userId);

        if (!$approved) {
            sendError("User not found or account is not pending", 404);
        }

        if ($user && !empty($user['email'])) {
            try {
                $emailService = new EmailService();
                $emailService->sendAccountVerificationStatus(
                    $user['email'],
                    $user['full_name'] ?? '',
                    $user['role'] ?? 'agent',
                    'approved'
                );
            } catch (Throwable $e) {
                error_log("Approval notification email error: " . $e->getMessage());
            }
        }

        sendSuccess("User account approved successfully");
    }

    // Reject an agent or owner account
    public function rejectUser(string $id): void
    {
        $userId = filter_var($id, FILTER_VALIDATE_INT);

        if ($userId === false || $userId <= 0) {
            sendError("Invalid user ID", 400);
        }

        $user = $this->userModel->getUserById($userId);
        $rejected = $this->userModel->rejectUser($userId);

        if (!$rejected) {
            sendError("User not found or account is not pending", 404);
        }

        if ($user && !empty($user['email'])) {
            try {
                $emailService = new EmailService();
                $emailService->sendAccountVerificationStatus(
                    $user['email'],
                    $user['full_name'] ?? '',
                    $user['role'] ?? 'agent',
                    'rejected'
                );
            } catch (Throwable $e) {
                error_log("Rejection notification email error: " . $e->getMessage());
            }
        }

        sendSuccess("User account rejected successfully");
    }

    // Get all registered agents and owners
    public function getAllUsers(): void
    {
        $users = $this->userModel->getAllManagedUsers();

        sendSuccess("Users retrieved successfully", [
            "users" => $users
        ]);
    }

    // Suspend an approved agent or owner account
    public function suspendUser(string $id): void
    {
        Middleware::requireRole(['admin']);
        $adminUser = Middleware::requireAuth();

        $userId = filter_var($id, FILTER_VALIDATE_INT);
        if ($userId === false || $userId <= 0) {
            sendError("Invalid user ID", 400);
        }

        $body = getJsonBody();
        $reason = trim((string) ($body['reason'] ?? ''));

        $suspended = $this->userModel->suspendUser($userId, (int) $adminUser['sub'], $reason);

        if (!$suspended) {
            sendError("User not found or account is not approved", 404);
        }

        sendSuccess("User account suspended successfully");
    }

    // Reactivate a suspended agent or owner account
    public function reactivateUser(string $id): void
    {
        Middleware::requireRole(['admin']);
        $adminUser = Middleware::requireAuth();

        $userId = filter_var($id, FILTER_VALIDATE_INT);
        if ($userId === false || $userId <= 0) {
            sendError("Invalid user ID", 400);
        }

        $body = getJsonBody();
        $reason = trim((string) ($body['reason'] ?? ''));

        $reactivated = $this->userModel->reactivateUser($userId, (int) $adminUser['sub'], $reason);

        if (!$reactivated) {
            sendError("User not found or account is not suspended", 404);
        }

        sendSuccess("User account reactivated successfully");
    }

    // Deactivate an agent, owner, or admin account directly
    public function deactivateUser(string $id): void
    {
        Middleware::requireRole(['admin']);
        $adminUser = Middleware::requireAuth();

        $userId = filter_var($id, FILTER_VALIDATE_INT);
        if ($userId === false || $userId <= 0) {
            sendError("Invalid user ID", 400);
        }

        $body = getJsonBody();
        $reason = trim((string) ($body['reason'] ?? ''));
        if (strlen($reason) < 5) {
            sendError("A reason of at least 5 characters is required to deactivate an account", 400);
        }

        $result = $this->userModel->deactivateUser($userId, (int) $adminUser['sub'], $reason);

        if (!$result['success']) {
            sendError($result['message'], $result['status_code'] ?? 400);
        }

        sendSuccess($result['message']);
    }

    // Safely delete an account (checks obligations, preserves business history, anonymizes personal details)
    public function deleteUser(string $id): void
    {
        Middleware::requireRole(['admin']);
        $adminUser = Middleware::requireAuth();

        $userId = filter_var($id, FILTER_VALIDATE_INT);
        if ($userId === false || $userId <= 0) {
            sendError("Invalid user ID", 400);
        }

        $body = getJsonBody();
        $reason = trim((string) ($body['reason'] ?? ''));
        if (strlen($reason) < 5) {
            sendError("A reason of at least 5 characters is required for account deletion", 400);
        }

        $result = $this->userModel->deleteUserSafely($userId, (int) $adminUser['sub'], $reason);

        if (!$result['success']) {
            sendError($result['message'], $result['status_code'] ?? 409, [
                'blocking' => $result['blocking'] ?? [],
                'warnings' => $result['warnings'] ?? [],
            ]);
        }

        sendSuccess($result['message']);
    }

    // Transfer property ownership and/or management from one user to another
    public function transferProperties(string $id): void
    {
        Middleware::requireRole(['admin']);
        $adminUser = Middleware::requireAuth();

        $sourceUserId = filter_var($id, FILTER_VALIDATE_INT);
        if ($sourceUserId === false || $sourceUserId <= 0) {
            sendError("Invalid source user ID", 400);
        }

        $body = getJsonBody();
        $targetUserId = filter_var($body['target_user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($targetUserId === false || $targetUserId <= 0) {
            sendError("Valid target_user_id is required", 400);
        }

        $reason = trim((string) ($body['reason'] ?? ''));
        if (strlen($reason) < 5) {
            sendError("A reason of at least 5 characters is required for property transfer", 400);
        }

        $transferType = (string) ($body['transfer_type'] ?? 'all');

        $result = $this->userModel->transferProperties(
            $sourceUserId,
            $targetUserId,
            (int) $adminUser['sub'],
            $reason,
            $transferType
        );

        if (!$result['success']) {
            sendError($result['message'], $result['status_code'] ?? 400);
        }

        sendSuccess($result['message'], [
            'owned_transferred' => $result['owned_transferred'],
            'managed_transferred' => $result['managed_transferred'],
        ]);
    }

    // Get pending account deletion requests for administrator review (Admin-only)
    public function getPendingDeletionRequests(): void
    {
        Middleware::requireRole(['admin']);

        $requests = $this->userModel->getPendingDeletionRequests();

        $enriched = [];
        foreach ($requests as $request) {
            $enriched[] = array_merge(
                $request,
                [
                    'obligations' => $this->userModel->assessDeletionObligations(
                        (int) $request['user_id'],
                        $request['role'] ?? ''
                    ),
                ]
            );
        }

        sendSuccess("Pending deletion requests retrieved", [
            'deletion_requests' => $enriched,
        ]);
    }

    // Approve a pending deletion request (Admin-only).
    // Re-checks role-based obligations, deactivates the account, anonymizes
    // personal data, and retains financial/transaction records.
    public function approveDeletionRequest(string $id): void
    {
        Middleware::requireRole(['admin']);
        $adminUser = Middleware::requireAuth();

        $requestId = filter_var($id, FILTER_VALIDATE_INT);
        if ($requestId === false || $requestId <= 0) {
            sendError("Invalid deletion request ID", 400);
        }

        $request = $this->userModel->getDeletionRequest($requestId);
        if (!$request) {
            sendError("Deletion request not found", 404);
        }
        if ($request['status'] !== 'pending') {
            sendError("Deletion request has already been processed", 409);
        }

        $obligations = $this->userModel->assessDeletionObligations(
            (int) $request['user_id'],
            $request['role'] ?? ''
        );
        if (!empty($obligations['blocking'])) {
            sendError(
                "Deletion cannot be processed while outstanding obligations remain",
                409,
                ['obligations' => $obligations]
            );
        }

        $data = getJsonBody();
        $adminNotes = Validator::sanitizeInput($data['notes'] ?? '');

        if (!$this->userModel->approveDeletionRequest(
            $requestId,
            (int) $adminUser['sub'],
            $adminNotes
        )) {
            sendError("Unable to approve deletion request", 500);
        }

        sendSuccess(
            "Account deactivated. Personal data anonymized; financial and transaction records retained."
        );
    }

    // Reject a pending deletion request and restore the account (Admin-only)
    public function rejectDeletionRequest(string $id): void
    {
        Middleware::requireRole(['admin']);
        $adminUser = Middleware::requireAuth();

        $requestId = filter_var($id, FILTER_VALIDATE_INT);
        if ($requestId === false || $requestId <= 0) {
            sendError("Invalid deletion request ID", 400);
        }

        $request = $this->userModel->getDeletionRequest($requestId);
        if (!$request) {
            sendError("Deletion request not found", 404);
        }
        if ($request['status'] !== 'pending') {
            sendError("Deletion request has already been processed", 409);
        }

        $data = getJsonBody();
        $adminNotes = Validator::sanitizeInput($data['notes'] ?? '');

        if (!$this->userModel->rejectDeletionRequest(
            $requestId,
            (int) $adminUser['sub'],
            $adminNotes
        )) {
            sendError("Unable to reject deletion request", 500);
        }

        sendSuccess("Deletion request rejected; account restored to active status");
    }
}
