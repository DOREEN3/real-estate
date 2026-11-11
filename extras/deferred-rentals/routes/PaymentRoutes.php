<?php

require_once __DIR__ . '/../app/controllers/PaymentController.php';

$paymentRoutes = [
    'POST /api/v1/payments/providers/mpesa/callback' => [
        PaymentController::class,
        'mpesaCallback',
    ],
    'POST /api/v1/admin/sales/{id}/payments' => [
        PaymentController::class,
        'createSalePayment',
    ],
    'GET /api/v1/admin/sales/{id}/payments' => [
        PaymentController::class,
        'listSalePayments',
    ],
    'GET /api/v1/admin/payments' => [
        PaymentController::class,
        'listSalePaymentsAdmin',
    ],
    'GET /api/v1/admin/payments/{id}' => [
        PaymentController::class,
        'getSalePayment',
    ],
    'PATCH /api/v1/admin/payments/{id}/status' => [
        PaymentController::class,
        'resolveProviderPayment',
    ],
    'POST /api/v1/admin/rent-charges/{id}/payments' => [
        PaymentController::class,
        'createRentPayment',
    ],
    'GET /api/v1/admin/rent-charges/{id}/payments' => [
        PaymentController::class,
        'listRentPaymentsForCharge',
    ],
    'GET /api/v1/admin/rent-payments' => [
        PaymentController::class,
        'listRentPaymentsAdmin',
    ],
    'GET /api/v1/admin/rent-payments/{id}' => [
        PaymentController::class,
        'getRentPayment',
    ],
  'POST /api/v1/admin/sales/{id}/payments/mpesa/stk-push' => [
    PaymentController::class,
    'initiateMpesaSalePayment',
],
];
