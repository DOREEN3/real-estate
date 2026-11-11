<?php
/**
 * Secure JSON response helper
 * Never exposes internal error details to the client
 */

function sendResponse(array $data, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header("Content-Type: application/json");
    header("X-Content-Type-Options: nosniff");
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send error response without exposing internals
 */
function sendError(string $message, int $statusCode = 400, array $extra = []): never
{
    sendResponse(array_merge([
        "success" => false,
        "message" => $message,
    ], $extra), $statusCode);
}

/**
 * Send success response
 */
function sendSuccess(string $message, array $data = [], int $statusCode = 200): never
{
    sendResponse(array_merge([
        "success" => true,
        "message" => $message,
    ], $data), $statusCode);
}

/**
 * Get JSON request body safely
 */
function getJsonBody(): array
{
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Generate CSRF token
 */
function generateCsrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCsrfToken(string $token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $stored = $_SESSION['csrf_token'] ?? '';
    return !empty($token) && !empty($stored) && hash_equals($stored, $token);
}