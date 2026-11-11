<?php

if (!isset($pdo)) {
    require_once __DIR__ . "/../../config/database.php";
}

class UserModel
{
    public function getUserByEmail($email)
    {
        global $pdo;

        $sql = "SELECT * FROM users WHERE email = :email LIMIT 1";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ":email" => $email
        ]);

        return $stmt->fetch();
    }

    public function getUserById($id)
    {
        global $pdo;

        $sql = "SELECT * FROM users WHERE id = :id LIMIT 1";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ":id" => $id
        ]);

        return $stmt->fetch();
    }

    public function getPublicProfileById(int $id): ?array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT id, full_name, email, role, account_status, email_verified, created_at
             FROM users
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute([":id" => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function getPendingUsers(): array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT id, full_name, email, role, account_status, email_verified, created_at
             FROM users
             WHERE account_status = 'pending'
             AND role IN ('agent', 'owner')
             ORDER BY created_at ASC"
        );

        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function approveUser(int $userId): bool
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "UPDATE users
             SET account_status = 'approved'
             WHERE id = :id
             AND role IN ('agent', 'owner')
             AND account_status = 'pending'"
        );

        $stmt->execute([":id" => $userId]);

        return $stmt->rowCount() === 1;
    }

    public function rejectUser(int $userId): bool
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "UPDATE users
             SET account_status = 'rejected'
             WHERE id = :id
             AND role IN ('agent', 'owner')
             AND account_status = 'pending'"
        );

        $stmt->execute([":id" => $userId]);

        return $stmt->rowCount() === 1;
    }

    public function getAllManagedUsers(): array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT id, full_name, email, role, account_status, email_verified, created_at
             FROM users
             WHERE role IN ('agent', 'owner', 'admin')
             ORDER BY created_at DESC"
        );

        $stmt->execute();
        $users = $stmt->fetchAll();
        if (!$users) {
            return [];
        }

        $userMap = [];
        foreach ($users as &$user) {
            $user['owned_properties'] = [];
            $user['managed_properties'] = [];
            $user['properties'] = [];
            $user['property_counts'] = [
                'total' => 0,
                'owned' => 0,
                'managed' => 0,
                'active_listings' => 0,
                'pending_listings' => 0,
                'verified_owned' => 0,
            ];
            $user['enquiries_count'] = 0;
            $userMap[(int) $user['id']] = &$user;
        }
        unset($user);

        $ids = array_keys($userMap);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $properties = $pdo->prepare(
            "SELECT p.id, p.owner_id, p.managed_by, p.title, p.city, p.transaction_type,
                    p.property_status, p.availability_status, p.created_at,
                    owner.full_name AS owner_name, manager.full_name AS manager_name,
                    verification.verification_status,
                    CASE WHEN verification.document_path IS NOT NULL THEN 1 ELSE 0 END AS document_available,
                    (
                        SELECT pi.image_url
                        FROM property_images pi
                        WHERE pi.property_id = p.id
                        ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
                        LIMIT 1
                    ) AS primary_image_url
             FROM properties p
             JOIN users owner ON owner.id = p.owner_id
             LEFT JOIN users manager ON manager.id = p.managed_by
             LEFT JOIN property_verifications verification
               ON verification.id = (
                   SELECT pv.id FROM property_verifications pv
                   WHERE pv.property_id = p.id
                   ORDER BY pv.created_at DESC, pv.id DESC
                   LIMIT 1
               )
             WHERE p.owner_id IN ({$placeholders}) OR p.managed_by IN ({$placeholders})
             ORDER BY p.created_at DESC, p.id DESC"
        );
        $properties->execute(array_merge($ids, $ids));
        $propertyRows = $properties->fetchAll();
        if (!$propertyRows) {
            return array_values($users);
        }

        $propertyIds = array_column($propertyRows, 'id');
        $propertyPlaceholders = implode(',', array_fill(0, count($propertyIds), '?'));
        $inquiries = $pdo->prepare(
            "SELECT id, property_id, name, email, phone, message, status, created_at
             FROM inquiries
             WHERE property_id IN ({$propertyPlaceholders})
             ORDER BY created_at DESC, id DESC"
        );
        $inquiries->execute($propertyIds);
        $inquiriesByProperty = [];
        foreach ($inquiries->fetchAll() as $inquiry) {
            $inquiriesByProperty[(int) $inquiry['property_id']][] = $inquiry;
        }

        $verifications = $pdo->prepare(
            "SELECT v.id, v.property_id, v.verification_status, v.review_notes,
                    v.reviewed_at, v.created_at,
                    CASE WHEN v.document_path IS NOT NULL THEN 1 ELSE 0 END AS document_available,
                    reviewer.full_name AS reviewer_name
             FROM property_verifications v
             LEFT JOIN users reviewer ON reviewer.id = v.reviewed_by
             WHERE v.property_id IN ({$propertyPlaceholders})
             ORDER BY v.created_at DESC, v.id DESC"
        );
        $verifications->execute($propertyIds);
        $verificationHistory = [];
        foreach ($verifications->fetchAll() as $verification) {
            $verificationHistory[(int) $verification['property_id']][] = $verification;
        }

        foreach ($propertyRows as $property) {
            $propertyId = (int) $property['id'];
            $property['document_available'] = (bool) $property['document_available'];
            $property['enquiries'] = $inquiriesByProperty[$propertyId] ?? [];
            $property['verification_history'] = $verificationHistory[$propertyId] ?? [];
            $relatedUserIds = array_unique(array_filter([
                (int) $property['owner_id'],
                $property['managed_by'] === null ? null : (int) $property['managed_by'],
            ]));

            foreach ($relatedUserIds as $relatedUserId) {
                if (!isset($userMap[$relatedUserId])) {
                    continue;
                }
                $entry = $property;
                $entry['relationship'] = [];
                if ((int) $property['owner_id'] === $relatedUserId) {
                    $entry['relationship'][] = 'owner';
                    $userMap[$relatedUserId]['owned_properties'][] = $entry;
                    $userMap[$relatedUserId]['property_counts']['owned']++;
                    if ($property['verification_status'] === 'verified') {
                        $userMap[$relatedUserId]['property_counts']['verified_owned']++;
                    }
                }
                if ($property['managed_by'] !== null && (int) $property['managed_by'] === $relatedUserId) {
                    $entry['relationship'][] = 'manager';
                    $userMap[$relatedUserId]['managed_properties'][] = $entry;
                    $userMap[$relatedUserId]['property_counts']['managed']++;
                }
                $userMap[$relatedUserId]['properties'][] = $entry;
                $userMap[$relatedUserId]['property_counts']['total']++;
                if (
                    $property['property_status'] === 'approved'
                    && $property['availability_status'] === 'available'
                ) {
                    $userMap[$relatedUserId]['property_counts']['active_listings']++;
                }
                if ($property['property_status'] === 'pending') {
                    $userMap[$relatedUserId]['property_counts']['pending_listings']++;
                }
                $userMap[$relatedUserId]['enquiries_count'] += count($inquiriesByProperty[$propertyId] ?? []);
            }
        }

        return array_values($users);
    }

    public function suspendUser(int $userId, ?int $adminId = null, string $reason = ''): bool
    {
        global $pdo;

        try {
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET account_status = 'suspended',
                     status_reason = :reason
                 WHERE id = :id
                 AND role IN ('agent', 'owner')
                 AND account_status = 'approved'"
            );
            $stmt->execute([
                ":id" => $userId,
                ":reason" => $reason !== '' ? $reason : null,
            ]);
        } catch (Throwable) {
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET account_status = 'suspended'
                 WHERE id = :id
                 AND role IN ('agent', 'owner')
                 AND account_status = 'approved'"
            );
            $stmt->execute([":id" => $userId]);
        }

        if ($stmt->rowCount() === 1) {
            $this->revokeRefreshTokensForUser($userId);
            if ($adminId !== null && $adminId > 0) {
                Middleware::auditLog($adminId, 'user_suspended', 'users', $userId, [
                    'reason' => $reason,
                ]);
            }
            return true;
        }

        return false;
    }

    public function reactivateUser(int $userId, ?int $adminId = null, string $reason = ''): bool
    {
        global $pdo;

        try {
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET account_status = 'approved',
                     status_reason = :reason
                 WHERE id = :id
                 AND role IN ('agent', 'owner')
                 AND account_status IN ('suspended', 'deactivated')"
            );
            $stmt->execute([
                ":id" => $userId,
                ":reason" => $reason !== '' ? $reason : null,
            ]);
        } catch (Throwable) {
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET account_status = 'approved'
                 WHERE id = :id
                 AND role IN ('agent', 'owner')
                 AND account_status IN ('suspended', 'deactivated')"
            );
            $stmt->execute([":id" => $userId]);
        }

        if ($stmt->rowCount() === 1) {
            if ($adminId !== null && $adminId > 0) {
                Middleware::auditLog($adminId, 'user_reactivated', 'users', $userId, [
                    'reason' => $reason,
                ]);
            }
            return true;
        }

        return false;
    }

    public function deactivateUser(int $userId, int $adminId, string $reason): array
    {
        global $pdo;

        $user = $this->getUserById($userId);
        if (!$user) {
            return ['success' => false, 'message' => 'User not found', 'status_code' => 404];
        }

        if ($user['role'] === 'admin') {
            $approvedAdmins = (int) $pdo->query(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND account_status = 'approved'"
            )->fetchColumn();
            if ($approvedAdmins <= 1) {
                return [
                    'success' => false,
                    'message' => 'Cannot deactivate the last approved administrator',
                    'status_code' => 409,
                ];
            }
        }

        if ($user['account_status'] === 'deactivated') {
            return [
                'success' => false,
                'message' => 'Account is already deactivated',
                'status_code' => 409,
            ];
        }

        try {
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET account_status = 'deactivated',
                     status_reason = :reason
                 WHERE id = :id"
            );
            $stmt->execute([
                ':id' => $userId,
                ':reason' => $reason,
            ]);
        } catch (Throwable) {
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET account_status = 'deactivated'
                 WHERE id = :id"
            );
            $stmt->execute([':id' => $userId]);
        }

        $this->revokeRefreshTokensForUser($userId);

        Middleware::auditLog($adminId, 'user_deactivated', 'users', $userId, [
            'reason' => $reason,
            'previous_status' => $user['account_status'],
            'role' => $user['role'],
        ]);

        return ['success' => true, 'message' => 'Account deactivated successfully'];
    }

    public function deleteUserSafely(int $userId, int $adminId, string $reason): array
    {
        global $pdo;

        $user = $this->getUserById($userId);
        if (!$user) {
            return ['success' => false, 'message' => 'User not found', 'status_code' => 404];
        }

        $obligations = $this->assessDeletionObligations($userId, $user['role']);
        if (!empty($obligations['blocking'])) {
            return [
                'success' => false,
                'message' => 'Cannot delete user account due to active obligations',
                'blocking' => $obligations['blocking'],
                'warnings' => $obligations['warnings'],
                'status_code' => 409,
            ];
        }

        $pdo->beginTransaction();
        try {
            $anonymizedEmail = sprintf(
                'deleted_user_%d_%d@deleted.local',
                $userId,
                (int) (microtime(true) * 1000)
            );

            try {
                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET account_status = 'deactivated',
                         full_name = 'Deleted User',
                         email = :email,
                         deleted_at = CURRENT_TIMESTAMP,
                         deleted_by = :deleted_by,
                         status_reason = :reason,
                         password_reset_token = NULL,
                         email_verification_token = NULL
                     WHERE id = :id"
                );
                $stmt->execute([
                    ':email' => $anonymizedEmail,
                    ':deleted_by' => $adminId,
                    ':reason' => $reason,
                    ':id' => $userId,
                ]);
            } catch (Throwable) {
                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET account_status = 'deactivated',
                         full_name = 'Deleted User',
                         email = :email,
                         password_reset_token = NULL,
                         email_verification_token = NULL
                     WHERE id = :id"
                );
                $stmt->execute([
                    ':email' => $anonymizedEmail,
                    ':id' => $userId,
                ]);
            }

            $this->revokeRefreshTokensForUser($userId);

            // Anonymize inquiry personal information
            $pdo->prepare(
                "UPDATE inquiries
                 SET name = 'Deleted User', email = NULL, phone = NULL
                 WHERE user_id = :user_id"
            )->execute([':user_id' => $userId]);

            // Anonymize sales buyer details if email matched
            if (!empty($user['email'])) {
                $pdo->prepare(
                    "UPDATE sales
                     SET buyer_name = 'Deleted User', buyer_email = NULL
                     WHERE buyer_email = :email"
                )->execute([':email' => $user['email']]);
            }

            $pdo->commit();

            Middleware::auditLog($adminId, 'user_account_deleted', 'users', $userId, [
                'reason' => $reason,
                'previous_email' => $user['email'],
                'role' => $user['role'],
            ]);

            return ['success' => true, 'message' => 'User account deleted and personal details anonymized safely'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function transferProperties(
        int $sourceUserId,
        int $targetUserId,
        int $adminId,
        string $reason,
        string $transferType = 'all'
    ): array {
        global $pdo;

        if ($sourceUserId === $targetUserId) {
            return [
                'success' => false,
                'message' => 'Source and target user must be different',
                'status_code' => 400,
            ];
        }

        $sourceUser = $this->getUserById($sourceUserId);
        if (!$sourceUser) {
            return ['success' => false, 'message' => 'Source user not found', 'status_code' => 404];
        }

        $targetUser = $this->getUserById($targetUserId);
        if (!$targetUser) {
            return ['success' => false, 'message' => 'Target user not found', 'status_code' => 404];
        }

        if ($targetUser['account_status'] !== 'approved') {
            return [
                'success' => false,
                'message' => 'Target user account must be approved and active',
                'status_code' => 400,
            ];
        }

        if (!in_array($transferType, ['all', 'owned', 'managed'], true)) {
            return [
                'success' => false,
                'message' => "Invalid transfer_type: must be 'all', 'owned', or 'managed'",
                'status_code' => 400,
            ];
        }

        $pdo->beginTransaction();
        try {
            $ownedTransferred = 0;
            $managedTransferred = 0;

            if ($transferType === 'all' || $transferType === 'owned') {
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM properties WHERE owner_id = :source_id");
                $countStmt->execute([':source_id' => $sourceUserId]);
                $ownedCount = (int) $countStmt->fetchColumn();

                if ($ownedCount > 0) {
                    $updateStmt = $pdo->prepare("UPDATE properties SET owner_id = :target_id WHERE owner_id = :source_id");
                    $updateStmt->execute([
                        ':target_id' => $targetUserId,
                        ':source_id' => $sourceUserId,
                    ]);
                    $ownedTransferred = $ownedCount;
                }
            }

            if ($transferType === 'all' || $transferType === 'managed') {
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM properties WHERE managed_by = :source_id");
                $countStmt->execute([':source_id' => $sourceUserId]);
                $managedCount = (int) $countStmt->fetchColumn();

                if ($managedCount > 0) {
                    $updateStmt = $pdo->prepare("UPDATE properties SET managed_by = :target_id WHERE managed_by = :source_id");
                    $updateStmt->execute([
                        ':target_id' => $targetUserId,
                        ':source_id' => $sourceUserId,
                    ]);
                    $managedTransferred = $managedCount;
                }
            }

            $pdo->commit();

            Middleware::auditLog($adminId, 'properties_transferred', 'users', $sourceUserId, [
                'target_user_id' => $targetUserId,
                'transfer_type' => $transferType,
                'reason' => $reason,
                'owned_properties_transferred' => $ownedTransferred,
                'managed_properties_transferred' => $managedTransferred,
            ]);

            return [
                'success' => true,
                'message' => 'Properties transferred successfully',
                'owned_transferred' => $ownedTransferred,
                'managed_transferred' => $managedTransferred,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function createUser($fullName, $email, $password, $role, $accountStatus)
    {
        global $pdo;

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO users
                (full_name, email, password, role, account_status)
                VALUES
                (:full_name, :email, :password, :role, :account_status)";

        $stmt = $pdo->prepare($sql);

        return $stmt->execute([
            ":full_name" => $fullName,
            ":email" => $email,
            ":password" => $hashedPassword,
            ":role" => $role,
            ":account_status" => $accountStatus
        ]);
    }

    public function hasAdminAccount(): bool
    {
        global $pdo;

        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND account_status = 'approved'"
        );

        return (int) $stmt->fetchColumn() > 0;
    }

    public function createAdmin(string $fullName, string $email, string $password): bool
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "INSERT INTO users (full_name, email, password, role, account_status, email_verified)
             VALUES (:full_name, :email, :password, 'admin', 'approved', 1)"
        );

        return $stmt->execute([
            ":full_name" => $fullName,
            ":email" => $email,
            ":password" => password_hash($password, PASSWORD_DEFAULT),
        ]);
    }

    public function createInitialAdmin(string $fullName, string $email, string $password): bool
    {
        global $pdo;

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $lockName = 'real_estate_initial_admin_setup';
        $lockAcquired = false;
        $transactionStarted = false;

        if ($driver === 'mysql') {
            $lock = $pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
            $lock->execute([':lock_name' => $lockName]);
            if ((int) $lock->fetchColumn() !== 1) {
                throw new RuntimeException('Unable to acquire initial administrator setup lock');
            }
            $lockAcquired = true;
        } elseif ($driver !== 'sqlite') {
            throw new RuntimeException('Unsupported database driver for initial administrator setup');
        }

        try {
            $pdo->beginTransaction();
            $transactionStarted = true;

            if ($this->hasAdminAccount()) {
                throw new DomainException('admin_exists');
            }
            if ($this->getUserByEmail($email)) {
                throw new DomainException('email_exists');
            }
            if (!$this->createAdmin($fullName, $email, $password)) {
                throw new RuntimeException('Unable to create initial administrator account');
            }

            $pdo->commit();
            $transactionStarted = false;
            return true;
        } catch (Throwable $exception) {
            if ($transactionStarted && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } finally {
            if ($lockAcquired) {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $release->execute([':lock_name' => $lockName]);
            }
        }
    }

    public function incrementFailedLogin($userId)
    {
        global $pdo;

        $sql = "UPDATE users SET failed_login_attempts = failed_login_attempts + 1 WHERE id = :id";

        $stmt = $pdo->prepare($sql);

        return $stmt->execute([":id" => $userId]);
    }

    public function resetFailedLogin($userId)
    {
        global $pdo;

        $sql = "UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id";

        $stmt = $pdo->prepare($sql);

        return $stmt->execute([":id" => $userId]);
    }

    public function lockAccount($userId, $lockUntil)
    {
        global $pdo;

        $sql = "UPDATE users SET locked_until = :lock_until WHERE id = :id";

        $stmt = $pdo->prepare($sql);

        return $stmt->execute([
            ":lock_until" => $lockUntil,
            ":id" => $userId
        ]);
    }

    public function updateLastLogin($userId)
    {
        global $pdo;

        $sql = "UPDATE users SET last_login_at = NOW() WHERE id = :id";

        $stmt = $pdo->prepare($sql);

        return $stmt->execute([":id" => $userId]);
    }

    public function storeRefreshToken($userId, $tokenHash, $expiresAt)
    {
        global $pdo;

        $sql = "INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
                VALUES (:user_id, :token_hash, :expires_at)";

        $stmt = $pdo->prepare($sql);

        return $stmt->execute([
            ":user_id" => $userId,
            ":token_hash" => $tokenHash,
            ":expires_at" => $expiresAt
        ]);
    }

    public function getRefreshTokenByHash($tokenHash)
    {
        global $pdo;

        $sql = "SELECT * FROM refresh_tokens WHERE token_hash = :token_hash LIMIT 1";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([":token_hash" => $tokenHash]);

        return $stmt->fetch();
    }

    public function revokeRefreshToken(string $tokenHash, int $userId): bool
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "UPDATE refresh_tokens
             SET revoked_at = CURRENT_TIMESTAMP
             WHERE token_hash = :token_hash AND user_id = :user_id AND revoked_at IS NULL"
        );

        $stmt->execute([
            ":token_hash" => $tokenHash,
            ":user_id" => $userId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function revokeRefreshTokensForUser(int $userId): bool
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "UPDATE refresh_tokens
             SET revoked_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id AND revoked_at IS NULL"
        );

        return $stmt->execute([":user_id" => $userId]);
    }

    public function setPasswordResetToken(int $userId, string $tokenHash, string $expiresAt): bool
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "UPDATE users
             SET password_reset_token = :token_hash, password_reset_expires = :expires_at
             WHERE id = :id"
        );

        return $stmt->execute([
            ":token_hash" => $tokenHash,
            ":expires_at" => $expiresAt,
            ":id" => $userId,
        ]);
    }

    public function clearPasswordResetToken($userId)
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "UPDATE users SET password_reset_token = NULL, password_reset_expires = NULL WHERE id = :id"
        );

        return $stmt->execute([":id" => $userId]);
    }

    public function completePasswordReset(string $tokenHash, string $newPassword): bool
    {
        global $pdo;

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        $pdo->beginTransaction();

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $lockClause = $driver === 'mysql' ? ' FOR UPDATE' : '';
            $lookup = $pdo->prepare(
                "SELECT id FROM users
                 WHERE password_reset_token = :token_hash
                 AND password_reset_expires > CURRENT_TIMESTAMP
                 LIMIT 1" . $lockClause
            );
            $lookup->execute([":token_hash" => $tokenHash]);
            $userId = $lookup->fetchColumn();

            if ($userId === false) {
                $pdo->rollBack();
                return false;
            }

            $stmt = $pdo->prepare(
                "UPDATE users
                 SET password = :password, password_reset_token = NULL, password_reset_expires = NULL
                 WHERE id = :id AND password_reset_token = :token_hash
                 AND password_reset_expires > CURRENT_TIMESTAMP"
            );
            $stmt->execute([
                ":password" => $hashedPassword,
                ":id" => (int) $userId,
                ":token_hash" => $tokenHash,
            ]);

            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                return false;
            }

            $this->revokeRefreshTokensForUser((int) $userId);
            $pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
    // ------------------------------------------------------------------
    // Account deletion request workflow (GoldERP account retirement rules)
    // ------------------------------------------------------------------

    public function createDeletionRequest(
        int $userId,
        string $reason,
        string $emailSnapshot,
        string $fullNameSnapshot
    ): int|bool {
        global $pdo;

        if ($this->hasPendingDeletionRequest($userId)) {
            return false;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO account_deletion_requests
                 (user_id, reason, status, user_email_snapshot, user_full_name_snapshot, requested_at)
                 VALUES
                 (:user_id, :reason, 'pending', :email, :full_name, CURRENT_TIMESTAMP)"
            );
            $stmt->execute([
                ":user_id" => $userId,
                ":reason" => $reason !== '' ? $reason : null,
                ":email" => $emailSnapshot,
                ":full_name" => $fullNameSnapshot,
            ]);

            $requestId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                "UPDATE users
                 SET account_status = 'deletion_requested'
                 WHERE id = :id"
            )->execute([":id" => $userId]);

            $this->revokeRefreshTokensForUser($userId);

            $pdo->commit();
            return $requestId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function hasPendingDeletionRequest(int $userId): bool
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT 1 FROM account_deletion_requests
             WHERE user_id = :user_id AND status = 'pending'
             LIMIT 1"
        );
        $stmt->execute([":user_id" => $userId]);

        return $stmt->fetch() !== false;
    }

    public function getDeletionRequestByUser(int $userId): ?array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT * FROM account_deletion_requests
             WHERE user_id = :user_id
             ORDER BY requested_at DESC
             LIMIT 1"
        );
        $stmt->execute([":user_id" => $userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getDeletionRequest(int $id): ?array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT * FROM account_deletion_requests WHERE id = :id LIMIT 1"
        );
        $stmt->execute([":id" => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getPendingDeletionRequests(): array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT r.id, r.user_id, r.reason, r.status, r.user_email_snapshot,
                    r.user_full_name_snapshot, r.requested_at, r.processed_by,
                    r.processed_at, r.admin_notes, u.role
             FROM account_deletion_requests r
             JOIN users u ON u.id = r.user_id
             WHERE r.status = 'pending'
             ORDER BY r.requested_at ASC"
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }
    /**
     * Assess role-based obligations before an account is deactivated permanently.
     *
     * @return array ['blocking' => string[], 'warnings' => string[], 'records' => array]
     * Blocking items must be resolved by an administrator before approval.
     */
    public function assessDeletionObligations(int $userId, string $role): array
    {
        global $pdo;

        $blocking = [];
        $warnings = [];
        $records = [];

        $userEmailStmt = $pdo->prepare(
            "SELECT email FROM users WHERE id = :id LIMIT 1"
        );
        $userEmailStmt->execute([":id" => $userId]);
        $snapshotEmail = (string) ($userEmailStmt->fetchColumn() ?? '');

        // Agent: assigned properties and enquiries.
        if ($role === 'agent') {
            $ownedProperties = (int) $pdo->query(
                "SELECT COUNT(*) FROM properties WHERE owner_id = $userId"
            )->fetchColumn();
            $submittedEnquiries = (int) $pdo->query(
                "SELECT COUNT(*) FROM inquiries WHERE user_id = $userId"
            )->fetchColumn();

            $records['owned_properties'] = $ownedProperties;
            $records['submitted_enquiries'] = $submittedEnquiries;

            if ($ownedProperties > 0) {
                $warnings[] = "$ownedProperties property record(s) owned by you will be retained as business history under an anonymized profile.";
            }
            if ($submittedEnquiries > 0) {
                $warnings[] = "$submittedEnquiries enquiry record(s) submitted by you will be retained with personal contact details removed.";
            }
        }

        // Owner: property ownership, active listings and outstanding transactions.
        if ($role === 'owner') {
            $ownedProperties = (int) $pdo->query(
                "SELECT COUNT(*) FROM properties WHERE owner_id = $userId"
            )->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM properties
                 WHERE owner_id = :user_id AND availability_status = 'available'"
            );
            $stmt->execute([":user_id" => $userId]);
            $activeListings = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM installments i
                 JOIN sales s ON s.id = i.sale_id
                 JOIN properties p ON p.id = s.property_id
                 WHERE p.owner_id = :user_id AND i.status = 'pending'"
            );
            $stmt->execute([":user_id" => $userId]);
            $unpaidInstallments = (int) $stmt->fetchColumn();

            $records['owned_properties'] = $ownedProperties;
            $records['active_listings'] = $activeListings;
            $records['unpaid_installments'] = $unpaidInstallments;

            if ($ownedProperties > 0) {
                $warnings[] = "$ownedProperties property record(s) owned by you will be retained; management history remains available for recordkeeping.";
            }
            if ($activeListings > 0) {
                $blocking[] = "$activeListings active property listing(s) exist. Resolve or reassign them before the account can be deactivated.";
            }
            if ($unpaidInstallments > 0) {
                $blocking[] = "$unpaidInstallments unpaid installment(s) on sales of your properties must be settled before the account can be deactivated.";
            }
        }

        // Buyer: active purchase agreements and installment balances (matched by email).
        if ($snapshotEmail !== '') {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM sales
                 WHERE buyer_email = :email AND sale_status = 'in_progress'"
            );
            $stmt->execute([":email" => $snapshotEmail]);
            $activePurchases = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM installments i
                 JOIN sales s ON s.id = i.sale_id
                 WHERE s.buyer_email = :email AND i.status = 'pending'"
            );
            $stmt->execute([":email" => $snapshotEmail]);
            $buyerInstallments = (int) $stmt->fetchColumn();

            $records['active_purchase_agreements'] = $activePurchases;
            $records['unpaid_buyer_installments'] = $buyerInstallments;

            if ($activePurchases > 0) {
                $blocking[] = "$activePurchases active purchase agreement(s) are linked to your email. Complete or cancel them before the account can be deactivated.";
            }
            if ($buyerInstallments > 0) {
                $blocking[] = "$buyerInstallments unpaid installment(s) on your purchase agreement(s) must be settled before the account can be deactivated.";
            }
        }

        // Admin: require another authorized administrator; prevent deletion of the last admin.
        if ($role === 'admin') {
            $approvedAdmins = (int) $pdo->query(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND account_status = 'approved'"
            )->fetchColumn();

            $records['approved_admins'] = $approvedAdmins;

            if ($approvedAdmins <= 1) {
                $blocking[] = "You are the last approved administrator. Activate another authorized administrator before this account can be deactivated.";
            }
        }

        // Tenant tenancy records live in a separate tenants/tenancies table and are
        // not linked to user accounts, so they impose no user-account obligation here.

        return [
            "blocking" => $blocking,
            "warnings" => $warnings,
            "records" => $records,
        ];
    }

    public function approveDeletionRequest(
        int $requestId,
        int $processedBy,
        string $adminNotes = ''
    ): bool {
        global $pdo;

        $pdo->beginTransaction();
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $lockClause = $driver === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $pdo->prepare(
                "SELECT * FROM account_deletion_requests
                 WHERE id = :id AND status = 'pending'{$lockClause}"
            );
            $stmt->execute([":id" => $requestId]);
            $request = $stmt->fetch();

            if (!$request) {
                $pdo->rollBack();
                return false;
            }

            $userId = (int) $request['user_id'];

            // Final obligation gate at processing time.
            $user = $this->getUserById($userId);
            if (!$user) {
                $pdo->rollBack();
                return false;
            }
            $obligations = $this->assessDeletionObligations($userId, $user['role']);
            if (!empty($obligations['blocking'])) {
                $pdo->rollBack();
                return false;
            }

            $anonymizedEmail = sprintf(
                'deleted_user_%d_%d@deleted.local',
                $userId,
                (int) (microtime(true) * 1000)
            );

            $stmt = $pdo->prepare(
                "UPDATE users
                 SET account_status = 'deactivated',
                     full_name = 'Deleted User',
                     email = :email,
                     password_reset_token = NULL,
                     email_verification_token = NULL
                 WHERE id = :id"
            );
            $stmt->execute([
                ":email" => $anonymizedEmail,
                ":id" => $userId,
            ]);

            $this->revokeRefreshTokensForUser($userId);

            // Protect personal information in enquiries while preserving the business record.
            $pdo->prepare(
                "UPDATE inquiries
                 SET name = 'Deleted User', email = NULL, phone = NULL
                 WHERE user_id = :user_id"
            )->execute([":user_id" => $userId]);

            // Retain sale and payment history; remove buyer personal identifiers where permitted.
            $pdo->prepare(
                "UPDATE sales
                 SET buyer_name = 'Deleted User', buyer_email = NULL
                 WHERE buyer_email = :email"
            )->execute([":email" => $request['user_email_snapshot']]);

            $stmt = $pdo->prepare(
                "UPDATE account_deletion_requests
                 SET status = 'approved',
                     processed_by = :processed_by,
                     processed_at = CURRENT_TIMESTAMP,
                     admin_notes = :notes
                 WHERE id = :id AND status = 'pending'"
            );
            $stmt->execute([
                ":processed_by" => $processedBy,
                ":notes" => $adminNotes,
                ":id" => $requestId,
            ]);

            $pdo->commit();

            Middleware::auditLog(
                $processedBy,
                'account_deletion_approved',
                'users',
                $userId,
                [
                    'deletion_request_id' => $requestId,
                    'previous_email' => $request['user_email_snapshot'],
                    'notes' => $adminNotes,
                ]
            );

            return true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function rejectDeletionRequest(
        int $requestId,
        int $processedBy,
        string $adminNotes = ''
    ): bool {
        global $pdo;

        $pdo->beginTransaction();
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $lockClause = $driver === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $pdo->prepare(
                "SELECT * FROM account_deletion_requests
                 WHERE id = :id AND status = 'pending'{$lockClause}"
            );
            $stmt->execute([":id" => $requestId]);
            $request = $stmt->fetch();

            if (!$request) {
                $pdo->rollBack();
                return false;
            }

            $userId = (int) $request['user_id'];

            $pdo->prepare(
                "UPDATE users
                 SET account_status = 'approved'
                 WHERE id = :id AND account_status = 'deletion_requested'"
            )->execute([":id" => $userId]);

            $stmt = $pdo->prepare(
                "UPDATE account_deletion_requests
                 SET status = 'rejected',
                     processed_by = :processed_by,
                     processed_at = CURRENT_TIMESTAMP,
                     admin_notes = :notes
                 WHERE id = :id AND status = 'pending'"
            );
            $stmt->execute([
                ":processed_by" => $processedBy,
                ":notes" => $adminNotes,
                ":id" => $requestId,
            ]);

            $pdo->commit();

            Middleware::auditLog(
                $processedBy,
                'account_deletion_rejected',
                'users',
                $userId,
                [
                    'deletion_request_id' => $requestId,
                    'notes' => $adminNotes,
                ]
            );

            return true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }


}
