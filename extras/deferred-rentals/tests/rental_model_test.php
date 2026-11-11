<?php

declare(strict_types=1);

date_default_timezone_set('Africa/Nairobi');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(
    "CREATE TABLE tenants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        email TEXT NULL,
        phone TEXT NOT NULL,
        national_id TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        full_name TEXT NOT NULL
    );
    CREATE TABLE properties (
        id INTEGER PRIMARY KEY,
        title TEXT NOT NULL,
        address TEXT NULL,
        currency TEXT NOT NULL DEFAULT 'KES',
        transaction_type TEXT NOT NULL,
        property_status TEXT NOT NULL,
        availability_status TEXT NOT NULL
    );
    CREATE TABLE tenancies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenant_id INTEGER NOT NULL,
        property_id INTEGER NOT NULL,
        monthly_rent NUMERIC NOT NULL,
        currency TEXT NOT NULL DEFAULT 'KES',
        rent_due_day INTEGER NOT NULL,
        start_date TEXT NOT NULL,
        end_date TEXT NULL,
        tenancy_status TEXT NOT NULL DEFAULT 'active',
        created_by INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE rent_charges (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenancy_id INTEGER NOT NULL,
        rent_month TEXT NOT NULL,
        amount_due NUMERIC NOT NULL,
        currency TEXT NOT NULL DEFAULT 'KES',
        due_date TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (tenancy_id, rent_month)
    );
    CREATE TABLE rent_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        rent_charge_id INTEGER NOT NULL,
        amount_paid NUMERIC NOT NULL
    );
    INSERT INTO users (id, full_name) VALUES (7, 'Admin User');
    INSERT INTO properties
        (id, title, address, currency, transaction_type, property_status, availability_status)
    VALUES
        (1, 'Nairobi Apartment', 'Nairobi', 'KES', 'rent', 'approved', 'available'),
        (2, 'Karen House', 'Karen', 'KES', 'rent', 'approved', 'available');"
);

require_once __DIR__ . '/../app/models/RentalModel.php';

$model = new RentalModel();
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

$tenantId = $model->createTenant([
    'full_name' => 'Grace Wanjiku',
    'email' => null,
    'phone' => '+254700000000',
    'national_id' => null,
]);
$assert(
    $tenantId > 0
        && $model->getTenant($tenantId)['full_name'] === 'Grace Wanjiku'
        && $model->countTenants() === 1
        && count($model->listTenants(1, 10)) === 1,
    'Tenant creation and retrieval should work.'
);

$tenancyId = $model->createTenancy($tenantId, [
    'tenant_id' => $tenantId,
    'property_id' => 1,
    'monthly_rent' => '50000.00',
    'currency' => 'EUR',
    'rent_due_day' => 5,
    'start_date' => '2026-01-20',
    'end_date' => null,
]);
$initialCharge = $model->getRentCharge(1);
$assert(
    $initialCharge['rent_month'] === '2026-01-01'
        && (float) $initialCharge['amount_due'] === 50000.0
        && $initialCharge['currency'] === 'EUR'
        && $initialCharge['due_date'] === '2026-01-20',
    'Tenancy creation should make a full initial-month charge in the tenancy currency.'
);
$assert(
    $pdo->query('SELECT availability_status FROM properties WHERE id = 1')->fetchColumn() === 'rented',
    'Creating an active tenancy should mark its property rented.'
);

$generated = $model->generateRentCharges($tenancyId, '2026-04');
$again = $model->generateRentCharges($tenancyId, '2026-04');
$assert(
    $generated['generated'] === 3
        && $again['generated'] === 0
        && $model->countRentCharges(['tenancy_id' => $tenancyId]) === 4,
    'Recurring charges should be generated once per month and remain idempotent.'
);
$februaryCharge = $model->listRentCharges(['tenancy_id' => $tenancyId], 1, 10)[1];
$assert(
    $februaryCharge['rent_month'] === '2026-02-01'
        && $februaryCharge['due_date'] === '2026-02-05'
        && $februaryCharge['currency'] === 'EUR',
    'Recurring charge due dates and currencies should follow tenancy settings.'
);
$assert(
    count($model->listTenancies(['tenant_id' => $tenantId], 1, 10)) === 1
        && count($model->listRentCharges(['property_id' => 1], 1, 10)) === 4,
    'Tenancy and charge filters should return their related records.'
);

$secondTenancyId = $model->createTenancy($tenantId, [
    'tenant_id' => $tenantId,
    'property_id' => 2,
    'monthly_rent' => '30000.00',
    'currency' => 'KES',
    'rent_due_day' => 31,
    'start_date' => '2026-01-01',
    'end_date' => null,
]);
$model->generateRentCharges($secondTenancyId, '2026-02');
$secondCharges = $model->listRentCharges(['tenancy_id' => $secondTenancyId], 1, 10);
$assert(
    $secondCharges[0]['due_date'] === '2026-01-31'
        && $secondCharges[1]['due_date'] === '2026-02-28',
    'Due dates should clamp to the final day of shorter months.'
);

$model->endTenancy($tenancyId, '2026-04-30');
$assert(
    $model->getTenancy($tenancyId)['tenancy_status'] === 'ended'
        && $pdo->query('SELECT availability_status FROM properties WHERE id = 1')->fetchColumn() === 'available',
    'Ending a tenancy should release the property.'
);

require_once __DIR__ . '/../routes/RentalRoutes.php';
require_once __DIR__ . '/../app/security/Middleware.php';
$allRentalRoutesAreAdminOnly = true;
foreach ($rentalRoutes as $route => [$controller, $method]) {
    $allRentalRoutesAreAdminOnly = $allRentalRoutesAreAdminOnly
        && method_exists($controller, $method)
        && Middleware::requiredRolesForRoute($route) === ['admin'];
}
$assert($allRentalRoutesAreAdminOnly, 'Every rental route should be wired to an admin-only controller method.');

echo "Rental model tests passed ({$assertions} assertions).\n";
