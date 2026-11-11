<?php

require_once __DIR__ . "/../app/controllers/UserController.php";

$userRoutes = [

    // Create an additional administrator account
    "POST /api/v1/admin/users/admins" =>
        [UserController::class, "createAdminUser"],

    // Send self-registration form link to agent or owner via email
    "POST /api/v1/admin/users/invite" =>
        [UserController::class, "inviteUser"],

    // View pending agent and owner accounts
    "GET /api/v1/admin/users/pending" =>
        [UserController::class, "getPendingUsers"],

    // View all agents and owners
    "GET /api/v1/admin/users" =>
        [UserController::class, "getAllUsers"],

    // Approve an account
    "PATCH /api/v1/admin/users/{id}/approve" =>
        [UserController::class, "approveUser"],

    // Reject an account
    "PATCH /api/v1/admin/users/{id}/reject" =>
        [UserController::class, "rejectUser"],

    // Suspend an account
    "PATCH /api/v1/admin/users/{id}/suspend" =>
        [UserController::class, "suspendUser"],

    // Reactivate an account
    "PATCH /api/v1/admin/users/{id}/reactivate" =>
        [UserController::class, "reactivateUser"],

    // Deactivate an account directly
    "PATCH /api/v1/admin/users/{id}/deactivate" =>
        [UserController::class, "deactivateUser"],

    // Safely delete an account (anonymize personal details, retain business history)
    "POST /api/v1/admin/users/{id}/delete" =>
        [UserController::class, "deleteUser"],
    "DELETE /api/v1/admin/users/{id}" =>
        [UserController::class, "deleteUser"],

    // Transfer property ownership and/or management to another user
    "POST /api/v1/admin/users/{id}/transfer-properties" =>
        [UserController::class, "transferProperties"],

    // View pending account deletion requests (Admin-only)
    "GET /api/v1/admin/deletion-requests" =>
        [UserController::class, "getPendingDeletionRequests"],

    // Approve a deletion request: deactivates account and anonymizes personal data (Admin-only)
    "PATCH /api/v1/admin/deletion-requests/{id}/approve" =>
        [UserController::class, "approveDeletionRequest"],

    // Reject a deletion request and restore the account (Admin-only)
    "PATCH /api/v1/admin/deletion-requests/{id}/reject" =>
        [UserController::class, "rejectDeletionRequest"],

];
