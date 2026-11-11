<?php

declare(strict_types=1);

if (!isset($pdo)) {
    require_once __DIR__ . '/../../config/database.php';
}

class PaymentModel
{
    public function saleExists(int $saleId): bool
    {
        return $this->fetchOne(
            'SELECT id FROM sales WHERE id = :id',
            [':id' => $saleId]
        ) !== null;
    }

    public function rentChargeExists(int $rentChargeId): bool
    {
        return $this->fetchOne(
            'SELECT id FROM rent_charges WHERE id = :id',
            [':id' => $rentChargeId]
        ) !== null;
    }

    public function getProviderPaymentByReference(string $provider, string $providerReference): ?array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT id, amount, recorded_by, payment_status, payment_source,
                transaction_reference, payer_phone
                FROM payments
                WHERE provider = :provider
                AND provider_reference = :provider_reference
                AND payment_source = 'provider'"
        );
        $stmt->execute([
            ':provider' => $this->normalizeProvider($provider),
            ':provider_reference' => $providerReference,
        ]);
        $payments = $stmt->fetchAll();
        if (count($payments) > 1) {
            throw new DomainException('duplicate_provider_reference');
        }

        return $payments[0] ?? null;
    }
    public function storeMpesaCallback(
    string $checkoutRequestId,
    ?string $merchantRequestId,
    array $payload
): array {
    global $pdo;

    $checkoutRequestId = trim($checkoutRequestId);
    $merchantRequestId = $merchantRequestId !== null
        ? trim($merchantRequestId)
        : null;

    if (
        $checkoutRequestId === ''
        || mb_strlen($checkoutRequestId) > 100
    ) {
        throw new InvalidArgumentException('Invalid checkout request ID.');
    }

    if ($merchantRequestId === '') {
        $merchantRequestId = null;
    }

    if (
        $merchantRequestId !== null
        && mb_strlen($merchantRequestId) > 100
    ) {
        throw new InvalidArgumentException('Invalid merchant request ID.');
    }

    $callbackPayload = json_encode($payload, JSON_THROW_ON_ERROR);

    $stmt = $pdo->prepare(
        'INSERT INTO mpesa_callback_inbox
            (checkout_request_id, merchant_request_id, callback_payload)
         VALUES
            (:checkout_request_id, :merchant_request_id, :callback_payload)
         ON DUPLICATE KEY UPDATE
            merchant_request_id = COALESCE(
                VALUES(merchant_request_id),
                merchant_request_id
            )'
    );

    $stmt->execute([
        ':checkout_request_id' => $checkoutRequestId,
        ':merchant_request_id' => $merchantRequestId,
        ':callback_payload' => $callbackPayload,
    ]);

    $callback = $this->fetchOne(
        'SELECT *
         FROM mpesa_callback_inbox
         WHERE checkout_request_id = :checkout_request_id',
        [':checkout_request_id' => $checkoutRequestId]
    );

    if ($callback === null) {
        throw new RuntimeException('Unable to retrieve stored M-Pesa callback.');
    }

    return $callback;
}


public function linkMpesaCallbackToPayment(
    string $checkoutRequestId,
    int $paymentId
): void {
    global $pdo;

    $stmt = $pdo->prepare(
        'UPDATE mpesa_callback_inbox
         SET payment_id = :payment_id
         WHERE checkout_request_id = :checkout_request_id
           AND (payment_id IS NULL OR payment_id = :existing_payment_id)'
    );

    $stmt->execute([
        ':payment_id' => $paymentId,
        ':checkout_request_id' => $checkoutRequestId,
        ':existing_payment_id' => $paymentId,
    ]);

    $callback = $this->fetchOne(
        'SELECT payment_id
         FROM mpesa_callback_inbox
         WHERE checkout_request_id = :checkout_request_id',
        [':checkout_request_id' => $checkoutRequestId]
    );

    if (
        $callback !== null
        && (int) $callback['payment_id'] !== $paymentId
    ) {
        throw new DomainException('callback_payment_mismatch');
    }
}
public function getMpesaCallback(string $checkoutRequestId): ?array
{
    return $this->fetchOne(
        'SELECT *
         FROM mpesa_callback_inbox
         WHERE checkout_request_id = :checkout_request_id',
        [':checkout_request_id' => $checkoutRequestId]
    );
}


public function updateMpesaCallbackStatus(
    int $callbackId,
    string $status,
    ?string $error = null
): void {
    global $pdo;

    if (!in_array($status, ['pending', 'processed', 'failed'], true)) {
        throw new InvalidArgumentException('Invalid callback processing status.');
    }

    $stmt = $pdo->prepare(
        'UPDATE mpesa_callback_inbox
         SET processing_status = :status,
             processing_error = :error,
             processed_at = :processed_at
         WHERE id = :id'
    );

    $stmt->execute([
        ':status' => $status,
        ':error' => $error,
        ':processed_at' => $status === 'processed' ? date('Y-m-d H:i:s') : null,
        ':id' => $callbackId,
    ]);
}

    public function recordSalePayment(int $saleId, int $userId, array $data): int
    {
        global $pdo;

        $pdo->beginTransaction();
        try {
            $sale = $this->fetchOne(
                'SELECT id, sale_price FROM sales WHERE id = :id',
                [':id' => $saleId],
                true
            );
            if ($sale === null) {
                throw new DomainException('sale_not_found');
            }

            $paymentAmount = $this->decimalToCents($data['amount']);
            if ($paymentAmount > $this->saleBalance($saleId, $sale['sale_price'])) {
                throw new DomainException('payment_exceeds_sale_balance');
            }

            $installmentId = $data['installment_id'];
            $installment = $this->validatePaymentInstallment(
                $saleId,
                $data['payment_type'],
                $installmentId,
                true
            );
            $installmentPaid = 0;
            $installmentDue = 0;
            if ($installment !== null) {
                $installmentPaid = $this->installmentPaid($installmentId);
                $installmentDue = $this->decimalToCents($installment['amount_due']);
                if ($paymentAmount > $installmentDue - $installmentPaid) {
                    throw new DomainException('payment_exceeds_installment_balance');
                }
            }

            $provider = $data['provider'] === null
                ? null
                : $this->normalizeProvider((string) $data['provider']);
            if ($data['payment_source'] === 'provider' && $data['provider_reference'] !== null) {
                $existingProviderPayment = $this->getProviderPaymentByReference(
                    (string) $provider,
                    $data['provider_reference']
                );
                if ($existingProviderPayment !== null) {
                    throw new DomainException('duplicate_provider_reference');
                }
            }
            $status = $data['payment_source'] === 'provider' ? 'pending' : 'completed';
            $stmt = $pdo->prepare(
                'INSERT INTO payments
                    (sale_id, amount, payment_type, payment_method, payment_source,
                     payment_status, provider, provider_reference, payer_phone,
                     transaction_reference, payment_date, recorded_by, installment_id)
                 VALUES
                    (:sale_id, :amount, :payment_type, :payment_method, :payment_source,
                    :payment_status, :provider, :provider_reference, :payer_phone,
                    :transaction_reference, :payment_date, :recorded_by, :installment_id)'
                );
            $this->executePaymentInsert($stmt, [
                ':sale_id' => $saleId,
                ':amount' => $data['amount'],
                ':payment_type' => $data['payment_type'],
                ':payment_method' => $data['payment_method'],
                ':payment_source' => $data['payment_source'],
                ':payment_status' => $status,
                ':provider' => $provider,
                ':provider_reference' => $data['provider_reference'],
                ':transaction_reference' => $data['transaction_reference'],
                ':payment_date' => $data['payment_date'],
                ':recorded_by' => $userId,
                ':installment_id' => $installmentId,
                ':payer_phone' => $data['payer_phone'] ?? null,
            ]);
            $paymentId = (int) $pdo->lastInsertId();
            if ($paymentId <= 0) {
                throw new RuntimeException('Unable to retrieve the created payment ID.');
            }

            if ($status === 'completed') {
                if ($installment !== null) {
                    $this->updateInstallmentStatus(
                        $installmentId,
                        $installmentPaid + $paymentAmount,
                        $installmentDue
                    );
                }
                $this->issueReceipt('payment_receipts', 'payment_id', $paymentId, $userId, 'SALE');
                $this->refreshSaleState($saleId);
            }

            $pdo->commit();

            return $paymentId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
    
public function attachMpesaCheckoutReference(
    int $paymentId,
    string $checkoutRequestId
): void {
    global $pdo;

    $checkoutRequestId = trim($checkoutRequestId);

    if ($checkoutRequestId === '' || mb_strlen($checkoutRequestId) > 100) {
        throw new InvalidArgumentException('Invalid M-Pesa checkout reference.');
    }

    $pdo->beginTransaction();

    try {
        $payment = $this->fetchOne(
            'SELECT id, provider, payment_source, payment_status, provider_reference
             FROM payments
             WHERE id = :id',
            [':id' => $paymentId],
            true
        );

        if ($payment === null) {
            throw new DomainException('payment_not_found');
        }

        if (
            $payment['provider'] !== 'mpesa'
            || $payment['payment_source'] !== 'provider'
            || $payment['payment_status'] !== 'pending'
        ) {
            throw new DomainException('payment_not_pending_mpesa');
        }

        if ($payment['provider_reference'] !== null) {
            throw new DomainException('checkout_reference_already_attached');
        }

        $duplicate = $this->fetchOne(
            'SELECT id FROM payments
             WHERE provider = :provider
               AND provider_reference = :reference',
            [
                ':provider' => 'mpesa',
                ':reference' => $checkoutRequestId,
            ]
        );

        if ($duplicate !== null) {
            throw new DomainException('duplicate_provider_reference');
        }

        $stmt = $pdo->prepare(
            "UPDATE payments
             SET provider_reference = :reference
             WHERE id = :id
               AND provider = 'mpesa'
               AND payment_source = 'provider'
               AND payment_status = 'pending'
               AND provider_reference IS NULL"
        );

        try {
            $stmt->execute([
                ':reference' => $checkoutRequestId,
                ':id' => $paymentId,
            ]);
        } catch (PDOException $exception) {
            $this->throwReferenceConstraint($exception);
        }

        if ($stmt->rowCount() !== 1) {
            throw new DomainException('checkout_reference_not_attached');
        }
        $this->linkMpesaCallbackToPayment(
        $checkoutRequestId,
        $paymentId
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}


    public function resolveProviderPayment(
        int $paymentId,
        ?int $userId,
        string $status,
        ?string $providerReference,
        ?string $transactionReference = null
    ): void {
        global $pdo;

        if (!in_array($status, ['completed', 'failed'], true)) {
            throw new DomainException('invalid_payment_status');
        }

        $pdo->beginTransaction();
        try {
            $payment = $this->fetchOne(
                'SELECT id, sale_id FROM payments WHERE id = :id',
                [':id' => $paymentId]
            );
            if ($payment === null) {
                throw new DomainException('payment_not_found');
            }
            $sale = $this->fetchOne(
                'SELECT id, sale_price FROM sales WHERE id = :id',
                [':id' => $payment['sale_id']],
                true
            );
            $payment = $this->fetchOne(
                'SELECT id, sale_id, amount, payment_type, payment_source, payment_status,
                        installment_id, recorded_by
                 FROM payments WHERE id = :id',
                [':id' => $paymentId],
                true
            );
            if ($sale === null || $payment === null) {
                throw new DomainException('payment_not_found');
            }
            if ($payment['payment_source'] !== 'provider' || $payment['payment_status'] !== 'pending') {
                throw new DomainException('payment_not_pending_provider');
            }

            $installment = null;
            $installmentPaid = 0;
            $installmentDue = 0;
            $amount = $this->decimalToCents($payment['amount']);
            if ($status === 'completed') {
                $installment = $this->validatePaymentInstallment(
                    (int) $payment['sale_id'],
                    $payment['payment_type'],
                    $payment['installment_id'] === null ? null : (int) $payment['installment_id'],
                    true
                );
                if ($amount > $this->saleBalance((int) $payment['sale_id'], $sale['sale_price'], $paymentId)) {
                    throw new DomainException('payment_exceeds_sale_balance');
                }
                if ($installment !== null) {
                    $installmentPaid = $this->installmentPaid(
                        (int) $payment['installment_id'],
                        $paymentId
                    );
                    $installmentDue = $this->decimalToCents($installment['amount_due']);
                    if ($amount > $installmentDue - $installmentPaid) {
                        throw new DomainException('payment_exceeds_installment_balance');
                    }
                }
            }

            $stmt = $pdo->prepare(
                "UPDATE payments
                 SET payment_status = :status,
                     provider_reference = COALESCE(:provider_reference, provider_reference),
                     transaction_reference = COALESCE(:transaction_reference, transaction_reference)
                 WHERE id = :id
                   AND payment_source = 'provider'
                   AND payment_status = 'pending'"
            );
            try {
                $stmt->execute([
                    ':status' => $status,
                    ':provider_reference' => $providerReference,
                    ':transaction_reference' => $transactionReference,
                    ':id' => $paymentId,
                ]);
            } catch (PDOException $exception) {
                $this->throwReferenceConstraint($exception);
            }
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('payment_not_pending_provider');
            }

            if ($status === 'completed') {
                if ($installment !== null) {
                    $this->updateInstallmentStatus(
                        (int) $payment['installment_id'],
                        $installmentPaid + $amount,
                        $installmentDue
                    );
                }
                $this->issueReceipt('payment_receipts', 'payment_id', $paymentId, $userId, 'SALE');
                $this->refreshSaleState((int) $payment['sale_id']);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function saleBalance(int $saleId, mixed $salePrice, ?int $excludedPendingPaymentId = null): int
    {
        global $pdo;

        $excludePendingSql = $excludedPendingPaymentId === null ? '' : ' AND id <> :excluded_id';
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM payments
             WHERE sale_id = :sale_id
               AND (
                    payment_status = 'completed'
                    OR (payment_source = 'provider' AND payment_status = 'pending')
               ){$excludePendingSql}"
        );
        $params = [':sale_id' => $saleId];
        if ($excludedPendingPaymentId !== null) {
            $params[':excluded_id'] = $excludedPendingPaymentId;
        }
        $stmt->execute($params);

        return $this->decimalToCents($salePrice) - $this->decimalToCents($stmt->fetchColumn());
    }

    private function refreshSaleState(int $saleId): void
    {
        global $pdo;

        $sale = $this->fetchOne(
            'SELECT sale_price FROM sales WHERE id = :id',
            [':id' => $saleId],
            true
        );
        if ($sale === null) {
            throw new DomainException('sale_not_found');
        }

        $completedAmount = $this->completedSalePayments($saleId);
        if ($completedAmount >= $this->decimalToCents($sale['sale_price'])) {
            $stmt = $pdo->prepare(
                "UPDATE sales
                 SET sale_status = 'completed', next_installment_due = NULL
                 WHERE id = :id"
            );
            $stmt->execute([':id' => $saleId]);
            return;
        }

        $stmt = $pdo->prepare(
            "SELECT i.due_date
             FROM installments i
             WHERE i.sale_id = :sale_id
               AND i.installment_number > 0
               AND i.amount_due > COALESCE((
                   SELECT SUM(p.amount)
                   FROM payments p
                   WHERE p.installment_id = i.id
                     AND p.payment_status = 'completed'
               ), 0)
             ORDER BY i.due_date, i.installment_number
             LIMIT 1"
        );
        $stmt->execute([':sale_id' => $saleId]);
        $nextDueDate = $stmt->fetchColumn();
        $stmt = $pdo->prepare(
            'UPDATE sales SET next_installment_due = :next_due WHERE id = :id'
        );
        $stmt->execute([
            ':next_due' => $nextDueDate === false ? null : $nextDueDate,
            ':id' => $saleId,
        ]);
    }

    private function completedSalePayments(int $saleId): int
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM payments
             WHERE sale_id = :sale_id AND payment_status = 'completed'"
        );
        $stmt->execute([':sale_id' => $saleId]);

        return $this->decimalToCents($stmt->fetchColumn());
    }

    private function installmentPaid(int $installmentId, ?int $excludedPendingPaymentId = null): int
    {
        global $pdo;

        $excludePendingSql = $excludedPendingPaymentId === null ? '' : ' AND id <> :excluded_id';
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM payments
             WHERE installment_id = :installment_id
               AND (
                    payment_status = 'completed'
                    OR (payment_source = 'provider' AND payment_status = 'pending')
               ){$excludePendingSql}"
        );
        $params = [':installment_id' => $installmentId];
        if ($excludedPendingPaymentId !== null) {
            $params[':excluded_id'] = $excludedPendingPaymentId;
        }
        $stmt->execute($params);

        return $this->decimalToCents($stmt->fetchColumn());
    }

    private function saleHasInstallments(int $saleId): bool
    {
        return $this->fetchOne(
            'SELECT id FROM installments WHERE sale_id = :sale_id',
            [':sale_id' => $saleId]
        ) !== null;
    }

    private function validatePaymentInstallment(
        int $saleId,
        string $paymentType,
        ?int $installmentId,
        bool $forUpdate
    ): ?array {
        if (!in_array($paymentType, ['deposit', 'installment', 'full'], true)) {
            throw new DomainException('invalid_payment_type');
        }

        if ($installmentId === null) {
            if ($paymentType === 'deposit') {
                throw new DomainException('deposit_installment_required');
            }
            if ($paymentType === 'installment' || $this->saleHasInstallments($saleId)) {
                throw new DomainException('installment_required');
            }

            return null;
        }

        if ($paymentType === 'full') {
            throw new DomainException('payment_type_installment_mismatch');
        }
        $installment = $this->fetchOne(
            'SELECT id, amount_due, installment_number
             FROM installments
             WHERE id = :id AND sale_id = :sale_id',
            [':id' => $installmentId, ':sale_id' => $saleId],
            $forUpdate
        );
        if ($installment === null) {
            throw new DomainException('installment_not_found');
        }

        $isDepositInstallment = (int) $installment['installment_number'] === 0;
        if (($paymentType === 'deposit') !== $isDepositInstallment) {
            throw new DomainException('payment_type_installment_mismatch');
        }
        return $installment;
    }

    private function updateInstallmentStatus(int $installmentId, int $paid, int $due): void
    {
        global $pdo;

        $status = $paid >= $due ? 'paid' : ($paid > 0 ? 'partial' : 'pending');
        $stmt = $pdo->prepare('UPDATE installments SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $installmentId]);
    }

    public function recordRentPayment(int $rentChargeId, int $userId, array $data): int
    {
        global $pdo;

        $pdo->beginTransaction();
        try {
            $rentCharge = $this->fetchOne(
                'SELECT id, amount_due FROM rent_charges WHERE id = :id',
                [':id' => $rentChargeId],
                true
            );
            if ($rentCharge === null) {
                throw new DomainException('rent_charge_not_found');
            }

            if (
                $this->decimalToCents($data['amount_paid'])
                > $this->rentBalance($rentChargeId, $rentCharge['amount_due'])
            ) {
                throw new DomainException('payment_exceeds_rent_charge_balance');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO rent_payments
                    (rent_charge_id, amount_paid, payment_method, transaction_reference,
                     payment_date, recorded_by)
                 VALUES
                    (:rent_charge_id, :amount_paid, :payment_method, :transaction_reference,
                     :payment_date, :recorded_by)'
            );
            try {
                $stmt->execute([
                    ':rent_charge_id' => $rentChargeId,
                    ':amount_paid' => $data['amount_paid'],
                    ':payment_method' => $data['payment_method'],
                    ':transaction_reference' => $data['transaction_reference'],
                    ':payment_date' => $data['payment_date'],
                    ':recorded_by' => $userId,
                ]);
            } catch (PDOException $exception) {
                $this->throwReferenceConstraint($exception);
            }
            $paymentId = (int) $pdo->lastInsertId();
            if ($paymentId <= 0) {
                throw new RuntimeException('Unable to retrieve the created rent payment ID.');
            }
            $this->issueReceipt(
                'rent_payment_receipts',
                'rent_payment_id',
                $paymentId,
                $userId,
                'RENT'
            );
            $pdo->commit();

            return $paymentId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function getSalePayment(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT p.*, r.receipt_number, r.issued_at, r.issued_by,
                    u.full_name AS recorded_by_name, s.buyer_name, s.currency
             FROM payments p
             LEFT JOIN payment_receipts r ON r.payment_id = p.id
             LEFT JOIN users u ON u.id = p.recorded_by
             LEFT JOIN sales s ON s.id = p.sale_id
             WHERE p.id = :id',
            [':id' => $id]
        );
    }

    public function listSalePayments(array $filters, int $page, int $limit): array
    {
        [$where, $params] = $this->saleFilters($filters);

        return $this->fetchPage(
            "SELECT p.*,  r.receipt_number, r.issued_at, s.buyer_name, s.currency
             FROM payments p
             LEFT JOIN payment_receipts r ON r.payment_id = p.id
             LEFT JOIN sales s ON s.id = p.sale_id
             {$where}
             ORDER BY p.payment_date DESC, p.id DESC",
            $params,
            $page,
            $limit
        );
    }

    public function countSalePayments(array $filters): int
    {
        [$where, $params] = $this->saleFilters($filters);

        return $this->countRows("SELECT COUNT(*) FROM payments p {$where}", $params);
    }

    public function getRentPayment(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT p.*, r.receipt_number, r.issued_at, r.issued_by,
                    u.full_name AS recorded_by_name, c.tenancy_id, c.currency
             FROM rent_payments p
             LEFT JOIN rent_payment_receipts r ON r.rent_payment_id = p.id
             LEFT JOIN users u ON u.id = p.recorded_by
             LEFT JOIN rent_charges c ON c.id = p.rent_charge_id
             WHERE p.id = :id',
            [':id' => $id]
        );
    }

    public function listRentPayments(array $filters, int $page, int $limit): array
    {
        [$where, $params] = $this->rentFilters($filters);

        return $this->fetchPage(
            "SELECT p.*,r.receipt_number, r.issued_at, c.tenancy_id, c.currency
             FROM rent_payments p
             LEFT JOIN rent_payment_receipts r ON r.rent_payment_id = p.id
             LEFT JOIN rent_charges c ON c.id = p.rent_charge_id
             {$where}
             ORDER BY p.payment_date DESC, p.id DESC",
            $params,
            $page,
            $limit
        );
    }

    public function countRentPayments(array $filters): int
    {
        [$where, $params] = $this->rentFilters($filters);

        return $this->countRows("SELECT COUNT(*) FROM rent_payments p {$where}", $params);
    }

    private function decimalToCents(mixed $amount): int
    {
        $amount = trim((string) $amount);

        if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }

        $parts = explode('.', $amount, 2);

        $whole = (int) $parts[0];
        $fraction = (int) str_pad($parts[1] ?? '0', 2, '0');

        return $whole * 100 + $fraction;
    }

    private function rentBalance(int $rentChargeId, mixed $amountDue): int
    {
        global $pdo;

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount_paid), 0)
             FROM rent_payments
             WHERE rent_charge_id = :rent_charge_id'
        );
        $stmt->execute([':rent_charge_id' => $rentChargeId]);

        return $this->decimalToCents($amountDue) - $this->decimalToCents($stmt->fetchColumn());
    }

    private function normalizeProvider(string $provider): string
    {
        $normalized = strtolower((string) preg_replace('/[\s-]+/', '', trim($provider)));
        if ($normalized === '') {
            throw new InvalidArgumentException('Provider name cannot be empty.');
        }

        return $normalized;
    }

    private function executePaymentInsert(PDOStatement $stmt, array $params): void
    {
        try {
            $stmt->execute($params);
        } catch (PDOException $exception) {
            $this->throwReferenceConstraint($exception);
        }
    }

    private function throwReferenceConstraint(PDOException $exception): never
    {
        $message = strtolower($exception->getMessage());
        if (
            str_contains($message, 'unique_payment_provider_reference')
            || str_contains($message, 'payments.provider')
            || str_contains($message, 'payments.provider_reference')
        ) {
            throw new DomainException('duplicate_provider_reference', 0, $exception);
        }
        if (
            str_contains($message, 'unique_payment_transaction_reference')
            || str_contains($message, 'unique_rent_transaction_reference')
            || str_contains($message, 'payments.transaction_reference')
            || str_contains($message, 'rent_payments.transaction_reference')
        ) {
            throw new DomainException('duplicate_transaction_reference', 0, $exception);
        }

        throw $exception;
    }

    private function issueReceipt(
    string $table,
    string $paymentColumn,
    int $paymentId,
    ?int $userId,
    string $prefix
): void {
    global $pdo;

    if (
        !in_array($table, ['payment_receipts', 'rent_payment_receipts'], true)
        || !in_array($paymentColumn, ['payment_id', 'rent_payment_id'], true)
    ) {
        throw new InvalidArgumentException('Unsupported payment receipt table.');
    }

    $receiptNumber = $prefix . '-' . date('Ymd') . '-' . bin2hex(random_bytes(12));

    $stmt = $pdo->prepare(
        "INSERT INTO {$table} ({$paymentColumn}, receipt_number, issued_by)
         VALUES (:payment_id, :receipt_number, :issued_by)"
    );

    $stmt->execute([
        ':payment_id' => $paymentId,
        ':receipt_number' => $receiptNumber,
        ':issued_by' => $userId,
    ]);
}
    private function saleFilters(array $filters): array
    {
        $conditions = [];
        $params = [];
        foreach ([
            'sale_id' => 'p.sale_id',
            'payment_type' => 'p.payment_type',
            'payment_method' => 'p.payment_method',
            'payment_source' => 'p.payment_source',
            'payment_status' => 'p.payment_status',
        ] as $key => $column) {
            if (isset($filters[$key])) {
                $conditions[] = "{$column} = :{$key}";
                $params[':' . $key] = $filters[$key];
            }
        }

        return [$conditions ? 'WHERE ' . implode(' AND ', $conditions) : '', $params];
    }

    private function rentFilters(array $filters): array
    {
        $conditions = [];
        $params = [];
        foreach (['rent_charge_id' => 'p.rent_charge_id', 'payment_method' => 'p.payment_method'] as $key => $column) {
            if (isset($filters[$key])) {
                $conditions[] = "{$column} = :{$key}";
                $params[':' . $key] = $filters[$key];
            }
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

        $stmt = $pdo->prepare($sql . ' LIMIT 1' . ($forUpdate ? $this->lockClause() : ''));
        $stmt->execute($params);
        $record = $stmt->fetch();

        return $record ?: null;
    }

    private function countRows(string $sql, array $params): int
    {
        global $pdo;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private function lockClause(): string
    {
        global $pdo;

        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
