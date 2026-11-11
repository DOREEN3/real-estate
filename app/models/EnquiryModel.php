<?php

declare(strict_types=1);

if (!isset($pdo)) {
    require_once __DIR__ . '/../../config/database.php';
}

class EnquiryModel
{
    private const ENQUIRY_FIELDS = [
        'lead_name',
        'phone',
        'email',
        'enquiry_source',
        'preferred_contact',
        'listing_type',
        'property_type',
        'country',
        'city',
        'area',
        'budget_currency',
        'min_budget',
        'max_budget',
        'message',
    ];

    public function createEnquiry(array $data): int
    {
        global $pdo;

        $pdo->beginTransaction();
        try {
            $columns = self::ENQUIRY_FIELDS;
            $values = [];
            foreach ($columns as $column) {
                $values[$column] = $data[$column] ?? null;
            }

            $names = implode(', ', $columns);
            $placeholders = implode(', ', array_map(
                static fn (string $column): string => ':' . $column,
                $columns
            ));
            $stmt = $pdo->prepare(
                "INSERT INTO enquiries ({$names}) VALUES ({$placeholders})"
            );
            $stmt->execute($values);
            $id = (int) $pdo->lastInsertId();
            if ($id <= 0) {
                throw new RuntimeException('Unable to retrieve the created enquiry ID.');
            }
            $this->refreshMatches($id);
            $pdo->commit();

            return $id;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function getEnquiry(int $id): ?array
    {
        global $pdo;

        $stmt = $pdo->prepare('SELECT * FROM enquiries WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $enquiry = $stmt->fetch();

        return $enquiry ?: null;
    }

    public function listEnquiries(?string $status, ?string $source, int $page, int $limit): array
    {
        global $pdo;

        [$where, $params] = $this->enquiryFilters($status, $source);
        $stmt = $pdo->prepare(
            "SELECT * FROM enquiries {$where}
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset"
        );
        $this->bindFilters($stmt, $params);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countEnquiries(?string $status, ?string $source): int
    {
        global $pdo;

        [$where, $params] = $this->enquiryFilters($status, $source);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM enquiries {$where}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function updateStatus(int $id, string $status): bool
    {
        global $pdo;

        $stmt = $pdo->prepare(
            'UPDATE enquiries SET enquiry_status = :status WHERE id = :id'
        );
        $stmt->execute([':status' => $status, ':id' => $id]);

        return $this->getEnquiry($id) !== null;
    }

    public function addFollowUp(int $enquiryId, int $userId, array $data): int
    {
        global $pdo;

        $stmt = $pdo->prepare(
            'INSERT INTO enquiry_follow_ups
                (enquiry_id, user_id, follow_up_type, follow_up_status, notes, next_follow_up_at)
             VALUES
                (:enquiry_id, :user_id, :follow_up_type, :follow_up_status, :notes, :next_follow_up_at)'
        );
        $stmt->execute([
            ':enquiry_id' => $enquiryId,
            ':user_id' => $userId,
            ':follow_up_type' => $data['follow_up_type'],
            ':follow_up_status' => $data['follow_up_status'],
            ':notes' => $data['notes'],
            ':next_follow_up_at' => $data['next_follow_up_at'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function listFollowUps(int $enquiryId): array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            'SELECT f.id, f.enquiry_id, f.user_id, u.full_name AS user_name,
                    f.follow_up_type, f.follow_up_status, f.notes,
                    f.next_follow_up_at, f.created_at
             FROM enquiry_follow_ups f
             LEFT JOIN users u ON u.id = f.user_id
             WHERE f.enquiry_id = :enquiry_id
             ORDER BY f.created_at DESC, f.id DESC'
        );
        $stmt->execute([':enquiry_id' => $enquiryId]);

        return $stmt->fetchAll();
    }

    public function refreshMatches(int $enquiryId): array
    {
        global $pdo;

        $enquiry = $this->getEnquiry($enquiryId);
        if ($enquiry === null) {
            return [];
        }

        $conditions = [
            "p.property_status = 'approved'",
            "p.availability_status = 'available'",
        ];
        $params = [];
        if ($enquiry['listing_type'] !== null) {
            $listingType = strtolower((string) $enquiry['listing_type']);
            if (in_array($listingType, ['for sale', 'sale'], true)) {
                $conditions[] = "p.transaction_type = 'sale'";
            } elseif (in_array($listingType, ['for rent', 'rent'], true)) {
                $conditions[] = "p.transaction_type = 'rent'";
            } else {
                $this->persistMatches($enquiryId, []);
                return [];
            }
        }
        if ($enquiry['property_type'] !== null) {
            $conditions[] = 'p.property_type = :property_type';
            $params[':property_type'] = strtolower((string) $enquiry['property_type']);
        }
        foreach (['city' => 'city', 'country' => 'country', 'area' => 'address'] as $field => $column) {
            if ($enquiry[$field] !== null && $enquiry[$field] !== '') {
                $conditions[] = "p.{$column} LIKE :{$field}";
                $params[":{$field}"] = '%' . $enquiry[$field] . '%';
            }
        }
        if ($enquiry['budget_currency'] !== null) {
            $conditions[] = 'p.currency = :budget_currency';
            $params[':budget_currency'] = $enquiry['budget_currency'];
        }
        if ($enquiry['min_budget'] !== null) {
            $conditions[] = 'p.price >= :min_budget';
            $params[':min_budget'] = $enquiry['min_budget'];
        }
        if ($enquiry['max_budget'] !== null) {
            $conditions[] = 'p.price <= :max_budget';
            $params[':max_budget'] = $enquiry['max_budget'];
        }

        $sql = 'SELECT p.id AS property_id
                FROM properties p
                WHERE ' . implode(' AND ', $conditions);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $propertyIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

       $this->persistMatches($enquiryId, $propertyIds);

        return $this->listMatches($enquiryId);
    }

    public function listMatches(int $enquiryId): array
    {
        global $pdo;

        $matches = $pdo->prepare(
            "SELECT m.id, m.enquiry_id, m.property_id, m.match_status, m.created_at,
                    p.title, p.property_type, p.transaction_type, p.price, p.currency,
                    p.address, p.city, p.country, p.property_status, p.availability_status
             FROM property_matches m
             INNER JOIN properties p ON p.id = m.property_id
             WHERE m.enquiry_id = :enquiry_id
             ORDER BY m.created_at DESC, m.id DESC"
        );
        $matches->execute([':enquiry_id' => $enquiryId]);

        return $matches->fetchAll();
    }

    public function updateMatchStatus(int $matchId, string $status): bool
    {
        global $pdo;

        $stmt = $pdo->prepare('UPDATE property_matches SET match_status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $matchId]);
        $exists = $pdo->prepare('SELECT id FROM property_matches WHERE id = :id');
        $exists->execute([':id' => $matchId]);

        return $exists->fetchColumn() !== false;
    }

    public function createInquiry(int $propertyId, ?int $userId, array $data): int
    {
        global $pdo;

        $stmt = $pdo->prepare(
            'INSERT INTO inquiries (property_id, user_id, name, email, phone, message)
             VALUES (:property_id, :user_id, :name, :email, :phone, :message)'
        );
        $stmt->execute([
            ':property_id' => $propertyId,
            ':user_id' => $userId,
            ':name' => $data['name'],
            ':email' => $data['email'],
            ':phone' => $data['phone'],
            ':message' => $data['message'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function getInquiry(int $id): ?array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            'SELECT i.*, p.title AS property_title, p.owner_id
             FROM inquiries i
             INNER JOIN properties p ON p.id = i.property_id
             WHERE i.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $inquiry = $stmt->fetch();

        return $inquiry ?: null;
    }

    public function listInquiries(?string $status, ?int $propertyId, int $page, int $limit): array
    {
        global $pdo;

        [$where, $params] = $this->inquiryFilters($status, $propertyId);
        $stmt = $pdo->prepare(
            "SELECT i.id, i.property_id, i.user_id, i.name, i.email, i.phone,
                    i.message, i.status, i.created_at, p.title AS property_title
             FROM inquiries i
             INNER JOIN properties p ON p.id = i.property_id
             {$where}
             ORDER BY i.created_at DESC, i.id DESC
             LIMIT :limit OFFSET :offset"
        );
        $this->bindFilters($stmt, $params);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countInquiries(?string $status, ?int $propertyId): int
    {
        global $pdo;

        [$where, $params] = $this->inquiryFilters($status, $propertyId);
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM inquiries i INNER JOIN properties p ON p.id = i.property_id {$where}"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function updateInquiryStatus(int $id, string $status): bool
    {
        global $pdo;

        $stmt = $pdo->prepare('UPDATE inquiries SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $id]);

        return $this->getInquiry($id) !== null;
    }

    private function enquiryFilters(?string $status, ?string $source): array
    {
        $conditions = [];
        $params = [];
        if ($status !== null) {
            $conditions[] = 'enquiry_status = :status';
            $params[':status'] = $status;
        }
        if ($source !== null) {
            $conditions[] = 'enquiry_source = :source';
            $params[':source'] = $source;
        }

        return [$conditions ? 'WHERE ' . implode(' AND ', $conditions) : '', $params];
    }

    private function inquiryFilters(?string $status, ?int $propertyId): array
    {
        $conditions = [];
        $params = [];
        if ($status !== null) {
            $conditions[] = 'i.status = :status';
            $params[':status'] = $status;
        }
        if ($propertyId !== null) {
            $conditions[] = 'i.property_id = :property_id';
            $params[':property_id'] = $propertyId;
        }

        return [$conditions ? 'WHERE ' . implode(' AND ', $conditions) : '', $params];
    }

    private function bindFilters(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $stmt->bindValue(
                $key,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
    }

    private function persistMatches(int $enquiryId, array $propertyIds): void
{
    global $pdo;

    // Avoid starting a nested transaction when createEnquiry()
    // has already opened one.
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // Remove matches that no longer qualify.
        if (empty($propertyIds)) {
            $delete = $pdo->prepare(
                'DELETE FROM property_matches WHERE enquiry_id = :enquiry_id'
            );
            $delete->execute([':enquiry_id' => $enquiryId]);
        } else {
            $placeholders = [];
            $params = [':enquiry_id' => $enquiryId];

            foreach (array_values($propertyIds) as $index => $propertyId) {
                $placeholder = ':property_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = (int) $propertyId;
            }

            $delete = $pdo->prepare(
                'DELETE FROM property_matches
                 WHERE enquiry_id = :enquiry_id
                 AND property_id NOT IN (' . implode(', ', $placeholders) . ')'
            );
            $delete->execute($params);
        }

        // Insert new matches without duplicating existing ones.
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sql = $driver === 'sqlite'
            ? 'INSERT INTO property_matches (enquiry_id, property_id)
               VALUES (:enquiry_id, :property_id)
               ON CONFLICT(enquiry_id, property_id) DO NOTHING'
            : 'INSERT INTO property_matches (enquiry_id, property_id)
               VALUES (:enquiry_id, :property_id)
               ON DUPLICATE KEY UPDATE id = id';

        $insert = $pdo->prepare($sql);

        foreach ($propertyIds as $propertyId) {
            $insert->execute([
                ':enquiry_id' => $enquiryId,
                ':property_id' => (int) $propertyId,
            ]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}
}
