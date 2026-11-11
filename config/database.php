<?php
require_once __DIR__ . '/env.php';
date_default_timezone_set('Africa/Nairobi');

$appEnvironment = strtolower(getenv('APP_ENV') ?: 'development');
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'real_estate';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';

if (
    $appEnvironment === 'production'
    && (getenv('DB_HOST') === false || getenv('DB_NAME') === false
        || getenv('DB_USER') === false || getenv('DB_PASSWORD') === false
        || $host === '' || $dbname === '' || $username === '' || $password === '')
) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode(["success" => false, "message" => "Database configuration is incomplete"]);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}
$pdo->exec("SET time_zone = '+03:00'");