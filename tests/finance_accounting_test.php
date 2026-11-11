<?php

declare(strict_types=1);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(
    "CREATE TABLE payments (
        id INTEGER PRIMARY KEY,
        sale_id INTEGER NULL,
        tenancy_id INTEGER NULL,
        booking_id INTEGER NULL,
        amount NUMERIC NOT NULL,
        payment_status TEXT NOT NULL,
        payment_date TEXT NOT NULL
    );
    CREATE TABLE sales (id INTEGER PRIMARY KEY, currency TEXT NOT NULL);
    CREATE TABLE tenancies (id INTEGER PRIMARY KEY, currency TEXT NOT NULL);
    CREATE TABLE bookings (id INTEGER PRIMARY KEY, currency TEXT NOT NULL);
    CREATE TABLE expenses (
        id INTEGER PRIMARY KEY,
        expense_date TEXT NOT NULL,
        category TEXT NOT NULL,
        vendor TEXT NULL,
        description TEXT NOT NULL,
        amount NUMERIC NOT NULL,
        currency TEXT NOT NULL,
        recorded_by INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    INSERT INTO sales (id, currency) VALUES (1, 'KES'), (2, 'USD');
    INSERT INTO payments (id, sale_id, amount, payment_status, payment_date) VALUES
        (1, 1, 1000, 'completed', '2025-02-01'),
        (2, 2, 20, 'completed', '2025-03-01'),
        (3, 1, 500, 'pending', '2025-03-01'),
        (4, 1, 300, 'completed', '2024-12-31');
    INSERT INTO expenses
        (id, expense_date, category, vendor, description, amount, currency, recorded_by)
    VALUES
        (1, '2025-02-10', 'operations', 'Vendor A', 'Office costs', 120, 'KES', 1),
        (2, '2025-02-11', 'maintenance', NULL, 'Repair', 5, 'USD', 1),
        (3, '2024-12-31', 'operations', NULL, 'Prior year', 100, 'KES', 1);"
);

require_once __DIR__ . '/../app/models/ExpenseModel.php';

$model = new ExpenseModel();
$firstExpensePage = $model->listForYear(2025, 1, 1);
$secondExpensePage = $model->listForYear(2025, 2, 1);
$annualResults = [];
foreach ($model->annualProfitLoss(2025) as $result) {
    $annualResults[$result['currency']] = $result;
}

if (
    $model->countForYear(2025) !== 2
    || count($firstExpensePage) !== 1
    || count($secondExpensePage) !== 1
    || $firstExpensePage[0]['id'] === $secondExpensePage[0]['id']
) {
    throw new RuntimeException('Expense listings must be year-scoped and paginated without overlap.');
}

if (
    (float) $annualResults['KES']['revenue'] !== 1000.0
    || (float) $annualResults['KES']['expenses'] !== 120.0
    || (float) $annualResults['KES']['net_result'] !== 880.0
    || (float) $annualResults['USD']['revenue'] !== 20.0
    || (float) $annualResults['USD']['expenses'] !== 5.0
    || (float) $annualResults['USD']['net_result'] !== 15.0
) {
    throw new RuntimeException('Annual P&L must count completed payments and expenses separately by currency.');
}

fwrite(STDOUT, "Finance accounting checks passed.\n");
