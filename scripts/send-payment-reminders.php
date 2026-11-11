<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/services/PaymentReminderScheduler.php';

$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'real-estate-payment-reminders.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "Unable to open reminder scheduler lock.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "A payment reminder run is already active.\n");
    fclose($lock);
    exit(0);
}

try {
    $result = (new PaymentReminderScheduler())->run();
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(($result['failed'] ?? 0) > 0 ? 1 : 0);
} catch (Throwable $exception) {
    error_log('Payment reminder scheduler failed: ' . $exception->getMessage());
    fwrite(STDERR, "Payment reminder scheduler failed. See PHP error log for details.\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
