<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/controllers/AuditLogController.php';

$auditLogRoutes = [
    'GET /api/v1/admin/audit-logs' => [
        AuditLogController::class,
        'list',
    ],
];
