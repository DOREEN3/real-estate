<?php
require_once __DIR__ . '/env.php';
date_default_timezone_set('Africa/Nairobi');

$appEnvironment = strtolower(getenv('APP_ENV') ?: 'development');
$host = getenv('DB_HOST') ?: '';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: '';
$username = getenv('DB_USER') ?: '';
$password = getenv('DB_PASSWORD') !== false
    ? getenv('DB_PASSWORD')
    : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

if ($appEnvironment === 'production') {
    $missing = [];
    if (empty($host)) $missing[] = 'DB_HOST';
    if (empty($dbname)) $missing[] = 'DB_NAME';
    if (empty($username)) $missing[] = 'DB_USER';
    if ($password === '') $missing[] = 'DB_PASSWORD (or DB_PASS)';

    if (!empty($missing)) {
        http_response_code(500);
        header("Content-Type: application/json");
        echo json_encode([
            "success" => false,
            "message" => "Database configuration is incomplete: missing " . implode(', ', $missing) . " in environment variables."
        ]);
        exit;
    }
} else {
    if (empty($host)) $host = 'localhost';
    if (empty($dbname)) $dbname = 'real_estate';
    if (empty($username)) $username = 'root';
}

$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

// Enable SSL when connecting to TiDB Cloud or when DB_SSL is requested
$isTiDB = str_contains($host, 'tidbcloud.com') || (string)$port === '4000';
$useSsl = getenv('DB_SSL') === 'true' || $isTiDB;

if ($useSsl) {
    $caBundle = getenv('DB_SSL_CA') ?: null;
    if ($caBundle && file_exists($caBundle)) {
        $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = $caBundle;
    } elseif (file_exists('/etc/ssl/certs/ca-certificates.crt')) {
        // Standard Linux/Docker/Render container path
        $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
    } elseif (file_exists('/etc/pki/tls/certs/ca-bundle.crt')) {
        $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = '/etc/pki/tls/certs/ca-bundle.crt';
    } else {
        $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
}

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, $pdoOptions);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    http_response_code(500);
    header("Content-Type: application/json");
    $message = ($appEnvironment === 'development')
        ? "Database connection failed: " . $e->getMessage()
        : "Database connection failed";
    echo json_encode(["success" => false, "message" => $message]);
    exit;
}
$pdo->exec("SET time_zone = '+03:00'");