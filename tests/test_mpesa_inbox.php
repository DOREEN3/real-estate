<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/PaymentModel.php';

$model = new PaymentModel();

$checkoutRequestId = 'TEST-CHECKOUT-' . time();
$merchantRequestId = 'TEST-MERCHANT-' . time();

$callbackPayload = [
    'Body' => [
        'stkCallback' => [
            'MerchantRequestID' => $merchantRequestId,
            'CheckoutRequestID' => $checkoutRequestId,
            'ResultCode' => 0,
            'ResultDesc' => 'The service request is successful.',
            'CallbackMetadata' => [
                'Item' => [
                    ['Name' => 'Amount', 'Value' => 5000],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'TEST12345'],
                ],
            ],
        ],
    ],
];

try {
    $stored = $model->storeMpesaCallback(
        $checkoutRequestId,
        $merchantRequestId,
        $callbackPayload
    );

    echo "1. Callback stored successfully.\n";

    $retrieved = $model->getMpesaCallback($checkoutRequestId);

    if ($retrieved === null) {
        throw new RuntimeException('Callback could not be retrieved.');
    }

    echo "2. Callback retrieved successfully.\n";
    echo "Checkout ID: " . $retrieved['checkout_request_id'] . "\n";
    echo "Inbox ID: " . $retrieved['id'] . "\n";

    echo "\n3. Inbox record:\n";
    print_r($retrieved);
        // Test linking the callback to an existing payment.
    $testPaymentId = 9;

    $model->linkMpesaCallbackToPayment(
        $checkoutRequestId,
        $testPaymentId
    );

    $linked = $model->getMpesaCallback($checkoutRequestId);

    if ((int) $linked['payment_id'] !== $testPaymentId) {
        throw new RuntimeException('Callback was not linked correctly.');
    }

    echo "\n4. Callback linked successfully.\n";
    echo "Linked payment ID: " . $linked['payment_id'] . "\n";

} catch (Throwable $e) {
    echo "TEST FAILED: " . $e->getMessage() . "\n";
}
    // Test duplicate callback handling.
    $duplicatePayload = $callbackPayload;
    $duplicatePayload['Body']['stkCallback']['ResultDesc'] =
        'Duplicate callback test';

    $model->storeMpesaCallback(
        $checkoutRequestId,
        $merchantRequestId,
        $duplicatePayload
    );

    $duplicate = $model->getMpesaCallback($checkoutRequestId);

    if ($duplicate['callback_payload'] !== json_encode($callbackPayload)) {
        throw new RuntimeException('Original callback payload was overwritten.');
    }

    echo "\n5. Duplicate callback handled successfully.\n";
    echo "Original payload preserved.\n";