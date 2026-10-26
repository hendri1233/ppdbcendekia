<?php
require_once __DIR__ . '/vendor/autoload.php';
if (file_exists(__DIR__ . '/.env')) {
    Dotenv\Dotenv::createUnsafeImmutable(__DIR__)->safeLoad();
}

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db = getenv('DB_NAME') ?: 'ppdb_cendekia';

$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    error_log('PPDB database connection failed: '.mysqli_connect_error());
    http_response_code(500);
    exit('Layanan pendaftaran sedang tidak tersedia. Silakan hubungi panitia.');
}

mysqli_set_charset($conn, 'utf8mb4');
