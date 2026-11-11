<?php

declare(strict_types=1);

require_once __DIR__ . '/../models/BookingModel.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../security/Middleware.php';
require_once __DIR__ . '/../security/Validator.php';

class BookingController
{
    private BookingModel $bookingModel;

    public function __construct()
    {
        $this->bookingModel = new BookingModel();
    }

    public function createBooking(): void
    {
        $user = Middleware::requireRole(['admin']);
        $body = getJsonBody();
        $data = $this->validateBookingInput($body);

        try {
            $bookingId = $this->bookingModel->createBooking((int) $user['sub'], $data);
        } catch (DomainException $exception) {
            $errors = [
                'property_not_found' => ['Property not found', 404],
                'property_not_available_for_bnb' => ['Property is not approved for short-term BnB', 409],
                'property_is_unavailable' => ['Property is currently unavailable or sold', 409],
                'checkout_must_be_after_checkin' => ['Check-out date must be after check-in date', 400],
                'invalid_booking_duration' => ['Booking must be for at least 1 night', 400],
                'booking_date_collision' => ['Property is already reserved or occupied for the selected dates', 409],
            ];
            $msg = $exception->getMessage();
            if (isset($errors[$msg])) {
                sendError($errors[$msg][0], $errors[$msg][1]);
            }
            sendError($msg, 400);
        }

        $booking = $this->bookingModel->getBooking($bookingId);
        if ($booking === null) {
            sendError('Unable to retrieve created booking', 500);
        }

        sendSuccess('Booking confirmed successfully', ['booking' => $booking], 201);
    }

    public function listBookings(): void
    {
        Middleware::requireRole(['admin']);
        [$page, $limit] = $this->pagination();
        $filters = [
            'property_id' => $this->queryPositiveId('property_id'),
            'booking_status' => $this->queryEnum('booking_status', ['confirmed', 'checked_in', 'checked_out', 'cancelled']),
            'guest_email' => $this->queryString('guest_email', 100),
            'guest_name' => $this->queryString('guest_name', 100),
            'from_date' => $this->queryDate('from_date'),
            'to_date' => $this->queryDate('to_date'),
        ];
        $total = $this->bookingModel->countBookings($filters);

        sendSuccess('Bookings retrieved', [
            'data' => $this->bookingModel->listBookings($filters, $page, $limit),
            'pagination' => $this->paginationData($page, $limit, $total),
        ]);
    }

    public function getBooking(string $id): void
    {
        Middleware::requireRole(['admin']);
        $bookingId = $this->positiveId($id, 'booking ID');
        $booking = $this->bookingModel->getBooking($bookingId);
        if ($booking === null) {
            sendError('Booking not found', 404);
        }

        sendSuccess('Booking retrieved', ['booking' => $booking]);
    }

    public function updateBookingStatus(string $id): void
    {
        Middleware::requireRole(['admin']);
        $bookingId = $this->positiveId($id, 'booking ID');
        $body = getJsonBody();
        $this->rejectUnknownFields($body, ['booking_status']);

        $status = $body['booking_status'] ?? null;
        if (!in_array($status, ['confirmed', 'checked_in', 'checked_out', 'cancelled'], true)) {
            sendError('Invalid booking_status', 400, ['allowed' => ['confirmed', 'checked_in', 'checked_out', 'cancelled']]);
        }

        try {
            $updated = $this->bookingModel->updateBookingStatus($bookingId, $status);
        } catch (DomainException $exception) {
            sendError($exception->getMessage(), 400);
        }

        sendSuccess("Booking status updated to {$status}", ['booking' => $updated]);
    }

    public function checkAvailability(string $propertyId): void
    {
        $id = $this->positiveId($propertyId, 'property ID');
        $checkIn = $this->queryDate('check_in');
        $checkOut = $this->queryDate('check_out');

        if (!$checkIn || !$checkOut) {
            sendError('check_in and check_out query parameters are required (YYYY-MM-DD)', 400);
        }

        try {
            $result = $this->bookingModel->checkAvailability($id, $checkIn, $checkOut);
            sendSuccess('Availability check completed', $result);
        } catch (DomainException $exception) {
            sendError($exception->getMessage(), 400);
        }
    }

    private function validateBookingInput(array $body): array
    {
        $this->rejectUnknownFields($body, [
            'property_id',
            'guest_name',
            'guest_email',
            'guest_phone',
            'check_in_date',
            'check_out_date',
            'nightly_rate',
            'required_booking_deposit',
            'required_damage_deposit',
            'currency',
            'special_requests',
        ]);

        $email = $this->requiredString($body, 'guest_email', 150);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendError('guest_email must be a valid email address', 400);
        }

        $checkIn = $this->requiredDate($body, 'check_in_date');
        $checkOut = $this->requiredDate($body, 'check_out_date');

        return [
            'property_id' => $this->requiredPositiveId($body, 'property_id'),
            'guest_name' => $this->requiredString($body, 'guest_name', 150),
            'guest_email' => $email,
            'guest_phone' => $this->requiredString($body, 'guest_phone', 30),
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'nightly_rate' => $this->optionalAmount($body, 'nightly_rate', null),
            'required_booking_deposit' => $this->optionalAmount($body, 'required_booking_deposit', '0.00'),
            'required_damage_deposit' => $this->optionalAmount($body, 'required_damage_deposit', '0.00'),
            'currency' => $this->optionalString($body, 'currency', 3) ?? 'KES',
            'special_requests' => $this->optionalString($body, 'special_requests', 2000),
        ];
    }

    private function requiredString(array $body, string $field, int $maxLength): string
    {
        if (!isset($body[$field]) || !is_string($body[$field])) {
            sendError("{$field} is required", 400);
        }
        $value = trim(Validator::sanitizeInput($body[$field]));
        if ($value === '' || mb_strlen($value) > $maxLength) {
            sendError("Invalid {$field}", 400);
        }

        return $value;
    }

    private function optionalString(array $body, string $field, int $maxLength): ?string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return null;
        }
        if (!is_string($body[$field])) {
            sendError("{$field} must be a string", 400);
        }
        $value = trim(Validator::sanitizeInput($body[$field]));
        if ($value === '' || mb_strlen($value) > $maxLength) {
            sendError("Invalid {$field}", 400);
        }

        return $value;
    }

    private function requiredPositiveId(array $body, string $field): int
    {
        $value = filter_var($body[$field] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0) {
            sendError("{$field} must be a positive integer", 400);
        }

        return $value;
    }

    private function positiveId(string $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            sendError("Invalid {$label}", 400);
        }

        return $id;
    }

    private function requiredDate(array $body, string $field): string
    {
        if (!isset($body[$field]) || !is_string($body[$field])) {
            sendError("{$field} must be a valid date in YYYY-MM-DD format", 400);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $body[$field]);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || (int) $date->format('Y') < 1000
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $body[$field]
        ) {
            sendError("{$field} must be a valid date in YYYY-MM-DD format", 400);
        }

        return $body[$field];
    }

    private function optionalAmount(array $body, string $field, ?string $default): ?string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return $default;
        }
        $value = (string) $body[$field];
        if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $value)) {
            sendError("{$field} must fit DECIMAL(15,2)", 400, ['field' => $field]);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return $whole . '.' . str_pad($fraction, 2, '0');
    }

    private function queryPositiveId(string $key): ?int
    {
        if (!array_key_exists($key, $_GET)) {
            return null;
        }
        $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0) {
            sendError("{$key} must be a positive integer", 400);
        }

        return $value;
    }

    private function queryString(string $key, int $maxLength): ?string
    {
        if (!isset($_GET[$key]) || !is_string($_GET[$key])) {
            return null;
        }
        $val = trim(Validator::sanitizeInput($_GET[$key]));
        return mb_strlen($val) > $maxLength ? mb_substr($val, 0, $maxLength) : $val;
    }

    private function queryDate(string $key): ?string
    {
        if (!isset($_GET[$key]) || !is_string($_GET[$key])) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $_GET[$key]);
        return ($date !== false && $date->format('Y-m-d') === $_GET[$key]) ? $_GET[$key] : null;
    }

    private function queryEnum(string $key, array $allowed): ?string
    {
        if (!array_key_exists($key, $_GET)) {
            return null;
        }
        if (!is_string($_GET[$key]) || !in_array($_GET[$key], $allowed, true)) {
            sendError("Invalid {$key} filter", 400, ['allowed' => $allowed]);
        }

        return $_GET[$key];
    }

    private function rejectUnknownFields(array $body, array $allowed): void
    {
        $unknown = array_diff(array_keys($body), $allowed);
        if ($unknown) {
            sendError('Unsupported request fields', 400, ['fields' => array_values($unknown)]);
        }
    }

    private function pagination(): array
    {
        $page = $this->queryInteger('page', 1, 1000000);
        $limit = $this->queryInteger('limit', 1, 100, 20);

        return [$page, $limit];
    }

    private function queryInteger(string $key, int $min, int $max, int $default = 1): int
    {
        if (!isset($_GET[$key])) {
            return $default;
        }
        $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            sendError("{$key} must be between {$min} and {$max}", 400);
        }

        return $value;
    }

    private function paginationData(int $page, int $limit, int $total): array
    {
        return [
            'current_page' => $page,
            'per_page' => $limit,
            'total' => $total,
            'total_pages' => (int) ceil($total / $limit),
        ];
    }
}
