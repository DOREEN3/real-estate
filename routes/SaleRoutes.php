<?php

require_once __DIR__ . '/../app/controllers/SaleController.php';

$saleRoutes = [
    'POST /api/v1/admin/sales' => [
        SaleController::class,
        'create',
    ],
    'GET /api/v1/admin/sales' => [
        SaleController::class,
        'list',
    ],
    'GET /api/v1/admin/sales/{id}' => [
        SaleController::class,
        'get',
    ],
    'GET /api/v1/admin/sales/{id}/installments' => [
        SaleController::class,
        'listInstallments',
    ],
    'POST /api/v1/admin/sales/{id}/cancel' => [
        SaleController::class,
        'cancel',
    ],
    'DELETE /api/v1/admin/sales/{id}' => [
        SaleController::class,
        'delete',
    ],
    'POST /api/v1/admin/installments/{id}/cancel' => [
        SaleController::class,
        'cancelInstallment',
    ],
    'PATCH /api/v1/admin/installments/{id}' => [
        SaleController::class,
        'adjustInstallment',
    ],
];
