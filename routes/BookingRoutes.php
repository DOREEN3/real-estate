<?php

require_once __DIR__ . '/../app/controllers/BookingController.php';

$bookingRoutes = [
    'POST /api/v1/admin/bookings' => [
        BookingController::class,
        'createBooking',
    ],
    'GET /api/v1/admin/bookings' => [
        BookingController::class,
        'listBookings',
    ],
    'GET /api/v1/admin/bookings/{id}' => [
        BookingController::class,
        'getBooking',
    ],
    'PATCH /api/v1/admin/bookings/{id}/status' => [
        BookingController::class,
        'updateBookingStatus',
    ],
    'GET /api/v1/properties/{id}/booking-availability' => [
        BookingController::class,
        'checkAvailability',
    ],
];
