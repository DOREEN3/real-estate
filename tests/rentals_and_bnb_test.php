<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/models/RentalModel.php';
require_once __DIR__ . '/../app/models/BookingModel.php';
require_once __DIR__ . '/../app/models/PaymentModel.php';
require_once __DIR__ . '/../app/services/BankPaymentService.php';
require_once __DIR__ . '/../app/helpers/PropertyAvailabilityHelper.php';

$rentalModel = new RentalModel();
$bookingModel = new BookingModel();
$paymentModel = new PaymentModel();
$bankService = new BankPaymentService();

echo "Running Rentals & Short-Term BnB Test Suite...\n\n";

// Helper to create test property
function createTestProperty(string $title, string $transactionType, float $price = 5000.00): int {
    global $pdo;
    $ownerId = (int) $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
    $stmt = $pdo->prepare(
        "INSERT INTO properties 
            (owner_id, title, description, price, property_type, transaction_type, property_status, availability_status, currency)
         VALUES 
            (:owner_id, :title, 'Test property description', :price, 'apartment', :type, 'approved', 'available', 'KES')"
    );
    $stmt->execute([
        ':owner_id' => $ownerId,
        ':title' => $title,
        ':price' => $price,
        ':type' => $transactionType,
    ]);
    return (int) $pdo->lastInsertId();
}

// Helper to create test tenant
function createTestTenant(string $name, string $email): int {
    global $pdo;
    $stmt = $pdo->prepare(
        "INSERT INTO tenants (full_name, email, phone) VALUES (:name, :email, '0711000000')"
    );
    $stmt->execute([':name' => $name, ':email' => $email]);
    return (int) $pdo->lastInsertId();
}

$testUserId = (int) $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();

// --- 1. Bank Payment Reference Parsing for Rentals & BnB ---
echo "1. Testing Bank Payment Reference Resolution:\n";
assert($bankService->resolveTenancyId('TEN-101') === 101, 'Resolves TEN-101');
assert($bankService->resolveTenancyId('RENT-55') === 55, 'Resolves RENT-55');
assert($bankService->resolveBookingId('BNB-202') === 202, 'Resolves BNB-202');
assert($bankService->resolveBookingId('BOOK-303') === 303, 'Resolves BOOK-303');
echo "  ✓ Bank reference resolution for Tenancy and Booking passed.\n\n";

// --- 2. Long-Term Rental: Security Deposit & Status Synchronization ---
echo "2. Testing Long-Term Rental & Security Deposit:\n";
$propRentId = createTestProperty('Luxury 2BR Apartment in Kilimani', 'rent', 45000.00);
$tenantId = createTestTenant('John Doe', 'johndoe_' . bin2hex(random_bytes(4)) . '@example.com');

$tenancyId = $rentalModel->createTenancy($testUserId, [
    'tenant_id' => $tenantId,
    'property_id' => $propRentId,
    'monthly_rent' => '45000.00',
    'security_deposit' => '90000.00',
    'rent_due_day' => 5,
    'start_date' => date('Y-m-d'),
    'end_date' => null,
]);
assert($tenancyId > 0, 'Tenancy created successfully');

$tenancy = $rentalModel->getTenancy($tenancyId);
assert((float) $tenancy['security_deposit'] === 90000.00, 'Security deposit recorded correctly');
echo "  ✓ Security deposit recorded in tenancy: KES " . number_format((float) $tenancy['security_deposit'], 2) . "\n";

// Verify property status was synchronized to 'rented'
$propStatus = $pdo->query("SELECT availability_status FROM properties WHERE id = {$propRentId}")->fetchColumn();
assert($propStatus === 'rented', "Property status should be 'rented', got {$propStatus}");
echo "  ✓ Property availability status automatically synchronized to 'rented'\n";

// End tenancy and verify status reverts to 'available'
$rentalModel->endTenancy($tenancyId, date('Y-m-d'));
$propStatusAfter = $pdo->query("SELECT availability_status FROM properties WHERE id = {$propRentId}")->fetchColumn();
assert($propStatusAfter === 'available', "Property status should be 'available' after ending tenancy, got {$propStatusAfter}");
echo "  ✓ Property availability status automatically synchronized back to 'available' on lease end\n\n";

// --- 3. Short-Term BnB: Booking Dates, Overlaps & Deposits ---
echo "3. Testing Short-Term BnB Bookings & Availability:\n";
$propBnbId = createTestProperty('Beachfront Villa Diani', 'bnb', 8000.00);

$checkIn1 = date('Y-m-d', strtotime('+5 days'));
$checkOut1 = date('Y-m-d', strtotime('+8 days')); // 3 nights

$booking1Id = $bookingModel->createBooking($testUserId, [
    'property_id' => $propBnbId,
    'guest_name' => 'Alice Wonder',
    'guest_email' => 'alice@example.com',
    'guest_phone' => '+254712345678',
    'check_in_date' => $checkIn1,
    'check_out_date' => $checkOut1,
    'nightly_rate' => '8000.00',
    'required_booking_deposit' => '5000.00',
    'required_damage_deposit' => '10000.00',
]);
assert($booking1Id > 0, 'BnB Booking 1 created');

$b1 = $bookingModel->getBooking($booking1Id);
assert((int) $b1['number_of_nights'] === 3, 'Calculated exactly 3 nights');
assert((float) $b1['accommodation_total'] === 24000.00, 'Accommodation total is 3 * 8000 = 24,000 (deposits not double-counted)');
assert((float) $b1['required_damage_deposit'] === 10000.00, 'Damage deposit held separately');
echo "  ✓ BnB Booking calculated correctly: 3 nights × KES 8,000 = KES 24,000 (damage deposit KES 10,000 kept distinct)\n";

// Check property summary status is 'reserved'
PropertyAvailabilityHelper::syncStatus($propBnbId);
$propBnbStatus = $pdo->query("SELECT availability_status FROM properties WHERE id = {$propBnbId}")->fetchColumn();
assert($propBnbStatus === 'reserved', "Property status should be 'reserved', got {$propBnbStatus}");
echo "  ✓ Confirmed upcoming booking synchronizes property status to 'reserved'\n";

// Test collision detection: attempt overlapping booking (e.g. +6 days to +9 days)
$checkInOverlap = date('Y-m-d', strtotime('+6 days'));
$checkOutOverlap = date('Y-m-d', strtotime('+9 days'));
$collisionCaught = false;
try {
    $bookingModel->createBooking($testUserId, [
        'property_id' => $propBnbId,
        'guest_name' => 'Bob Collision',
        'guest_email' => 'bob@example.com',
        'guest_phone' => '+254722000000',
        'check_in_date' => $checkInOverlap,
        'check_out_date' => $checkOutOverlap,
    ]);
} catch (DomainException $e) {
    if ($e->getMessage() === 'booking_date_collision') {
        $collisionCaught = true;
    }
}
assert($collisionCaught, 'Date collision detected and prevented');
echo "  ✓ Overlapping booking collision correctly blocked with DomainException\n";

// Test non-overlapping booking: +10 days to +12 days succeeds
$checkIn2 = date('Y-m-d', strtotime('+10 days'));
$checkOut2 = date('Y-m-d', strtotime('+12 days'));
$booking2Id = $bookingModel->createBooking($testUserId, [
    'property_id' => $propBnbId,
    'guest_name' => 'Charlie NonOverlap',
    'guest_email' => 'charlie@example.com',
    'guest_phone' => '+254733000000',
    'check_in_date' => $checkIn2,
    'check_out_date' => $checkOut2,
]);
assert($booking2Id > 0, 'Non-overlapping booking succeeded');
echo "  ✓ Subsequent non-overlapping booking succeeded seamlessly\n\n";

// --- 4. Booking Lifecycle & Occupancy Synchronization ---
echo "4. Testing Booking Lifecycle Transitions:\n";
// Guest checks in -> status becomes 'occupied'
$bookingModel->updateBookingStatus($booking1Id, 'checked_in');
$b1CheckedIn = $bookingModel->getBooking($booking1Id);
assert($b1CheckedIn['booking_status'] === 'checked_in', 'Booking status checked_in');
$statusOcc = $pdo->query("SELECT availability_status FROM properties WHERE id = {$propBnbId}")->fetchColumn();
assert($statusOcc === 'occupied', "Property status should be 'occupied' when guest checks in, got {$statusOcc}");
echo "  ✓ Guest check-in transitions property availability to 'occupied'\n";

// Guest checks out -> because Booking 2 is upcoming (+10 days), property status becomes 'reserved', NOT 'available'!
$bookingModel->updateBookingStatus($booking1Id, 'checked_out');
$b1CheckedOut = $bookingModel->getBooking($booking1Id);
assert($b1CheckedOut['booking_status'] === 'checked_out', 'Booking status checked_out');
$statusAfterCheckout = $pdo->query("SELECT availability_status FROM properties WHERE id = {$propBnbId}")->fetchColumn();
assert($statusAfterCheckout === 'reserved', "Property status should be 'reserved' because Booking 2 is upcoming, got {$statusAfterCheckout}");
echo "  ✓ Guest check-out recognizes upcoming reservation and maintains 'reserved' status\n\n";

// --- 5. Unified Payment Architecture & Mutex Rule ---
echo "5. Testing Unified Payment Architecture & Mutex:\n";

// Test Mutex Rule: cannot assign multiple targets or zero targets
$mutexCaught = false;
try {
    $paymentModel->validatePaymentMutex(1, 2, null);
} catch (DomainException $e) {
    if ($e->getMessage() === 'payment_target_mutex_violation') {
        $mutexCaught = true;
    }
}
assert($mutexCaught, 'Mutex violation detected when both sale_id and tenancy_id are set');

$zeroTargetCaught = false;
try {
    $paymentModel->validatePaymentMutex(null, null, null);
} catch (DomainException $e) {
    if ($e->getMessage() === 'payment_target_mutex_violation') {
        $zeroTargetCaught = true;
    }
}
assert($zeroTargetCaught, 'Mutex violation detected when all targets are null');
echo "  ✓ Payment target mutex rule strictly enforced (exclusive transaction ownership)\n";

// Test Tenancy Payment in unified payments
$tenancyPayId = $paymentModel->recordTenancyPayment($tenancyId, $testUserId, [
    'amount' => '45000.00',
    'payment_type' => 'rent',
    'payment_method' => 'bank_transfer',
    'transaction_reference' => 'TX-RENT-' . bin2hex(random_bytes(4)),
    'payment_date' => date('Y-m-d H:i:s'),
]);
assert($tenancyPayId > 0, 'Tenancy payment recorded');
$tPay = $paymentModel->getPayment($tenancyPayId);
assert($tPay['tenancy_id'] == $tenancyId, 'Payment links to tenancy');
assert($tPay['sale_id'] === null, 'Sale ID is null (exclusive)');
assert($tPay['booking_id'] === null, 'Booking ID is null (exclusive)');
assert(str_starts_with($tPay['receipt_number'], 'TEN-'), 'Tenancy receipt number prefixed with TEN-');
echo "  ✓ Tenancy payment recorded in unified payments with TEN receipt: {$tPay['receipt_number']}\n";

// Test BnB Accommodation Payment in unified payments
$bnbPayId = $paymentModel->recordBookingPayment($booking1Id, $testUserId, [
    'amount' => '10000.00',
    'payment_type' => 'accommodation',
    'payment_method' => 'mobile_money',
    'transaction_reference' => 'TX-BNB-' . bin2hex(random_bytes(4)),
    'payment_date' => date('Y-m-d H:i:s'),
]);
assert($bnbPayId > 0, 'BnB payment recorded');
$bPay = $paymentModel->getPayment($bnbPayId);
assert($bPay['booking_id'] == $booking1Id, 'Payment links to booking');
assert($bPay['sale_id'] === null, 'Sale ID is null (exclusive)');
assert($bPay['tenancy_id'] === null, 'Tenancy ID is null (exclusive)');
assert(str_starts_with($bPay['receipt_number'], 'BNB-'), 'BnB receipt number prefixed with BNB-');
echo "  ✓ BnB Accommodation payment recorded with BNB receipt: {$bPay['receipt_number']}\n";

// Verify booking balances
$b1Financials = $bookingModel->getBooking($booking1Id);
assert((float) $b1Financials['accommodation_paid'] === 10000.00, 'Accommodation paid is 10,000');
assert((float) $b1Financials['accommodation_balance'] === 14000.00, 'Accommodation balance is 14,000 (24,000 - 10,000)');
echo "  ✓ Booking accommodation balance dynamically updated: KES 14,000.00 remaining\n";

// Test BnB Damage Deposit Payment
$damagePayId = $paymentModel->recordBookingPayment($booking1Id, $testUserId, [
    'amount' => '10000.00',
    'payment_type' => 'damage_deposit',
    'payment_method' => 'cash',
    'transaction_reference' => 'TX-DMG-' . bin2hex(random_bytes(4)),
    'payment_date' => date('Y-m-d H:i:s'),
]);
$b1DamageFinancials = $bookingModel->getBooking($booking1Id);
assert((float) $b1DamageFinancials['damage_deposit_paid'] === 10000.00, 'Damage deposit paid 10,000');
assert((float) $b1DamageFinancials['damage_deposit_balance'] === 0.00, 'Damage deposit balance 0.00');
// Confirm accommodation total was NOT altered by damage deposit
assert((float) $b1DamageFinancials['accommodation_total'] === 24000.00, 'Accommodation total strictly preserved at 24,000');
echo "  ✓ Damage deposit tracked without inflating accommodation charges\n";

echo "\n🎉 ALL RENTALS & SHORT-TERM BNB TESTS PASSED PERFECTLY!\n";
