<?php

declare(strict_types=1);

require_once __DIR__ . '/../models/NotificationLogModel.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../security/Middleware.php';
require_once __DIR__ . '/../security/Validator.php';

class NotificationLogController
{
    private const CHANNELS = ['email', 'sms', 'whatsapp'];
    private const DELIVERY_STATUSES = ['pending', 'sent', 'delivered', 'failed'];
    private const LINK_FIELDS = [
        'enquiry_id',
        'property_id',
        'sale_id',
        'installment_id',
        'rent_charge_id',
        'rent_payment_id',
    ];
    private const STATUS_TRANSITIONS = [
        'pending' => ['sent', 'failed'],
        'sent' => ['delivered', 'failed'],
        'failed' => ['pending'],
        'delivered' => [],
    ];

    private NotificationLogModel $notificationLogModel;

    public function __construct()
    {
        $this->notificationLogModel = new NotificationLogModel();
    }

    public function create(): void
    {
        Middleware::requireRole(['admin']);
        $data = $this->validatedNotification(getJsonBody());
        foreach (self::LINK_FIELDS as $field) {
            if (
                $data[$field] !== null
                && !$this->notificationLogModel->linkedRecordExists($field, $data[$field])
            ) {
                sendError("{$field} does not reference an existing record", 422, ['field' => $field]);
            }
        }

        $id = $this->notificationLogModel->create($data);
        $notification = $this->notificationLogModel->getById($id);
        if ($notification === null) {
            error_log('Notification log insert completed without a retrievable record.');
            sendError('Unable to create notification log', 500);
        }

        sendSuccess('Notification log created', ['notification' => $notification], 201);
    }

    public function list(): void
    {
        Middleware::requireRole(['admin']);
        [$page, $limit] = $this->pagination();
        $filters = [
            'delivery_status' => $this->queryEnum('delivery_status', self::DELIVERY_STATUSES),
            'channel' => $this->queryEnum('channel', self::CHANNELS),
            'notification_type' => $this->queryString('notification_type', 50),
        ];
        foreach (self::LINK_FIELDS as $field) {
            $filters[$field] = $this->queryPositiveId($field);
        }

        $total = $this->notificationLogModel->count($filters);
        sendSuccess('Notification logs retrieved', [
            'data' => $this->notificationLogModel->list($filters, $page, $limit),
            'pagination' => $this->paginationData($page, $limit, $total),
        ]);
    }

    public function get(string $id): void
    {
        Middleware::requireRole(['admin']);
        $notification = $this->notificationLogModel->getById(
            $this->positiveId($id, 'notification log ID')
        );
        if ($notification === null) {
            sendError('Notification log not found', 404);
        }

        sendSuccess('Notification log retrieved', ['notification' => $notification]);
    }

    public function updateStatus(string $id): void
    {
        Middleware::requireRole(['admin']);
        $notificationId = $this->positiveId($id, 'notification log ID');
        $data = $this->validatedStatusUpdate(getJsonBody());
        $notification = $this->notificationLogModel->getById($notificationId);
        if ($notification === null) {
            sendError('Notification log not found', 404);
        }

        $currentStatus = (string) $notification['delivery_status'];
        if (!in_array($data['delivery_status'], self::STATUS_TRANSITIONS[$currentStatus] ?? [], true)) {
            sendError('Invalid notification status transition', 409, [
                'current_status' => $currentStatus,
                'allowed_statuses' => self::STATUS_TRANSITIONS[$currentStatus] ?? [],
            ]);
        }

        if (!$this->notificationLogModel->transitionStatus(
            $notificationId,
            $currentStatus,
            $data['delivery_status'],
            $data
        )) {
            $current = $this->notificationLogModel->getById($notificationId);
            if ($current === null) {
                sendError('Notification log not found', 404);
            }
            sendError('Notification status changed; reload the log and retry', 409);
        }

        sendSuccess('Notification status updated', [
            'notification' => $this->notificationLogModel->getById($notificationId),
        ]);
    }

    private function validatedNotification(array $body): array
    {
        $allowed = array_merge(
            [
                'recipient_name',
                'recipient_contact',
                'channel',
                'notification_type',
                'message',
            ],
            self::LINK_FIELDS
        );
        $this->rejectUnknownFields($body, $allowed);

        $channel = $this->canonicalChannel($this->requiredString($body, 'channel', 20));
        if ($channel === null) {
            sendError('Invalid channel', 400, ['allowed' => self::CHANNELS]);
        }
        $type = $this->requiredString($body, 'notification_type', 50);
        $message = $this->requiredText($body, 'message');
        $data = [
            'recipient_name' => $this->optionalString($body, 'recipient_name', 150),
            'recipient_contact' => $this->requiredString($body, 'recipient_contact', 191),
            'channel' => $channel,
            'notification_type' => $type,
            'message' => $message,
        ];
        foreach (self::LINK_FIELDS as $field) {
            $data[$field] = $this->optionalPositiveId($body, $field);
        }

        return $data;
    }

    private function validatedStatusUpdate(array $body): array
    {
        $this->rejectUnknownFields($body, [
            'delivery_status',
            'provider',
            'provider_reference',
            'error_message',
        ]);
        $status = strtolower($this->requiredString($body, 'delivery_status', 20));
        if (!in_array($status, self::DELIVERY_STATUSES, true)) {
            sendError('Invalid delivery_status', 400, ['allowed' => self::DELIVERY_STATUSES]);
        }

        return [
            'delivery_status' => $status,
            'provider' => $this->optionalString($body, 'provider', 100),
            'provider_reference' => $this->optionalString($body, 'provider_reference', 191),
            'error_message' => $this->optionalText($body, 'error_message'),
        ];
    }

    private function canonicalChannel(string $channel): ?string
    {
        $normalized = strtolower($channel);

        return in_array($normalized, self::CHANNELS, true) ? $normalized : null;
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

    private function requiredText(array $body, string $field): string
    {
        if (!isset($body[$field]) || !is_string($body[$field])) {
            sendError("{$field} is required", 400, ['field' => $field]);
        }
        $value = trim($body[$field]);
        if ($value === '') {
            sendError("{$field} is required", 400, ['field' => $field]);
        }
        if (strlen($value) > 65535) {
            sendError("{$field} exceeds the database TEXT limit", 400);
        }

        return $value;
    }

    private function optionalText(array $body, string $field): ?string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return null;
        }
        if (!is_string($body[$field])) {
            sendError("{$field} must be a string", 400, ['field' => $field]);
        }
        $value = trim($body[$field]);
        if (strlen($value) > 65535) {
            sendError("{$field} exceeds the database TEXT limit", 400);
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

    private function queryEnum(string $key, array $allowed): ?string
    {
        $value = $this->queryString($key, 20);
        if ($value === null) {
            return null;
        }
        $value = strtolower($value);
        if (!in_array($value, $allowed, true)) {
            sendError("Invalid {$key} filter", 400, ['allowed' => $allowed]);
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
