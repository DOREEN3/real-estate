<?php

declare(strict_types=1);

require_once __DIR__ . '/../models/PaymentModel.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../security/Middleware.php';
require_once __DIR__ . '/../security/Validator.php';
require_once __DIR__ . '/../services/MpesaDarajaService.php';

class PaymentController
{
    private const PAYMENT_TYPES = ['deposit', 'installment', 'full'];
    private const PAYMENT_METHODS = ['cash', 'mobile_money', 'bank_transfer'];

    private PaymentModel $paymentModel;
    private MpesaDarajaService $mpesaDarajaService;

    public function __construct()
    {
        $this->paymentModel = new PaymentModel();
        $this->mpesaDarajaService = new MpesaDarajaService();
    }

    public function createSalePayment(string $id): void
    {
        $user = Middleware::requireRole(['admin']);
        $saleId = $this->positiveId($id, 'sale ID');
        $data = $this->validatedSalePayment(getJsonBody());

        try {
            $paymentId = $this->paymentModel->recordSalePayment(
                $saleId,
                (int) $user['sub'],
                $data
            );
        } catch (DomainException $exception) {
            $errors = [
                'sale_not_found' => ['Sale not found', 404],
                'installment_not_found' => ['Installment not found for this sale', 422],
                'payment_exceeds_sale_balance' => ['Payment exceeds the outstanding sale balance', 422],
                'payment_exceeds_installment_balance' => ['Payment exceeds the outstanding installment balance', 422],
                'payment_type_installment_mismatch' => ['Payment type does not match the selected installment', 422],
                'deposit_installment_required' => ['Deposit payments must be linked to the deposit installment', 422],
                'installment_required' => ['Payments for an installment sale must be linked to an installment', 422],
                'invalid_payment_type' => ['Invalid payment_type', 400],
                'duplicate_provider_reference' => ['Provider reference is already in use', 409],
                'duplicate_transaction_reference' => ['Transaction reference is already in use', 409],
            ];
            $error = $exception->getMessage();
            if (isset($errors[$error])) {
                sendError($errors[$error][0], $errors[$error][1]);
            }
            throw $exception;
        }

        $payment = $this->paymentModel->getSalePayment($paymentId);
        if ($payment === null) {
            error_log('Sale payment insert completed without a retrievable record.');
            sendError('Unable to retrieve recorded payment', 500);
        }
        $message = $payment['payment_status'] === 'completed'
            ? 'Sale payment recorded and receipt issued'
            : 'Provider payment recorded as pending; no receipt has been issued';
        sendSuccess($message, ['payment' => $payment], 201);
    }

    public function initiateMpesaSalePayment(int $saleId): void
{
        $user = Middleware::requireRole(['admin']);

    try {
            $userId = (int) $user['sub'];
        $data = $this->validatedMpesaStkRequest();

        $amountInCents = (int) round((float) $data['amount'] * 100);

        if ($amountInCents <= 0 || $amountInCents % 100 !== 0) {
            throw new InvalidArgumentException(
                'M-Pesa payments must be whole Kenyan shillings.'
            );
        }

        $phone = $data['payer_phone'];

        // Create a pending payment before requesting STK Push.
        $paymentId = $this->paymentModel->recordSalePayment(
            $saleId,
            $userId,
            [
                'amount' => $data['amount'],
                'payment_type' => $data['payment_type'],
                'payment_method' => 'mobile_money',
                'payment_source' => 'provider',
                'provider' => 'mpesa',
                'provider_reference' => null,
                'payer_phone' => $phone,
                'transaction_reference' => null,
                'payment_date' => date('Y-m-d H:i:s'),
                'installment_id' => $data['installment_id'],
            ]
        );

        $response = $this->mpesaDarajaService->initiateStkPush(
            $phone,
            (int) ($amountInCents / 100),
            'SALE-' . $paymentId,
            'Real estate payment'
        );

        $checkoutRequestId = $response['checkout_request_id'] ?? null;

        if (!is_string($checkoutRequestId) || $checkoutRequestId === '') {
            throw new RuntimeException(
                'M-Pesa did not return a valid CheckoutRequestID.'
            );
        }

        $this->paymentModel->attachMpesaCheckoutReference(
            $paymentId,
            $checkoutRequestId
        );

        // Check whether M-Pesa's callback arrived before the
        // checkout reference was attached to this payment.
        $storedCallback = $this->paymentModel->getMpesaCallback(
            $checkoutRequestId
        );

        if ($storedCallback !== null) {
            $linkedPayment = $this->paymentModel->getProviderPaymentByReference(
                'mpesa',
                $checkoutRequestId
            );

            if ($linkedPayment !== null) {
                $this->processStoredMpesaCallback(
                    $storedCallback,
                    $linkedPayment
                );
            }
        }

        sendResponse([
            'success' => true,
            'message' => 'STK Push request sent successfully.',
            'payment_id' => $paymentId,
            'checkout_request_id' => $checkoutRequestId,
            'customer_message' => $response['customer_message'] ?? null,
        ], 200);

    } catch (InvalidArgumentException $exception) {
        sendResponse([
            'success' => false,
            'message' => $exception->getMessage(),
        ], 422);

    } catch (DomainException $exception) {
        sendResponse([
            'success' => false,
            'message' => $exception->getMessage(),
        ], 422);

    } catch (Throwable $exception) {
        error_log('M-Pesa STK Push error: ' . $exception->getMessage());

        sendResponse([
            'success' => false,
            'message' => 'Unable to complete the STK Push request. Check the payment status before retrying.',
        ], 502);
    }
}
private function validatedMpesaStkRequest(): array
{
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid JSON request body.');
    }

    $amount = $this->requiredAmount($input, 'amount');

    $paymentType = $input['payment_type'] ?? null;

    if (!in_array($paymentType, self::PAYMENT_TYPES, true)) {
        throw new InvalidArgumentException('Invalid payment type.');
    }

    $phone = preg_replace('/[\s+\-]/', '', (string) ($input['payer_phone'] ?? ''));

    if (preg_match('/^(0[17]\d{8}|254[17]\d{8})$/', $phone) !== 1) {
        throw new InvalidArgumentException('Enter a valid Kenyan mobile number.');
    }

    if (str_starts_with($phone, '0')) {
        $phone = '254' . substr($phone, 1);
    }

    $installmentId = $input['installment_id'] ?? null;

    if ($installmentId !== null) {
        $installmentId = filter_var($installmentId, FILTER_VALIDATE_INT);

        if ($installmentId === false || $installmentId <= 0) {
            throw new InvalidArgumentException('Invalid installment ID.');
        }
    }

    return [
        'amount' => $amount,
        'payment_type' => $paymentType,
        'payer_phone' => $phone,
        'installment_id' => $installmentId,
    ];
}
    public function resolveProviderPayment(string $id): void
    {
        $user = Middleware::requireRole(['admin']);
        $paymentId = $this->positiveId($id, 'payment ID');
        $body = getJsonBody();
        $this->rejectUnknownFields($body, ['payment_status', 'provider_reference']);
        $status = $this->requiredString($body, 'payment_status', 30);
        if (!in_array($status, ['completed', 'failed'], true)) {
            sendError('payment_status must be completed or failed', 400, [
                'allowed' => ['completed', 'failed'],
            ]);
        }
        $providerReference = $this->optionalString($body, 'provider_reference', 150);

        try {
            $this->paymentModel->resolveProviderPayment(
                $paymentId,
                (int) $user['sub'],
                $status,
                $providerReference
            );
        } catch (DomainException $exception) {
            $errors = [
                'payment_not_found' => ['Payment not found', 404],
                'payment_not_pending_provider' => ['Only pending provider payments can be resolved', 409],
                'invalid_payment_status' => ['payment_status must be completed or failed', 400],
                'payment_exceeds_sale_balance' => ['Payment exceeds the outstanding sale balance', 422],
                'payment_exceeds_installment_balance' => ['Payment exceeds the outstanding installment balance', 422],
                'installment_not_found' => ['Installment not found for this sale', 422],
                'payment_type_installment_mismatch' => ['Payment type does not match the selected installment', 422],
                'deposit_installment_required' => ['Deposit payments must be linked to the deposit installment', 422],
                'installment_required' => ['Payments for an installment sale must be linked to an installment', 422],
                'duplicate_transaction_reference' => ['Provider transaction reference is already in use', 409],
            ];
            $error = $exception->getMessage();
            if (isset($errors[$error])) {
                sendError($errors[$error][0], $errors[$error][1]);
            }
            throw $exception;
        }

        $payment = $this->paymentModel->getSalePayment($paymentId);
        if ($payment === null) {
            error_log('Resolved provider payment is not retrievable.');
            sendError('Unable to retrieve resolved payment', 500);
        }
        sendSuccess(
            $status === 'completed'
                ? 'Provider payment confirmed and receipt issued'
                : 'Provider payment marked failed',
            ['payment' => $payment]
        );
    }

    
public function mpesaCallback(): void
{
    $body = getJsonBody();
    $callback = $body['Body']['stkCallback'] ?? null;

    if (!is_array($callback)) {
        sendError('Invalid M-Pesa callback payload', 400);
    }

    $checkoutRequestId = $callback['CheckoutRequestID'] ?? null;
    $merchantRequestId = $callback['MerchantRequestID'] ?? null;
    $callbackResultCode = filter_var(
        $callback['ResultCode'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (
        !is_string($checkoutRequestId)
        || $checkoutRequestId === ''
        || mb_strlen($checkoutRequestId) > 100
        || $callbackResultCode === false
        || $callbackResultCode < 0
        || (
            $merchantRequestId !== null
            && (
                !is_string($merchantRequestId)
                || mb_strlen($merchantRequestId) > 100
            )
        )
    ) {
        sendError('Invalid M-Pesa callback payload', 400);
    }

    try {
        // Store the callback before attempting payment lookup.
        $inbox = $this->paymentModel->storeMpesaCallback(
            $checkoutRequestId,
            $merchantRequestId,
            $body
        );

        $payment = $this->paymentModel->getProviderPaymentByReference(
            'mpesa',
            $checkoutRequestId
        );

        // The callback arrived before the checkout reference
        // was attached to the payment. Keep it for later processing.
        if ($payment === null) {
            sendResponse([
                'ResultCode' => 0,
                'ResultDesc' => 'Accepted and stored for processing',
            ]);
        }

        // Link the stored callback to its payment.
        $this->paymentModel->linkMpesaCallbackToPayment(
            $checkoutRequestId,
            (int) $payment['id']
        );

    } catch (DomainException $exception) {
        if ($exception->getMessage() === 'duplicate_provider_reference') {
            sendError('M-Pesa checkout reference is ambiguous', 409);
        }

        throw $exception;
    }

    $this->processStoredMpesaCallback($inbox, $payment);

    sendResponse([
        'ResultCode' => 0,
        'ResultDesc' => 'Accepted',
    ]);
}
private function processStoredMpesaCallback(array $inbox, array $payment): void
{
    try {
        $body = json_decode(
            (string) ($inbox['callback_payload'] ?? ''),
            true
        );

        $callback = $body['Body']['stkCallback'] ?? null;

        if (!is_array($callback)) {
            throw new DomainException('mpesa_callback_invalid_payload');
        }

        $checkoutRequestId = $callback['CheckoutRequestID'] ?? null;
        $callbackResultCode = filter_var(
            $callback['ResultCode'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (
            !is_string($checkoutRequestId)
            || $checkoutRequestId === ''
            || $callbackResultCode === false
            || $callbackResultCode < 0
        ) {
            throw new DomainException('mpesa_callback_invalid_payload');
        }

        $verifiedResult = $this->mpesaDarajaService->queryStkStatus(
            $checkoutRequestId
        );

        if (
            $verifiedResult['checkout_request_id'] !== $checkoutRequestId
            || $verifiedResult['result_code'] !== $callbackResultCode
        ) {
            throw new DomainException('mpesa_callback_verification_mismatch');
        }

        $status = $callbackResultCode === 0 ? 'completed' : 'failed';
        $transactionReference = null;

        if ($status === 'completed') {
            $metadata = $callback['CallbackMetadata']['Item'] ?? null;

            if (!is_array($metadata)) {
                throw new DomainException('mpesa_callback_missing_metadata');
            }

            $items = [];

            foreach ($metadata as $item) {
                if (
                    is_array($item)
                    && isset($item['Name'], $item['Value'])
                    && is_string($item['Name'])
                ) {
                    $items[$item['Name']] = $item['Value'];
                }
            }

            $receivedAmount = $items['Amount'] ?? null;
            $transactionReference = $items['MpesaReceiptNumber'] ?? null;

            if (
                (!is_string($receivedAmount)
                    && !is_int($receivedAmount)
                    && !is_float($receivedAmount))
                || !is_string($transactionReference)
                || $transactionReference === ''
                || mb_strlen($transactionReference) > 150
                || $this->amountToCents((string) $receivedAmount)
                    !== $this->amountToCents((string) $payment['amount'])
            ) {
                throw new DomainException('mpesa_transaction_details_mismatch');
            }
        }

        if ($payment['payment_status'] === 'pending') {
            $this->paymentModel->resolveProviderPayment(
                (int) $payment['id'],
                null,
                $status,
                null,
                $transactionReference
            );
        } elseif ($payment['payment_status'] !== $status) {
            throw new DomainException('mpesa_payment_already_resolved_differently');
        }

        $this->paymentModel->updateMpesaCallbackStatus(
            (int) $inbox['id'],
            'processed'
        );

    } catch (Throwable $exception) {
        error_log(
            'M-Pesa callback processing error: ' . $exception->getMessage()
        );

        $permanentErrors = [
            'mpesa_callback_invalid_payload',
            'mpesa_callback_verification_mismatch',
            'mpesa_callback_missing_metadata',
            'mpesa_transaction_details_mismatch',
            'mpesa_payment_already_resolved_differently',
            'payment_exceeds_sale_balance',
            'payment_exceeds_installment_balance',
            'duplicate_transaction_reference',
        ];

        $error = $exception instanceof DomainException
            ? $exception->getMessage()
            : 'callback_processing_error';

        $processingStatus = in_array($error, $permanentErrors, true)
            ? 'failed'
            : 'pending';

        $this->paymentModel->updateMpesaCallbackStatus(
            (int) $inbox['id'],
            $processingStatus,
            $error
        );
    }
}

    public function listSalePayments(string $id): void
    {
        Middleware::requireRole(['admin']);
        $saleId = $this->positiveId($id, 'sale ID');
        if (!$this->paymentModel->saleExists($saleId)) {
            sendError('Sale not found', 404);
        }
        [$page, $limit] = $this->pagination();
        $filters = ['sale_id' => $saleId];
        $total = $this->paymentModel->countSalePayments($filters);

        sendSuccess('Sale payments retrieved', [
            'data' => $this->paymentModel->listSalePayments($filters, $page, $limit),
            'pagination' => $this->paginationData($page, $limit, $total),
        ]);
    }

    public function listSalePaymentsAdmin(): void
    {
        Middleware::requireRole(['admin']);
        [$page, $limit] = $this->pagination();
        $filters = [
            'sale_id' => $this->queryPositiveId('sale_id'),
            'payment_type' => $this->queryString('payment_type', 30),
            'payment_method' => $this->queryString('payment_method', 30),
            'payment_source' => $this->queryString('payment_source', 30),
            'payment_status' => $this->queryString('payment_status', 30),
        ];
        $total = $this->paymentModel->countSalePayments($filters);

        sendSuccess('Sale payments retrieved', [
            'data' => $this->paymentModel->listSalePayments($filters, $page, $limit),
            'pagination' => $this->paginationData($page, $limit, $total),
        ]);
    }

    public function getSalePayment(string $id): void
    {
        Middleware::requireRole(['admin']);
        $payment = $this->paymentModel->getSalePayment(
            $this->positiveId($id, 'payment ID')
        );
        if ($payment === null) {
            sendError('Payment not found', 404);
        }

        sendSuccess('Payment retrieved', ['payment' => $payment]);
    }

    public function createRentPayment(string $id): void
    {
        $user = Middleware::requireRole(['admin']);
        $rentChargeId = $this->positiveId($id, 'rent charge ID');
        $data = $this->validatedRentPayment(getJsonBody());

        try {
            $paymentId = $this->paymentModel->recordRentPayment(
                $rentChargeId,
                (int) $user['sub'],
                $data
            );
        } catch (DomainException $exception) {
            if ($exception->getMessage() === 'rent_charge_not_found') {
                sendError('Rent charge not found', 404);
            }
            if ($exception->getMessage() === 'payment_exceeds_rent_charge_balance') {
                sendError('Payment exceeds the outstanding rent-charge balance', 422);
            }
            if ($exception->getMessage() === 'duplicate_transaction_reference') {
                sendError('Transaction reference is already in use', 409);
            }
            throw $exception;
        }

        $payment = $this->paymentModel->getRentPayment($paymentId);
        if ($payment === null) {
            error_log('Rent payment insert completed without a retrievable record.');
            sendError('Unable to retrieve recorded rent payment', 500);
        }

        sendSuccess('Rent payment recorded and receipt issued', ['payment' => $payment], 201);
    }

    public function listRentPaymentsForCharge(string $id): void
    {
        Middleware::requireRole(['admin']);
        $rentChargeId = $this->positiveId($id, 'rent charge ID');
        if (!$this->paymentModel->rentChargeExists($rentChargeId)) {
            sendError('Rent charge not found', 404);
        }
        [$page, $limit] = $this->pagination();
        $filters = ['rent_charge_id' => $rentChargeId];
        $total = $this->paymentModel->countRentPayments($filters);

        sendSuccess('Rent payments retrieved', [
            'data' => $this->paymentModel->listRentPayments($filters, $page, $limit),
            'pagination' => $this->paginationData($page, $limit, $total),
        ]);
    }

    public function listRentPaymentsAdmin(): void
    {
        Middleware::requireRole(['admin']);
        [$page, $limit] = $this->pagination();
        $filters = [
            'rent_charge_id' => $this->queryPositiveId('rent_charge_id'),
            'payment_method' => $this->queryString('payment_method', 30),
        ];
        $total = $this->paymentModel->countRentPayments($filters);

        sendSuccess('Rent payments retrieved', [
            'data' => $this->paymentModel->listRentPayments($filters, $page, $limit),
            'pagination' => $this->paginationData($page, $limit, $total),
        ]);
    }

    public function getRentPayment(string $id): void
    {
        Middleware::requireRole(['admin']);
        $payment = $this->paymentModel->getRentPayment(
            $this->positiveId($id, 'rent payment ID')
        );
        if ($payment === null) {
            sendError('Rent payment not found', 404);
        }

        sendSuccess('Rent payment retrieved', ['payment' => $payment]);
    }

    private function validatedSalePayment(array $body): array
    {
        $this->rejectUnknownFields($body, [
            'amount',
            'payment_type',
            'payment_method',
            'payment_source',
            'provider',
            'provider_reference',
            'transaction_reference',
            'payment_date',
            'installment_id',
        ]);

        $paymentSource = array_key_exists('payment_source', $body)
            ? strtolower($this->requiredString($body, 'payment_source', 30))
            : 'manual';
        if (!in_array($paymentSource, ['manual', 'provider'], true)) {
            sendError('Invalid payment_source', 400, ['allowed' => ['manual', 'provider']]);
        }
        $provider = $this->optionalString($body, 'provider', 50);
        $providerReference = $this->optionalString($body, 'provider_reference', 150);
        if ($paymentSource === 'provider' && $provider === null) {
            sendError('provider is required for provider payments', 400);
        }
        if ($paymentSource === 'manual' && ($provider !== null || $providerReference !== null)) {
            sendError('Provider details are only valid for provider payments', 400);
        }
        $paymentType = strtolower($this->requiredString($body, 'payment_type', 30));
        if (!in_array($paymentType, self::PAYMENT_TYPES, true)) {
            sendError('Invalid payment_type', 400, ['allowed' => self::PAYMENT_TYPES]);
        }
        $paymentMethod = strtolower($this->requiredString($body, 'payment_method', 30));
        if (!in_array($paymentMethod, self::PAYMENT_METHODS, true)) {
            sendError('Invalid payment_method', 400, ['allowed' => self::PAYMENT_METHODS]);
        }
        if (
            $paymentSource === 'provider'
            && strtolower(str_replace('-', '', $provider ?? '')) === 'mpesa'
            && $providerReference === null
        ) {
            sendError('provider_reference is required for M-Pesa payments', 400);
        }
        if (
            $paymentSource === 'provider'
            && strtolower(str_replace('-', '', $provider ?? '')) === 'mpesa'
            && $paymentMethod !== 'mobile_money'
        ) {
            sendError('M-Pesa payments must use mobile_money', 400);
        }

        return [
            'amount' => $this->requiredAmount($body, 'amount'),
            'payment_type' => $paymentType,
            'payment_method' => $paymentMethod,
            'payment_source' => $paymentSource,
            'provider' => $provider,
            'provider_reference' => $providerReference,
            'transaction_reference' => $this->optionalString($body, 'transaction_reference', 150),
            'payment_date' => $this->paymentDate($body),
            'installment_id' => $this->optionalPositiveId($body, 'installment_id'),
        ];
    }

    private function validatedRentPayment(array $body): array
    {
        $this->rejectUnknownFields($body, [
            'amount_paid',
            'payment_method',
            'transaction_reference',
            'payment_date',
        ]);

        return [
            'amount_paid' => $this->requiredAmount($body, 'amount_paid'),
            'payment_method' => $this->validatedPaymentMethod($body),
            'transaction_reference' => $this->optionalString($body, 'transaction_reference', 150),
            'payment_date' => $this->paymentDate($body),
        ];
    }

    private function validatedPaymentMethod(array $body): string
    {
        $method = strtolower($this->requiredString($body, 'payment_method', 30));
        if (!in_array($method, self::PAYMENT_METHODS, true)) {
            sendError('Invalid payment_method', 400, ['allowed' => self::PAYMENT_METHODS]);
        }

        return $method;
    }

    private function amountToCents(string $amount): int
    {
        if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $amount)) {
            sendError('Invalid payment amount', 400);
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function requiredAmount(array $body, string $field): string
    {
        if (
            !array_key_exists($field, $body)
            || !is_string($body[$field]) && !is_int($body[$field]) && !is_float($body[$field])
        ) {
            sendError("{$field} must be a positive decimal amount", 400, ['field' => $field]);
        }
        $value = (string) $body[$field];
        if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $value)) {
            sendError("{$field} must fit a positive DECIMAL(15,2)", 400, ['field' => $field]);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $normalized = $whole . '.' . str_pad($fraction, 2, '0');
        if ((int) $whole === 0 && (int) $fraction === 0) {
            sendError("{$field} must be greater than zero", 400, ['field' => $field]);
        }

        return $normalized;
    }

    private function paymentDate(array $body): string
    {
        if (!array_key_exists('payment_date', $body)) {
            return date('Y-m-d H:i:s');
        }
        if (!is_string($body['payment_date'])) {
            sendError('payment_date must be an ISO 8601 date-time', 400);
        }
        $value = Validator::sanitizeInput($body['payment_date']);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/', $value)) {
            sendError('payment_date must be an ISO 8601 date-time', 400);
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (Exception) {
            sendError('Invalid payment_date', 400);
        }
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (
            $dateErrors !== false
            && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)
        ) {
            sendError('Invalid payment_date', 400);
        }

        return $date
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
    }

    private function rejectUnknownFields(array $body, array $allowed): void
    {
        $unknown = array_diff(array_keys($body), $allowed);
        if ($unknown) {
            sendError('Unsupported request fields', 400, ['fields' => array_values($unknown)]);
        }
    }

    private function requiredString(array $body, string $field, int $maxLength): string
    {
        if (!isset($body[$field]) || !is_string($body[$field])) {
            sendError("{$field} is required", 400, ['field' => $field]);
        }
        $value = Validator::sanitizeInput($body[$field]);
        if ($value === '') {
            sendError("{$field} is required", 400, ['field' => $field]);
        }
        if (mb_strlen($value) > $maxLength) {
            sendError("{$field} is too long", 400, ['field' => $field, 'max_length' => $maxLength]);
        }

        return $value;
    }

    private function optionalString(array $body, string $field, int $maxLength): ?string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return null;
        }
        if (!is_string($body[$field])) {
            sendError("{$field} must be a string", 400, ['field' => $field]);
        }
        $value = Validator::sanitizeInput($body[$field]);
        if (mb_strlen($value) > $maxLength) {
            sendError("{$field} is too long", 400, ['field' => $field, 'max_length' => $maxLength]);
        }

        return $value === '' ? null : $value;
    }

    private function optionalPositiveId(array $body, string $field): ?int
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }
        if (!is_int($body[$field]) && !is_string($body[$field])) {
            sendError("{$field} must be a positive integer", 400, ['field' => $field]);
        }
        $id = filter_var($body[$field], FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            sendError("{$field} must be a positive integer", 400, ['field' => $field]);
        }

        return $id;
    }

    private function positiveId(string $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            sendError("Invalid {$label}", 400);
        }

        return $id;
    }

    private function queryPositiveId(string $key): ?int
    {
        if (!array_key_exists($key, $_GET)) {
            return null;
        }
        $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0) {
            sendError("{$key} must be a positive integer", 400);
        }

        return $value;
    }

    private function queryString(string $key, int $maxLength): ?string
    {
        if (!array_key_exists($key, $_GET)) {
            return null;
        }
        if (!is_string($_GET[$key])) {
            sendError("{$key} must be a string", 400);
        }
        $value = Validator::sanitizeInput($_GET[$key]);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            sendError("Invalid {$key} filter", 400);
        }

        return $value;
    }

    private function pagination(): array
    {
        $page = $this->queryInteger('page', 1);
        $limit = $this->queryInteger('limit', 12);
        if ($page < 1 || $limit < 1 || $limit > 100) {
            sendError('page must be positive and limit must be between 1 and 100', 400);
        }

        return [$page, $limit];
    }

    private function queryInteger(string $key, int $default): int
    {
        if (!array_key_exists($key, $_GET)) {
            return $default;
        }
        $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);
        if ($value === false) {
            sendError("{$key} must be an integer", 400);
        }

        return $value;
    }

    private function paginationData(int $page, int $limit, int $total): array
    {
        return ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)];
    }
}
