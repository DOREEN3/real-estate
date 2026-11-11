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

    public function validatePaymentMutex(?int $saleId, ?int $tenancyId, ?int $bookingId): void
    {
        $nonNullCount = ($saleId !== null ? 1 : 0) + ($tenancyId !== null ? 1 : 0) + ($bookingId !== null ? 1 : 0);
        if ($nonNullCount !== 1) {
            throw new DomainException('payment_target_mutex_violation');
        }
    }

    public function recordSalePayment(int $saleId, ?int $userId, array $data): int
    {
        global $pdo;

        $this->validatePaymentMutex($saleId, null, null);

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

            if ($data['transaction_reference'] !== null && trim((string) $data['transaction_reference']) !== '') {
                $existingTx = $this->getPaymentByTransactionReference(trim((string) $data['transaction_reference']));
                if ($existingTx !== null) {
                    throw new DomainException('duplicate_transaction_reference');
                }
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
            $installmentRemaining = 0;
            $allowAdvance = !empty($data['confirm_overpayment']) && $data['payment_source'] === 'manual';

            if ($installment !== null) {
                $installmentPaid = $this->installmentPaid($installmentId);
                $installmentDue = $this->decimalToCents($installment['amount_due']);
                $installmentRemaining = $installmentDue - $installmentPaid;
                if ($installmentRemaining <= 0) {
                    throw new DomainException('installment_already_paid');
                }
                if ($paymentAmount > $installmentRemaining && !$allowAdvance) {
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

            // Primary installment / sale payment allocation
            $primaryCents = ($installment !== null && $allowAdvance && $paymentAmount > $installmentRemaining)
                ? $installmentRemaining
                : $paymentAmount;
            $primaryAmountFormatted = number_format($primaryCents / 100, 2, '.', '');

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
                ':amount' => $primaryAmountFormatted,
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
                        $installmentPaid + $primaryCents,
                        $installmentDue
                    );
                }
                $this->issueReceipt($paymentId, $userId);

                // If administrator confirmed advance overpayment, allocate excess across subsequent unpaid installments
                $excessCents = $paymentAmount - $primaryCents;
                if ($excessCents > 0 && $installment !== null && $allowAdvance) {
                    $nextInstallmentsStmt = $pdo->prepare(
                        'SELECT id, amount_due, installment_number
                         FROM installments
                         WHERE sale_id = :sale_id AND id <> :primary_id
                         ORDER BY installment_number ASC, due_date ASC' . $this->lockClause()
                    );
                    $nextInstallmentsStmt->execute([
                        ':sale_id' => $saleId,
                        ':primary_id' => $installmentId,
                    ]);
                    $nextInstallments = $nextInstallmentsStmt->fetchAll();

                    foreach ($nextInstallments as $nextInst) {
                        if ($excessCents <= 0) {
                            break;
                        }
                        $nextId = (int) $nextInst['id'];
                        $nextPaid = $this->installmentPaid($nextId);
                        $nextDue = $this->decimalToCents($nextInst['amount_due']);
                        $nextRem = $nextDue - $nextPaid;
                        if ($nextRem <= 0) {
                            continue;
                        }
                        $allocCents = min($excessCents, $nextRem);
                        $allocFormatted = number_format($allocCents / 100, 2, '.', '');
                        $nextType = ((int) $nextInst['installment_number'] === 0) ? 'deposit' : 'installment';
                        $advRef = $data['transaction_reference'] !== null
                            ? substr($data['transaction_reference'], 0, 130) . '-ADV' . (int) $nextInst['installment_number']
                            : null;

                        $this->executePaymentInsert($stmt, [
                            ':sale_id' => $saleId,
                            ':amount' => $allocFormatted,
                            ':payment_type' => $nextType,
                            ':payment_method' => $data['payment_method'],
                            ':payment_source' => $data['payment_source'],
                            ':payment_status' => 'completed',
                            ':provider' => null,
                            ':provider_reference' => null,
                            ':transaction_reference' => $advRef,
                            ':payment_date' => $data['payment_date'],
                            ':recorded_by' => $userId,
                            ':installment_id' => $nextId,
                            ':payer_phone' => $data['payer_phone'] ?? null,
                        ]);
                        $advPaymentId = (int) $pdo->lastInsertId();
                        $this->updateInstallmentStatus($nextId, $nextPaid + $allocCents, $nextDue);
                        $this->issueReceipt($advPaymentId, $userId);
                        $excessCents -= $allocCents;
                    }
                }

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

    public function recordTenancyPayment(int $tenancyId, ?int $userId, array $data): int
    {
        global $pdo;

        $this->validatePaymentMutex(null, $tenancyId, null);

        $pdo->beginTransaction();
        try {
            $tenancy = $this->fetchOne(
                'SELECT id, tenant_id, property_id, monthly_rent, security_deposit, currency, tenancy_status
                 FROM tenancies WHERE id = :id',
                [':id' => $tenancyId],
                true
            );
            if ($tenancy === null) {
                throw new DomainException('tenancy_not_found');
            }

            $allowedTypes = ['rent', 'security_deposit'];
            if (!in_array($data['payment_type'], $allowedTypes, true)) {
                throw new DomainException('invalid_tenancy_payment_type');
            }

            $amount = $data['amount'];
            if ((float) $amount <= 0) {
                throw new DomainException('amount_must_be_greater_than_zero');
            }

            if (!empty($data['transaction_reference']) && trim((string) $data['transaction_reference']) !== '') {
                $existingTx = $this->getPaymentByTransactionReference(trim((string) $data['transaction_reference']));
                if ($existingTx !== null) {
                    throw new DomainException('duplicate_transaction_reference');
                }
            }

            $provider = isset($data['provider']) && $data['provider'] !== null
                ? $this->normalizeProvider((string) $data['provider'])
                : null;
            if (($data['payment_source'] ?? 'manual') === 'provider' && !empty($data['provider_reference'])) {
                $existingProviderPayment = $this->getProviderPaymentByReference(
                    (string) $provider,
                    $data['provider_reference']
                );
                if ($existingProviderPayment !== null) {
                    throw new DomainException('duplicate_provider_reference');
                }
            }

            $status = ($data['payment_source'] ?? 'manual') === 'provider' ? 'pending' : 'completed';
            $rentCharge = null;
            if ($data['payment_type'] === 'rent') {
                $rentCharge = $this->fetchOne(
                    "SELECT rc.id
                     FROM rent_charges rc
                     WHERE rc.tenancy_id = :tenancy_id
                       AND rc.amount_due > (
                           COALESCE((
                               SELECT SUM(p.amount)
                               FROM payments p
                               WHERE p.rent_charge_id = rc.id
                                 AND p.payment_status = 'completed'
                           ), 0) + COALESCE((
                               SELECT SUM(rp.amount_paid)
                               FROM rent_payments rp
                               WHERE rp.rent_charge_id = rc.id
                                 AND rp.payment_id IS NULL
                           ), 0)
                       )
                     ORDER BY rc.due_date ASC, rc.id ASC",
                    [':tenancy_id' => $tenancyId]
                );
            }

            $stmt = $pdo->prepare(
                'INSERT INTO payments
                    (sale_id, tenancy_id, booking_id, amount, payment_type, payment_method, payment_source,
                     payment_status, provider, provider_reference, payer_phone,
                     transaction_reference, payment_date, recorded_by, installment_id, rent_charge_id)
                 VALUES
                    (NULL, :tenancy_id, NULL, :amount, :payment_type, :payment_method, :payment_source,
                     :payment_status, :provider, :provider_reference, :payer_phone,
                     :transaction_reference, :payment_date, :recorded_by, NULL, :rent_charge_id)'
            );
            $this->executePaymentInsert($stmt, [
                ':tenancy_id' => $tenancyId,
                ':amount' => $amount,
                ':payment_type' => $data['payment_type'],
                ':payment_method' => $data['payment_method'],
                ':payment_source' => $data['payment_source'] ?? 'manual',
                ':payment_status' => $status,
                ':provider' => $provider,
                ':provider_reference' => $data['provider_reference'] ?? null,
                ':transaction_reference' => $data['transaction_reference'] ?? null,
                ':payment_date' => $data['payment_date'],
                ':recorded_by' => $userId,
                ':payer_phone' => $data['payer_phone'] ?? null,
                ':rent_charge_id' => $rentCharge['id'] ?? null,
            ]);
            $paymentId = (int) $pdo->lastInsertId();
            if ($paymentId <= 0) {
                throw new RuntimeException('Unable to retrieve created payment ID.');
            }

            if ($status === 'completed') {
                $this->issueReceipt($paymentId, $userId, 'TEN');

                // If rent payment, also record against open rent charge if any to keep legacy rent accounting in sync
                if ($data['payment_type'] === 'rent') {
                    if ($rentCharge !== null) {
                        $rpStmt = $pdo->prepare(
                            'INSERT INTO rent_payments
                                (rent_charge_id, payment_id, amount_paid, payment_method, transaction_reference, payment_date, recorded_by)
                             VALUES
                                (:rent_charge_id, :payment_id, :amount_paid, :payment_method, :transaction_reference, :payment_date, :recorded_by)'
                        );
                        $rpStmt->execute([
                            ':rent_charge_id' => $rentCharge['id'],
                            ':payment_id' => $paymentId,
                            ':amount_paid' => $amount,
                            ':payment_method' => $data['payment_method'],
                            ':transaction_reference' => $data['transaction_reference'] ?? ('RENT-' . $paymentId),
                            ':payment_date' => $data['payment_date'],
                            ':recorded_by' => $userId ?? 1,
                        ]);
                    }
                }
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

    public function recordBookingPayment(int $bookingId, ?int $userId, array $data): int
    {
        global $pdo;

        $this->validatePaymentMutex(null, null, $bookingId);

        $pdo->beginTransaction();
        try {
            $booking = $this->fetchOne(
                'SELECT id, property_id, accommodation_total, required_booking_deposit, required_damage_deposit,
                        currency, booking_status
                 FROM bookings WHERE id = :id',
                [':id' => $bookingId],
                true
            );
            if ($booking === null) {
                throw new DomainException('booking_not_found');
            }
            if ($booking['booking_status'] === 'cancelled') {
                throw new DomainException('booking_is_cancelled');
            }

            $allowedTypes = ['accommodation', 'damage_deposit'];
            if (!in_array($data['payment_type'], $allowedTypes, true)) {
                throw new DomainException('invalid_booking_payment_type');
            }

            $paymentAmount = $this->decimalToCents($data['amount']);
            if ($paymentAmount <= 0) {
                throw new DomainException('amount_must_be_greater_than_zero');
            }

            if (!empty($data['transaction_reference']) && trim((string) $data['transaction_reference']) !== '') {
                $existingTx = $this->getPaymentByTransactionReference(trim((string) $data['transaction_reference']));
                if ($existingTx !== null) {
                    throw new DomainException('duplicate_transaction_reference');
                }
            }

            // Balance checks: accommodation charges vs damage deposits
            if ($data['payment_type'] === 'accommodation') {
                $stmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(amount), 0) FROM payments
                     WHERE booking_id = :id
                       AND payment_type IN ('accommodation', 'deposit')
                       AND (payment_status = 'completed' OR (payment_source = 'provider' AND payment_status = 'pending'))"
                );
                $stmt->execute([':id' => $bookingId]);
                $paidCents = $this->decimalToCents($stmt->fetchColumn());
                $totalCents = $this->decimalToCents($booking['accommodation_total']);
                if (($totalCents - $paidCents) <= 0) {
                    throw new DomainException('booking_charge_already_paid');
                }
                if ($paymentAmount > ($totalCents - $paidCents)) {
                    throw new DomainException('payment_exceeds_accommodation_balance');
                }
            } elseif ($data['payment_type'] === 'damage_deposit') {
                $stmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(amount), 0) FROM payments
                     WHERE booking_id = :id
                       AND payment_type = 'damage_deposit'
                       AND (payment_status = 'completed' OR (payment_source = 'provider' AND payment_status = 'pending'))"
                );
                $stmt->execute([':id' => $bookingId]);
                $paidCents = $this->decimalToCents($stmt->fetchColumn());
                $requiredCents = $this->decimalToCents($booking['required_damage_deposit']);
                if ($requiredCents > 0 && ($requiredCents - $paidCents) <= 0) {
                    throw new DomainException('booking_charge_already_paid');
                }
                if ($requiredCents > 0 && $paymentAmount > ($requiredCents - $paidCents)) {
                    throw new DomainException('payment_exceeds_damage_deposit_balance');
                }
            }

            $provider = isset($data['provider']) && $data['provider'] !== null
                ? $this->normalizeProvider((string) $data['provider'])
                : null;
            if (($data['payment_source'] ?? 'manual') === 'provider' && !empty($data['provider_reference'])) {
                $existingProviderPayment = $this->getProviderPaymentByReference(
                    (string) $provider,
                    $data['provider_reference']
                );
                if ($existingProviderPayment !== null) {
                    throw new DomainException('duplicate_provider_reference');
                }
            }

            $status = ($data['payment_source'] ?? 'manual') === 'provider' ? 'pending' : 'completed';

            $stmt = $pdo->prepare(
                'INSERT INTO payments
                    (sale_id, tenancy_id, booking_id, amount, payment_type, payment_method, payment_source,
                     payment_status, provider, provider_reference, payer_phone,
                     transaction_reference, payment_date, recorded_by, installment_id)
                 VALUES
                    (NULL, NULL, :booking_id, :amount, :payment_type, :payment_method, :payment_source,
                     :payment_status, :provider, :provider_reference, :payer_phone,
                     :transaction_reference, :payment_date, :recorded_by, NULL)'
            );
            $this->executePaymentInsert($stmt, [
                ':booking_id' => $bookingId,
                ':amount' => $data['amount'],
                ':payment_type' => $data['payment_type'],
                ':payment_method' => $data['payment_method'],
                ':payment_source' => $data['payment_source'] ?? 'manual',
                ':payment_status' => $status,
                ':provider' => $provider,
                ':provider_reference' => $data['provider_reference'] ?? null,
                ':transaction_reference' => $data['transaction_reference'] ?? null,
                ':payment_date' => $data['payment_date'],
                ':recorded_by' => $userId,
                ':payer_phone' => $data['payer_phone'] ?? null,
            ]);
            $paymentId = (int) $pdo->lastInsertId();
            if ($paymentId <= 0) {
                throw new RuntimeException('Unable to retrieve created payment ID.');
            }

            if ($status === 'completed') {
                $this->issueReceipt($paymentId, $userId, 'BNB');
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
                'SELECT id, sale_id, tenancy_id, booking_id, amount, payment_type, payment_source, payment_status,
                        installment_id, recorded_by
                 FROM payments WHERE id = :id',
                [':id' => $paymentId],
                true
            );
            if ($payment === null) {
                throw new DomainException('payment_not_found');
            }
            if ($payment['payment_source'] !== 'provider' || $payment['payment_status'] !== 'pending') {
                throw new DomainException('payment_not_pending_provider');
            }

            $installment = null;
            $installmentPaid = 0;
            $installmentDue = 0;
            $amount = $this->decimalToCents($payment['amount']);

            if ($status === 'completed' && $payment['sale_id'] !== null) {
                $sale = $this->fetchOne(
                    'SELECT id, sale_price FROM sales WHERE id = :id',
                    [':id' => $payment['sale_id']],
                    true
                );
                if ($sale === null) {
                    throw new DomainException('sale_not_found');
                }
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
                if ($payment['sale_id'] !== null) {
                    if ($installment !== null) {
                        $this->updateInstallmentStatus(
                            (int) $payment['installment_id'],
                            $installmentPaid + $amount,
                            $installmentDue
                        );
                    }
                    $this->issueReceipt($paymentId, $userId, 'SALE');
                    $this->refreshSaleState((int) $payment['sale_id']);
                } elseif ($payment['tenancy_id'] !== null) {
                    $this->issueReceipt($paymentId, $userId, 'TEN');
                } elseif ($payment['booking_id'] !== null) {
                    $this->issueReceipt($paymentId, $userId, 'BNB');
                }
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

    public function getPayment(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT p.*, r.receipt_number, r.issued_at, r.issued_by, r.issuer_type,
                    u.full_name AS recorded_by_name,
                    COALESCE(s.buyer_name, tn.full_name, b.guest_name) AS client_name,
                    COALESCE(s.buyer_name, tn.full_name, b.guest_name) AS buyer_name,
                    COALESCE(s.currency, t.currency, b.currency, \'KES\') AS currency,
                    COALESCE(invoice_by_installment.invoice_number, invoice_by_rent.invoice_number) AS invoice_number,
                    CASE 
                        WHEN p.sale_id IS NOT NULL THEN \'sale\'
                        WHEN p.tenancy_id IS NOT NULL THEN \'rental\'
                        WHEN p.booking_id IS NOT NULL THEN \'bnb\'
                        ELSE \'unknown\'
                    END AS transaction_scope
             FROM payments p
             LEFT JOIN payment_receipts r ON r.payment_id = p.id
             LEFT JOIN payment_invoices invoice_by_installment ON invoice_by_installment.installment_id = p.installment_id
             LEFT JOIN payment_invoices invoice_by_rent ON invoice_by_rent.rent_charge_id = p.rent_charge_id
             LEFT JOIN users u ON u.id = p.recorded_by
             LEFT JOIN sales s ON s.id = p.sale_id
             LEFT JOIN tenancies t ON t.id = p.tenancy_id
             LEFT JOIN tenants tn ON tn.id = t.tenant_id
             LEFT JOIN bookings b ON b.id = p.booking_id
             WHERE p.id = :id',
            [':id' => $id]
        );
    }

    public function getSalePayment(int $id): ?array
    {
        return $this->getPayment($id);
    }

    public function reversePayment(int $paymentId, int $adminId, string $action, string $reason): array
    {
        global $pdo;

        if (!in_array($action, ['reverse', 'refund', 'void'], true)) {
            return [
                'success' => false,
                'message' => "Invalid reversal action: must be 'reverse', 'refund', or 'void'",
                'status_code' => 400,
            ];
        }

        $payment = $this->getPayment($paymentId);
        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found', 'status_code' => 404];
        }

        if (in_array($payment['payment_status'], ['reversed', 'refunded', 'voided'], true)) {
            return [
                'success' => false,
                'message' => "Payment has already been {$payment['payment_status']}",
                'status_code' => 409,
            ];
        }

        $targetStatus = match ($action) {
            'refund' => 'refunded',
            'void' => 'voided',
            default => 'reversed',
        };

        $pdo->beginTransaction();
        try {
            try {
                $stmt = $pdo->prepare(
                    "UPDATE payments
                     SET payment_status = :status,
                         reversed_at = CURRENT_TIMESTAMP,
                         reversed_by = :admin_id,
                         reversal_reason = :reason
                     WHERE id = :id"
                );
                $stmt->execute([
                    ':status' => $targetStatus,
                    ':admin_id' => $adminId,
                    ':reason' => $reason,
                    ':id' => $paymentId,
                ]);
            } catch (Throwable) {
                $stmt = $pdo->prepare(
                    "UPDATE payments
                     SET payment_status = :status
                     WHERE id = :id"
                );
                $stmt->execute([
                    ':status' => $targetStatus,
                    ':id' => $paymentId,
                ]);
            }

            // If linked to an installment, recalculate installment status
            if (!empty($payment['installment_id'])) {
                $installmentId = (int) $payment['installment_id'];
                $paidStmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(amount), 0) FROM payments
                     WHERE installment_id = :id AND payment_status = 'completed'"
                );
                $paidStmt->execute([':id' => $installmentId]);
                $paid = (float) $paidStmt->fetchColumn();

                $instStmt = $pdo->prepare("SELECT amount_due FROM installments WHERE id = :id");
                $instStmt->execute([':id' => $installmentId]);
                $amountDue = (float) $instStmt->fetchColumn();

                $newInstStatus = ($paid >= $amountDue && $amountDue > 0) ? 'paid' : ($paid > 0 ? 'partial' : 'pending');
                $pdo->prepare("UPDATE installments SET status = :status WHERE id = :id")
                    ->execute([':status' => $newInstStatus, ':id' => $installmentId]);
            }

            // If linked to a sale, refresh sale state
            if (!empty($payment['sale_id'])) {
                $this->refreshSaleState((int) $payment['sale_id']);
            }

            $pdo->commit();

            Middleware::auditLog($adminId, 'payment_' . $action, 'payments', $paymentId, [
                'action' => $action,
                'target_status' => $targetStatus,
                'reason' => $reason,
                'previous_status' => $payment['payment_status'],
                'amount' => $payment['amount'],
                'sale_id' => $payment['sale_id'],
                'tenancy_id' => $payment['tenancy_id'],
                'installment_id' => $payment['installment_id'],
            ]);

            return [
                'success' => true,
                'message' => "Payment successfully {$targetStatus}",
                'payment' => $this->getPayment($paymentId),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function listSalePayments(array $filters, int $page, int $limit): array
    {
        [$where, $params] = $this->saleFilters($filters);

        return $this->fetchPage(
            "SELECT p.*, r.receipt_number, r.issued_at, r.issuer_type,
                    u.full_name AS recorded_by_name, u.email AS recorded_by_email,
                    COALESCE(s.buyer_name, tn.full_name, b.guest_name) AS buyer_name,
                    COALESCE(sp.title, tp.title, bp.title) AS property_title,
                    COALESCE(s.currency, t.currency, b.currency, 'KES') AS currency,
                    COALESCE(invoice_by_installment.invoice_number, invoice_by_rent.invoice_number) AS invoice_number,
                    CASE 
                        WHEN p.sale_id IS NOT NULL THEN 'sale'
                        WHEN p.tenancy_id IS NOT NULL THEN 'rental'
                        WHEN p.booking_id IS NOT NULL THEN 'bnb'
                        ELSE 'unknown'
                    END AS transaction_scope
             FROM payments p
             LEFT JOIN payment_receipts r ON r.payment_id = p.id
             LEFT JOIN users u ON u.id = p.recorded_by
             LEFT JOIN sales s ON s.id = p.sale_id
             LEFT JOIN properties sp ON sp.id = s.property_id
             LEFT JOIN tenancies t ON t.id = p.tenancy_id
             LEFT JOIN properties tp ON tp.id = t.property_id
             LEFT JOIN tenants tn ON tn.id = t.tenant_id
             LEFT JOIN bookings b ON b.id = p.booking_id
             LEFT JOIN properties bp ON bp.id = b.property_id
             LEFT JOIN payment_invoices invoice_by_installment ON invoice_by_installment.installment_id = p.installment_id
             LEFT JOIN payment_invoices invoice_by_rent ON invoice_by_rent.rent_charge_id = p.rent_charge_id
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
            || str_contains($message, 'payments.transaction_reference')
        ) {
            throw new DomainException('duplicate_transaction_reference', 0, $exception);
        }

        throw $exception;
    }

    private function issueReceipt(int $paymentId, ?int $userId, string $prefix = 'SALE'): void
    {
        global $pdo;

        $receiptNumber = $prefix . '-' . date('Ymd') . '-' . bin2hex(random_bytes(12));
        $stmt = $pdo->prepare(
            'INSERT INTO payment_receipts (payment_id, receipt_number, issued_by, issuer_type)
             VALUES (:payment_id, :receipt_number, :issued_by, :issuer_type)'
        );
        $stmt->execute([
            ':payment_id' => $paymentId,
            ':receipt_number' => $receiptNumber,
            ':issued_by' => $userId,
            ':issuer_type' => $userId === null ? 'system' : 'user',
        ]);
    }
    private function saleFilters(array $filters): array
    {
        $conditions = [];
        $params = [];
        foreach ([
            'sale_id' => 'p.sale_id',
            'tenancy_id' => 'p.tenancy_id',
            'booking_id' => 'p.booking_id',
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

    public function storeBankCallback(
        string $txRef,
        ?string $accRef,
        float $amount,
        string $currency,
        string $bankName,
        array $payload
    ): array {
        global $pdo;

        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $stmt = $pdo->prepare(
            'INSERT INTO bank_callback_inbox
                (transaction_reference, account_reference, amount, currency, bank_name, callback_payload)
             VALUES
                (:transaction_reference, :account_reference, :amount, :currency, :bank_name, :callback_payload)
             ON DUPLICATE KEY UPDATE
                account_reference = COALESCE(VALUES(account_reference), account_reference),
                amount = VALUES(amount)'
        );

        $stmt->execute([
            ':transaction_reference' => $txRef,
            ':account_reference' => $accRef,
            ':amount' => $amount,
            ':currency' => $currency,
            ':bank_name' => $bankName,
            ':callback_payload' => $payloadJson,
        ]);

        return $this->fetchOne(
            'SELECT * FROM bank_callback_inbox WHERE transaction_reference = :ref',
            [':ref' => $txRef]
        ) ?: [];
    }

    public function updateBankCallbackStatus(int $inboxId, string $status, ?string $error = null, ?int $paymentId = null): void
    {
        global $pdo;

        $stmt = $pdo->prepare(
            'UPDATE bank_callback_inbox
             SET processing_status = :status,
                 processing_error = :error,
                 payment_id = COALESCE(:payment_id, payment_id),
                 processed_at = NOW()
             WHERE id = :id'
        );

        $stmt->execute([
            ':id' => $inboxId,
            ':status' => $status,
            ':error' => $error,
            ':payment_id' => $paymentId,
        ]);
    }

    public function getPaymentByTransactionReference(string $txRef): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM payments WHERE transaction_reference = :tx_ref',
            [':tx_ref' => $txRef]
        );
    }

    public function getPaymentsReport(
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $paymentMethod = null,
        int $page = 1,
        int $limit = 100
    ): array
    {
        global $pdo;

        $conditions = ["p.payment_status = 'completed'"];
        $params = [];

        if ($startDate) {
            $conditions[] = 'p.payment_date >= :start_date';
            $params[':start_date'] = $startDate . ' 00:00:00';
        }
        if ($endDate) {
            $conditions[] = 'p.payment_date <= :end_date';
            $params[':end_date'] = $endDate . ' 23:59:59';
        }
        if ($paymentMethod) {
            $conditions[] = 'p.payment_method = :payment_method';
            $params[':payment_method'] = $paymentMethod;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        // Overall totals
        $stmt = $pdo->prepare(
            "SELECT 
                COUNT(*) AS total_transactions,
                COALESCE(SUM(p.amount), 0) AS total_revenue,
                COALESCE(AVG(p.amount), 0) AS average_payment,
                MIN(p.amount) AS minimum_payment,
                MAX(p.amount) AS maximum_payment
             FROM payments p {$where}"
        );
        $stmt->execute($params);
        $summary = $stmt->fetch() ?: [];

        $stmtCurrency = $pdo->prepare(
            "SELECT COALESCE(s.currency, t.currency, b.currency, 'KES') AS currency,
                    COUNT(*) AS transaction_count,
                    COALESCE(SUM(p.amount), 0) AS total_revenue,
                    COALESCE(AVG(p.amount), 0) AS average_payment,
                    MIN(p.amount) AS minimum_payment,
                    MAX(p.amount) AS maximum_payment
             FROM payments p
             LEFT JOIN sales s ON s.id = p.sale_id
             LEFT JOIN tenancies t ON t.id = p.tenancy_id
             LEFT JOIN bookings b ON b.id = p.booking_id
             {$where}
             GROUP BY COALESCE(s.currency, t.currency, b.currency, 'KES')
             ORDER BY currency"
        );
        $stmtCurrency->execute($params);
        $byCurrency = $stmtCurrency->fetchAll();

        // Breakdown by payment method
        $stmtMethod = $pdo->prepare(
            "SELECT 
                p.payment_method,
                COALESCE(s.currency, t.currency, b.currency, 'KES') AS currency,
                COUNT(*) AS transaction_count,
                COALESCE(SUM(p.amount), 0) AS total_amount
             FROM payments p
             LEFT JOIN sales s ON s.id = p.sale_id
             LEFT JOIN tenancies t ON t.id = p.tenancy_id
             LEFT JOIN bookings b ON b.id = p.booking_id
             {$where}
             GROUP BY p.payment_method, COALESCE(s.currency, t.currency, b.currency, 'KES')"
        );
        $stmtMethod->execute($params);
        $byMethod = $stmtMethod->fetchAll();

        // Breakdown by payment type
        $stmtType = $pdo->prepare(
            "SELECT 
                p.payment_type,
                COALESCE(s.currency, t.currency, b.currency, 'KES') AS currency,
                COUNT(*) AS transaction_count,
                COALESCE(SUM(p.amount), 0) AS total_amount
             FROM payments p
             LEFT JOIN sales s ON s.id = p.sale_id
             LEFT JOIN tenancies t ON t.id = p.tenancy_id
             LEFT JOIN bookings b ON b.id = p.booking_id
             {$where}
             GROUP BY p.payment_type, COALESCE(s.currency, t.currency, b.currency, 'KES')"
        );
        $stmtType->execute($params);
        $byType = $stmtType->fetchAll();

        // Breakdown by revenue stream (sale, rental, bnb)
        $stmtStream = $pdo->prepare(
            "SELECT 
                CASE 
                    WHEN p.sale_id IS NOT NULL THEN 'sale'
                    WHEN p.tenancy_id IS NOT NULL THEN 'rental'
                    WHEN p.booking_id IS NOT NULL THEN 'bnb'
                    ELSE 'other'
                END AS transaction_scope,
                COALESCE(s.currency, t.currency, b.currency, 'KES') AS currency,
                COUNT(*) AS transaction_count,
                COALESCE(SUM(p.amount), 0) AS total_amount
             FROM payments p
             LEFT JOIN sales s ON s.id = p.sale_id
             LEFT JOIN tenancies t ON t.id = p.tenancy_id
             LEFT JOIN bookings b ON b.id = p.booking_id
             {$where}
             GROUP BY transaction_scope, COALESCE(s.currency, t.currency, b.currency, 'KES')"
        );
        $stmtStream->execute($params);
        $byStream = $stmtStream->fetchAll();

        // Recent completed transactions (sales, rentals, and BnB bookings)
        $stmtRecent = $pdo->prepare(
            "SELECT p.*,
                    r.receipt_number,
                    COALESCE(invoice_by_installment.invoice_number, invoice_by_rent.invoice_number) AS invoice_number,
                    COALESCE(s.currency, t.currency, b.currency, 'KES') AS currency,
                    COALESCE(s.buyer_name, tn.full_name, b.guest_name) AS client_name,
                    COALESCE(s.buyer_name, tn.full_name, b.guest_name) AS buyer_name,
                    COALESCE(pr_s.title, pr_t.title, pr_b.title) AS property_title,
                    CASE 
                        WHEN p.sale_id IS NOT NULL THEN 'sale'
                        WHEN p.tenancy_id IS NOT NULL THEN 'rental'
                        WHEN p.booking_id IS NOT NULL THEN 'bnb'
                        ELSE 'unknown'
                    END AS transaction_scope
             FROM payments p
             LEFT JOIN payment_receipts r ON r.payment_id = p.id
             LEFT JOIN payment_invoices invoice_by_installment ON invoice_by_installment.installment_id = p.installment_id
             LEFT JOIN payment_invoices invoice_by_rent ON invoice_by_rent.rent_charge_id = p.rent_charge_id
             LEFT JOIN sales s ON s.id = p.sale_id
             LEFT JOIN properties pr_s ON pr_s.id = s.property_id
             LEFT JOIN tenancies t ON t.id = p.tenancy_id
             LEFT JOIN tenants tn ON tn.id = t.tenant_id
             LEFT JOIN properties pr_t ON pr_t.id = t.property_id
             LEFT JOIN bookings b ON b.id = p.booking_id
             LEFT JOIN properties pr_b ON pr_b.id = b.property_id
             {$where}
             ORDER BY p.payment_date DESC, p.id DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmtRecent->execute($params + [
            ':limit' => $limit,
            ':offset' => ($page - 1) * $limit,
        ]);
        $recent = $stmtRecent->fetchAll();
        $totalTransactions = (int) ($summary['total_transactions'] ?? 0);

        return [
            'summary' => $summary,
            'by_currency' => $byCurrency,
            'by_method' => $byMethod,
            'by_type' => $byType,
            'by_stream' => $byStream,
            'recent_transactions' => $recent,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalTransactions,
                'total_pages' => (int) ceil($totalTransactions / $limit),
            ],
        ];
    }

    public function getInstallmentsReport(): array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT 
                COUNT(*) AS total_installments,
                SUM(CASE WHEN i.status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN i.status = 'pending' AND i.due_date >= CURRENT_DATE THEN 1 ELSE 0 END) AS upcoming_count,
                SUM(CASE WHEN i.status = 'pending' AND i.due_date < CURRENT_DATE THEN 1 ELSE 0 END) AS overdue_count,
                COALESCE(SUM(i.amount_due), 0) AS total_due,
                COALESCE(SUM(CASE WHEN i.status = 'paid' THEN i.amount_due ELSE 0 END), 0) AS total_collected,
                COALESCE(SUM(CASE WHEN i.status != 'paid' THEN i.amount_due ELSE 0 END), 0) AS total_outstanding
             FROM installments i"
        );
        $stmt->execute();
        $summary = $stmt->fetch() ?: [];

        // Overdue list
        $stmtOverdue = $pdo->prepare(
            "SELECT i.*, s.buyer_name, s.buyer_email, s.currency, pr.title AS property_title,
                    COALESCE((
                        SELECT SUM(p.amount)
                        FROM payments p
                        WHERE p.installment_id = i.id AND p.payment_status = 'completed'
                    ), 0) AS amount_paid,
                    i.amount_due - COALESCE((
                        SELECT SUM(p.amount)
                        FROM payments p
                        WHERE p.installment_id = i.id AND p.payment_status = 'completed'
                    ), 0) AS outstanding_balance,
                    DATEDIFF(CURRENT_DATE, i.due_date) AS days_overdue
             FROM installments i
             JOIN sales s ON s.id = i.sale_id
             JOIN properties pr ON pr.id = s.property_id
             WHERE i.status != 'paid' AND i.due_date < CURRENT_DATE
             ORDER BY i.due_date ASC"
        );
        $stmtOverdue->execute();
        $overdue = $stmtOverdue->fetchAll();

        // Upcoming installments list
        $stmtUpcoming = $pdo->prepare(
            "SELECT i.*, s.buyer_name, s.buyer_email, s.currency, pr.title AS property_title,
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
             JOIN sales s ON s.id = i.sale_id
             JOIN properties pr ON pr.id = s.property_id
             WHERE i.status != 'paid' AND i.due_date >= CURRENT_DATE
             ORDER BY i.due_date ASC
             LIMIT 30"
        );
        $stmtUpcoming->execute();
        $upcoming = $stmtUpcoming->fetchAll();

        return [
            'summary' => $summary,
            'overdue_installments' => $overdue,
            'upcoming_installments' => $upcoming,
        ];
    }

    public function getSaleStatement(int $saleId): ?array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            "SELECT s.*, pr.title AS property_title, pr.property_type, pr.address, pr.city,
                    COALESCE((
                        SELECT SUM(pay.amount) FROM payments pay 
                        WHERE pay.sale_id = s.id AND pay.payment_status = 'completed'
                    ), 0) AS total_paid
             FROM sales s
             JOIN properties pr ON pr.id = s.property_id
             WHERE s.id = :id"
        );
        $stmt->execute([':id' => $saleId]);
        $sale = $stmt->fetch();
        if (!$sale) {
            return null;
        }

        $salePrice = (float) $sale['sale_price'];
        $totalPaid = (float) $sale['total_paid'];
        $outstanding = max(0, $salePrice - $totalPaid);

        // Installments schedule
        $stmtInst = $pdo->prepare(
            "SELECT i.*, invoice.invoice_number,
                    COALESCE((SELECT SUM(amount) FROM payments p WHERE p.installment_id = i.id AND p.payment_status = 'completed'), 0) AS amount_paid
             FROM installments i
             LEFT JOIN payment_invoices invoice ON invoice.installment_id = i.id
             WHERE i.sale_id = :sale_id
             ORDER BY i.installment_number ASC"
        );
        $stmtInst->execute([':sale_id' => $saleId]);
        $installments = $stmtInst->fetchAll();

        // Payments ledger
        $stmtPay = $pdo->prepare(
            "SELECT p.*, r.receipt_number AS official_receipt_number,
                    invoice.invoice_number,
                    u.full_name AS recorded_by_name, u.email AS recorded_by_email
             FROM payments p
             LEFT JOIN payment_receipts r ON r.payment_id = p.id
             LEFT JOIN payment_invoices invoice ON invoice.installment_id = p.installment_id
             LEFT JOIN users u ON u.id = p.recorded_by
             WHERE p.sale_id = :sale_id
             ORDER BY p.payment_date ASC, p.id ASC"
        );
        $stmtPay->execute([':sale_id' => $saleId]);
        $payments = $stmtPay->fetchAll();

        return [
            'sale' => $sale,
            'summary' => [
                'sale_price' => $salePrice,
                'total_paid' => $totalPaid,
                'outstanding_balance' => $outstanding,
                'completion_percentage' => $salePrice > 0 ? round(($totalPaid / $salePrice) * 100, 2) : 0,
            ],
            'installments' => $installments,
            'payments' => $payments,
        ];
    }

    private function lockClause(): string
    {
        global $pdo;

        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
