<?php

declare(strict_types=1);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(
    "CREATE TABLE tenancies (
        id INTEGER PRIMARY KEY,
        tenant_id INTEGER NOT NULL,
        property_id INTEGER NOT NULL,
        tenancy_status TEXT NOT NULL,
        grace_period_days INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE tenants (
        id INTEGER PRIMARY KEY,
        full_name TEXT NOT NULL,
        email TEXT,
        phone TEXT
    );
    CREATE TABLE properties (id INTEGER PRIMARY KEY, title TEXT NOT NULL);
    CREATE TABLE rent_charges (
        id INTEGER PRIMARY KEY,
        tenancy_id INTEGER NOT NULL,
        due_date TEXT NOT NULL,
        amount_due NUMERIC NOT NULL,
        currency TEXT NOT NULL
    );
    CREATE TABLE sales (
        id INTEGER PRIMARY KEY,
        property_id INTEGER NOT NULL,
        buyer_name TEXT NOT NULL,
        buyer_email TEXT,
        currency TEXT NOT NULL
    );
    CREATE TABLE installments (
        id INTEGER PRIMARY KEY,
        sale_id INTEGER NOT NULL,
        due_date TEXT NOT NULL,
        amount_due NUMERIC NOT NULL,
        installment_number INTEGER NOT NULL
    );
    CREATE TABLE payments (
        id INTEGER PRIMARY KEY,
        installment_id INTEGER,
        rent_charge_id INTEGER,
        amount NUMERIC NOT NULL,
        payment_status TEXT NOT NULL,
        transaction_reference TEXT
    );
    CREATE TABLE rent_payments (
        id INTEGER PRIMARY KEY,
        rent_charge_id INTEGER NOT NULL,
        payment_id INTEGER,
        amount_paid NUMERIC NOT NULL,
        transaction_reference TEXT
    );
    CREATE TABLE payment_invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_number TEXT NOT NULL UNIQUE,
        installment_id INTEGER UNIQUE,
        rent_charge_id INTEGER UNIQUE,
        invoice_date TEXT NOT NULL,
        due_date TEXT NOT NULL,
        amount_due NUMERIC NOT NULL,
        currency TEXT NOT NULL
    );
    CREATE TABLE payment_reminder_dispatches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        target_type TEXT NOT NULL,
        target_id INTEGER NOT NULL,
        event_key TEXT NOT NULL,
        event_date TEXT NOT NULL,
        email_status TEXT NOT NULL DEFAULT 'pending',
        sms_status TEXT NOT NULL DEFAULT 'pending',
        last_error TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (target_type, target_id, event_key)
    );
    INSERT INTO properties (id, title) VALUES (1, 'Garden Apartment');
    INSERT INTO tenants (id, full_name, email, phone) VALUES (1, 'Tenant One', 'tenant@example.test', '0712345678');
    INSERT INTO tenancies (id, tenant_id, property_id, tenancy_status, grace_period_days)
        VALUES (1, 1, 1, 'active', 2), (2, 1, 1, 'ended', 0);
    INSERT INTO rent_charges (id, tenancy_id, due_date, amount_due, currency)
        VALUES
            (1, 1, '2026-11-17', 1000, 'KES'),
            (2, 2, '2026-11-17', 1000, 'KES'),
            (3, 1, '2026-11-17', 1000, 'KES');
    INSERT INTO rent_payments (id, rent_charge_id, payment_id, amount_paid)
        VALUES (1, 1, NULL, 100);
    INSERT INTO payments (id, rent_charge_id, amount, payment_status)
        VALUES
            (1, 1, 400, 'completed'),
            (2, 1, 500, 'pending'),
            (3, 1, 100, 'failed'),
            (6, 3, 1000, 'completed');
    INSERT INTO sales (id, property_id, buyer_name, buyer_email, currency)
        VALUES (1, 1, 'Buyer One', 'buyer@example.test', 'KES');
    INSERT INTO installments (id, sale_id, due_date, amount_due, installment_number)
        VALUES (1, 1, '2026-11-17', 1000, 1), (2, 1, '2026-11-17', 1000, 2);
    INSERT INTO payments (id, installment_id, amount, payment_status)
        VALUES
            (4, 1, 600, 'completed'),
            (5, 1, 400, 'pending'),
            (7, 2, 1000, 'completed');"
);

require_once __DIR__ . '/../app/models/PaymentReminderModel.php';

$model = new PaymentReminderModel();
$installments = $model->installmentBalances('2026-11-17');
$rents = $model->rentChargeBalances('2026-11-17');
if (
    count($installments) !== 1
    || (float) $installments[0]['outstanding_balance'] !== 400.0
    || count($rents) !== 1
    || (float) $rents[0]['outstanding_balance'] !== 500.0
) {
    throw new RuntimeException('Outstanding balances must count only completed payments and active tenancies.');
}

$invoice = $model->getOrCreateInvoice($installments[0], '2026-11-10');
$sameInvoice = $model->getOrCreateInvoice($installments[0], '2026-11-10');
if (
    $invoice['invoice_number'] !== $sameInvoice['invoice_number']
    || (float) $invoice['amount_due'] !== 400.0
) {
    throw new RuntimeException('An installment must have one invoice for its outstanding balance.');
}

$dispatch = $model->ensureDispatch($installments[0], 'reminder_7_days', '2026-11-10');
$duplicate = $model->ensureDispatch($installments[0], 'reminder_7_days', '2026-11-10');
if ((int) $dispatch['id'] !== (int) $duplicate['id']) {
    throw new RuntimeException('Repeated scheduler runs must reuse a reminder event record.');
}
if (!$model->claimChannel((int) $dispatch['id'], 'email')) {
    throw new RuntimeException('A new email reminder should be claimable.');
}
$model->finishChannel((int) $dispatch['id'], 'email', 'sent');
if ($model->claimChannel((int) $dispatch['id'], 'email')) {
    throw new RuntimeException('A successfully sent email reminder must not be sent twice.');
}
$model->finishChannel((int) $dispatch['id'], 'sms', 'failed', 'Temporary provider error');
if ($model->retryEventsForTarget($installments[0]) !== ['reminder_7_days']) {
    throw new RuntimeException('A failed reminder channel must remain eligible for retry.');
}

fwrite(STDOUT, "Payment reminder model checks passed.\n");
