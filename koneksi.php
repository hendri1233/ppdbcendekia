<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'ppdb_cendekia';

$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    error_log('PPDB database connection failed: '.mysqli_connect_error());
    http_response_code(500);
    exit('Layanan pendaftaran sedang tidak tersedia. Silakan hubungi panitia.');
}

mysqli_set_charset($conn, 'utf8mb4');