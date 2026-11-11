<?php

require_once __DIR__ . "/../config/env.php";
require_once __DIR__ . "/../app/helpers/response.php";
require_once __DIR__ . "/../app/security/JWT.php";
require_once __DIR__ . "/../app/security/Middleware.php";
require_once __DIR__ . "/../app/security/RateLimiter.php";
require_once __DIR__ . "/../app/security/Validator.php";


// Central exception handler: log details privately, never expose them to the client.
set_exception_handler(function (\Throwable $exception): void {
    error_log('Uncaught exception: ' . $exception->getMessage() . ' at ' . $exception->getFile() . ':' . $exception->getLine());
    sendError('An unexpected error occurred', 500);
});

// Production error display and debug settings
$appEnvironment = strtolower(getenv('APP_ENV') ?: 'development');
$appDebug = filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);
if ($appEnvironment === 'production' || !$appDebug) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}


$appEnvironment = strtolower(getenv('APP_ENV') ?: 'development');

// Configure allowed CORS origins
if ($appEnvironment === 'production') {
    $rawOrigins = explode(',', getenv('CORS_ALLOWED_ORIGINS') ?: '');
    $origins = [];
    foreach ($rawOrigins as $raw) {
        $origin = rtrim(trim($raw), '/');
        if ($origin === '') {
            continue;
        }
        if ($origin === '*') {
            $origins[] = '*';
            continue;
        }
        $parsedOrigin = parse_url($origin);
        if (
            isset($parsedOrigin['scheme'], $parsedOrigin['host'])
            && in_array(strtolower($parsedOrigin['scheme']), ['https', 'http'], true)
        ) {
            $origins[] = $origin;
        }
    }
    if ($origins) {
        Middleware::setAllowedOrigins($origins);
    }
}

// Apply CORS headers immediately so preflight OPTIONS and error responses carry CORS headers
Middleware::cors();
Middleware::securityHeaders();

// Secrets and production origins validation
$jwtSecret = getenv('JWT_SECRET');
if (
    !in_array($appEnvironment, ['development', 'production'], true)
    || !is_string($jwtSecret)
    || strlen($jwtSecret) < 32
) {
    sendError("Server security configuration is missing or invalid: JWT_SECRET must be at least 32 characters", 500);
}
JWT::init($jwtSecret);

require_once __DIR__ . "/../routes/PropertyRoutes.php";
require_once __DIR__ . "/../routes/EnquiryRoutes.php";
require_once __DIR__ . "/../routes/NotificationLogRoutes.php";
require_once __DIR__ . "/../routes/PaymentRoutes.php";
require_once __DIR__ . "/../routes/RentalRoutes.php";
require_once __DIR__ . "/../routes/BookingRoutes.php";
require_once __DIR__ . "/../routes/SaleRoutes.php";
require_once __DIR__ . "/../routes/authRoutes.php";
require_once __DIR__ . "/../routes/userRoutes.php";
require_once __DIR__ . "/../routes/FinanceRoutes.php";
require_once __DIR__ . "/../routes/AuditLogRoutes.php";

// Apply security headers


$requestMethod = $_SERVER["REQUEST_METHOD"];
$requestUri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) ?: "/";

$scriptPath = str_replace("\\", "/", $_SERVER["SCRIPT_NAME"]);
$basePath = rtrim(str_replace("\\", "/", dirname($scriptPath)), "/");

// Strip base path from request URI
if ($basePath !== "" && strpos($requestUri, $basePath) === 0) {
    $remaining = substr($requestUri, strlen($basePath));
    if ($remaining === "" || $remaining[0] === "/") {
        $endpoint = $remaining;
    } else {
        $endpoint = $requestUri;
    }
} else {
    $endpoint = $requestUri;
}

// Normalize endpoint: remove leading/trailing slashes, strip index.php
$endpoint = ltrim($endpoint, "/");
$endpoint = preg_replace("#^index\.php/?#i", "", $endpoint);
$endpoint = rtrim($endpoint, "/");
if ($endpoint === "") {
    $endpoint = "/";
}

// Build route key with leading slash
$routeKey = $requestMethod . " /" . $endpoint;

$routes = array_merge(
    $propertyRoutes,
    $enquiryRoutes,
    $notificationLogRoutes,
    $paymentRoutes,
    $rentalRoutes,
    $bookingRoutes,
    $saleRoutes,
    $authRoutes,
    $userRoutes,
    $financeRoutes,
    $auditLogRoutes
);

// Health check (public, no rate limit)
if ($routeKey === "GET /api/v1/health") {
    sendSuccess("API is healthy", ['timestamp' => date('c')]);
}

// Try exact match first
$matched = false;
$controllerClass = null;
$method = null;
$routeParams = [];
$matchedRouteKey = null;

if (isset($routes[$routeKey])) {
    $matched = true;
    $matchedRouteKey = $routeKey;
    [$controllerClass, $method] = $routes[$routeKey];
} else {
    // Try pattern matching for routes with {id} parameters
    foreach ($routes as $pattern => $routeInfo) {
        $regexPattern = "#^" . str_replace("\\{id\\}", "([0-9]+)", preg_quote($pattern, "#")) . "$#";

        if (preg_match($regexPattern, $routeKey, $matches)) {
            array_shift($matches);
            $routeParams = $matches;
            $matchedRouteKey = $pattern;
            [$controllerClass, $method] = $routeInfo;
            $matched = true;
            break;
        }
    }
}

if ($matched) {
    // Check authentication for non-public routes
    if (!Middleware::isPublicRoute($requestMethod, $endpoint)) {
        $user = Middleware::authenticate();
        if (!$user) {
            sendError("Authentication required", 401);
        }

        // Role requirements are attached to explicit route patterns, not URL substrings.
        $requiredRoles = Middleware::requiredRolesForRoute($matchedRouteKey);
        if ($requiredRoles !== null && !in_array($user['role'], $requiredRoles, true)) {
            sendError("Insufficient permissions", 403);
        }

        // Check rate limit for authenticated endpoints
        Middleware::checkRateLimit(
            $routeKey,
            100,
            60
        );

       Middleware::auditLog((int) $user['sub'], "api_request", null, null, [
         'method' => $requestMethod,
        'endpoint' => $endpoint,
        ]);
    } else {
        // Rate limit for public endpoints (stricter)
        Middleware::checkRateLimit(
            $matchedRouteKey ?? $routeKey,
            30,
            60
        );
    }

    $controller = new $controllerClass();
    try {
        if (!empty($routeParams)) {
            $controller->$method(...$routeParams);
        } else {
            $controller->$method();
        }
    } catch (\Throwable $exception) {
        error_log('Uncaught exception in ' . ($matchedRouteKey ?? $routeKey) . ': ' . $exception->getMessage() . ' at ' . $exception->getFile() . ':' . $exception->getLine());
        sendError('An unexpected error occurred', 500);
    }
} else {
    sendError("Route not found", 404, ['requested' => $routeKey]);
}