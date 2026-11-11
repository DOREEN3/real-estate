<?php

declare(strict_types=1);

date_default_timezone_set('Africa/Nairobi');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec(
    "CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        full_name TEXT NOT NULL,
        email TEXT NULL
    );
    CREATE TABLE sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        property_id INTEGER NOT NULL,
        enquiry_id INTEGER NULL,
        buyer_name TEXT NOT NULL,
        buyer_email TEXT NULL,
        sale_price NUMERIC NOT NULL,
        currency TEXT NOT NULL DEFAULT 'KES',
        deposit_amount NUMERIC NOT NULL DEFAULT 0,
        installment_amount NUMERIC NULL,
        number_of_installments INTEGER NOT NULL DEFAULT 0,
        next_installment_due TEXT NULL,
        sale_status TEXT NOT NULL DEFAULT 'in_progress',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE installments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        installment_number INTEGER NOT NULL,
        amount_due NUMERIC NOT NULL,
        due_date TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (sale_id, installment_number)
    );
    CREATE TABLE payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NULL,
        tenancy_id INTEGER NULL,
        booking_id INTEGER NULL,
        amount NUMERIC NOT NULL,
        payment_type TEXT NOT NULL,
        payment_method TEXT NOT NULL,
        payment_source TEXT NOT NULL DEFAULT 'manual',
        payment_status TEXT NOT NULL DEFAULT 'completed',
        provider TEXT NULL,
        provider_reference TEXT NULL,
        payer_phone TEXT NULL,
        transaction_reference TEXT NULL,
        payment_date TEXT NOT NULL,
        recorded_by INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        installment_id INTEGER NULL,
        rent_charge_id INTEGER NULL,
        UNIQUE (provider, provider_reference),
        UNIQUE (transaction_reference)
    );
    CREATE TABLE payment_receipts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        payment_id INTEGER NOT NULL UNIQUE,
        receipt_number TEXT NOT NULL UNIQUE,
        issued_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        issued_by INTEGER NULL,
        issuer_type TEXT NOT NULL DEFAULT 'user'
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
    CREATE TABLE properties (
        id INTEGER PRIMARY KEY,
        title TEXT NOT NULL,
        currency TEXT NOT NULL DEFAULT 'KES',
        transaction_type TEXT NOT NULL DEFAULT 'sale',
        property_status TEXT NOT NULL DEFAULT 'approved',
        availability_status TEXT NOT NULL DEFAULT 'available'
    );
    CREATE TABLE enquiries (id INTEGER PRIMARY KEY);
    CREATE TABLE IF NOT EXISTS tenancies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenant_id INTEGER NOT NULL,
        property_id INTEGER NOT NULL,
        currency TEXT NOT NULL DEFAULT 'KES'
    );
    CREATE TABLE IF NOT EXISTS tenants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        property_id INTEGER NOT NULL,
        guest_name TEXT NOT NULL,
        currency TEXT NOT NULL DEFAULT 'KES'
    );
    INSERT INTO users (id, full_name) VALUES (7, 'Admin User');
    INSERT INTO properties (id, title) VALUES
        (1, 'Nairobi Apartment'),
        (2, 'Karen House'),
        (3, 'Installment Sale Property'),
        (4, 'Cash Sale Property'),
        (5, 'Rounding Test Property'),
        (6, 'Zero Deposit Plan Property');
    INSERT INTO sales
        (id, property_id, buyer_name, sale_price, deposit_amount)
    VALUES
        (1, 1, 'Grace Wanjiku', 100000, 20000),
        (2, 2, 'Daniel Kimani', 100000, 0);
    INSERT INTO installments
        (id, sale_id, installment_number, amount_due, due_date)
    VALUES
        (1, 1, 1, 25000, '2026-12-01'),
        (2, 1, 0, 20000, '2026-11-05');"
);

require_once __DIR__ . '/../app/models/PaymentModel.php';
require_once __DIR__ . '/../app/controllers/PaymentController.php';
require_once __DIR__ . '/../routes/PaymentRoutes.php';
require_once __DIR__ . '/../routes/SaleRoutes.php';
require_once __DIR__ . '/../app/models/SaleModel.php';

$model = new PaymentModel();
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};

$salePaymentId = $model->recordSalePayment(1, 7, [
    'amount' => '12500.00',
    'payment_type' => 'installment',
    'payment_method' => 'mobile_money',
    'payment_source' => 'manual',
    'provider' => null,
    'provider_reference' => null,
    'transaction_reference' => 'TXN-100',
    'payment_date' => '2026-11-05 10:30:00',
    'installment_id' => 1,
]);
$pdo->prepare(
    "INSERT INTO payment_invoices
        (invoice_number, installment_id, invoice_date, due_date, amount_due, currency)
     VALUES ('INV-SALE-1', 1, '2026-11-01', '2026-12-01', 25000, 'KES')"
)->execute();
$salePayment = $model->getSalePayment($salePaymentId);
$assert(
    $salePaymentId > 0 && $salePayment['amount'] === '12500'
        && (int) $salePayment['recorded_by'] === 7
        && $salePayment['buyer_name'] === 'Grace Wanjiku'
        && $salePayment['invoice_number'] === 'INV-SALE-1'
        && $salePayment['payment_source'] === 'manual'
        && $salePayment['payment_status'] === 'completed',
    'Sale payment details should explicitly record source/status and include sale data.'
);
$assert(
    preg_match('/^SALE-\d{8}-[a-f0-9]{24}$/', $salePayment['receipt_number']) === 1
        && (int) $salePayment['issued_by'] === 7
        && (int) $pdo->query('SELECT COUNT(*) FROM payment_receipts WHERE payment_id = ' . $salePaymentId)->fetchColumn() === 1,
    'A sale payment should receive one unique receipt issued by its authenticated recorder.'
);
$assert(
    $model->countSalePayments(['sale_id' => 1, 'payment_type' => 'installment']) === 1
        && count($model->listSalePayments(['sale_id' => 1], 1, 10)) === 1
        && $model->saleExists(1) && !$model->saleExists(999),
    'Sale payment filters should work with paginated retrieval.'
);
$assert(
    $pdo->query('SELECT status FROM installments WHERE id = 1')->fetchColumn() === 'partial',
    'A partial completed installment payment should set the installment status to partial.'
);

$depositPaymentId = $model->recordSalePayment(1, 7, [
    'amount' => '20000.00',
    'payment_type' => 'deposit',
    'payment_method' => 'cash',
    'payment_source' => 'manual',
    'provider' => null,
    'provider_reference' => null,
    'transaction_reference' => 'DEP-100',
    'payment_date' => '2026-11-05 10:45:00',
    'installment_id' => 2,
]);
$assert(
    $pdo->query('SELECT status FROM installments WHERE id = 2')->fetchColumn() === 'paid',
    'The paid deposit should update its installment-number-zero row.'
);

$providerPaymentId = $model->recordSalePayment(1, 7, [
    'amount' => '12500.00',
    'payment_type' => 'installment',
    'payment_method' => 'mobile_money',
    'payment_source' => 'provider',
    'provider' => 'M-Pesa',
    'provider_reference' => 'MPESA-PENDING-1',
    'transaction_reference' => null,
    'payment_date' => '2026-11-05 11:00:00',
    'installment_id' => 1,
]);
$pendingProviderPayment = $model->getSalePayment($providerPaymentId);
$assert(
    $pendingProviderPayment['payment_status'] === 'pending'
        && $pendingProviderPayment['payment_source'] === 'provider'
        && $pendingProviderPayment['receipt_number'] === null
        && $pdo->query('SELECT status FROM installments WHERE id = 1')->fetchColumn() === 'partial',
    'Pending provider payments must not create receipts or change installment status.'
);
$model->resolveProviderPayment($providerPaymentId, 7, 'completed', 'MPESA-CONFIRMED-1');
$confirmedProviderPayment = $model->getSalePayment($providerPaymentId);
$assert(
    $confirmedProviderPayment['payment_status'] === 'completed'
        && $confirmedProviderPayment['provider_reference'] === 'MPESA-CONFIRMED-1'
        && $confirmedProviderPayment['receipt_number'] !== null
        && $pdo->query('SELECT status FROM installments WHERE id = 1')->fetchColumn() === 'paid',
    'Provider confirmation should atomically complete the payment, update installment, and issue receipt.'
);
$systemIssuedPaymentId = $model->recordSalePayment(2, 7, [
    'amount' => '1000.00',
    'payment_type' => 'full',
    'payment_method' => 'mobile_money',
    'payment_source' => 'provider',
    'provider' => 'M-Pesa',
    'provider_reference' => 'MPESA-SYSTEM-ISSUER',
    'transaction_reference' => null,
    'payment_date' => '2026-11-05 11:01:00',
    'installment_id' => null,
]);
$model->resolveProviderPayment($systemIssuedPaymentId, null, 'completed', null, 'MPESA-RECEIPT-1');
$systemIssuedPayment = $model->getSalePayment($systemIssuedPaymentId);
$assert(
    $systemIssuedPayment['issuer_type'] === 'system'
        && $systemIssuedPayment['issued_by'] === null
        && $systemIssuedPayment['transaction_reference'] === 'MPESA-RECEIPT-1',
    'A callback-issued receipt should be marked as system-issued without impersonating a user.'
);

$duplicateProviderReferenceRejected = false;
try {
    $model->recordSalePayment(2, 7, [
        'amount' => '1000.00',
        'payment_type' => 'full',
        'payment_method' => 'mobile_money',
        'payment_source' => 'provider',
        'provider' => 'm-pesa',
        'provider_reference' => 'MPESA-SYSTEM-ISSUER',
        'transaction_reference' => null,
        'payment_date' => '2026-11-05 11:02:00',
        'installment_id' => null,
    ]);
} catch (DomainException $exception) {
    $duplicateProviderReferenceRejected = $exception->getMessage() === 'duplicate_provider_reference';
}
$assert(
    $duplicateProviderReferenceRejected
        && (int) $model->getProviderPaymentByReference('M-Pesa', 'MPESA-SYSTEM-ISSUER')['id'] === $systemIssuedPaymentId,
    'Canonical provider references should identify one payment and reject duplicates.'
);

$duplicateSaleTransactionRejected = false;
try {
    $model->recordSalePayment(2, 7, [
        'amount' => '100.00',
        'payment_type' => 'full',
        'payment_method' => 'cash',
        'payment_source' => 'manual',
        'provider' => null,
        'provider_reference' => null,
        'transaction_reference' => 'TXN-100',
        'payment_date' => '2026-11-05 11:03:00',
        'installment_id' => null,
    ]);
} catch (DomainException $exception) {
    $duplicateSaleTransactionRejected = $exception->getMessage() === 'duplicate_transaction_reference';
}
$assert($duplicateSaleTransactionRejected, 'Duplicate sale transaction references should return a domain error.');
$installmentOverpaymentRejected = false;
$installmentOverpaymentError = null;
try {
    $model->recordSalePayment(1, 7, [
        'amount' => '0.01',
        'payment_type' => 'installment',
        'payment_method' => 'cash',
        'payment_source' => 'manual',
        'provider' => null,
        'provider_reference' => null,
        'transaction_reference' => 'INSTALLMENT-OVERPAY',
        'payment_date' => '2026-11-05 11:02:00',
        'installment_id' => 1,
    ]);
} catch (DomainException $exception) {
    $installmentOverpaymentRejected = in_array(
        $exception->getMessage(),
        ['payment_exceeds_installment_balance', 'installment_already_paid'],
        true
    );
    $installmentOverpaymentError = $exception->getMessage();
}
$assert(
    $installmentOverpaymentRejected,
    'Completed payments must not exceed the outstanding installment balance. Got: '
        . ($installmentOverpaymentError ?? 'no exception')
);
$model->resolveProviderPayment(
    $model->recordSalePayment(2, 7, [
        'amount' => '1000.00',
        'payment_type' => 'full',
        'payment_method' => 'mobile_money',
        'payment_source' => 'provider',
        'provider' => 'M-Pesa',
        'provider_reference' => 'MPESA-PENDING-FAILED',
        'transaction_reference' => null,
        'payment_date' => '2026-11-05 11:05:00',
        'installment_id' => null,
    ]),
    7,
    'failed',
    null
);
$assert(
    (int) $pdo->query(
        "SELECT COUNT(*) FROM payments p
         LEFT JOIN payment_receipts r ON r.payment_id = p.id
         WHERE p.provider_reference = 'MPESA-PENDING-FAILED'
           AND p.payment_status = 'failed' AND r.id IS NULL"
    )->fetchColumn() === 1,
    'A failed provider payment should remain receipted neither as a payment nor installment completion.'
);

$balanceRacePaymentId = $model->recordSalePayment(2, 7, [
    'amount' => '60000.00',
    'payment_type' => 'full',
    'payment_method' => 'mobile_money',
    'payment_source' => 'provider',
    'provider' => 'M-Pesa',
    'provider_reference' => 'MPESA-BALANCE-RACE',
    'transaction_reference' => null,
    'payment_date' => '2026-11-05 11:10:00',
    'installment_id' => null,
]);
$saleReportWithPending = (new SaleModel())->getById(2);
$assert(
    (int) $saleReportWithPending['outstanding_balance'] === 99000
        && (int) $saleReportWithPending['available_balance'] === 39000,
    'Sale reports should distinguish money not yet received from balance available for new payment attempts.'
);
$pendingAllocationRejected = false;
try {
    $model->recordSalePayment(2, 7, [
        'amount' => '50000.00',
        'payment_type' => 'full',
        'payment_method' => 'cash',
        'payment_source' => 'manual',
        'provider' => null,
        'provider_reference' => null,
        'transaction_reference' => 'CASH-200',
        'payment_date' => '2026-11-05 11:15:00',
        'installment_id' => null,
    ]);
} catch (DomainException $exception) {
    $pendingAllocationRejected = $exception->getMessage() === 'payment_exceeds_sale_balance';
}
$model->resolveProviderPayment($balanceRacePaymentId, 7, 'completed', null);
$confirmedReservedPayment = $model->getSalePayment($balanceRacePaymentId);
$assert(
    $pendingAllocationRejected
        && $confirmedReservedPayment['payment_status'] === 'completed'
        && $confirmedReservedPayment['receipt_number'] !== null,
    'Pending provider attempts must reserve sale balance and remain confirmable while excluding their own reservation.'
);

$mismatchedConfirmationId = $model->recordSalePayment(2, 7, [
    'amount' => '1000.00',
    'payment_type' => 'full',
    'payment_method' => 'mobile_money',
    'payment_source' => 'provider',
    'provider' => 'M-Pesa',
    'provider_reference' => 'MPESA-MISMATCHED-TYPE',
    'transaction_reference' => null,
    'payment_date' => '2026-11-05 11:20:00',
    'installment_id' => null,
]);
$pdo->prepare('UPDATE payments SET payment_type = :payment_type WHERE id = :id')->execute([
    ':payment_type' => 'deposit',
    ':id' => $mismatchedConfirmationId,
]);
$mismatchedConfirmationRejected = false;
try {
    $model->resolveProviderPayment($mismatchedConfirmationId, 7, 'completed', null);
} catch (DomainException $exception) {
    $mismatchedConfirmationRejected = $exception->getMessage() === 'deposit_installment_required';
}
$assert(
    $mismatchedConfirmationRejected
        && $model->getSalePayment($mismatchedConfirmationId)['payment_status'] === 'pending',
    'Provider confirmation must re-check payment-type and installment consistency.'
);

$invalidResolutionStatusRejected = false;
try {
    $model->resolveProviderPayment($providerPaymentId, 7, 'approved', null);
} catch (DomainException $exception) {
    $invalidResolutionStatusRejected = $exception->getMessage() === 'invalid_payment_status';
}
$assert($invalidResolutionStatusRejected, 'The payment model must reject unknown provider-resolution statuses.');

$mismatchedInstallmentRejected = false;
$paymentCountBeforeMismatch = (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();
$receiptCountBeforeMismatch = (int) $pdo->query('SELECT COUNT(*) FROM payment_receipts')->fetchColumn();
try {
    $model->recordSalePayment(2, 7, [
        'amount' => '100.00',
        'payment_type' => 'deposit',
        'payment_method' => 'cash',
        'transaction_reference' => null,
        'payment_date' => '2026-11-05 10:30:00',
        'installment_id' => 1,
    ]);
} catch (DomainException $exception) {
    $mismatchedInstallmentRejected = $exception->getMessage() === 'installment_not_found';
}
$assert(
    $mismatchedInstallmentRejected
        && (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn() === $paymentCountBeforeMismatch
        && (int) $pdo->query('SELECT COUNT(*) FROM payment_receipts')->fetchColumn() === $receiptCountBeforeMismatch,
    'A sale payment must not use another sale’s installment, and failure must roll back inserts.'
);

$controller = new PaymentController();
$validateSalePayment = new ReflectionMethod($controller, 'validatedSalePayment');
$validateSalePayment->setAccessible(true);
$normalizedSalePayment = $validateSalePayment->invoke($controller, [
    'amount' => '250.5',
    'payment_type' => 'deposit',
    'payment_method' => 'cash',
    'transaction_reference' => 'RCPT-CASH-001',
    'payment_date' => '2026-11-05T06:00:00Z',
]);
$assert(
    $normalizedSalePayment['amount'] === '250.50'
        && $normalizedSalePayment['payment_date'] === '2026-11-05 09:00:00'
        && $normalizedSalePayment['installment_id'] === null,
    'Payment amounts should be normalized and dates converted to the configured timezone.'
);
$validateProviderPayment = new ReflectionMethod($controller, 'validatedSalePayment');
$validateProviderPayment->setAccessible(true);
$normalizedProviderPayment = $validateProviderPayment->invoke($controller, [
    'amount' => '125.00',
    'payment_type' => 'INSTALLMENT',
    'payment_method' => 'mobile_money',
    'payment_source' => 'PROVIDER',
    'provider' => 'M-Pesa',
    'provider_reference' => 'MPESA-PENDING-API',
    'installment_id' => '1',
]);
$assert(
    $normalizedProviderPayment['payment_source'] === 'provider'
        && $normalizedProviderPayment['payment_type'] === 'installment'
        && $normalizedProviderPayment['provider'] === 'M-Pesa',
    'Provider payments should be normalized and retain the supplied provider metadata.'
);

$saleModel = new SaleModel();
$saleController = new SaleController();
$decimalToCents = new ReflectionMethod($saleModel, 'decimalToCents');
$decimalToCents->setAccessible(true);
$invalidModelAmountRejected = false;
try {
    $decimalToCents->invoke($saleModel, 'invalid');
} catch (InvalidArgumentException) {
    $invalidModelAmountRejected = true;
}
$assert($invalidModelAmountRejected, 'SaleModel must reject malformed decimal input.');
$validateSale = new ReflectionMethod($saleController, 'validatedSale');
$validateSale->setAccessible(true);
$normalizedSale = $validateSale->invoke($saleController, [
    'property_id' => 3,
    'buyer_name' => 'Monthly Buyer',
    'sale_price' => '5000000',
    'deposit_amount' => '1000000.00',
    'number_of_installments' => 4,
    'installment_frequency' => 'monthly',
    'schedule_start_date' => '2026-12-31',
    'currency' => 'kes',
]);
$assert(
    $normalizedSale['sale_price'] === '5000000.00'
        && $normalizedSale['deposit_amount'] === '1000000.00'
        && $normalizedSale['currency'] === 'KES'
        && $normalizedSale['schedule_start_date'] === '2026-12-31',
    'Sale request validation should normalize monetary values, currency, and schedule dates.'
);
$installmentSaleId = $saleModel->create([
    'property_id' => 3,
    'enquiry_id' => null,
    'buyer_name' => 'Monthly Buyer',
    'buyer_email' => null,
    'sale_price' => '5000000.00',
    'currency' => null,
    'deposit_amount' => '1000000.00',
    'number_of_installments' => 4,
    'installment_frequency' => 'monthly',
    'schedule_start_date' => '2026-01-31',
]);
$createdSale = $saleModel->getById($installmentSaleId);
$scheduledInstallments = $saleModel->listInstallments($installmentSaleId);
$assert(
    $createdSale['deposit_amount'] === '1000000'
        && (int) $createdSale['number_of_installments'] === 4
        && (int) $createdSale['total_paid'] === 0
        && (int) $createdSale['outstanding_balance'] === 5000000,
    'Agreed deposit must not count as paid until an actual completed payment is recorded.'
);
$assert(
    count($scheduledInstallments) === 5
        && (int) $scheduledInstallments[0]['installment_number'] === 0
        && $scheduledInstallments[0]['amount_due'] === '1000000'
        && array_column($scheduledInstallments, 'due_date') === [
            '2026-01-31',
            '2026-02-28',
            '2026-03-31',
            '2026-04-30',
            '2026-05-31',
        ]
        && $createdSale['next_installment_due'] === '2026-02-28'
        && array_unique(array_column($scheduledInstallments, 'status')) === ['pending'],
    'Sale creation should point next_installment_due to the first regular installment and create clamped monthly rows.'
);
$scheduleTotalCents = array_sum(array_map(
    static function (array $installment): int {
        [$whole, $fraction] = array_pad(explode('.', (string) $installment['amount_due'], 2), 2, '0');
        return (int) $whole * 100 + (int) $fraction;
    },
    $scheduledInstallments
));
$assert(
    $scheduleTotalCents === 500000000,
    'The deposit and generated installments should sum exactly to the contracted sale price.'
);

$cashSaleId = $saleModel->create([
    'property_id' => 4,
    'enquiry_id' => null,
    'buyer_name' => 'Cash Buyer',
    'buyer_email' => null,
    'sale_price' => '75000.00',
    'currency' => null,
    'deposit_amount' => '0.00',
    'number_of_installments' => 0,
    'installment_frequency' => null,
    'schedule_start_date' => null,
]);
$assert(
    $saleModel->listInstallments($cashSaleId) === [],
    'A cash sale without an installment arrangement should not create installment rows.'
);
$cashPaymentId = $model->recordSalePayment($cashSaleId, 7, [
    'amount' => '75000.00',
    'payment_type' => 'full',
    'payment_method' => 'cash',
    'payment_source' => 'manual',
    'provider' => null,
    'provider_reference' => null,
    'transaction_reference' => 'CASH-SALE-FULL',
    'payment_date' => '2026-11-05 12:00:00',
    'installment_id' => null,
]);
$completedCashSale = $saleModel->getById($cashSaleId);
$assert(
    $completedCashSale['sale_status'] === 'completed'
        && (int) $completedCashSale['outstanding_balance'] === 0
        && (int) $completedCashSale['available_balance'] === 0
        && (int) $model->getSalePayment($cashPaymentId)['issued_by'] === 7,
    'Receiving the full cash sale price should automatically complete the sale.'
);
$duplicateSaleRejected = false;
try {
    $saleModel->create([
        'property_id' => 3,
        'enquiry_id' => null,
        'buyer_name' => 'Duplicate Buyer',
        'buyer_email' => null,
        'sale_price' => '5000000.00',
        'currency' => null,
        'deposit_amount' => '1000000.00',
        'number_of_installments' => 4,
        'installment_frequency' => 'monthly',
        'schedule_start_date' => '2026-11-15',
    ]);
} catch (DomainException $exception) {
    $duplicateSaleRejected = $exception->getMessage() === 'property_sale_exists';
}
$assert(
    $duplicateSaleRejected && $saleModel->count([]) === 4,
    'A property with an active sale should not receive a duplicate sale record.'
);
$roundingSaleId = $saleModel->create([
    'property_id' => 5,
    'enquiry_id' => null,
    'buyer_name' => 'Rounding Buyer',
    'buyer_email' => null,
    'sale_price' => '100.00',
    'currency' => null,
    'deposit_amount' => '0.99',
    'number_of_installments' => 3,
    'installment_frequency' => 'monthly',
    'schedule_start_date' => '2026-11-30',
]);
$roundedAmounts = array_column($saleModel->listInstallments($roundingSaleId), 'amount_due');
$assert(
    $roundedAmounts === ['0.99', '33.01', '33', '33'],
    'Rounding cents should be distributed across early installments without changing the sale total: '
        . json_encode($roundedAmounts)
);
$depositWithoutScheduleRejected = false;
try {
    $saleModel->create([
        'property_id' => 6,
        'enquiry_id' => null,
        'buyer_name' => 'Invalid Cash Sale',
        'buyer_email' => null,
        'sale_price' => '100.00',
        'currency' => null,
        'deposit_amount' => '1.00',
        'number_of_installments' => 0,
        'installment_frequency' => null,
        'schedule_start_date' => null,
    ]);
} catch (DomainException $exception) {
    $depositWithoutScheduleRejected = $exception->getMessage() === 'deposit_without_installments';
}
$assert(
    $depositWithoutScheduleRejected,
    'A cash sale without installments must reject a nonzero deposit.'
);
$zeroDepositPlanId = $saleModel->create([
    'property_id' => 6,
    'enquiry_id' => null,
    'buyer_name' => 'Zero Deposit Buyer',
    'buyer_email' => null,
    'sale_price' => '100.00',
    'currency' => null,
    'deposit_amount' => '0.00',
    'number_of_installments' => 2,
    'installment_frequency' => 'monthly',
    'schedule_start_date' => '2026-11-30',
]);
$zeroDepositInstallments = $saleModel->listInstallments($zeroDepositPlanId);
$assert(
    count($zeroDepositInstallments) === 2
        && array_map('intval', array_column($zeroDepositInstallments, 'installment_number')) === [1, 2],
    'A zero deposit should not create a zero-value deposit installment row.'
);
$assert(
    $saleModel->count([]) === 6 && count($saleModel->list([], 1, 10)) === 6,
    'Sale listing should support paginated retrieval and aggregate completed balances.'
);

$assert(
    isset($paymentRoutes['POST /api/v1/admin/sales/{id}/payments'])
        && isset($paymentRoutes['GET /api/v1/admin/payments/{id}'])
        && isset($paymentRoutes['PATCH /api/v1/admin/payments/{id}/status'])
        && isset($paymentRoutes['POST /api/v1/payments/providers/mpesa/callback'])
        && isset($saleRoutes['POST /api/v1/admin/sales'])
        && isset($saleRoutes['GET /api/v1/admin/sales/{id}/installments']),
    'Sale, installment, and provider-payment routes should be registered.'
);
$assert(
    Middleware::requiredRolesForRoute('POST /api/v1/admin/sales/{id}/payments') === ['admin']
        && Middleware::requiredRolesForRoute('GET /api/v1/admin/payments/{id}') === ['admin']
        && Middleware::requiredRolesForRoute('PATCH /api/v1/admin/payments/{id}/status') === ['admin']
        && Middleware::requiredRolesForRoute('POST /api/v1/admin/sales') === ['admin']
        && Middleware::requiredRolesForRoute('GET /api/v1/admin/sales/{id}/installments') === ['admin']
        && Middleware::requiredRolesForRoute('POST /api/v1/admin/rent-charges/{id}/payments') === null
        && Middleware::requiredRolesForRoute('GET /api/v1/admin/rent-payments/{id}') === null
        && Middleware::requiredRolesForRoute('POST /api/v1/payments/providers/mpesa/callback') === null
        && Middleware::isPublicRoute('POST', '/api/v1/payments/providers/mpesa/callback')
        && !Middleware::isPublicRoute('GET', '/api/v1/admin/payments'),
    'Administrative payment endpoints should require admin access, while the callback is public for provider verification.'
);

fwrite(STDOUT, "Payment API checks passed ({$assertions} assertions).\n");
