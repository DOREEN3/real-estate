<?php

require_once __DIR__ . '/../app/controllers/NotificationLogController.php';

$notificationLogRoutes = [
    'POST /api/v1/admin/notification-logs' => [
        NotificationLogController::class,
        'create',
    ],
    'GET /api/v1/admin/notification-logs' => [ 
        NotificationLogController::class,
        'list',
    ],
    'GET /api/v1/admin/notification-logs/{id}' => [
        NotificationLogController::class,
        'get',
    ],
    'PATCH /api/v1/admin/notification-logs/{id}/status' => [
        NotificationLogController::class,
        'updateStatus',
    ],
];
