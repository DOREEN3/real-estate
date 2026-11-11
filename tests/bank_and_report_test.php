<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/PaymentModel.php';
require_once __DIR__ . '/../app/models/SaleModel.php';
require_once __DIR__ . '/../app/services/BankPaymentService.php';

$paymentModel = new PaymentModel();
$bankService = new BankPaymentService();

echo "Running Bank & Payment Reports tests...\n";

// 1. Bank Payment Service reference resolving
assert($bankService->resolveSaleId('SALE-12') === 12, 'Resolves SALE-12');
assert($bankService->resolveSaleId('SALE15') === 15, 'Resolves SALE15');
assert($bankService->resolveSaleId('99') === 99, 'Resolves 99');
assert($bankService->resolveInstallmentId('INST-42') === 42, 'Resolves INST-42');
echo "✓ Reference parsing passed.\n";

// 2. Bank payload parsing
$parsed = $bankService->parseCallback([
    'transaction_reference' => 'BK-TEST-1001',
    'account_reference' => 'SALE-1',
    'amount' => 50000,
    'bank_name' => 'Equity Bank',
    'status' => 'completed',
]);
assert($parsed['transaction_reference'] === 'BK-TEST-1001');
assert($parsed['amount'] === 50000.0);
assert($parsed['status'] === 'completed');
echo "✓ Bank callback parsing passed.\n";

// 3. Store Bank Callback in inbox
$inbox = $paymentModel->storeBankCallback(
    'BK-TEST-1001',
    'SALE-1',
    50000.0,
    'KES',
    'Equity Bank',
    $parsed
);
assert(!empty($inbox['id']), 'Callback stored with ID');
assert($inbox['transaction_reference'] === 'BK-TEST-1001');
echo "✓ Bank inbox storage passed.\n";

// 4. Test Payment Reports query
$report = $paymentModel->getPaymentsReport();
assert(is_array($report['summary']), 'Summary array returned');
assert(isset($report['summary']['total_transactions']), 'Total transactions present');
assert(is_array($report['by_method']), 'By method array present');
assert(is_array($report['by_type']), 'By type array present');
echo "✓ Payment reports query passed.\n";

// 5. Test Installments Report query
$instReport = $paymentModel->getInstallmentsReport();
assert(isset($instReport['summary']), 'Installments summary present');
assert(is_array($instReport['overdue_installments']), 'Overdue list present');
echo "✓ Installment reports query passed.\n";

echo "All Bank & Reports tests passed!\n";
