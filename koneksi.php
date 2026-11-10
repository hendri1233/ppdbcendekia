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
    error_log('SPMB database connection failed: '.mysqli_connect_error());
    http_response_code(500);
    exit('Layanan pendaftaran sedang tidak tersedia. Silakan hubungi panitia.');
}

mysqli_set_charset($conn, 'utf8mb4');

require_once __DIR__ . '/maintenance.php';
ppdb_maintenance_guard($conn);

// Antrean WhatsApp berjeda dijalankan di akhir permintaan halaman (lihat wa_queue.php).
if (PHP_SAPI !== 'cli') {
    register_shutdown_function(static function () use ($conn): void {
        require_once __DIR__ . '/wa_queue.php';
        wa_queue_run_after_response($conn);
    });
}
