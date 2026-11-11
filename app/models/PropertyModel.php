<?php

if (!isset($pdo)) {
    require_once __DIR__ . "/../../config/database.php";
}

class PropertyModel
{
    private const WRITABLE_FIELDS = [
        'title',
        'description',
        'property_type',
        'transaction_type',
        'price',
        'currency',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
        'latitude',
        'longitude',
        'bedrooms',
        'bathrooms',
        'square_feet',
        'availability_status',
        'property_status',
        'featured',
        'managed_by',
        'owner_id',
    ];

    private const PROPERTY_SELECT = "
        SELECT p.*, u.full_name AS owner_name, manager.full_name AS manager_name,
            (SELECT pi.image_url
             FROM property_images pi
             WHERE pi.property_id = p.id
             ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC
             LIMIT 1) AS primary_image_url
        FROM properties p
        INNER JOIN users u ON u.id = p.owner_id
        LEFT JOIN users manager ON manager.id = p.managed_by
    ";

    public function listPublicProperties(array $filters, int $page, int $limit, string $sortBy, string $sortOrder): array
    {
        global $pdo;

        [$where, $params] = $this->buildFilters($filters);
        $sortColumns = [
            'created_at' => 'p.created_at',
            'price' => 'p.price',
            'bedrooms' => 'p.bedrooms',
            'views_count' => 'p.views_count',
        ];
        $orderColumn = $sortColumns[$sortBy] ?? $sortColumns['created_at'];
        $orderDirection = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $sql = self::PROPERTY_SELECT
            . " WHERE p.property_status = 'approved'
                AND p.availability_status = 'available' {$where}
                ORDER BY {$orderColumn} {$orderDirection}, p.featured DESC, p.id DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $this->bindFilters($stmt, $params);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countPublicProperties(array $filters): int
    {
        global $pdo;

        [$where, $params] = $this->buildFilters($filters);
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM properties p
             WHERE p.property_status = 'approved'
               AND p.availability_status = 'available' {$where}"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function listAdminProperties(array $filters, int $page, int $limit, string $sortBy, string $sortOrder): array
    {
        global $pdo;

        [$where, $params] = $this->buildFilters($filters, true);
        $sortColumns = [
            'created_at' => 'p.created_at',
            'updated_at' => 'p.updated_at',
            'price' => 'p.price',
            'views_count' => 'p.views_count',
        ];
        $orderColumn = $sortColumns[$sortBy] ?? $sortColumns['created_at'];
        $orderDirection = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
        $stmt = $pdo->prepare(
            self::PROPERTY_SELECT
            . " WHERE 1 = 1 {$where}
                ORDER BY {$orderColumn} {$orderDirection}, p.id DESC
                LIMIT :limit OFFSET :offset"
        );
        $this->bindFilters($stmt, $params);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countAdminProperties(array $filters): int
    {
        global $pdo;

        [$where, $params] = $this->buildFilters($filters, true);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM properties p WHERE 1 = 1 {$where}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function listPropertiesByOwner(int $ownerId, int $page, int $limit): array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            self::PROPERTY_SELECT
            . " WHERE p.owner_id = :owner_id
                ORDER BY p.created_at DESC, p.id DESC
                LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':owner_id', $ownerId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countPropertiesByOwner(int $ownerId): int
    {
        global $pdo;

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE owner_id = :owner_id');
        $stmt->execute([':owner_id' => $ownerId]);

        return (int) $stmt->fetchColumn();
    }

    public function getPublicPropertyById(int $id): ?array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            self::PROPERTY_SELECT
            . " WHERE p.id = :id AND p.property_status = 'approved'
                AND p.availability_status = 'available' LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $property = $stmt->fetch();

        return $property ?: null;
    }

    public function getPropertyById(int $id): ?array
    {
        global $pdo;

        $stmt = $pdo->prepare(self::PROPERTY_SELECT . ' WHERE p.id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $property = $stmt->fetch();

        return $property ?: null;
    }

    public function getPropertyByOwner(int $id, int $ownerId): ?array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            self::PROPERTY_SELECT . ' WHERE p.id = :id AND p.owner_id = :owner_id LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':owner_id' => $ownerId]);
        $property = $stmt->fetch();

        return $property ?: null;
    }

    public function getPropertyImages(int $propertyId): array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            'SELECT id, property_id, image_url, alt_text, is_primary, sort_order, created_at
             FROM property_images
             WHERE property_id = :property_id
             ORDER BY is_primary DESC, sort_order ASC, id ASC'
        );
        $stmt->execute([':property_id' => $propertyId]);

        return $stmt->fetchAll();
    }

    public function incrementViews(int $id): bool
    {
        global $pdo;

        $stmt = $pdo->prepare('UPDATE properties SET views_count = views_count + 1 WHERE id = :id');

        return $stmt->execute([':id' => $id]);
    }

    public function createProperty(int $ownerId, array $data, ?int $managerId = null): int
    {
        global $pdo;

        $columns = array_keys($data);
        foreach ($columns as $column) {
            if (!in_array($column, self::WRITABLE_FIELDS, true)) {
                throw new InvalidArgumentException('Unsupported property field.');
            }
        }

        $columns = array_merge(['owner_id', 'managed_by'], $columns);
        $placeholders = array_map(static function (string $column): string {
            return ':' . $column;
        }, $columns);
        $values = array_merge(['owner_id' => $ownerId, 'managed_by' => $managerId], $data);
        $sql = 'INSERT INTO properties (' . implode(', ', $columns) . ')
                VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($this->namedParameters($values));

        return (int) $pdo->lastInsertId();
    }

   
public function updateProperty(int $id, array $data, bool $requireReview = false): bool
{
    global $pdo;

    if (!$data) {
        return true;
    }

    foreach (array_keys($data) as $column) {
        if (!in_array($column, self::WRITABLE_FIELDS, true)) {
            throw new InvalidArgumentException('Unsupported property field.');
        }
    }

    $pdo->beginTransaction();

    try {
        // Lock the property while its details and review status are updated.
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $lockClause = $driver === 'mysql' ? ' FOR UPDATE' : '';
        $lock = $pdo->prepare(
            "SELECT property_status FROM properties WHERE id = :id{$lockClause}"
        );
        $lock->execute([':id' => $id]);
        $currentStatus = $lock->fetchColumn();

        if ($currentStatus === false) {
            $pdo->rollBack();
            return false;
        }

        // Availability changes alone do not require a new property review.
        $detailFields = array_diff(
            array_keys($data),
            ['availability_status', 'property_status']
        );

        $needsReview = $requireReview
            && $currentStatus === 'approved'
            && !empty($detailFields);

        if ($needsReview) {
            $data['property_status'] = 'pending';
        }

        $sets = [];
        $params = [':id' => $id];

        foreach ($data as $column => $value) {
            $sets[] = "{$column} = :{$column}";
            $params[":{$column}"] = $value;
        }

        $stmt = $pdo->prepare(
            'UPDATE properties SET ' . implode(', ', $sets) . ' WHERE id = :id'
        );
        $stmt->execute($params);

        if ($needsReview) {
            $activeStatuses = [
                'pending',
                'documents_submitted',
                'under_review',
                'visit_required',
            ];

            $placeholders = implode(
                ', ',
                array_fill(0, count($activeStatuses), '?')
            );

            $existing = $pdo->prepare(
                "SELECT id FROM property_verifications
                 WHERE property_id = ?
                 AND verification_status IN ({$placeholders})
                 LIMIT 1"
            );

            $existing->execute(array_merge([$id], $activeStatuses));

            if ($existing->fetchColumn() === false) {
                $verification = $pdo->prepare(
                    "INSERT INTO property_verifications
                        (property_id, verification_status)
                     VALUES (:property_id, 'pending')"
                );

                $verification->execute([':property_id' => $id]);
            }
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


    public function archiveProperty(int $id): bool
{
    global $pdo;

    $pdo->beginTransaction();

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $lockClause = $driver === 'mysql' ? ' FOR UPDATE' : '';
        $lock = $pdo->prepare(
            "SELECT id, availability_status
             FROM properties
             WHERE id = :id{$lockClause}"
        );

        $lock->execute([':id' => $id]);
        $property = $lock->fetch();

        if (!$property) {
            $pdo->rollBack();
            return false;
        }

        if ($property['availability_status'] === 'unavailable') {
            $pdo->rollBack();
            return false;
        }

        $stmt = $pdo->prepare(
            "UPDATE properties
             SET availability_status = 'unavailable'
             WHERE id = :id"
        );

        $stmt->execute([':id' => $id]);

        $pdo->commit();

        return true;

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

    public function setReviewStatus(int $id, string $status): bool
    {
        global $pdo;

        if (!in_array($status, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Unsupported property review status.');
        }
        $verificationCondition = $status === 'approved'
            ? "AND EXISTS (
                   SELECT 1 FROM property_verifications verification
                   WHERE verification.property_id = properties.id
                  AND verification.id = (
                      SELECT MAX(latest_verification.id)
                      FROM property_verifications latest_verification
                      WHERE latest_verification.property_id = properties.id
                  )
                  AND verification.verification_status = 'verified'
            )"
            : '';
        $stmt = $pdo->prepare(
            "UPDATE properties
             SET property_status = :status
             WHERE id = :id AND property_status = 'pending' {$verificationCondition}"
        );
        $stmt->execute([':status' => $status, ':id' => $id]);

        return $stmt->rowCount() === 1;
    }

    public function addPropertyImage(
    int $propertyId,
    string $imageUrl,
    ?string $altText,
    bool $isPrimary,
    int $sortOrder,
    bool $requireReview = false
): int {
    global $pdo;

    $pdo->beginTransaction();

    try {
        // Lock the property to prevent conflicting updates.
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $lockClause = $driver === 'mysql' ? ' FOR UPDATE' : '';
        $lock = $pdo->prepare(
            "SELECT property_status FROM properties WHERE id = :id{$lockClause}"
        );
        $lock->execute([':id' => $propertyId]);
        $currentStatus = $lock->fetchColumn();

        if ($currentStatus === false) {
            $pdo->rollBack();
            return 0;
        }

        // Preserve the existing primary-image behaviour.
        if ($isPrimary) {
            $clearPrimary = $pdo->prepare(
                'UPDATE property_images
                 SET is_primary = 0
                 WHERE property_id = :property_id'
            );
            $clearPrimary->execute([':property_id' => $propertyId]);
        }

        // Insert the new image.
        $stmt = $pdo->prepare(
            'INSERT INTO property_images
                (property_id, image_url, alt_text, is_primary, sort_order)
             VALUES
                (:property_id, :image_url, :alt_text, :is_primary, :sort_order)'
        );

        $stmt->execute([
            ':property_id' => $propertyId,
            ':image_url' => $imageUrl,
            ':alt_text' => $altText,
            ':is_primary' => $isPrimary ? 1 : 0,
            ':sort_order' => $sortOrder,
        ]);

        $imageId = (int) $pdo->lastInsertId();

        // New images on approved owner/agent listings require review.
        $needsReview = $requireReview && $currentStatus === 'approved';

        if ($needsReview) {
            $updateStatus = $pdo->prepare(
                "UPDATE properties
                 SET property_status = 'pending'
                 WHERE id = :id"
            );
            $updateStatus->execute([':id' => $propertyId]);

            // Reuse an existing active verification if one exists.
            $activeStatuses = [
                'pending',
                'documents_submitted',
                'under_review',
                'visit_required',
            ];

            $placeholders = implode(
                ', ',
                array_fill(0, count($activeStatuses), '?')
            );

            $existing = $pdo->prepare(
                "SELECT id
                 FROM property_verifications
                 WHERE property_id = ?
                 AND verification_status IN ({$placeholders})
                 LIMIT 1"
            );

            $existing->execute(
                array_merge([$propertyId], $activeStatuses)
            );

            if ($existing->fetchColumn() === false) {
                $verification = $pdo->prepare(
                    "INSERT INTO property_verifications
                        (property_id, verification_status)
                     VALUES (:property_id, 'pending')"
                );

                $verification->execute([
                    ':property_id' => $propertyId,
                ]);
            }
        }

        $pdo->commit();

        return $imageId;

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}
    private function buildFilters(array $filters, bool $includeStatusFilters = false): array
    {
        $conditions = [];
        $params = [];
        $exactFilters = [
            'property_type' => 'p.property_type',
            'transaction_type' => 'p.transaction_type',
            'currency' => 'p.currency',
        ];
        foreach ($exactFilters as $key => $column) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $conditions[] = "{$column} = :{$key}";
                $params[":{$key}"] = $filters[$key];
            }
        }

        foreach (['city', 'state', 'country'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $conditions[] = "p.{$key} LIKE :{$key}";
                $params[":{$key}"] = '%' . $filters[$key] . '%';
            }
        }

        foreach (['min_price' => '>=', 'max_price' => '<='] as $key => $operator) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $conditions[] = "p.price {$operator} :{$key}";
                $params[":{$key}"] = $filters[$key];
            }
        }

        foreach (['bedrooms_min' => 'bedrooms', 'bathrooms_min' => 'bathrooms'] as $key => $column) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $conditions[] = "p.{$column} >= :{$key}";
                $params[":{$key}"] = (int) $filters[$key];
            }
        }

        if ($includeStatusFilters) {
            foreach ([
                'property_status' => 'p.property_status',
                'availability_status' => 'p.availability_status',
            ] as $key => $column) {
                if (isset($filters[$key]) && $filters[$key] !== '') {
                    $conditions[] = "{$column} = :{$key}";
                    $params[":{$key}"] = $filters[$key];
                }
            }
        }

        return [$conditions ? ' AND ' . implode(' AND ', $conditions) : '', $params];
    }

    private function bindFilters(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
    }

    private function namedParameters(array $values): array
    {
        $params = [];
        foreach ($values as $key => $value) {
            $params[':' . $key] = $value;
        }

        return $params;
    }
}
