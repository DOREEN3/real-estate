<?php

require_once __DIR__ . '/../app/controllers/PaymentController.php';

$paymentRoutes = [
    'POST /api/v1/payments/providers/mpesa/callback' => [
        PaymentController::class,
        'mpesaCallback',
    ],
    'POST /api/v1/payments/providers/bank/callback' => [
        PaymentController::class,
        'bankCallback',
    ],
    'GET /api/v1/admin/reports/payments' => [
        PaymentController::class,
        'getPaymentsReport',
    ],
    'GET /api/v1/admin/reports/installments' => [
        PaymentController::class,
        'getInstallmentsReport',
    ],
    'GET /api/v1/admin/sales/{id}/statement' => [
        PaymentController::class,
        'getSaleStatement',
    ],
    'POST /api/v1/admin/sales/{id}/reminders' => [
        PaymentController::class,
        'sendInstallmentReminder',
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
    'POST /api/v1/admin/payments/{id}/reverse' => [
        PaymentController::class,
        'reversePayment',
    ],
  'POST /api/v1/admin/sales/{id}/payments/mpesa/stk-push' => [
    PaymentController::class,
    'initiateMpesaSalePayment',
],
    'POST /api/v1/admin/tenancies/{id}/payments' => [
        PaymentController::class,
        'recordTenancyPayment',
    ],
    'GET /api/v1/admin/tenancies/{id}/payments' => [
        PaymentController::class,
        'listTenancyPayments',
    ],
    'POST /api/v1/admin/bookings/{id}/payments' => [
        PaymentController::class,
        'recordBookingPayment',
    ],
    'GET /api/v1/admin/bookings/{id}/payments' => [
        PaymentController::class,
        'listBookingPayments',
    ],
];
