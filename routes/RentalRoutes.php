<?php

require_once __DIR__ . '/../app/controllers/RentalController.php';

$rentalRoutes = [
    'POST /api/v1/admin/tenants' => [
        RentalController::class,
        'createTenant',
    ],
    'GET /api/v1/admin/tenants' => [
        RentalController::class,
        'listTenants',
    ],
    'GET /api/v1/admin/tenants/{id}' => [
        RentalController::class,
        'getTenant',
    ],
    'PATCH /api/v1/admin/tenants/{id}/archive' => [
        RentalController::class,
        'archiveTenant',
    ],
    'POST /api/v1/admin/tenancies' => [
        RentalController::class,
        'createTenancy',
    ],
    'GET /api/v1/admin/tenancies' => [
        RentalController::class,
        'listTenancies',
    ],
    'GET /api/v1/admin/tenancies/{id}' => [
        RentalController::class,
        'getTenancy',
    ],
    'PATCH /api/v1/admin/tenancies/{id}/status' => [
        RentalController::class,
        'endTenancy',
    ],
    'POST /api/v1/admin/tenancies/{id}/terminate' => [
        RentalController::class,
        'terminateTenancy',
    ],
    'DELETE /api/v1/admin/tenancies/{id}' => [
        RentalController::class,
        'deleteTenancy',
    ],
    'GET /api/v1/admin/tenancies/{id}/rent-charges' => [
        RentalController::class,
        'listRentChargesForTenancy',
    ],
    'POST /api/v1/admin/tenancies/{id}/rent-charges' => [
        RentalController::class,
        'generateRentCharges',
    ],
    'GET /api/v1/admin/rent-charges' => [
        RentalController::class,
        'listRentCharges',
    ],
    'GET /api/v1/admin/rent-charges/{id}' => [
        RentalController::class,
        'getRentCharge',
    ],
    'POST /api/v1/admin/rent-charges/{id}/void' => [
        RentalController::class,
        'voidRentCharge',
    ],
    'PATCH /api/v1/admin/rent-charges/{id}' => [
        RentalController::class,
        'adjustRentCharge',
    ],
];
