<?php

require_once __DIR__ . "/../app/controllers/PropertyController.php";
require_once __DIR__ . "/../app/controllers/PropertyVerificationController.php";

$propertyRoutes = [
    "GET /api/v1/properties" => [
        PropertyController::class,
        "getProperties"
    ],

    "GET /api/v1/admin/properties" => [
        PropertyController::class,
        "getAdminProperties"
    ],

    "GET /api/v1/properties/{id}" => [
        PropertyController::class,
        "getPropertyById"
    ],

    "POST /api/v1/properties" => [
        PropertyController::class,
        "createProperty"
    ],

    "PUT /api/v1/properties/{id}" => [
        PropertyController::class,
        "updateProperty"
    ],

    "PATCH /api/v1/properties/{id}" => [
        PropertyController::class,
        "updateProperty"
    ],

    "DELETE /api/v1/properties/{id}" => [
        PropertyController::class,
        "archiveProperty"
    ],

    "GET /api/v1/properties/my" => [
        PropertyController::class,
        "getMyProperties"
    ],

    "POST /api/v1/properties/{id}/images" => [
        PropertyController::class,
        "addPropertyImage"
    ],

    "PATCH /api/v1/properties/{id}/approve" => [
        PropertyController::class,
        "approveProperty"
    ],

    "PATCH /api/v1/properties/{id}/reject" => [
        PropertyController::class,
        "rejectProperty"
    ],

    "POST /api/v1/properties/{id}/verification" => [
        PropertyVerificationController::class,
        "requestVerification"
    ],

    "GET /api/v1/properties/{id}/verification" => [
        PropertyVerificationController::class,
        "listPropertyVerifications"
    ],

    "GET /api/v1/admin/property-verifications" => [
        PropertyVerificationController::class,
        "listAdminVerifications"
    ],

    "POST /api/v1/property-verifications/{id}/document" => [
        PropertyVerificationController::class,
        "uploadDocument"
    ],

    "GET /api/v1/property-verifications/{id}/document" => [
        PropertyVerificationController::class,
        "downloadDocument"
    ],

    "PATCH /api/v1/admin/property-verifications/{id}/review" => [
        PropertyVerificationController::class,
        "reviewVerification"
    ],

    "POST /api/v1/admin/property-verifications/{id}/visits" => [
        PropertyVerificationController::class,
        "scheduleVisit"
    ],

    "GET /api/v1/property-verifications/my-visits" => [
        PropertyVerificationController::class,
        "listMyVisits"
    ],

    "PATCH /api/v1/property-verification-visits/{id}" => [
        PropertyVerificationController::class,
        "recordVisitOutcome"
    ],
];