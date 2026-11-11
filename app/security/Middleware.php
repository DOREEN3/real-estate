<?php
/**
 * Security Middleware
 * Handles CORS, security headers, authentication, rate limiting, and request logging
 */

declare(strict_types=1);

class Middleware
{
    private static array $allowedOrigins = [
        'http://localhost:3000',
        'http://localhost:5173',
    ];
private static array $routeRoleRequirements = [

    // Property administration
    'GET /api/v1/admin/properties' => ['admin'],
    'PATCH /api/v1/properties/{id}/approve' => ['admin'],
    'PATCH /api/v1/properties/{id}/reject' => ['admin'],
    'GET /api/v1/admin/property-verifications' => ['admin'],
    'PATCH /api/v1/admin/property-verifications/{id}/review' => ['admin'],
    'POST /api/v1/admin/property-verifications/{id}/visits' => ['admin'],

    // Buyer enquiries have no assigned-agent column in the current schema.
    'GET /api/v1/admin/enquiries' => ['admin'],
    'GET /api/v1/enquiries/{id}' => ['admin'],
    'PATCH /api/v1/enquiries/{id}/status' => ['admin'],
    'GET /api/v1/enquiries/{id}/follow-ups' => ['admin'],
    'POST /api/v1/enquiries/{id}/follow-ups' => ['admin'],
    'GET /api/v1/enquiries/{id}/matches' => ['admin'],
    'POST /api/v1/enquiries/{id}/matches/refresh' => ['admin'],
    'PATCH /api/v1/property-matches/{id}/status' => ['admin'],
    'GET /api/v1/admin/inquiries' => ['admin'],
    'GET /api/v1/properties/{id}/inquiries' => ['admin', 'owner'],
    'PATCH /api/v1/inquiries/{id}/status' => ['admin', 'owner'],

    // Notification records contain recipient contact details and are admin-only.
    'POST /api/v1/admin/notification-logs' => ['admin'],
    'GET /api/v1/admin/notification-logs' => ['admin'],
    'GET /api/v1/admin/notification-logs/{id}' => ['admin'],
    'PATCH /api/v1/admin/notification-logs/{id}/status' => ['admin'],

    // Payment records and receipts expose financial information and are admin-only.
    'POST /api/v1/admin/sales/{id}/payments' => ['admin'],
    'GET /api/v1/admin/sales/{id}/payments' => ['admin'],
    'POST /api/v1/admin/sales' => ['admin'],
    'GET /api/v1/admin/sales' => ['admin'],
    'GET /api/v1/admin/sales/{id}' => ['admin'],
    'GET /api/v1/admin/sales/{id}/installments' => ['admin'],
    'POST /api/v1/admin/sales/{id}/cancel' => ['admin'],
    'DELETE /api/v1/admin/sales/{id}' => ['admin'],
    'POST /api/v1/admin/installments/{id}/cancel' => ['admin'],
    'PATCH /api/v1/admin/installments/{id}' => ['admin'],
    'GET /api/v1/admin/payments' => ['admin'],
    'GET /api/v1/admin/payments/{id}' => ['admin'],
    'PATCH /api/v1/admin/payments/{id}/status' => ['admin'],
    'POST /api/v1/admin/payments/{id}/reverse' => ['admin'],
    'POST /api/v1/admin/sales/{id}/payments/mpesa/stk-push' => ['admin'],
    'GET /api/v1/admin/reports/payments' => ['admin'],
    'GET /api/v1/admin/reports/installments' => ['admin'],
    'GET /api/v1/admin/reports/profit-loss' => ['admin'],
    'GET /api/v1/admin/expenses' => ['admin'],
    'POST /api/v1/admin/expenses' => ['admin'],
    'GET /api/v1/admin/company-profile' => ['admin'],
    'PUT /api/v1/admin/company-profile' => ['admin'],
    'POST /api/v1/admin/company-profile/logo' => ['admin'],
    'GET /api/v1/admin/sales/{id}/statement' => ['admin'],
    'POST /api/v1/admin/sales/{id}/reminders' => ['admin'],

    // Rentals administration
    'POST /api/v1/admin/tenants' => ['admin'],
    'GET /api/v1/admin/tenants' => ['admin'],
    'GET /api/v1/admin/tenants/{id}' => ['admin'],
    'PATCH /api/v1/admin/tenants/{id}/archive' => ['admin'],
    'POST /api/v1/admin/tenancies' => ['admin'],
    'GET /api/v1/admin/tenancies' => ['admin'],
    'GET /api/v1/admin/tenancies/{id}' => ['admin'],
    'PATCH /api/v1/admin/tenancies/{id}/status' => ['admin'],
    'POST /api/v1/admin/tenancies/{id}/terminate' => ['admin'],
    'DELETE /api/v1/admin/tenancies/{id}' => ['admin'],
    'GET /api/v1/admin/tenancies/{id}/rent-charges' => ['admin'],
    'POST /api/v1/admin/tenancies/{id}/rent-charges' => ['admin'],
    'GET /api/v1/admin/rent-charges' => ['admin'],
    'GET /api/v1/admin/rent-charges/{id}' => ['admin'],
    'POST /api/v1/admin/rent-charges/{id}/void' => ['admin'],
    'PATCH /api/v1/admin/rent-charges/{id}' => ['admin'],
    'POST /api/v1/admin/tenancies/{id}/payments' => ['admin'],
    'GET /api/v1/admin/tenancies/{id}/payments' => ['admin'],

    // Audit logs
    'GET /api/v1/admin/audit-logs' => ['admin'],

    // BnB Bookings administration
    'POST /api/v1/admin/bookings' => ['admin'],
    'GET /api/v1/admin/bookings' => ['admin'],
    'GET /api/v1/admin/bookings/{id}' => ['admin'],
    'PATCH /api/v1/admin/bookings/{id}/status' => ['admin'],
    'POST /api/v1/admin/bookings/{id}/payments' => ['admin'],
    'GET /api/v1/admin/bookings/{id}/payments' => ['admin'],

    // User administration
    'POST /api/v1/admin/users/admins' => ['admin'],
    'POST /api/v1/admin/users/invite' => ['admin'],
    'GET /api/v1/admin/users/pending' => ['admin'],
    'GET /api/v1/admin/users' => ['admin'],
    'PATCH /api/v1/admin/users/{id}/approve' => ['admin'],
    'PATCH /api/v1/admin/users/{id}/reject' => ['admin'],
    'PATCH /api/v1/admin/users/{id}/suspend' => ['admin'],
    'PATCH /api/v1/admin/users/{id}/reactivate' => ['admin'],
    'PATCH /api/v1/admin/users/{id}/deactivate' => ['admin'],
    'POST /api/v1/admin/users/{id}/delete' => ['admin'],
    'DELETE /api/v1/admin/users/{id}' => ['admin'],
    'POST /api/v1/admin/users/{id}/transfer-properties' => ['admin'],

    // Account deletion requests (administrator review)
    'GET /api/v1/admin/deletion-requests' => ['admin'],
    'PATCH /api/v1/admin/deletion-requests/{id}/approve' => ['admin'],
    'PATCH /api/v1/admin/deletion-requests/{id}/reject' => ['admin'],
];

   private static array $publicRoutes = [
    // Public authentication routes
    'GET /api/v1/auth/setup-status',
    'POST /api/v1/auth/setup-admin',
    'POST /api/v1/auth/register',
    'POST /api/v1/auth/login',
    'POST /api/v1/auth/refresh',
    'POST /api/v1/auth/forgot-password',
    'POST /api/v1/auth/reset-password',

    // Daraja callbacks are public HTTP requests; the controller verifies each transaction with Daraja.
    'POST /api/v1/payments/providers/mpesa/callback',
    'POST /api/v1/payments/providers/bank/callback',

    // Public property routes
    'GET /api/v1/properties',
    'GET /api/v1/properties/{id}',
    'POST /api/v1/enquiries',
    'POST /api/v1/properties/{id}/inquiries',
    'GET /api/v1/properties/{id}/booking-availability',
    'GET /api/v1/health',
];
    /**
     * Set allowed CORS origins
     */
    public static function setAllowedOrigins(array $origins): void
    {
        self::$allowedOrigins = $origins;
    }

    public static function requiredRolesForRoute(string $routeKey): ?array
    {
        return self::$routeRoleRequirements[$routeKey] ?? null;
    }

    /**
     * Apply CORS headers
     */
    public static function cors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array($origin, self::$allowedOrigins, true) || in_array('*', self::$allowedOrigins, true)) {
            $allowOrigin = in_array('*', self::$allowedOrigins, true) ? '*' : $origin;
            header("Access-Control-Allow-Origin: $allowOrigin");
        }

        header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Max-Age: 86400");

        // Handle preflight OPTIONS request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /**
     * Apply security headers
     */
    public static function securityHeaders(): void
    {
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: DENY");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        header("Cross-Origin-Opener-Policy: same-origin");
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");

        // HSTS - only if served over HTTPS
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
        }
        // Remove server signature
        header("Server: ", true);
    }

    /**
     * Check if route is public (no auth required)
     */
    public static function isPublicRoute(string $method, string $endpoint): bool
    {
        // Normalize endpoint to always have leading slash
        $normalizedEndpoint = ltrim($endpoint, "/");
        $normalizedEndpoint = "/" . $normalizedEndpoint;

        $routeKey = "$method $normalizedEndpoint";
        if (in_array($routeKey, self::$publicRoutes, true)) {
            return true;
        }

        // Match property/{id} pattern
        if ($method === 'GET' && preg_match('#^/api/v1/properties/\d+$#', $normalizedEndpoint)) {
            return true;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/properties/\d+/inquiries$#', $normalizedEndpoint)) {
            return true;
        }

        return false;
    }

    /**
     * Authenticate request using JWT
     * Returns user payload array or null
     */
    public static function authenticate(): ?array
    {
        $token = JWT::getBearerToken();

        if (!$token) {
            return null;
        }

        $payload = JWT::decode($token);
        if (!$payload) {
            return null;
        }

        // Check if token is a refresh token (should not be used for access)
        if (isset($payload['type']) && $payload['type'] === 'refresh') {
            return null;
        }

        $userId = filter_var($payload['sub'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId <= 0) {
            return null;
        }

        require_once __DIR__ . '/../models/UserModel.php';
        $user = (new UserModel())->getUserById($userId);
        if (!$user || $user['account_status'] !== 'approved') {
            return null;
        }

        $payload['sub'] = (string) $user['id'];
        $payload['email'] = $user['email'];
        $payload['role'] = $user['role'];

        return $payload;
    }

    /**
     * Require authentication - sends 401 if not authenticated
     */
    public static function requireAuth(): ?array
    {
        $user = self::authenticate();
        if (!$user) {
            sendResponse([
                "success" => false,
                "message" => "Authentication required"
            ], 401);
        }
        return $user;
    }

    /**
     * Require specific roles - sends 403 if user doesn't have required role
     * Returns the authenticated user payload
     */
    public static function requireRole(array $allowedRoles): ?array
    {
        $user = self::requireAuth();
        if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
            sendResponse([
                "success" => false,
                "message" => "Insufficient permissions"
            ], 403);
        }
        return $user;
    }

    /**
     * Check rate limit for the current request
     * Sends 429 if rate limited
     */
    public static function checkRateLimit(string $endpoint, int $maxRequests = 60, int $windowSeconds = 60): void
    {
        $ip = RateLimiter::getClientIp();
        $limiter = new RateLimiter($ip, $endpoint, $maxRequests, $windowSeconds);

        if (!$limiter->check()) {
            sendResponse([
                "success" => false,
                "message" => "Too many requests. Please try again later.",
                "retry_after" => $windowSeconds
            ], 429);
        }
    }

    /**
     * Log audit trail
     */
    public static function auditLog(?int $userId, string $action, ?string $resourceType = null, ?int $resourceId = null, array $details = []): void
    {
        global $pdo;

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO audit_logs (user_id, action, resource_type, resource_id, ip_address, user_agent, details) 
                 VALUES (:user_id, :action, :resource_type, :resource_id, :ip_address, :user_agent, :details)"
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':action' => $action,
                ':resource_type' => $resourceType,
                ':resource_id' => $resourceId,
                ':ip_address' => RateLimiter::getClientIp(),
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':details' => json_encode($details),
            ]);
        } catch (Exception $e) {
            // Silent fail - auditing should not break the request
        }
    }
}