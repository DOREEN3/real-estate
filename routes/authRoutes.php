<?php

require_once __DIR__ . "/../app/controllers/AuthController.php";

$authRoutes = [
    "GET /api/v1/auth/setup-status" => [
        AuthController::class,
        "setupStatus"
    ],

    "POST /api/v1/auth/setup-admin" => [
        AuthController::class,
        "setupInitialAdmin"
    ],

    "POST /api/v1/auth/register" => [
        AuthController::class,
        "register"
    ],

    "POST /api/v1/auth/login" => [
        AuthController::class,
        "login"
    ],

    "POST /api/v1/auth/refresh" => [
        AuthController::class,
        "refresh"
    ],

    "POST /api/v1/auth/logout" => [
        AuthController::class,
        "logout"
    ],

    "POST /api/v1/auth/forgot-password" => [
        AuthController::class,
        "forgotPassword"
    ],

    "POST /api/v1/auth/reset-password" => [
        AuthController::class,
        "resetPassword"
    ],

    "GET /api/v1/auth/me" => [
        AuthController::class,
        "me"
    ],

    // Account deletion request with password confirmation (authenticated user)
    "POST /api/v1/auth/account/delete-request" => [
        AuthController::class,
        "requestAccountDeletion"
    ],

    // Current user's deletion request status (authenticated user)
    "GET /api/v1/auth/account/deletion-status" => [
        AuthController::class,
        "getMyDeletionRequest"
    ],
];
