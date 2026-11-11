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
        payment_type TEXT NOT NULL,
        payment_method TEXT NOT NULL,
        payment_status TEXT NOT NULL,
        payment_date TEXT NOT NULL,
        installment_id INTEGER NULL,
        rent_charge_id INTEGER NULL
    );
    CREATE TABLE sales (
        id INTEGER PRIMARY KEY,
        property_id INTEGER NOT NULL,
        buyer_name TEXT NOT NULL,
        currency TEXT NOT NULL
    );
    CREATE TABLE tenancies (
        id INTEGER PRIMARY KEY,
        tenant_id INTEGER NOT NULL,
        property_id INTEGER NOT NULL,
        currency TEXT NOT NULL
    );
    CREATE TABLE tenants (id INTEGER PRIMARY KEY, full_name TEXT NOT NULL);
    CREATE TABLE bookings (
        id INTEGER PRIMARY KEY,
        property_id INTEGER NOT NULL,
        guest_name TEXT NOT NULL,
        currency TEXT NOT NULL
    );
    CREATE TABLE properties (id INTEGER PRIMARY KEY, title TEXT NOT NULL);
    CREATE TABLE payment_receipts (
        payment_id INTEGER NOT NULL,
        receipt_number TEXT NOT NULL
    );
    CREATE TABLE payment_invoices (
        id INTEGER PRIMARY KEY,
        invoice_number TEXT NOT NULL,
        installment_id INTEGER NULL,
        rent_charge_id INTEGER NULL
    );
    INSERT INTO properties (id, title) VALUES (1, 'Apartment');
    INSERT INTO sales (id, property_id, buyer_name, currency) VALUES (1, 1, 'Buyer', 'KES');
    INSERT INTO sales (id, property_id, buyer_name, currency) VALUES (2, 1, 'Buyer', 'USD');
    INSERT INTO payments
        (id, sale_id, amount, payment_type, payment_method, payment_status, payment_date)
    VALUES
        (1, 1, 1000, 'deposit', 'cash', 'completed', '2026-01-03 10:00:00'),
        (2, 2, 20, 'deposit', 'cash', 'completed', '2026-01-02 10:00:00'),
        (3, 1, 500, 'installment', 'mobile_money', 'completed', '2026-01-01 10:00:00');
    INSERT INTO payment_receipts (payment_id, receipt_number) VALUES
        (1, 'REC-1'), (2, 'REC-2'), (3, 'REC-3');"
);

require_once __DIR__ . '/../app/models/PaymentModel.php';

$reportPageOne = (new PaymentModel())->getPaymentsReport(null, null, null, 1, 2);
$reportPageTwo = (new PaymentModel())->getPaymentsReport(null, null, null, 2, 2);

if (
    $reportPageOne['pagination']['total'] !== 3
    || $reportPageOne['pagination']['total_pages'] !== 2
    || count($reportPageOne['recent_transactions']) !== 2
    || count($reportPageTwo['recent_transactions']) !== 1
    || $reportPageOne['recent_transactions'][0]['id'] === $reportPageTwo['recent_transactions'][0]['id']
) {
    throw new RuntimeException('Report transaction pages must be complete and non-overlapping.');
}

$currencyTotals = [];
foreach ($reportPageOne['by_currency'] as $currencySummary) {
    $currencyTotals[$currencySummary['currency']] = (float) $currencySummary['total_revenue'];
}
if (($currencyTotals['KES'] ?? 0) !== 1500.0 || ($currencyTotals['USD'] ?? 0) !== 20.0) {
    throw new RuntimeException('Report revenue summaries must not combine unlike currencies.');
}

$pageOneCurrencies = array_column($reportPageOne['recent_transactions'], 'currency');
if ($pageOneCurrencies !== ['KES', 'USD']) {
    throw new RuntimeException('Each exported transaction must include its currency.');
}

fwrite(STDOUT, "Payment report pagination checks passed.\n");
