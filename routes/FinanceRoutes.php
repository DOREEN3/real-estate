<?php

require_once __DIR__ . '/../app/controllers/FinanceController.php';
require_once __DIR__ . '/../app/controllers/CompanyProfileController.php';

$financeRoutes = [
    'GET /api/v1/admin/expenses' => [
        FinanceController::class,
        'listExpenses',
    ],
    'POST /api/v1/admin/expenses' => [
        FinanceController::class,
        'createExpense',
    ],
    'GET /api/v1/admin/reports/profit-loss' => [
        FinanceController::class,
        'profitLoss',
    ],
    'GET /api/v1/admin/company-profile' => [
        CompanyProfileController::class,
        'get',
    ],
    'PUT /api/v1/admin/company-profile' => [
        CompanyProfileController::class,
        'update',
    ],
    'POST /api/v1/admin/company-profile/logo' => [
        CompanyProfileController::class,
        'uploadLogo',
    ],
];
