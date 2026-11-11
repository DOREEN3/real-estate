<?php

if (!isset($pdo)) {
    require_once __DIR__ . "/../../config/database.php";
}

class PropertyVerificationModel
{
    private const ACTIVE_STATUSES = [
        'pending',
        'documents_submitted',
        'under_review',
        'visit_required',
    ];

    public function requestVerification(int $propertyId): int
    {
        global $pdo;

        $pdo->beginTransaction();
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $lock = $pdo->prepare('SELECT id FROM properties WHERE id = :id FOR UPDATE');
                $lock->execute([':id' => $propertyId]);
                if ($lock->fetchColumn() === false) {
                    $pdo->rollBack();
                    return 0;
                }
            }

            $placeholders = implode(',', array_fill(0, count(self::ACTIVE_STATUSES), '?'));
            $existing = $pdo->prepare(
                "SELECT id FROM property_verifications
                 WHERE property_id = ? AND verification_status IN ({$placeholders})
                 ORDER BY id DESC LIMIT 1"
            );
            $existing->execute(array_merge([$propertyId], self::ACTIVE_STATUSES));
            $existingId = $existing->fetchColumn();
            if ($existingId !== false) {
                $pdo->commit();
                return (int) $existingId;
            }

            $insert = $pdo->prepare(
                "INSERT INTO property_verifications (property_id, verification_status)
                 VALUES (:property_id, 'pending')"
            );
            $insert->execute([':property_id' => $propertyId]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();

            return $id;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function listForProperty(int $propertyId): array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT v.id, v.property_id, v.reviewed_by, v.verification_status,
                    v.document_path, v.review_notes, v.reviewed_at, v.created_at,
                    reviewer.full_name AS reviewer_name
             FROM property_verifications v
             LEFT JOIN users reviewer ON reviewer.id = v.reviewed_by
             WHERE v.property_id = :property_id
             ORDER BY v.created_at DESC, v.id DESC"
        );
        $stmt->execute([':property_id' => $propertyId]);

        return $stmt->fetchAll();
    }

    public function getById(int $verificationId): ?array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT v.id, v.property_id, v.reviewed_by, v.verification_status,
                    v.document_path, v.review_notes, v.reviewed_at, v.created_at,
                    p.owner_id, p.title AS property_title, p.address, p.city, p.country,
                    p.property_status, p.availability_status,
                    owner.full_name AS owner_name, reviewer.full_name AS reviewer_name
             FROM property_verifications v
             INNER JOIN properties p ON p.id = v.property_id
             INNER JOIN users owner ON owner.id = p.owner_id
             LEFT JOIN users reviewer ON reviewer.id = v.reviewed_by
             WHERE v.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $verificationId]);
        $verification = $stmt->fetch();

        return $verification ?: null;
    }

    public function listAll(?string $status, int $page, int $limit): array
    {
        global $pdo;

        $where = '';
        $params = [];
        if ($status !== null) {
            $where = ' WHERE v.verification_status = :status';
            $params[':status'] = $status;
        }
        $stmt = $pdo->prepare(
            "SELECT v.id, v.property_id, v.reviewed_by, v.verification_status,
                    v.document_path, v.review_notes, v.reviewed_at, v.created_at,
                    p.title AS property_title, p.owner_id, owner.full_name AS owner_name,
                    reviewer.full_name AS reviewer_name
             FROM property_verifications v
             INNER JOIN properties p ON p.id = v.property_id
             INNER JOIN users owner ON owner.id = p.owner_id
             LEFT JOIN users reviewer ON reviewer.id = v.reviewed_by
             {$where}
             ORDER BY v.created_at DESC, v.id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countAll(?string $status): int
    {
        global $pdo;

        $sql = 'SELECT COUNT(*) FROM property_verifications';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE verification_status = :status';
            $params[':status'] = $status;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function attachDocument(int $verificationId, string $relativePath): bool
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "UPDATE property_verifications
             SET document_path = :document_path, verification_status = 'documents_submitted'
             WHERE id = :id AND document_path IS NULL AND verification_status = 'pending'"
        );
        $stmt->execute([
            ':document_path' => $relativePath,
            ':id' => $verificationId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function review(int $verificationId, int $adminId, string $status, ?string $notes): bool
    {
        global $pdo;

        if (!in_array($status, ['under_review', 'verified', 'rejected', 'visit_required'], true)) {
            throw new InvalidArgumentException('Unsupported verification review status.');
        }

        $stmt = $pdo->prepare(
            "UPDATE property_verifications
             SET verification_status = :status, reviewed_by = :reviewed_by,
                 reviewed_at = CURRENT_TIMESTAMP, review_notes = :review_notes
             WHERE id = :id AND document_path IS NOT NULL
               AND verification_status IN ('documents_submitted', 'under_review', 'visit_required')"
        );
        $stmt->execute([
            ':status' => $status,
            ':reviewed_by' => $adminId,
            ':review_notes' => $notes,
            ':id' => $verificationId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function createVisit(
        int $verificationId,
        int $assignedTo,
        ?string $scheduledAt,
        ?string $notes
    ): int {
        global $pdo;

        $pdo->beginTransaction();
        try {
            $verification = $pdo->prepare(
                "SELECT id, verification_status FROM property_verifications
                 WHERE id = :id AND document_path IS NOT NULL
                   AND verification_status IN ('documents_submitted', 'under_review', 'visit_required')
                 LIMIT 1"
            );
            $verification->execute([':id' => $verificationId]);
            if ($verification->fetch() === false) {
                $pdo->rollBack();
                return 0;
            }

            $insert = $pdo->prepare(
                "INSERT INTO property_verification_visits
                    (verification_id, assigned_to, scheduled_at, visit_status,
                     visit_notes)
                 VALUES (:verification_id, :assigned_to, :scheduled_at, 'scheduled', :visit_notes)"
            );
            $insert->execute([
                ':verification_id' => $verificationId,
                ':assigned_to' => $assignedTo,
                ':scheduled_at' => $scheduledAt,
                ':visit_notes' => $notes,
            ]);
            $visitId = (int) $pdo->lastInsertId();

            $update = $pdo->prepare(
                "UPDATE property_verifications
                 SET verification_status = 'visit_required'
                 WHERE id = :id AND verification_status <> 'visit_required'"
            );
            $update->execute([':id' => $verificationId]);
            $pdo->commit();

            return $visitId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function listVisitsForUser(int $userId, bool $isAdmin, int $page, int $limit): array
    {
        global $pdo;

        $where = $isAdmin ? '' : ' WHERE visit.assigned_to = :user_id';
        $stmt = $pdo->prepare(
            "SELECT visit.id, visit.verification_id, visit.assigned_to,
                    visit.scheduled_at, visit.visit_status, visit.visit_notes,
                    visit.outcome_recorded_by, visit.created_at,
                    assignee.full_name AS assigned_to_name,
                    recorder.full_name AS outcome_recorded_by_name,
                    verification.property_id, verification.verification_status,
                    property.title AS property_title, property.address,
                    property.city, property.country
             FROM property_verification_visits visit
             INNER JOIN property_verifications verification
                 ON verification.id = visit.verification_id
             INNER JOIN properties property ON property.id = verification.property_id
             INNER JOIN users assignee ON assignee.id = visit.assigned_to
             LEFT JOIN users recorder ON recorder.id = visit.outcome_recorded_by
             {$where}
             ORDER BY visit.scheduled_at IS NULL, visit.scheduled_at ASC, visit.id DESC
             LIMIT :limit OFFSET :offset"
        );
        if (!$isAdmin) {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countVisitsForUser(int $userId, bool $isAdmin): int
    {
        global $pdo;

        if ($isAdmin) {
            return (int) $pdo->query('SELECT COUNT(*) FROM property_verification_visits')->fetchColumn();
        }
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM property_verification_visits WHERE assigned_to = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function getVisitById(int $visitId): ?array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT visit.id, visit.verification_id, visit.assigned_to,
                    visit.scheduled_at, visit.visit_status, visit.visit_notes,
                    visit.outcome_recorded_by, visit.created_at,
                    verification.property_id, verification.verification_status,
                    property.owner_id
             FROM property_verification_visits visit
             INNER JOIN property_verifications verification
                 ON verification.id = visit.verification_id
             INNER JOIN properties property ON property.id = verification.property_id
             WHERE visit.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $visitId]);
        $visit = $stmt->fetch();

        return $visit ?: null;
    }

    public function recordVisitOutcome(
        int $visitId,
        int $assignedTo,
        int $recordedBy,
        string $status,
        string $notes
    ): bool {
        global $pdo;

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE property_verification_visits
                 SET visit_status = :status, visit_notes = :notes,
                     outcome_recorded_by = :recorded_by
                 WHERE id = :id AND assigned_to = :assigned_to AND visit_status = 'scheduled'"
            );
            $stmt->execute([
                ':status' => $status,
                ':notes' => $notes,
                ':recorded_by' => $recordedBy,
                ':id' => $visitId,
                ':assigned_to' => $assignedTo,
            ]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                return false;
            }

            if ($status === 'completed') {
                $verification = $pdo->prepare(
                    "UPDATE property_verifications
                     SET verification_status = 'under_review'
                     WHERE id = (
                         SELECT verification_id
                         FROM property_verification_visits
                         WHERE id = :visit_id
                     ) AND verification_status = 'visit_required'"
                );
                $verification->execute([':visit_id' => $visitId]);
            }

            $pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function hasAssignedVisit(int $verificationId, int $userId): bool
    {
        global $pdo;

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM property_verification_visits
             WHERE verification_id = :verification_id AND assigned_to = :user_id'
        );
        $stmt->execute([
            ':verification_id' => $verificationId,
            ':user_id' => $userId,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function isPropertyVerified(int $propertyId): bool
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT verification_status
             FROM property_verifications
             WHERE property_id = :property_id
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([':property_id' => $propertyId]);

        return $stmt->fetchColumn() === 'verified';
    }
}
