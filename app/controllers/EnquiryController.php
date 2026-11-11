<?php

declare(strict_types=1);

require_once __DIR__ . '/../models/EnquiryModel.php';
require_once __DIR__ . '/../models/PropertyModel.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../security/Middleware.php';
require_once __DIR__ . '/../security/Validator.php';
require_once __DIR__ . '/../services/NotificationDispatcher.php';

class EnquiryController
{
    private const ENQUIRY_STATUSES = ['NEW', 'CONTACTED', 'FOLLOW_UP', 'MATCHED', 'CLOSED'];
    private const INQUIRY_STATUSES = ['new', 'read', 'replied', 'closed'];
    private const MATCH_STATUSES = ['NEW', 'CONTACTED', 'INTERESTED', 'DECLINED', 'CLOSED'];
    private const PROPERTY_TYPES = ['apartment', 'house', 'villa', 'condo', 'townhouse', 'commercial', 'land'];

    private EnquiryModel $enquiryModel;
    private PropertyModel $propertyModel;

    public function __construct()
    {
        $this->enquiryModel = new EnquiryModel();
        $this->propertyModel = new PropertyModel();
    }

    public function createEnquiry(): void
    {
        $data = $this->validatedEnquiry(getJsonBody());
        $id = $this->enquiryModel->createEnquiry($data);
        $enquiry = $this->enquiryModel->getEnquiry($id);
        if ($id <= 0 || $enquiry === null) {
            error_log('Enquiry insert completed without a retrievable record.');
            sendError('Unable to create enquiry', 500);
        }

        $matches = $this->enquiryModel->listMatches($id);

        // Requirement 4: Automated action on property match
        if (!empty($matches)) {
            try {
                $dispatcher = new NotificationDispatcher();
                $dispatcher->dispatchEnquiryMatchAlerts($id, $matches);
            } catch (Throwable $e) {
                error_log('Automated enquiry match alerts failed: ' . $e->getMessage());
            }
        }

        sendSuccess('Enquiry received', [
            'enquiry' => $enquiry,
            'matches' => $matches,
        ], 201);
    }

    public function listEnquiries(): void
    {
        Middleware::requireRole(['admin']);
        [$page, $limit] = $this->pagination();
        $status = $this->queryEnum('status', self::ENQUIRY_STATUSES);
        $source = $this->queryString('source', 30);
        $total = $this->enquiryModel->countEnquiries($status, $source);

        sendSuccess('Enquiries retrieved', [
            'data' => $this->enquiryModel->listEnquiries($status, $source, $page, $limit),
            'pagination' => $this->paginationData($page, $limit, $total),
        ]);
    }

    public function getEnquiry(string $id): void
    {
        Middleware::requireRole(['admin']);
        $enquiry = $this->enquiryModel->getEnquiry($this->positiveId($id, 'enquiry ID'));
        if (!$enquiry) {
            sendError('Enquiry not found', 404);
        }

        sendSuccess('Enquiry retrieved', ['enquiry' => $enquiry]);
    }

    public function updateEnquiryStatus(string $id): void
    {
        Middleware::requireRole(['admin']);
        $enquiryId = $this->positiveId($id, 'enquiry ID');
        $status = $this->validatedEnquiryStatus(getJsonBody());
        if (!$this->enquiryModel->updateStatus($enquiryId, $status)) {
            sendError('Enquiry not found', 404);
        }

        sendSuccess('Enquiry status updated', [
            'enquiry' => $this->enquiryModel->getEnquiry($enquiryId),
        ]);
    }

    public function listFollowUps(string $id): void
    {
        Middleware::requireRole(['admin']);
        $enquiryId = $this->positiveId($id, 'enquiry ID');
        if (!$this->enquiryModel->getEnquiry($enquiryId)) {
            sendError('Enquiry not found', 404);
        }

        sendSuccess('Enquiry follow-ups retrieved', [
            'data' => $this->enquiryModel->listFollowUps($enquiryId),
        ]);
    }

    public function createFollowUp(string $id): void
    {
        $user = Middleware::requireRole(['admin']);
        $enquiryId = $this->positiveId($id, 'enquiry ID');
        if (!$this->enquiryModel->getEnquiry($enquiryId)) {
            sendError('Enquiry not found', 404);
        }
        $data = $this->validatedFollowUp(getJsonBody());
        $followUpId = $this->enquiryModel->addFollowUp(
            $enquiryId,
            (int) $user['sub'],
            $data
        );
        $followUps = $this->enquiryModel->listFollowUps($enquiryId);

        sendSuccess('Enquiry follow-up recorded', [
            'follow_up' => array_values(array_filter(
                $followUps,
                static fn (array $followUp): bool => (int) $followUp['id'] === $followUpId
            ))[0] ?? null,
        ], 201);
    }

    public function listMatches(string $id): void
    {
        Middleware::requireRole(['admin']);
        $enquiryId = $this->positiveId($id, 'enquiry ID');
        if (!$this->enquiryModel->getEnquiry($enquiryId)) {
            sendError('Enquiry not found', 404);
        }

        sendSuccess('Matching properties retrieved', [
            'data' => $this->enquiryModel->listMatches($enquiryId),
        ]);
    }

    public function refreshMatches(string $id): void
    {
        Middleware::requireRole(['admin']);
        $enquiryId = $this->positiveId($id, 'enquiry ID');
        if (!$this->enquiryModel->getEnquiry($enquiryId)) {
            sendError('Enquiry not found', 404);
        }

        $refreshed = $this->enquiryModel->refreshMatches($enquiryId);
        if (!empty($refreshed)) {
            try {
                $dispatcher = new NotificationDispatcher();
                $dispatcher->dispatchEnquiryMatchAlerts($enquiryId, $refreshed);
            } catch (Throwable $e) {
                error_log('Automated match alerts on refresh failed: ' . $e->getMessage());
            }
        }

        sendSuccess('Property matches refreshed', [
            'data' => $refreshed,
        ]);
    }

    public function updateMatchStatus(string $id): void
    {
        Middleware::requireRole(['admin']);
        $matchId = $this->positiveId($id, 'match ID');
        $body = getJsonBody();
        $this->rejectUnknownFields($body, ['match_status']);
        $status = $body['match_status'] ?? null;
        if (!is_string($status)) {
            sendError('match_status is required', 400);
        }
        $status = strtoupper(str_replace(' ', '_', trim($status)));
        if (!in_array($status, self::MATCH_STATUSES, true)) {
            sendError('Invalid match_status', 400, ['allowed' => self::MATCH_STATUSES]);
        }
        if (!$this->enquiryModel->updateMatchStatus($matchId, $status)) {
            sendError('Property match not found', 404);
        }

        sendSuccess('Property match status updated');
    }

    public function createPropertyInquiry(string $id): void
    {
        $propertyId = $this->positiveId($id, 'property ID');
        if (!$this->propertyModel->getPublicPropertyById($propertyId)) {
            sendError('Property not found', 404);
        }
        $user = Middleware::authenticate();
        $data = $this->validatedPropertyInquiry(getJsonBody());
        $inquiryId = $this->enquiryModel->createInquiry(
            $propertyId,
            $user ? (int) $user['sub'] : null,
            $data
        );
        $inquiry = $this->enquiryModel->getInquiry($inquiryId);
        if ($inquiry === null) {
            error_log('Property inquiry insert completed without a retrievable record.');
            sendError('Unable to create property inquiry', 500);
        }
        unset($inquiry['owner_id']);

        sendSuccess('Property inquiry received', ['inquiry' => $inquiry], 201);
    }

    public function listAdminInquiries(): void
    {
        Middleware::requireRole(['admin']);
        [$page, $limit] = $this->pagination();
        $status = $this->queryEnum('status', self::INQUIRY_STATUSES);
        $total = $this->enquiryModel->countInquiries($status, null);

        sendSuccess('Property inquiries retrieved', [
            'data' => $this->enquiryModel->listInquiries($status, null, $page, $limit),
            'pagination' => $this->paginationData($page, $limit, $total),
        ]);
    }

    public function listPropertyInquiries(string $id): void
    {
        $user = Middleware::requireAuth();
        $propertyId = $this->positiveId($id, 'property ID');
        $property = $this->propertyModel->getPropertyById($propertyId);
        if (!$property) {
            sendError('Property not found', 404);
        }
        if ($user['role'] !== 'admin' && (
            $user['role'] !== 'owner' || (int) $property['owner_id'] !== (int) $user['sub']
        )) {
            sendError('Insufficient permissions', 403);
        }

        [$page, $limit] = $this->pagination();
        $status = $this->queryEnum('status', self::INQUIRY_STATUSES);
        $total = $this->enquiryModel->countInquiries($status, $propertyId);
        sendSuccess('Property inquiries retrieved', [
            'data' => $this->enquiryModel->listInquiries($status, $propertyId, $page, $limit),
            'pagination' => $this->paginationData($page, $limit, $total),
        ]);
    }

    public function updatePropertyInquiryStatus(string $id): void
    {
        $user = Middleware::requireRole(['admin', 'owner']);
        $inquiryId = $this->positiveId($id, 'inquiry ID');
        $inquiry = $this->enquiryModel->getInquiry($inquiryId);
        if (!$inquiry) {
            sendError('Property inquiry not found', 404);
        }
        if ($user['role'] !== 'admin' && (int) $inquiry['owner_id'] !== (int) $user['sub']) {
            sendError('Insufficient permissions', 403);
        }

        $body = getJsonBody();
        $this->rejectUnknownFields($body, ['status']);
        $status = $body['status'] ?? null;
        if (!is_string($status) || !in_array($status, self::INQUIRY_STATUSES, true)) {
            sendError('Invalid status', 400, ['allowed' => self::INQUIRY_STATUSES]);
        }
        if (!$this->enquiryModel->updateInquiryStatus($inquiryId, $status)) {
            sendError('Property inquiry not found', 404);
        }
        $updated = $this->enquiryModel->getInquiry($inquiryId);
        unset($updated['owner_id']);

        sendSuccess('Property inquiry status updated', ['inquiry' => $updated]);
    }

    private function validatedEnquiry(array $body): array
    {
        $allowed = [
            'lead_name', 'phone', 'email', 'enquiry_source', 'preferred_contact',
            'listing_type', 'property_type', 'country', 'city', 'area',
            'budget_currency', 'min_budget', 'max_budget', 'message',
        ];
        $this->rejectUnknownFields($body, $allowed);
        $name = $this->requiredString($body, 'lead_name', 150);
        $data = ['lead_name' => $name];

        foreach ([
            'phone' => 30,
            'enquiry_source' => 30,
            'preferred_contact' => 30,
            'listing_type' => 30,
            'property_type' => 50,
            'country' => 100,
            'city' => 100,
            'area' => 150,
            'message' => 16000,
        ] as $field => $maxLength) {
            $data[$field] = $this->optionalString($body, $field, $maxLength);
        }

        if ($data['enquiry_source'] !== null) {
            $data['enquiry_source'] = $this->canonicalChoice(
                $data['enquiry_source'],
                ['website' => 'Website', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'Email', 'phone' => 'Phone', 'referral' => 'Referral', 'other' => 'Other'],
                'enquiry_source'
            );
        }
        if ($data['preferred_contact'] !== null) {
            $data['preferred_contact'] = $this->canonicalChoice(
                $data['preferred_contact'],
                ['phone' => 'Phone', 'whatsapp' => 'WhatsApp', 'email' => 'Email', 'sms' => 'SMS'],
                'preferred_contact'
            );
        }
        if ($data['listing_type'] !== null) {
            $data['listing_type'] = $this->canonicalChoice(
                $data['listing_type'],
                ['for sale' => 'For Sale', 'sale' => 'For Sale', 'for rent' => 'For Rent', 'rent' => 'For Rent', 'bnb' => 'BnB'],
                'listing_type'
            );
        }
        if ($data['property_type'] !== null) {
            $data['property_type'] = $this->canonicalChoice(
                $data['property_type'],
                array_combine(self::PROPERTY_TYPES, self::PROPERTY_TYPES),
                'property_type'
            );
        }

        $email = $this->optionalString($body, 'email', 191);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendError('Invalid email format', 400, ['field' => 'email']);
        }
        $data['email'] = $email;

        $currency = $this->optionalString($body, 'budget_currency', 3);
        if ($currency !== null) {
            if (!preg_match('/^[A-Za-z]{3}$/', $currency)) {
                sendError('budget_currency must be a three-letter currency code', 400);
            }
            $currency = strtoupper($currency);
        }
        $data['budget_currency'] = $currency;
        $data['min_budget'] = $this->optionalBudget($body, 'min_budget');
        $data['max_budget'] = $this->optionalBudget($body, 'max_budget');
        if (
            $data['min_budget'] !== null && $data['max_budget'] !== null
            && $this->budgetCents($data['min_budget']) > $this->budgetCents($data['max_budget'])
        ) {
            sendError('min_budget cannot exceed max_budget', 400);
        }

        return $data;
    }

    private function validatedEnquiryStatus(array $body): string
    {
        $this->rejectUnknownFields($body, ['status', 'enquiry_status']);
        if (isset($body['status'], $body['enquiry_status']) && $body['status'] !== $body['enquiry_status']) {
            sendError('Provide either status or enquiry_status, not conflicting values', 400);
        }
        $status = $body['status'] ?? $body['enquiry_status'] ?? null;
        if (!is_string($status)) {
            sendError('status is required', 400);
        }
        $status = strtoupper(str_replace(' ', '_', trim($status)));
        if (!in_array($status, self::ENQUIRY_STATUSES, true)) {
            sendError('Invalid status', 400, ['allowed' => self::ENQUIRY_STATUSES]);
        }

        return $status;
    }

    private function validatedFollowUp(array $body): array
    {
        $allowed = ['follow_up_type', 'follow_up_status', 'notes', 'next_follow_up_at'];
        $this->rejectUnknownFields($body, $allowed);
        $type = $this->requiredString($body, 'follow_up_type', 30);
        $status = $this->optionalString($body, 'follow_up_status', 30);
        $type = $this->canonicalChoice(
            $type,
            ['call' => 'Call', 'whatsapp' => 'WhatsApp', 'email' => 'Email', 'sms' => 'SMS', 'meeting' => 'Meeting', 'other' => 'Other'],
            'follow_up_type'
        );
        if ($status !== null) {
            $status = $this->canonicalChoice(
                $status,
                [
                    'contacted' => 'CONTACTED',
                    'no_answer' => 'NO_ANSWER',
                    'left_message' => 'LEFT_MESSAGE',
                    'appointment_scheduled' => 'APPOINTMENT_SCHEDULED',
                    'not_interested' => 'NOT_INTERESTED',
                    'converted' => 'CONVERTED',
                ],
                'follow_up_status'
            );
        }

        return [
            'follow_up_type' => $type,
            'follow_up_status' => $status,
            'notes' => $this->optionalString($body, 'notes', 10000),
            'next_follow_up_at' => $this->optionalDateTime($body, 'next_follow_up_at'),
        ];
    }

    private function validatedPropertyInquiry(array $body): array
    {
        $allowed = ['name', 'email', 'phone', 'message'];
        $this->rejectUnknownFields($body, $allowed);
        $email = $this->requiredString($body, 'email', 255);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendError('Invalid email format', 400, ['field' => 'email']);
        }

        return [
            'name' => $this->requiredString($body, 'name', 100),
            'email' => $email,
            'phone' => $this->optionalString($body, 'phone', 50),
            'message' => $this->requiredString($body, 'message', 16000),
        ];
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
        if (!array_key_exists($field, $body) || !is_string($body[$field])) {
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

    private function canonicalChoice(string $value, array $choices, string $field): string
    {
        $key = strtolower(trim($value));
        if (!array_key_exists($key, $choices)) {
            sendError("Invalid {$field}", 400, ['allowed' => array_values(array_unique($choices))]);
        }

        return $choices[$key];
    }

    private function optionalBudget(array $body, string $field): ?string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return null;
        }
        if (!is_string($body[$field]) && !is_int($body[$field]) && !is_float($body[$field])) {
            sendError("{$field} must be a non-negative decimal", 400);
        }
        $value = (string) $body[$field];
        if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $value)) {
            sendError("{$field} must fit a non-negative DECIMAL(15,2)", 400);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $whole . '.' . str_pad($fraction, 2, '0');
    }

    private function budgetCents(string $budget): int
    {
        [$whole, $fraction] = explode('.', $budget, 2);

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function optionalDateTime(array $body, string $field): ?string
    {
        $value = $this->optionalString($body, $field, 35);
        if ($value === null) {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/', $value)) {
            sendError("{$field} must be an ISO 8601 date-time", 400);
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (Exception) {
            sendError("Invalid {$field}", 400);
        }

        return $date
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
    }

    private function positiveId(string $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            sendError("Invalid {$label}", 400);
        }

        return $id;
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

    private function paginationData(int $page, int $limit, int $total): array
    {
        return ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit)];
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

    private function queryEnum(string $key, array $allowed): ?string
    {
        $value = $this->queryString($key, 30);
        if ($value === null) {
            return null;
        }
        $normalized = strtolower(str_replace(' ', '_', $value));
        foreach ($allowed as $choice) {
            if (strtolower($choice) === $normalized) {
                return $choice;
            }
        }
        sendError("Invalid {$key} filter", 400, ['allowed' => $allowed]);
    }
}
