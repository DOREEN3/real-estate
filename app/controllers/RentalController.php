<?php

declare(strict_types=1);

require_once __DIR__ . '/../models/RentalModel.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../security/Middleware.php';
require_once __DIR__ . '/../security/Validator.php';

class RentalController
{
    private RentalModel $rentalModel;

    public function __construct()
    {
        $this->rentalModel = new RentalModel();
    }

    public function createTenant(): void
    {
        Middleware::requireRole(['admin']);
        $data = $this->validatedTenant(getJsonBody());
        $tenantId = $this->rentalModel->createTenant($data);
        $tenant = $this->rentalModel->getTenant($tenantId);
        if ($tenant === null) {
            error_log('Tenant insert completed without a retrievable record.');
            sendError('Unable to retrieve created tenant', 500);
        }

        sendSuccess('Tenant created', ['tenant' => $tenant], 201);
    }

    public function listTenants(): void
    {
        Middleware::requireRole(['admin']);
        [$page, $limit] = $this->pagination();
        $total = $this->rentalModel->countTenants();

        sendSuccess('Tenants retrieved', [
            'data' => $this->rentalModel->listTenants($page, $limit),
            'pagination' => $this->paginationData($page, $limit, $total),
        ]);
    }

    public function getTenant(string $id): void
    {
        Middleware::requireRole(['admin']);
        $tenant = $this->rentalModel->getTenant($this->positiveId($id, 'tenant ID'));
        if ($tenant === null) {
            sendError('Tenant not found', 404);
        }

        sendSuccess('Tenant retrieved', ['tenant' => $tenant]);
    }

    public function archiveTenant(string $id): void
    {
        Middleware::requireRole(['admin']);
        $adminUser = Middleware::requireAuth();
        $tenantId = $this->positiveId($id, 'tenant ID');

        $body = getJsonBody();
        $reason = trim((string) ($body['reason'] ?? ''));
        if (strlen($reason) < 5) {
            sendError('A reason of at least 5 characters is required to archive a tenant', 400);
        }

        $result = $this->rentalModel->archiveTenant($tenantId, (int) $adminUser['sub'], $reason);
        if (!$result['success']) {
            sendError($result['message'], $result['status_code'] ?? 400);
        }

        sendSuccess($result['message']);
    }

    public function createTenancy(): void
    {
        $user = Middleware::requireRole(['admin']);
        $data = $this->validatedTenancy(getJsonBody());

        try {
            $tenancyId = $this->rentalModel->createTenancy((int) $user['sub'], $data);
        } catch (DomainException $exception) {
            $errors = [
                'tenant_not_found' => ['Tenant not found', 404],
                'property_not_found' => ['Property not found', 404],
                'property_not_available_for_rent' => ['Property is not approved for rent', 409],
                'property_has_active_tenancy' => ['Property already has an active tenancy or is unavailable', 409],
            ];
            $error = $exception->getMessage();
            if (isset($errors[$error])) {
                sendError($errors[$error][0], $errors[$error][1]);
            }
            throw $exception;
        }

        $tenancy = $this->rentalModel->getTenancy($tenancyId);
        if ($tenancy === null) {
            error_log('Tenancy insert completed without a retrievable record.');
            sendError('Unable to retrieve created tenancy', 500);
        }
        $charges = $this->rentalModel->listRentCharges(['tenancy_id' => $tenancyId], 1, 1);

        sendSuccess('Tenancy created', [
            'tenancy' => $tenancy,
            'initial_rent_charge' => $charges[0] ?? null,
        ], 201);
    }

    public function listTenancies(): void
    {
        Middleware::requireRole(['admin']);
        [$page, $limit] = $this->pagination();
        $filters = [
            'tenant_id' => $this->queryPositiveId('tenant_id'),
            'property_id' => $this->queryPositiveId('property_id'),
            'tenancy_status' => $this->queryEnum('tenancy_status', ['active', 'ended', 'terminated']),
        ];
        $total = $this->rentalModel->countTenancies($filters);

        sendSuccess('Tenancies retrieved', [
            'data' => $this->rentalModel->listTenancies($filters, $page, $limit),
            'pagination' => $this->paginationData($page, $limit, $total),
        ]);
    }

    public function getTenancy(string $id): void
    {
        Middleware::requireRole(['admin']);
        $tenancy = $this->rentalModel->getTenancy($this->positiveId($id, 'tenancy ID'));
        if ($tenancy === null) {
            sendError('Tenancy not found', 404);
        }

        sendSuccess('Tenancy retrieved', [
            'tenancy' => $tenancy,
            'rent_charges' => $this->rentalModel->listRentCharges(
                ['tenancy_id' => (int) $tenancy['id']],
                1,
                200
            ),
        ]);
    }

    public function endTenancy(string $id): void
    {
        Middleware::requireRole(['admin']);
        $body = getJsonBody();
        $this->rejectUnknownFields($body, ['tenancy_status', 'end_date']);
        if (($body['tenancy_status'] ?? null) !== 'ended') {
            sendError('tenancy_status must be ended', 400, ['allowed' => ['ended']]);
        }
        $endDate = array_key_exists('end_date', $body)
            ? $this->requiredDate($body, 'end_date')
            : date('Y-m-d');

        try {
            $this->rentalModel->endTenancy(
                $this->positiveId($id, 'tenancy ID'),
                $endDate
            );
        } catch (DomainException $exception) {
            $errors = [
                'tenancy_not_found' => ['Tenancy not found', 404],
                'tenancy_not_active' => ['Only active tenancies can be ended', 409],
                'end_date_before_start' => ['end_date cannot be before start_date', 400],
            ];
            $error = $exception->getMessage();
            if (isset($errors[$error])) {
                sendError($errors[$error][0], $errors[$error][1]);
            }
            throw $exception;
        }

        sendSuccess('Tenancy ended', [
            'tenancy' => $this->rentalModel->getTenancy((int) $id),
        ]);
    }

    public function terminateTenancy(string $id): void
    {
        Middleware::requireRole(['admin']);
        $adminUser = Middleware::requireAuth();
        $tenancyId = $this->positiveId($id, 'tenancy ID');

        $body = getJsonBody();
        $endDate = array_key_exists('end_date', $body)
            ? $this->requiredDate($body, 'end_date')
            : date('Y-m-d');

        $reason = trim((string) ($body['reason'] ?? ''));
        if (strlen($reason) < 5) {
            sendError('A termination reason of at least 5 characters is required', 400);
        }

        $result = $this->rentalModel->terminateTenancy($tenancyId, (int) $adminUser['sub'], $endDate, $reason);
        if (!$result['success']) {
            sendError($result['message'], $result['status_code'] ?? 400);
        }

        sendSuccess($result['message']);
    }

    public function deleteTenancy(string $id): void
    {
        Middleware::requireRole(['admin']);
        $adminUser = Middleware::requireAuth();
        $tenancyId = $this->positiveId($id, 'tenancy ID');

        $body = getJsonBody();
        $reason = trim((string) ($body['reason'] ?? ''));
        if (strlen($reason) < 5) {
            sendError('A reason of at least 5 characters is required to delete a draft tenancy', 400);
        }

        $result = $this->rentalModel->deleteDraftTenancy($tenancyId, (int) $adminUser['sub'], $reason);
        if (!$result['success']) {
            sendError($result['message'], $result['status_code'] ?? 400);
        }

        sendSuccess($result['message']);
    }

    public function generateRentCharges(string $id): void
    {
        Middleware::requireRole(['admin']);
        $body = getJsonBody();
        $this->rejectUnknownFields($body, ['through_month']);
        $throughMonth = $this->requiredMonth($body, 'through_month');

        try {
            $result = $this->rentalModel->generateRentCharges(
                $this->positiveId($id, 'tenancy ID'),
                $throughMonth
            );
        } catch (DomainException $exception) {
            $errors = [
                'tenancy_not_found' => ['Tenancy not found', 404],
                'tenancy_not_active' => ['Rent charges can only be generated for active tenancies', 409],
                'invalid_charge_range' => ['through_month must be after the initial rent month', 400],
                'charge_range_too_large' => ['A maximum of 120 months can be generated per request', 400],
            ];
            $error = $exception->getMessage();
            if (isset($errors[$error])) {
                sendError($errors[$error][0], $errors[$error][1]);
            }
            throw $exception;
        }

        sendSuccess('Rent charges generated', $result);
    }

    public function listRentChargesForTenancy(string $id): void
    {
        Middleware::requireRole(['admin']);
        $tenancyId = $this->positiveId($id, 'tenancy ID');
        if ($this->rentalModel->getTenancy($tenancyId) === null) {
            sendError('Tenancy not found', 404);
        }

        [$page, $limit] = $this->pagination();
        $filters = ['tenancy_id' => $tenancyId];
        $total = $this->rentalModel->countRentCharges($filters);

        sendSuccess('Rent charges retrieved', [
            'data' => $this->rentalModel->listRentCharges($filters, $page, $limit),
            'pagination' => $this->paginationData($page, $limit, $total),
        ]);
    }

    public function listRentCharges(): void
    {
        Middleware::requireRole(['admin']);
        [$page, $limit] = $this->pagination();
        $filters = [
            'tenancy_id' => $this->queryPositiveId('tenancy_id'),
            'tenant_id' => $this->queryPositiveId('tenant_id'),
            'property_id' => $this->queryPositiveId('property_id'),
        ];
        $total = $this->rentalModel->countRentCharges($filters);

        sendSuccess('Rent charges retrieved', [
            'data' => $this->rentalModel->listRentCharges($filters, $page, $limit),
            'pagination' => $this->paginationData($page, $limit, $total),
        ]);
    }

    public function getRentCharge(string $id): void
    {
        Middleware::requireRole(['admin']);
        $charge = $this->rentalModel->getRentCharge($this->positiveId($id, 'rent charge ID'));
        if ($charge === null) {
            sendError('Rent charge not found', 404);
        }

        sendSuccess('Rent charge retrieved', ['rent_charge' => $charge]);
    }

    public function voidRentCharge(string $id): void
    {
        Middleware::requireRole(['admin']);
        $adminUser = Middleware::requireAuth();
        $chargeId = $this->positiveId($id, 'rent charge ID');

        $body = getJsonBody();
        $reason = trim((string) ($body['reason'] ?? ''));
        if (strlen($reason) < 5) {
            sendError('A void reason of at least 5 characters is required', 400);
        }

        $result = $this->rentalModel->voidRentCharge($chargeId, (int) $adminUser['sub'], $reason);
        if (!$result['success']) {
            sendError($result['message'], $result['status_code'] ?? 400);
        }

        sendSuccess($result['message']);
    }

    public function adjustRentCharge(string $id): void
    {
        Middleware::requireRole(['admin']);
        $adminUser = Middleware::requireAuth();
        $chargeId = $this->positiveId($id, 'rent charge ID');

        $body = getJsonBody();
        $result = $this->rentalModel->adjustRentCharge($chargeId, (int) $adminUser['sub'], $body);
        if (!$result['success']) {
            sendError($result['message'], $result['status_code'] ?? 400);
        }

        sendSuccess($result['message']);
    }

    private function validatedTenant(array $body): array
    {
        $this->rejectUnknownFields($body, ['full_name', 'email', 'phone', 'national_id']);
        $email = $this->optionalString($body, 'email', 191);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendError('email must be a valid email address', 400);
        }

        return [
            'full_name' => $this->requiredString($body, 'full_name', 150),
            'email' => $email,
            'phone' => $this->requiredString($body, 'phone', 30),
            'national_id' => $this->optionalString($body, 'national_id', 30),
        ];
    }

    private function validatedTenancy(array $body): array
    {
        $this->rejectUnknownFields($body, [
            'tenant_id',
            'property_id',
            'monthly_rent',
            'security_deposit',
            'currency',
            'rent_due_day',
            'grace_period_days',
            'start_date',
            'end_date',
        ]);

        $startDate = $this->requiredDate($body, 'start_date');
        $endDate = array_key_exists('end_date', $body) && $body['end_date'] !== null
            ? $this->requiredDate($body, 'end_date')
            : null;
        if ($endDate !== null && $endDate < $startDate) {
            sendError('end_date cannot be before start_date', 400);
        }

        $currency = $this->optionalString($body, 'currency', 3);
        if ($currency !== null) {
            $currency = strtoupper($currency);
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                sendError('currency must be a three-letter currency code', 400);
            }
        }

        return [
            'tenant_id' => $this->requiredPositiveId($body, 'tenant_id'),
            'property_id' => $this->requiredPositiveId($body, 'property_id'),
            'monthly_rent' => $this->requiredAmount($body, 'monthly_rent'),
            'security_deposit' => $this->optionalAmount($body, 'security_deposit', '0.00'),
            'currency' => $currency,
            'rent_due_day' => $this->requiredInteger($body, 'rent_due_day', 1, 31),
            'grace_period_days' => $this->optionalInteger($body, 'grace_period_days', 0, 30, 0),
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
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
            sendError("{$field} must fit DECIMAL(15,2)", 400, ['field' => $field]);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if ((int) $whole === 0 && (int) $fraction === 0) {
            sendError("{$field} must be greater than zero", 400);
        }

        return $whole . '.' . str_pad($fraction, 2, '0');
    }

    private function optionalAmount(array $body, string $field, string $default = '0.00'): string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return $default;
        }
        $value = (string) $body[$field];
        if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $value)) {
            sendError("{$field} must fit DECIMAL(15,2)", 400, ['field' => $field]);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return $whole . '.' . str_pad($fraction, 2, '0');
    }

    private function requiredPositiveId(array $body, string $field): int
    {
        $value = filter_var($body[$field] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0) {
            sendError("{$field} must be a positive integer", 400);
        }

        return $value;
    }

    private function positiveId(string $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            sendError("Invalid {$label}", 400);
        }

        return $id;
    }

    private function requiredString(array $body, string $field, int $maxLength): string
    {
        if (!isset($body[$field]) || !is_string($body[$field])) {
            sendError("{$field} is required", 400);
        }
        $value = trim(Validator::sanitizeInput($body[$field]));
        if ($value === '' || mb_strlen($value) > $maxLength) {
            sendError("Invalid {$field}", 400);
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
        $value = trim(Validator::sanitizeInput($body[$field]));
        if ($value === '' || mb_strlen($value) > $maxLength) {
            sendError("Invalid {$field}", 400);
        }

        return $value;
    }

    private function requiredInteger(array $body, string $field, int $min, int $max): int
    {
        $value = filter_var($body[$field] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            sendError("{$field} must be between {$min} and {$max}", 400);
        }

        return $value;
    }

    private function optionalInteger(array $body, string $field, int $min, int $max, int $default): int
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return $default;
        }

        return $this->requiredInteger($body, $field, $min, $max);
    }

    private function requiredDate(array $body, string $field): string
    {
        if (!isset($body[$field]) || !is_string($body[$field])) {
            sendError("{$field} must be a valid date in YYYY-MM-DD format", 400);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $body[$field]);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || (int) $date->format('Y') < 1000
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $body[$field]
        ) {
            sendError("{$field} must be a valid date in YYYY-MM-DD format", 400);
        }

        return $body[$field];
    }

    private function requiredMonth(array $body, string $field): string
    {
        if (!isset($body[$field]) || !is_string($body[$field])
            || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $body[$field])
            || (int) substr($body[$field], 0, 4) < 1000
        ) {
            sendError("{$field} must use YYYY-MM format", 400);
        }

        return $body[$field];
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

    private function queryEnum(string $key, array $allowed): ?string
    {
        if (!array_key_exists($key, $_GET)) {
            return null;
        }
        if (!is_string($_GET[$key]) || !in_array($_GET[$key], $allowed, true)) {
            sendError("Invalid {$key} filter", 400, ['allowed' => $allowed]);
        }

        return $_GET[$key];
    }

    private function rejectUnknownFields(array $body, array $allowed): void
    {
        $unknown = array_diff(array_keys($body), $allowed);
        if ($unknown) {
            sendError('Unsupported request fields', 400, ['fields' => array_values($unknown)]);
        }
    }

    private function pagination(): array
    {
        $page = $this->queryInteger('page', 1, 1000000);
        $limit = $this->queryInteger('limit', 1, 100, 20);

        return [$page, $limit];
    }

    private function queryInteger(string $key, int $min, int $max, int $default = 1): int
    {
        if (!isset($_GET[$key])) {
            return $default;
        }
        $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            sendError("{$key} must be between {$min} and {$max}", 400);
        }

        return $value;
    }

    private function paginationData(int $page, int $limit, int $total): array
    {
        return [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => $total,
            'total_pages' => (int) ceil($total / $limit),
        ];
    }
}
