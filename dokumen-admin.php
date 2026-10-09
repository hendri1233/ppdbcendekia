<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_admin();

/** Nama berkas di header HTTP dibersihkan agar aman dari path traversal. */
function dokumen_safe_filename(string $name): string
{
    return preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'dokumen';
}

$documentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$documentId || $documentId < 1) {
    http_response_code(404);
    exit('Dokumen tidak ditemukan.');
}

if (ppdb_is_super_admin()) {
    $stmt = mysqli_prepare($conn, 'SELECT d.path_file,d.mime_type,d.nama_file_asli FROM tb_dokumen_peserta d WHERE d.id=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $documentId);
} else {
    $unitCode = (string) $_SESSION['admin_unit_code'];
    $stmt = mysqli_prepare($conn, 'SELECT d.path_file,d.mime_type,d.nama_file_asli FROM tb_dokumen_peserta d JOIN tb_pendaftaran p ON p.id_pendaftaran=d.id_pendaftaran WHERE d.id=? AND p.kode_unit=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'is', $documentId, $unitCode);
}
mysqli_stmt_execute($stmt);
$document = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$document) {
    http_response_code(404);
    exit('Dokumen tidak ditemukan.');
}

$realFilePath = ppdb_resolve_stored_file('participant-documents', $document['path_file']);
$allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
if (!$realFilePath || !in_array($document['mime_type'], $allowedMimes, true)) {
    http_response_code(404);
    exit('Dokumen tidak ditemukan.');
}

$extension = $document['mime_type'] === 'application/pdf' ? 'pdf' : ($document['mime_type'] === 'image/png' ? 'png' : 'jpg');
header('Content-Type: '.$document['mime_type']);
header('Content-Length: '.filesize($realFilePath));
header('Content-Disposition: inline; filename="'.dokumen_safe_filename($document['nama_file_asli']).'.'.$extension.'"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
readfile($realFilePath);
exit;
