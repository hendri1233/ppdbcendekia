<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_admin();

$paymentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$paymentId || $paymentId < 1) {
    http_response_code(404);
    exit('Bukti pembayaran tidak ditemukan.');
}

$stmt = mysqli_prepare($conn, 'SELECT path_file,mime_type FROM tb_bukti_pembayaran WHERE id=? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $paymentId);
mysqli_stmt_execute($stmt);
$proof = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$proof) {
    http_response_code(404);
    exit('Bukti pembayaran tidak ditemukan.');
}

$storageDirectory = getenv('PPDB_PAYMENT_STORAGE') ?: 'C:/xampp/private/ppdb-payment-proofs';
$realStorageDirectory = realpath($storageDirectory);
$realFilePath = realpath($proof['path_file']);
$allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
if (!$realStorageDirectory || !$realFilePath || strpos(strtolower($realFilePath), strtolower($realStorageDirectory.DIRECTORY_SEPARATOR)) !== 0 || !in_array($proof['mime_type'], $allowedMimes, true)) {
    http_response_code(404);
    exit('Bukti pembayaran tidak ditemukan.');
}

$extension = $proof['mime_type'] === 'application/pdf' ? 'pdf' : ($proof['mime_type'] === 'image/png' ? 'png' : 'jpg');
header('Content-Type: '.$proof['mime_type']);
header('Content-Length: '.filesize($realFilePath));
header('Content-Disposition: inline; filename="bukti-pembayaran-'.$paymentId.'.'.$extension.'"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
readfile($realFilePath);
exit;