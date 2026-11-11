<?php

declare(strict_types=1);

require_once __DIR__ . '/../models/SaleModel.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../security/Middleware.php';
require_once __DIR__ . '/../security/Validator.php';

class SaleController
{
    private SaleModel $saleModel;

    public function __construct()
    {
        $this->saleModel = new SaleModel();
    }

    public function create(): void
    {
        Middleware::requireRole(['admin']);
        $data = $this->validatedSale(getJsonBody());
        try {
            $saleId = $this->saleModel->create($data);
        } catch (DomainException $exception) {
            $errors = [
                'deposit_without_installments' => ['deposit_amount must be zero when no installment schedule is provided', 400],
                'property_not_found' => ['Property not found', 404],
                'property_not_for_sale' => ['Property is not approved and available for sale', 409],
                'enquiry_not_found' => ['Enquiry not found', 422],
                'property_sale_exists' => ['An active or completed sale already exists for this property', 409],
            ];
            $error = $exception->getMessage();
            if (isset($errors[$error])) {
                sendError($errors[$error][0], $errors[$error][1]);
            }
            throw $exception;
        }

        $sale = $this->saleModel->getById($saleId);
        if ($sale === null) {
            error_log('Sale insert completed without a retrievable record.');
            sendError('Unable to retrieve created sale', 500);
        }
        sendSuccess('Sale created', [
            'sale' => $sale,
            'installments' => $this->saleModel->listInstallments($saleId),
        ], 201);
    }

    public function list(): void
    {
        Middleware::requireRole(['admin']);
        [$page, $limit] = $this->pagination();
        $filters = [
            'sale_status' => $this->queryString('sale_status', 30, ['in_progress', 'completed', 'cancelled']),
            'property_id' => $this->queryPositiveId('property_id'),
        ];
        $total = $this->saleModel->count($filters);

        sendSuccess('Sales retrieved', [
            'data' => $this->saleModel->list($filters, $page, $limit),
            'pagination' => $this->paginationData($page, $limit, $total),
        ]);
    }

    public function get(string $id): void
    {
        Middleware::requireRole(['admin']);
        $saleId = $this->positiveId($id, 'sale ID');
        $sale = $this->saleModel->getById($saleId);
        if ($sale === null) {
            sendError('Sale not found', 404);
        }

        sendSuccess('Sale retrieved', [
            'sale' => $sale,
            'installments' => $this->saleModel->listInstallments($saleId),
        ]);
    }

    public function listInstallments(string $id): void
    {
        Middleware::requireRole(['admin']);
        $saleId = $this->positiveId($id, 'sale ID');
        if ($this->saleModel->getById($saleId) === null) {
            sendError('Sale not found', 404);
        }

        sendSuccess('Sale installments retrieved', [
            'data' => $this->saleModel->listInstallments($saleId),
        ]);
    }

    public function cancel(string $id): void
    {
        Middleware::requireRole(['admin']);
        $adminUser = Middleware::requireAuth();
        $saleId = $this->positiveId($id, 'sale ID');

        $body = getJsonBody();
        $reason = trim((string) ($body['reason'] ?? ''));
        if (strlen($reason) < 5) {
            sendError('A cancellation reason of at least 5 characters is required', 400);
        }

        $result = $this->saleModel->cancelSale($saleId, (int) $adminUser['sub'], $reason);
        if (!$result['success']) {
            sendError($result['message'], $result['status_code'] ?? 400);
        }

        sendSuccess($result['message']);
    }

    public function delete(string $id): void
    {
        Middleware::requireRole(['admin']);
        $adminUser = Middleware::requireAuth();
        $saleId = $this->positiveId($id, 'sale ID');

        $body = getJsonBody();
        $reason = trim((string) ($body['reason'] ?? ''));
        if (strlen($reason) < 5) {
            sendError('A reason of at least 5 characters is required to delete a draft sale', 400);
        }

        $result = $this->saleModel->deleteDraftSale($saleId, (int) $adminUser['sub'], $reason);
        if (!$result['success']) {
            sendError($result['message'], $result['status_code'] ?? 400);
        }

        sendSuccess($result['message']);
    }

    public function cancelInstallment(string $id): void
    {
        Middleware::requireRole(['admin']);
        $adminUser = Middleware::requireAuth();
        $installmentId = $this->positiveId($id, 'installment ID');

        $body = getJsonBody();
        $reason = trim((string) ($body['reason'] ?? ''));
        if (strlen($reason) < 5) {
            sendError('A cancellation reason of at least 5 characters is required', 400);
        }

        $result = $this->saleModel->cancelInstallment($installmentId, (int) $adminUser['sub'], $reason);
        if (!$result['success']) {
            sendError($result['message'], $result['status_code'] ?? 400);
        }

        sendSuccess($result['message']);
    }

    public function adjustInstallment(string $id): void
    {
        Middleware::requireRole(['admin']);
        $adminUser = Middleware::requireAuth();
        $installmentId = $this->positiveId($id, 'installment ID');

        $body = getJsonBody();
        $result = $this->saleModel->adjustInstallment($installmentId, (int) $adminUser['sub'], $body);
        if (!$result['success']) {
            sendError($result['message'], $result['status_code'] ?? 400);
        }

        sendSuccess($result['message']);
    }

    private function validatedSale(array $body): array
    {
        $allowed = [
            'property_id',
            'enquiry_id',
            'buyer_name',
            'buyer_email',
            'sale_price',
            'currency',
            'deposit_amount',
            'number_of_installments',
            'installment_frequency',
            'schedule_start_date',
        ];
        $this->rejectUnknownFields($body, $allowed);

        $salePrice = $this->requiredAmount($body, 'sale_price', true);
        $depositAmount = array_key_exists('deposit_amount', $body)
            ? $this->requiredAmount($body, 'deposit_amount', false)
            : '0.00';
        if ($this->amountToCents($depositAmount) > $this->amountToCents($salePrice)) {
            sendError('deposit_amount cannot exceed sale_price', 400);
        }

        $numberOfInstallments = $this->installmentCount($body);
        $scheduleStartDate = $this->optionalDate($body, 'schedule_start_date');
        $frequency = $this->optionalString($body, 'installment_frequency', 20);
        if ($numberOfInstallments === 0 && $this->amountToCents($depositAmount) !== 0) {
            sendError('deposit_amount must be zero when no installment schedule is provided', 400);
        }
        if ($numberOfInstallments > 0) {
            if ($this->amountToCents($depositAmount) <= 0) {
                sendError('An installment plan requires a positive deposit', 400);
            }
            if ($this->amountToCents($depositAmount) >= $this->amountToCents($salePrice)) {
                sendError('deposit_amount must be less than sale_price when installments are scheduled', 400);
            }
            if ($frequency === null || strtolower($frequency) !== 'monthly') {
                sendError('installment_frequency must be monthly', 400, ['allowed' => ['monthly']]);
            }
            if ($scheduleStartDate === null) {
                sendError('schedule_start_date is required for installment plans', 400);
            }
            $frequency = 'monthly';
        } elseif ($frequency !== null || $scheduleStartDate !== null) {
            sendError('Installment schedule fields require number_of_installments greater than zero', 400);
        }

        $currency = $this->optionalString($body, 'currency', 3);
        if ($currency !== null) {
            $currency = strtoupper($currency);
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                sendError('currency must be a three-letter currency code', 400);
            }
        }
        $email = $this->optionalString($body, 'buyer_email', 191);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendError('buyer_email must be a valid email address', 400);
        }

        return [
            'property_id' => $this->requiredPositiveId($body, 'property_id'),
            'enquiry_id' => $this->optionalPositiveId($body, 'enquiry_id'),
            'buyer_name' => $this->requiredString($body, 'buyer_name', 150),
            'buyer_email' => $email,
            'sale_price' => $salePrice,
            'currency' => $currency,
            'deposit_amount' => $depositAmount,
            'number_of_installments' => $numberOfInstallments,
            'installment_frequency' => $frequency,
            'schedule_start_date' => $scheduleStartDate,
        ];
    }

    private function requiredAmount(array $body, string $field, bool $mustBePositive): string
    {
        if (
            !array_key_exists($field, $body)
            || (!is_string($body[$field]) && !is_int($body[$field]) && !is_float($body[$field]))
        ) {
            sendError("{$field} must be a decimal amount", 400, ['field' => $field]);
        }
        $value = (string) $body[$field];
        if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $value)) {
            sendError("{$field} must fit DECIMAL(15,2)", 400, ['field' => $field]);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $normalized = $whole . '.' . str_pad($fraction, 2, '0');
        if ($mustBePositive && $this->amountToCents($normalized) <= 0) {
            sendError("{$field} must be greater than zero", 400);
        }

        return $normalized;
    }

    private function amountToCents(string $amount): int
    {
        [$whole, $fraction] = explode('.', $amount, 2);

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function installmentCount(array $body): int
    {
        if (!array_key_exists('number_of_installments', $body)) {
            return 0;
        }
        $raw = $body['number_of_installments'];
        if (is_bool($raw) || is_float($raw) || is_array($raw)) {
            sendError('number_of_installments must be an integer', 400);
        }
        $count = filter_var($raw, FILTER_VALIDATE_INT);
        if ($count === false || $count < 0 || $count > 120) {
            sendError('number_of_installments must be between 0 and 120', 400);
        }

        return $count;
    }

    private function optionalDate(array $body, string $field): ?string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return null;
        }
        if (!is_string($body[$field]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $body[$field])) {
            sendError("{$field} must be a YYYY-MM-DD date", 400);
        }
        [$year, $month, $day] = array_map('intval', explode('-', $body[$field]));
        if (!checkdate($month, $day, $year)) {
            sendError("Invalid {$field}", 400);
        }
        // Business rule: an installment schedule's first due date must not be in the past.
        $value = $body[$field];
        if ($value < (new DateTimeImmutable())->format('Y-m-d')) {
            sendError("{$field} must not be in the past", 400);
        }

        return $value;
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
            sendError("{$field} is required", 400);
        }
        if (mb_strlen($value) > $maxLength) {
            sendError("{$field} is too long", 400, ['field' => $field]);
        }

        return $value;
    }

    private function optionalString(array $body, string $field, int $maxLength): ?string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return null;
        }
        if (!is_string($body[$field])) {
            sendError("{$field} must be a string", 400);
        }
        $value = Validator::sanitizeInput($body[$field]);
        if (mb_strlen($value) > $maxLength) {
            sendError("{$field} is too long", 400);
        }

        return $value === '' ? null : $value;
    }

    private function requiredPositiveId(array $body, string $field): int
    {
        $id = $this->optionalPositiveId($body, $field);
        if ($id === null) {
            sendError("{$field} is required", 400);
        }

        return $id;
    }

    private function optionalPositiveId(array $body, string $field): ?int
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }
        if (!is_int($body[$field]) && !is_string($body[$field])) {
            sendError("{$field} must be a positive integer", 400);
        }
        $id = filter_var($body[$field], FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            sendError("{$field} must be a positive integer", 400);
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
        $id = filter_var($_GET[$key], FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            sendError("{$key} must be a positive integer", 400);
        }

        return $id;
    }

    private function queryString(string $key, int $maxLength, ?array $allowedValues = null): ?string
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
        if ($allowedValues !== null && !in_array($value, $allowedValues, true)) {
            sendError("Invalid {$key} filter", 400, ['allowed' => $allowedValues]);
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
