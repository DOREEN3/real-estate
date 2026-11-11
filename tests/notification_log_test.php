<?php

declare(strict_types=1);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(
    "CREATE TABLE enquiries (id INTEGER PRIMARY KEY);
    CREATE TABLE properties (id INTEGER PRIMARY KEY);
    CREATE TABLE sales (id INTEGER PRIMARY KEY);
    CREATE TABLE installments (id INTEGER PRIMARY KEY);
    CREATE TABLE notification_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        recipient_name TEXT NULL,
        recipient_contact TEXT NOT NULL,
        channel TEXT NOT NULL,
        notification_type TEXT NOT NULL,
        message TEXT NOT NULL,
        delivery_status TEXT NOT NULL DEFAULT 'pending',
        provider TEXT NULL,
        provider_reference TEXT NULL,
        error_message TEXT NULL,
        retry_count INTEGER NOT NULL DEFAULT 0,
        enquiry_id INTEGER NULL,
        property_id INTEGER NULL,
        sale_id INTEGER NULL,
        installment_id INTEGER NULL,
        rent_charge_id INTEGER NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at TEXT NULL,
        delivered_at TEXT NULL
    );
    INSERT INTO enquiries (id) VALUES (1);
    INSERT INTO properties (id) VALUES (1);
    INSERT INTO sales (id) VALUES (1);
    INSERT INTO installments (id) VALUES (1);"
);

require_once __DIR__ . '/../app/models/NotificationLogModel.php';
require_once __DIR__ . '/../app/controllers/NotificationLogController.php';
require_once __DIR__ . '/../routes/NotificationLogRoutes.php';

$model = new NotificationLogModel();
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

$notificationData = [
    'recipient_name' => 'Grace Wanjiku',
    'recipient_contact' => '+254712345678',
    'channel' => 'sms',
    'notification_type' => 'enquiry_follow_up',
    'message' => 'We received your enquiry.',
    'enquiry_id' => 1,
    'property_id' => 1,
    'sale_id' => 1,
    'installment_id' => 1,
];
$id = $model->create($notificationData);
$notification = $model->getById($id);
$assert(
    $id > 0 && $notification['delivery_status'] === 'pending'
        && (int) $notification['retry_count'] === 0,
    'New notification records should use the database defaults for status and retries.'
);
$assert(
    count(array_filter(
        ['enquiry_id', 'property_id', 'sale_id', 'installment_id'],
        static fn (string $field): bool => $model->linkedRecordExists($field, 1)
    )) === 4
        && !$model->linkedRecordExists('enquiry_id', 999),
    'Each notification context ID should be checkable against its source table.'
);
$assert(
    $model->count(['delivery_status' => 'pending', 'channel' => 'sms', 'enquiry_id' => 1]) === 1
        && count($model->list(['delivery_status' => 'pending'], 1, 10)) === 1,
    'Notification filters should compose with pagination.'
);

$assert(
    $model->transitionStatus($id, 'pending', 'sent', [
        'provider' => 'example-sms',
        'provider_reference' => 'msg-1',
        'error_message' => null,
    ]),
    'Pending notifications should transition to sent.'
);
$sent = $model->getById($id);
$assert(
    $sent['delivery_status'] === 'sent' && $sent['sent_at'] !== null
        && $sent['provider'] === 'example-sms' && $sent['provider_reference'] === 'msg-1',
    'A sent outcome should retain provider metadata and set sent_at.'
);
$assert(
    $model->transitionStatus($id, 'sent', 'delivered', [
        'provider' => null,
        'provider_reference' => null,
        'error_message' => null,
    ]),
    'Sent notifications should transition to delivered.'
);
$delivered = $model->getById($id);
$assert(
    $delivered['delivery_status'] === 'delivered' && $delivered['delivered_at'] !== null
        && $delivered['provider'] === 'example-sms',
    'Delivered outcome should stamp delivery and retain existing provider metadata.'
);
$assert(
    !$model->transitionStatus($id, 'sent', 'failed', [
        'provider' => null,
        'provider_reference' => null,
        'error_message' => 'late update',
    ]),
    'A stale or terminal status transition should not overwrite a notification.'
);

$failedId = $model->create($notificationData);
$assert(
    $model->transitionStatus($failedId, 'pending', 'failed', [
        'provider' => null,
        'provider_reference' => null,
        'error_message' => 'Provider unavailable',
    ]),
    'Pending notifications should record a failed outcome.'
);
$failed = $model->getById($failedId);
$assert(
    $failed['delivery_status'] === 'failed' && $failed['error_message'] === 'Provider unavailable',
    'Failed status should retain its error details.'
);
$assert(
    $model->transitionStatus($failedId, 'failed', 'pending', [
        'provider' => null,
        'provider_reference' => null,
        'error_message' => null,
    ]),
    'A failed notification should be eligible for a tracked retry.'
);
$retried = $model->getById($failedId);
$assert(
    $retried['delivery_status'] === 'pending' && (int) $retried['retry_count'] === 1
        && $retried['error_message'] === null,
    'Retrying should increment retry_count and clear the previous error.'
);
$assert(
    $model->transitionStatus($failedId, 'pending', 'sent', [
        'provider' => null,
        'provider_reference' => null,
        'error_message' => null,
    ]),
    'A retried notification should be able to record a successful send.'
);
$firstSentAt = $model->getById($failedId)['sent_at'];
$assert(
    $model->transitionStatus($failedId, 'sent', 'failed', [
        'provider' => null,
        'provider_reference' => null,
        'error_message' => 'Delivery failed after sending',
    ]),
    'A sent notification should be able to record a later delivery failure.'
);
$assert(
    $model->transitionStatus($failedId, 'failed', 'pending', [
        'provider' => null,
        'provider_reference' => null,
        'error_message' => null,
    ]),
    'A failed send should be eligible for another tracked retry.'
);
$assert(
    $model->transitionStatus($failedId, 'pending', 'sent', [
        'provider' => null,
        'provider_reference' => null,
        'error_message' => null,
    ]),
    'A subsequent retry should record its send outcome.'
);
$assert(
    $model->getById($failedId)['sent_at'] === $firstSentAt,
    'sent_at should preserve the first successful send time across retries.'
);

$controller = new NotificationLogController();
$validateNotification = new ReflectionMethod($controller, 'validatedNotification');
$validateNotification->setAccessible(true);
$canonicalChannel = new ReflectionMethod($controller, 'canonicalChannel');
$canonicalChannel->setAccessible(true);
foreach (['email', 'sms', 'whatsapp'] as $channel) {
    $channelInput = $notificationData;
    $channelInput['channel'] = strtoupper($channel);
    $normalized = $validateNotification->invoke($controller, $channelInput);
    $assert(
        $normalized['channel'] === $channel && $normalized['enquiry_id'] === 1,
        "Notification input should accept and normalize the {$channel} channel."
    );
}
$assert(
    $canonicalChannel->invoke($controller, 'push') === null,
    'Unsupported notification channels should be rejected.'
);
$assert(
    isset($notificationLogRoutes['POST /api/v1/admin/notification-logs'])
        && isset($notificationLogRoutes['GET /api/v1/admin/notification-logs/{id}'])
        && isset($notificationLogRoutes['PATCH /api/v1/admin/notification-logs/{id}/status']),
    'The API should register create, detail, and delivery status routes.'
);
$assert(
    Middleware::requiredRolesForRoute('GET /api/v1/admin/notification-logs') === ['admin']
        && Middleware::requiredRolesForRoute('POST /api/v1/admin/notification-logs') === ['admin']
        && Middleware::requiredRolesForRoute('GET /api/v1/admin/notification-logs/{id}') === ['admin']
        && Middleware::requiredRolesForRoute('PATCH /api/v1/admin/notification-logs/{id}/status') === ['admin']
        && !Middleware::isPublicRoute('GET', '/api/v1/admin/notification-logs'),
    'Notification records should be protected from non-admin and unauthenticated access.'
);

fwrite(STDOUT, "Notification log API checks passed ({$assertions} assertions).\n");
