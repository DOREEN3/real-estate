<?php

declare(strict_types=1);

if (!isset($pdo)) {
    require_once __DIR__ . '/../../config/database.php';
}

class SaleModel
{
    public function create(array $data): int
    {
        global $pdo;

        if (
            (int) $data['number_of_installments'] === 0
            && $this->decimalToCents($data['deposit_amount']) !== 0
        ) {
            throw new DomainException('deposit_without_installments');
        }

        $pdo->beginTransaction();
        try {
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
                $property['transaction_type'] !== 'sale'
                || $property['property_status'] !== 'approved'
                || $property['availability_status'] !== 'available'
            ) {
                throw new DomainException('property_not_for_sale');
            }
            if ($data['enquiry_id'] !== null && !$this->fetchOne(
                'SELECT id FROM enquiries WHERE id = :id',
                [':id' => $data['enquiry_id']]
            )) {
                throw new DomainException('enquiry_not_found');
            }
            if ($this->fetchOne(
                "SELECT id FROM sales
                 WHERE property_id = :property_id
                   AND sale_status IN ('in_progress', 'completed')",
                [':property_id' => $data['property_id']]
            )) {
                throw new DomainException('property_sale_exists');
            }

            $installmentAmount = $data['number_of_installments'] > 0
                ? $this->centsToDecimal(intdiv(
                    $this->decimalToCents($data['sale_price'])
                        - $this->decimalToCents($data['deposit_amount']),
                    $data['number_of_installments']
                ))
                : null;
            $nextInstallmentDue = $data['number_of_installments'] > 0
                ? $this->monthlyDueDate(
                    new DateTimeImmutable($data['schedule_start_date']),
                    1
                )
                : null;
            $stmt = $pdo->prepare(
                'INSERT INTO sales
                    (property_id, enquiry_id, buyer_name, buyer_email, sale_price,
                     currency, deposit_amount, installment_amount, number_of_installments,
                     next_installment_due)
                 VALUES
                    (:property_id, :enquiry_id, :buyer_name, :buyer_email, :sale_price,
                     :currency, :deposit_amount, :installment_amount, :number_of_installments,
                     :next_installment_due)'
            );
            $stmt->execute([
                ':property_id' => $data['property_id'],
                ':enquiry_id' => $data['enquiry_id'],
                ':buyer_name' => $data['buyer_name'],
                ':buyer_email' => $data['buyer_email'],
                ':sale_price' => $data['sale_price'],
                ':currency' => $data['currency'] ?? $property['currency'],
                ':deposit_amount' => $data['deposit_amount'],
                ':installment_amount' => $installmentAmount,
                ':number_of_installments' => $data['number_of_installments'],
                ':next_installment_due' => $nextInstallmentDue,
            ]);
            $saleId = (int) $pdo->lastInsertId();
            if ($saleId <= 0) {
                throw new RuntimeException('Unable to retrieve the created sale ID.');
            }

            if ($data['number_of_installments'] > 0) {
                $this->createInstallments($saleId, $data);
            }

            $pdo->commit();

            return $saleId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function getById(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT s.*, p.title AS property_title,
                    COALESCE((
                        SELECT SUM(pay.amount)
                        FROM payments pay
                        WHERE pay.sale_id = s.id AND pay.payment_status = \'completed\'
                    ), 0) AS total_paid,
                    s.sale_price - COALESCE((
                        SELECT SUM(pay.amount)
                        FROM payments pay
                        WHERE pay.sale_id = s.id AND pay.payment_status = \'completed\'
                    ), 0) AS outstanding_balance,
                    s.sale_price - COALESCE((
                        SELECT SUM(pay.amount)
                        FROM payments pay
                        WHERE pay.sale_id = s.id
                          AND (
                              pay.payment_status = \'completed\'
                              OR (pay.payment_source = \'provider\' AND pay.payment_status = \'pending\')
                          )
                    ), 0) AS available_balance
             FROM sales s
             JOIN properties p ON p.id = s.property_id
             WHERE s.id = :id',
            [':id' => $id]
        );
    }

    public function list(array $filters, int $page, int $limit): array
    {
        [$where, $params] = $this->filters($filters);

        return $this->fetchPage(
            "SELECT s.*, p.title AS property_title,
                    COALESCE((
                        SELECT SUM(pay.amount)
                        FROM payments pay
                        WHERE pay.sale_id = s.id AND pay.payment_status = 'completed'
                    ), 0) AS total_paid,
                    s.sale_price - COALESCE((
                        SELECT SUM(pay.amount)
                        FROM payments pay
                        WHERE pay.sale_id = s.id AND pay.payment_status = 'completed'
                    ), 0) AS outstanding_balance,
                    s.sale_price - COALESCE((
                        SELECT SUM(pay.amount)
                        FROM payments pay
                        WHERE pay.sale_id = s.id
                          AND (
                              pay.payment_status = 'completed'
                              OR (pay.payment_source = 'provider' AND pay.payment_status = 'pending')
                          )
                    ), 0) AS available_balance
             FROM sales s
             JOIN properties p ON p.id = s.property_id
             {$where}
             ORDER BY s.created_at DESC, s.id DESC",
            $params,
            $page,
            $limit
        );
    }

    public function count(array $filters): int
    {
        [$where, $params] = $this->filters($filters);
        $record = $this->fetchOne(
            "SELECT COUNT(*) AS total FROM sales s {$where}",
            $params
        );

        return (int) ($record['total'] ?? 0);
    }

    public function listInstallments(int $saleId): array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT i.*,
                    COALESCE((
                        SELECT SUM(p.amount)
                        FROM payments p
                        WHERE p.installment_id = i.id AND p.payment_status = 'completed'
                    ), 0) AS amount_paid,
                    i.amount_due - COALESCE((
                        SELECT SUM(p.amount)
                        FROM payments p
                        WHERE p.installment_id = i.id AND p.payment_status = 'completed'
                    ), 0) AS outstanding_balance
             FROM installments i
             WHERE i.sale_id = :sale_id
             ORDER BY i.installment_number ASC"
        );
        $stmt->execute([':sale_id' => $saleId]);

        return $stmt->fetchAll();
    }

    public function cancelSale(int $saleId, int $adminId, string $reason): array
    {
        global $pdo;

        $sale = $this->getById($saleId);
        if (!$sale) {
            return ['success' => false, 'message' => 'Sale not found', 'status_code' => 404];
        }

        if ($sale['sale_status'] === 'cancelled') {
            return ['success' => false, 'message' => 'Sale is already cancelled', 'status_code' => 409];
        }

        $pdo->beginTransaction();
        try {
            try {
                $stmt = $pdo->prepare(
                    "UPDATE sales
                     SET sale_status = 'cancelled',
                         cancelled_at = CURRENT_TIMESTAMP,
                         cancelled_by = :admin_id,
                         cancellation_reason = :reason,
                         next_installment_due = NULL
                     WHERE id = :id"
                );
                $stmt->execute([
                    ':admin_id' => $adminId,
                    ':reason' => $reason,
                    ':id' => $saleId,
                ]);
            } catch (Throwable) {
                $stmt = $pdo->prepare(
                    "UPDATE sales
                     SET sale_status = 'cancelled',
                         next_installment_due = NULL
                     WHERE id = :id"
                );
                $stmt->execute([':id' => $saleId]);
            }

            // Cancel unpaid installments
            try {
                $instStmt = $pdo->prepare(
                    "UPDATE installments
                     SET status = 'cancelled',
                         cancelled_at = CURRENT_TIMESTAMP,
                         cancelled_by = :admin_id,
                         adjustment_reason = 'Sale cancelled'
                     WHERE sale_id = :sale_id
                       AND id NOT IN (
                           SELECT installment_id FROM payments
                           WHERE sale_id = :sale_id AND installment_id IS NOT NULL AND payment_status = 'completed'
                       )"
                );
                $instStmt->execute([':admin_id' => $adminId, ':sale_id' => $saleId]);
            } catch (Throwable) {
                $instStmt = $pdo->prepare(
                    "UPDATE installments
                     SET status = 'cancelled'
                     WHERE sale_id = :sale_id
                       AND id NOT IN (
                           SELECT installment_id FROM payments
                           WHERE sale_id = :sale_id AND installment_id IS NOT NULL AND payment_status = 'completed'
                       )"
                );
                $instStmt->execute([':sale_id' => $saleId]);
            }

            // Release property availability if currently sold or reserved
            $propId = (int) $sale['property_id'];
            $pdo->prepare(
                "UPDATE properties
                 SET availability_status = 'available'
                 WHERE id = :id AND availability_status IN ('sold', 'reserved')"
            )->execute([':id' => $propId]);

            $pdo->commit();

            Middleware::auditLog($adminId, 'sale_cancelled', 'sales', $saleId, [
                'reason' => $reason,
                'property_id' => $sale['property_id'],
                'total_paid' => $sale['total_paid'] ?? 0,
            ]);

            return ['success' => true, 'message' => 'Sale cancelled successfully'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function deleteDraftSale(int $saleId, int $adminId, string $reason): array
    {
        global $pdo;

        $sale = $this->getById($saleId);
        if (!$sale) {
            return ['success' => false, 'message' => 'Sale not found', 'status_code' => 404];
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE sale_id = :id AND payment_status = 'completed'");
        $stmt->execute([':id' => $saleId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return [
                'success' => false,
                'message' => 'Cannot delete sale with completed payments. Cancel the sale or reverse the payments first.',
                'status_code' => 409,
            ];
        }

        $pdo->beginTransaction();
        try {
            // Delete payments if any non-completed (e.g. pending/failed/voided)
            $pdo->prepare("DELETE FROM payments WHERE sale_id = :id")->execute([':id' => $saleId]);

            // Delete invoices if any
            try {
                $pdo->prepare(
                    "DELETE FROM payment_invoices
                     WHERE installment_id IN (SELECT id FROM installments WHERE sale_id = :id)"
                )->execute([':id' => $saleId]);
            } catch (Throwable) {
                // Table might not exist or empty
            }

            // Delete installments
            $pdo->prepare("DELETE FROM installments WHERE sale_id = :id")->execute([':id' => $saleId]);

            // Delete sale
            $pdo->prepare("DELETE FROM sales WHERE id = :id")->execute([':id' => $saleId]);

            // Release property availability back to available
            $propId = (int) $sale['property_id'];
            $pdo->prepare(
                "UPDATE properties
                 SET availability_status = 'available'
                 WHERE id = :id AND availability_status IN ('sold', 'reserved')"
            )->execute([':id' => $propId]);

            $pdo->commit();

            Middleware::auditLog($adminId, 'sale_draft_deleted', 'sales', $saleId, [
                'reason' => $reason,
                'property_id' => $sale['property_id'],
            ]);

            return ['success' => true, 'message' => 'Draft sale deleted successfully'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function cancelInstallment(int $installmentId, int $adminId, string $reason): array
    {
        global $pdo;

        $stmt = $pdo->prepare("SELECT * FROM installments WHERE id = :id");
        $stmt->execute([':id' => $installmentId]);
        $installment = $stmt->fetch();
        if (!$installment) {
            return ['success' => false, 'message' => 'Installment not found', 'status_code' => 404];
        }

        $payStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE installment_id = :id AND payment_status = 'completed'"
        );
        $payStmt->execute([':id' => $installmentId]);
        $paid = (float) $payStmt->fetchColumn();

        if ($paid > 0) {
            return [
                'success' => false,
                'message' => 'Cannot cancel an installment with completed payments. Reverse payments first.',
                'status_code' => 409,
            ];
        }

        try {
            $update = $pdo->prepare(
                "UPDATE installments
                 SET status = 'cancelled',
                     cancelled_at = CURRENT_TIMESTAMP,
                     cancelled_by = :admin_id,
                     adjustment_reason = :reason
                 WHERE id = :id"
            );
            $update->execute([
                ':admin_id' => $adminId,
                ':reason' => $reason,
                ':id' => $installmentId,
            ]);
        } catch (Throwable) {
            $update = $pdo->prepare("UPDATE installments SET status = 'cancelled' WHERE id = :id");
            $update->execute([':id' => $installmentId]);
        }

        Middleware::auditLog($adminId, 'installment_cancelled', 'installments', $installmentId, [
            'reason' => $reason,
            'sale_id' => $installment['sale_id'],
            'installment_number' => $installment['installment_number'],
        ]);

        return ['success' => true, 'message' => 'Installment cancelled successfully'];
    }

    public function adjustInstallment(int $installmentId, int $adminId, array $data): array
    {
        global $pdo;

        $stmt = $pdo->prepare("SELECT * FROM installments WHERE id = :id");
        $stmt->execute([':id' => $installmentId]);
        $installment = $stmt->fetch();
        if (!$installment) {
            return ['success' => false, 'message' => 'Installment not found', 'status_code' => 404];
        }

        $payStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE installment_id = :id AND payment_status = 'completed'"
        );
        $payStmt->execute([':id' => $installmentId]);
        $paid = (float) $payStmt->fetchColumn();

        $amountDue = isset($data['amount_due']) ? (float) $data['amount_due'] : (float) $installment['amount_due'];
        if ($amountDue < $paid) {
            return [
                'success' => false,
                'message' => "Adjusted amount_due cannot be less than already paid amount ({$paid})",
                'status_code' => 400,
            ];
        }

        $dueDate = $data['due_date'] ?? $installment['due_date'];
        $reason = trim((string) ($data['reason'] ?? 'Adjusted by administrator'));

        $status = ($paid >= $amountDue && $amountDue > 0) ? 'paid' : ($paid > 0 ? 'partial' : 'pending');

        try {
            $update = $pdo->prepare(
                "UPDATE installments
                 SET amount_due = :amount_due,
                     due_date = :due_date,
                     status = :status,
                     adjustment_reason = :reason
                 WHERE id = :id"
            );
            $update->execute([
                ':amount_due' => number_format($amountDue, 2, '.', ''),
                ':due_date' => $dueDate,
                ':status' => $status,
                ':reason' => $reason,
                ':id' => $installmentId,
            ]);
        } catch (Throwable) {
            $update = $pdo->prepare(
                "UPDATE installments
                 SET amount_due = :amount_due,
                     due_date = :due_date,
                     status = :status
                 WHERE id = :id"
            );
            $update->execute([
                ':amount_due' => number_format($amountDue, 2, '.', ''),
                ':due_date' => $dueDate,
                ':status' => $status,
                ':id' => $installmentId,
            ]);
        }

        Middleware::auditLog($adminId, 'installment_adjusted', 'installments', $installmentId, [
            'reason' => $reason,
            'sale_id' => $installment['sale_id'],
            'previous_amount' => $installment['amount_due'],
            'new_amount' => $amountDue,
            'previous_due_date' => $installment['due_date'],
            'new_due_date' => $dueDate,
        ]);

        return ['success' => true, 'message' => 'Installment adjusted successfully'];
    }

    private function createInstallments(int $saleId, array $data): void
    {
        global $pdo;

        $depositCents = $this->decimalToCents($data['deposit_amount']);
        $balanceCents = $this->decimalToCents($data['sale_price']) - $depositCents;
        $count = $data['number_of_installments'];
        $baseCents = intdiv($balanceCents, $count);
        $remainderCents = $balanceCents % $count;
        $dueDate = new DateTimeImmutable($data['schedule_start_date']);

        $stmt = $pdo->prepare(
            'INSERT INTO installments (sale_id, installment_number, amount_due, due_date)
             VALUES (:sale_id, :installment_number, :amount_due, :due_date)'
        );
        if ($depositCents > 0) {
            $stmt->execute([
                ':sale_id' => $saleId,
                ':installment_number' => 0,
                ':amount_due' => $data['deposit_amount'],
                ':due_date' => $dueDate->format('Y-m-d'),
            ]);
        }

        for ($number = 1; $number <= $count; $number++) {
            $cents = $baseCents + ($number <= $remainderCents ? 1 : 0);
            $stmt->execute([
                ':sale_id' => $saleId,
                ':installment_number' => $number,
                ':amount_due' => $this->centsToDecimal($cents),
                ':due_date' => $this->monthlyDueDate($dueDate, $number),
            ]);
        }
    }

    private function monthlyDueDate(DateTimeImmutable $startDate, int $months): string
    {
        $yearMonth = $startDate
            ->modify('first day of this month')
            ->modify("+{$months} months");
        $day = min(
            (int) $startDate->format('d'),
            (int) $yearMonth->format('t')
        );

        return $yearMonth->setDate(
            (int) $yearMonth->format('Y'),
            (int) $yearMonth->format('m'),
            $day
        )->format('Y-m-d');
    }

    private function filters(array $filters): array
    {
        $conditions = [];
        $params = [];
        foreach (['sale_status' => 's.sale_status', 'property_id' => 's.property_id'] as $key => $column) {
            if (!isset($filters[$key])) {
                continue;
            }
            $conditions[] = "{$column} = :{$key}";
            $params[':' . $key] = $filters[$key];
        }

        return [$conditions ? 'WHERE ' . implode(' AND ', $conditions) : '', $params];
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

    private function fetchOne(string $sql, array $params, bool $forUpdate = false): ?array
    {
        global $pdo;

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $lock = $forUpdate && $driver === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare($sql . ' LIMIT 1' . $lock);
        $stmt->execute($params);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    private function decimalToCents(mixed $amount): int
    {
        if (
            !is_string($amount) && !is_int($amount) && !is_float($amount)
            || !preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', (string) $amount)
        ) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }
        $parts = explode('.', (string) $amount, 2);

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '0', 2, '0');
    }

    private function centsToDecimal(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
