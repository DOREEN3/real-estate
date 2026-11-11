<?php

require_once __DIR__ . '/../app/controllers/EnquiryController.php';

$enquiryRoutes = [
    'POST /api/v1/enquiries' => [
        EnquiryController::class,
        'createEnquiry',
    ],
    'GET /api/v1/admin/enquiries' => [
        EnquiryController::class,
        'listEnquiries',
    ],
    'GET /api/v1/enquiries/{id}' => [
        EnquiryController::class,
        'getEnquiry',
    ],
    'PATCH /api/v1/enquiries/{id}/status' => [
        EnquiryController::class,
        'updateEnquiryStatus',
    ],
    'GET /api/v1/enquiries/{id}/follow-ups' => [
        EnquiryController::class,
        'listFollowUps',
    ],
    'POST /api/v1/enquiries/{id}/follow-ups' => [
        EnquiryController::class,
        'createFollowUp',
    ],
    'GET /api/v1/enquiries/{id}/matches' => [
        EnquiryController::class,
        'listMatches',
    ],
    'POST /api/v1/enquiries/{id}/matches/refresh' => [
        EnquiryController::class,
        'refreshMatches',
    ],
    'PATCH /api/v1/property-matches/{id}/status' => [
        EnquiryController::class,
        'updateMatchStatus',
    ],
    'POST /api/v1/properties/{id}/inquiries' => [
        EnquiryController::class,
        'createPropertyInquiry',
    ],
    'GET /api/v1/admin/inquiries' => [
        EnquiryController::class,
        'listAdminInquiries',
    ],
    'GET /api/v1/properties/{id}/inquiries' => [
        EnquiryController::class,
        'listPropertyInquiries',
    ],
    'PATCH /api/v1/inquiries/{id}/status' => [
        EnquiryController::class,
        'updatePropertyInquiryStatus',
    ],
];
