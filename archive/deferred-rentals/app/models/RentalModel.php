<?php

declare(strict_types=1);

if (!isset($pdo)) {
    require_once __DIR__ . '/../../config/database.php';
}

class RentalModel
{
    public function createTenant(array $data): int
    {
        global $pdo;

        $stmt = $pdo->prepare(
            'INSERT INTO tenants (full_name, email, phone, national_id)
             VALUES (:full_name, :email, :phone, :national_id)'
        );
        $stmt->execute([
            ':full_name' => $data['full_name'],
            ':email' => $data['email'],
            ':phone' => $data['phone'],
            ':national_id' => $data['national_id'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function getTenant(int $tenantId): ?array
    {
        return $this->fetchOne(
            'SELECT id, full_name, email, phone, national_id, created_at
             FROM tenants WHERE id = :id',
            [':id' => $tenantId]
        );
    }

    public function listTenants(int $page, int $limit): array
    {
        return $this->fetchPage(
            'SELECT id, full_name, email, phone, national_id, created_at
             FROM tenants
             ORDER BY created_at DESC, id DESC',
            [],
            $page,
            $limit
        );
    }

    public function countTenants(): int
    {
        return $this->countRows('SELECT COUNT(*) FROM tenants', []);
    }

    public function createTenancy(int $userId, array $data): int
    {
        global $pdo;

        $pdo->beginTransaction();
        try {
            $tenant = $this->fetchOne(
                'SELECT id FROM tenants WHERE id = :id',
                [':id' => $data['tenant_id']]
            );
            if ($tenant === null) {
                throw new DomainException('tenant_not_found');
            }

            $property = $this->fetchOne(
                'SELECT id, currency, transaction_type, property_status, availability_status
                 FROM properties WHERE id = :id',
                [':id' => $data['property_id']],
                true
            );
            if ($property === null) {
                throw new DomainException('property_not_found');
            }
            if (
                $property['transaction_type'] !== 'rent'
                || $property['property_status'] !== 'approved'
            ) {
                throw new DomainException('property_not_available_for_rent');
            }
            $currency = $data['currency'] ?? $property['currency'];
            if (!is_string($currency) || !preg_match('/^[A-Za-z]{3}$/', $currency)) {
                $currency = 'KES';
            }
            $currency = strtoupper($currency);

            $overlap = $this->fetchOne(
                "SELECT id FROM tenancies
                 WHERE property_id = :property_id
                   AND tenancy_status = 'active'
                   AND start_date <= COALESCE(:end_date, '9999-12-31')
                   AND COALESCE(end_date, '9999-12-31') >= :start_date",
                [
                    ':property_id' => $data['property_id'],
                    ':end_date' => $data['end_date'],
                    ':start_date' => $data['start_date'],
                ]
            );
            if ($overlap !== null || $property['availability_status'] !== 'available') {
                throw new DomainException('property_has_active_tenancy');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO tenancies
                    (tenant_id, property_id, monthly_rent, currency, rent_due_day,
                     start_date, end_date, tenancy_status, created_by)
                 VALUES
                    (:tenant_id, :property_id, :monthly_rent, :currency, :rent_due_day,
                     :start_date, :end_date, \'active\', :created_by)'
            );
            $stmt->execute([
                ':tenant_id' => $data['tenant_id'],
                ':property_id' => $data['property_id'],
                ':monthly_rent' => $data['monthly_rent'],
                ':currency' => $currency,
                ':rent_due_day' => $data['rent_due_day'],
                ':start_date' => $data['start_date'],
                ':end_date' => $data['end_date'],
                ':created_by' => $userId,
            ]);
            $tenancyId = (int) $pdo->lastInsertId();
            if ($tenancyId <= 0) {
                throw new RuntimeException('Unable to retrieve the created tenancy ID.');
            }

            $startDate = new DateTimeImmutable($data['start_date']);
            $rentMonth = $startDate->format('Y-m-01');
            $dueDay = min(
                $data['rent_due_day'],
                (int) $startDate->format('t')
            );
            $dueDate = $startDate->setDate(
                (int) $startDate->format('Y'),
                (int) $startDate->format('m'),
                max((int) $startDate->format('d'), $dueDay)
            );
            $this->insertRentCharge(
                $tenancyId,
                $rentMonth,
                $data['monthly_rent'],
                $currency,
                $dueDate->format('Y-m-d')
            );

            $stmt = $pdo->prepare(
                "UPDATE properties SET availability_status = 'rented' WHERE id = :id"
            );
            $stmt->execute([':id' => $data['property_id']]);

            $pdo->commit();

            return $tenancyId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function getTenancy(int $tenancyId): ?array
    {
        return $this->fetchOne(
            'SELECT t.*, tn.full_name AS tenant_name, tn.email AS tenant_email,
                    tn.phone AS tenant_phone, tn.national_id,
                    p.title AS property_title, p.address AS property_address,
                    u.full_name AS created_by_name
             FROM tenancies t
             JOIN tenants tn ON tn.id = t.tenant_id
             JOIN properties p ON p.id = t.property_id
             LEFT JOIN users u ON u.id = t.created_by
             WHERE t.id = :id',
            [':id' => $tenancyId]
        );
    }

    public function listTenancies(array $filters, int $page, int $limit): array
    {
        [$where, $params] = $this->tenancyFilters($filters);

        return $this->fetchPage(
            "SELECT t.*, tn.full_name AS tenant_name, tn.email AS tenant_email,
                    p.title AS property_title,
                    COALESCE((
                        SELECT SUM(rc.amount_due)
                        FROM rent_charges rc
                        WHERE rc.tenancy_id = t.id
                    ), 0) AS total_charged,
                    COALESCE((
                        SELECT SUM(rp.amount_paid)
                        FROM rent_payments rp
                        JOIN rent_charges rc ON rc.id = rp.rent_charge_id
                        WHERE rc.tenancy_id = t.id
                    ), 0) AS total_paid
             FROM tenancies t
             JOIN tenants tn ON tn.id = t.tenant_id
             JOIN properties p ON p.id = t.property_id
             {$where}
             ORDER BY t.created_at DESC, t.id DESC",
            $params,
            $page,
            $limit
        );
    }

    public function countTenancies(array $filters): int
    {
        [$where, $params] = $this->tenancyFilters($filters);

        return $this->countRows(
            "SELECT COUNT(*) FROM tenancies t JOIN tenants tn ON tn.id = t.tenant_id {$where}",
            $params
        );
    }

    public function endTenancy(int $tenancyId, string $endDate): void
    {
        global $pdo;

        $pdo->beginTransaction();
        try {
            $tenancy = $this->fetchOne(
                'SELECT id, property_id, start_date, tenancy_status
                 FROM tenancies WHERE id = :id',
                [':id' => $tenancyId],
                true
            );
            if ($tenancy === null) {
                throw new DomainException('tenancy_not_found');
            }
            if ($tenancy['tenancy_status'] !== 'active') {
                throw new DomainException('tenancy_not_active');
            }
            if ($endDate < $tenancy['start_date']) {
                throw new DomainException('end_date_before_start');
            }

            $stmt = $pdo->prepare(
                "UPDATE tenancies
                 SET tenancy_status = 'ended', end_date = :end_date
                 WHERE id = :id AND tenancy_status = 'active'"
            );
            $stmt->execute([':end_date' => $endDate, ':id' => $tenancyId]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('tenancy_not_active');
            }

            $otherActiveTenancy = $this->fetchOne(
                "SELECT id FROM tenancies
                 WHERE property_id = :property_id
                   AND tenancy_status = 'active'
                   AND id <> :id",
                [':property_id' => $tenancy['property_id'], ':id' => $tenancyId]
            );
            if ($otherActiveTenancy === null) {
                $stmt = $pdo->prepare(
                    "UPDATE properties SET availability_status = 'available' WHERE id = :id"
                );
                $stmt->execute([':id' => $tenancy['property_id']]);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function generateRentCharges(int $tenancyId, string $throughMonth): array
    {
        global $pdo;

        $pdo->beginTransaction();
        try {
            $tenancy = $this->fetchOne(
                'SELECT id, monthly_rent, currency, rent_due_day, start_date, end_date, tenancy_status
                 FROM tenancies WHERE id = :id',
                [':id' => $tenancyId],
                true
            );
            if ($tenancy === null) {
                throw new DomainException('tenancy_not_found');
            }
            if ($tenancy['tenancy_status'] !== 'active') {
                throw new DomainException('tenancy_not_active');
            }

            $startMonth = new DateTimeImmutable(substr($tenancy['start_date'], 0, 7) . '-01');
            $through = new DateTimeImmutable($throughMonth . '-01');
            $currentMonth = $startMonth->modify('+1 month');
            if ($through < $currentMonth) {
                throw new DomainException('invalid_charge_range');
            }
            if ($currentMonth->diff($through)->m + ($currentMonth->diff($through)->y * 12) > 120) {
                throw new DomainException('charge_range_too_large');
            }

            $generated = 0;
            while ($currentMonth <= $through) {
                $monthEnd = $currentMonth->modify('last day of this month');
                if ($tenancy['end_date'] !== null && $currentMonth->format('Y-m-d') > $tenancy['end_date']) {
                    break;
                }
                $day = min((int) $tenancy['rent_due_day'], (int) $monthEnd->format('d'));
                $dueDate = $currentMonth->setDate(
                    (int) $currentMonth->format('Y'),
                    (int) $currentMonth->format('m'),
                    $day
                );
                if ($tenancy['end_date'] !== null && $dueDate->format('Y-m-d') > $tenancy['end_date']) {
                    break;
                }
                if ($this->insertRentCharge(
                    $tenancyId,
                    $currentMonth->format('Y-m-d'),
                    $tenancy['monthly_rent'],
                    $tenancy['currency'],
                    $dueDate->format('Y-m-d')
                )) {
                    $generated++;
                }
                $currentMonth = $currentMonth->modify('+1 month');
            }

            $pdo->commit();

            return [
                'generated' => $generated,
                'charges' => $this->listRentCharges(['tenancy_id' => $tenancyId], 1, 200),
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function listRentCharges(array $filters, int $page, int $limit): array
    {
        [$where, $params] = $this->chargeFilters($filters);

        return $this->fetchPage(
            "SELECT rc.*, t.tenant_id, tn.full_name AS tenant_name,
                    t.property_id, p.title AS property_title,
                    COALESCE((
                        SELECT SUM(rp.amount_paid)
                        FROM rent_payments rp
                        WHERE rp.rent_charge_id = rc.id
                    ), 0) AS amount_paid,
                    rc.amount_due - COALESCE((
                        SELECT SUM(rp.amount_paid)
                        FROM rent_payments rp
                        WHERE rp.rent_charge_id = rc.id
                    ), 0) AS outstanding_balance
             FROM rent_charges rc
             JOIN tenancies t ON t.id = rc.tenancy_id
             JOIN tenants tn ON tn.id = t.tenant_id
             JOIN properties p ON p.id = t.property_id
             {$where}
             ORDER BY rc.due_date ASC, rc.id ASC",
            $params,
            $page,
            $limit
        );
    }

    public function countRentCharges(array $filters): int
    {
        [$where, $params] = $this->chargeFilters($filters);

        return $this->countRows(
            "SELECT COUNT(*) FROM rent_charges rc JOIN tenancies t ON t.id = rc.tenancy_id {$where}",
            $params
        );
    }

    public function getRentCharge(int $chargeId): ?array
    {
        $charges = $this->listRentCharges(['id' => $chargeId], 1, 1);

        return $charges[0] ?? null;
    }

    private function insertRentCharge(
        int $tenancyId,
        string $rentMonth,
        mixed $amountDue,
        string $currency,
        string $dueDate
    ): bool {
        global $pdo;

        $existing = $this->fetchOne(
            'SELECT id FROM rent_charges WHERE tenancy_id = :tenancy_id AND rent_month = :rent_month',
            [':tenancy_id' => $tenancyId, ':rent_month' => $rentMonth]
        );
        if ($existing !== null) {
            return false;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO rent_charges (tenancy_id, rent_month, amount_due, currency, due_date)
                 VALUES (:tenancy_id, :rent_month, :amount_due, :currency, :due_date)'
            );
            $stmt->execute([
                ':tenancy_id' => $tenancyId,
                ':rent_month' => $rentMonth,
                ':amount_due' => $amountDue,
                ':currency' => $currency,
                ':due_date' => $dueDate,
            ]);
        } catch (PDOException $exception) {
            $driverCode = $exception->errorInfo[1] ?? null;
            $driverMessage = $exception->errorInfo[2] ?? $exception->getMessage();
            if (
                (int) $driverCode === 1062
                || str_contains($driverMessage, 'UNIQUE constraint failed')
                || str_contains($driverMessage, 'Duplicate entry')
            ) {
                return false;
            }
            throw $exception;
        }

        return true;
    }

    private function tenancyFilters(array $filters): array
    {
        $conditions = [];
        $params = [];
        foreach ([
            'tenant_id' => 't.tenant_id',
            'property_id' => 't.property_id',
            'tenancy_status' => 't.tenancy_status',
        ] as $key => $column) {
            if (isset($filters[$key])) {
                $conditions[] = "{$column} = :{$key}";
                $params[':' . $key] = $filters[$key];
            }
        }

        return [$conditions ? 'WHERE ' . implode(' AND ', $conditions) : '', $params];
    }

    private function chargeFilters(array $filters): array
    {
        $conditions = [];
        $params = [];
        foreach ([
            'id' => 'rc.id',
            'tenancy_id' => 'rc.tenancy_id',
            'tenant_id' => 't.tenant_id',
            'property_id' => 't.property_id',
        ] as $key => $column) {
            if (isset($filters[$key])) {
                $conditions[] = "{$column} = :{$key}";
                $params[':' . $key] = $filters[$key];
            }
        }

        return [$conditions ? 'WHERE ' . implode(' AND ', $conditions) : '', $params];
    }

    private function fetchOne(string $sql, array $params, bool $forUpdate = false): ?array
    {
        global $pdo;

        $lock = $forUpdate && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' FOR UPDATE'
            : '';
        $stmt = $pdo->prepare($sql . ' LIMIT 1' . $lock);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function fetchPage(string $sql, array $params, int $page, int $limit): array
    {
        global $pdo;

        $stmt = $pdo->prepare($sql . ' LIMIT :limit OFFSET :offset');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function countRows(string $sql, array $params): int
    {
        global $pdo;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
