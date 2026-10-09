<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';

start_app_session();
$isAdmin = !empty($_SESSION['admin_id']);
if ($isAdmin) {
    require_super_admin();
}

$materialId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$fileKind = (string) ($_GET['file'] ?? 'preview');
if (!$materialId || !in_array($fileKind, ['preview', 'original'], true)) {
    http_response_code(404);
    exit('File materi tidak ditemukan.');
}

$registrationCode = strtoupper(trim((string) ($_GET['kode'] ?? '')));
$registrationCode = preg_match('/^P[0-9]{9}$/', $registrationCode) ? $registrationCode : '';
$stmt = mysqli_prepare($conn, 'SELECT m.kode_unit,m.nama_file_asli,m.path_file_asli,m.mime_file_asli,m.nama_file_pratinjau,m.path_file_pratinjau,m.mime_file_pratinjau,p.token_formulir,p.token_bukti,p.token_whatsapp FROM tb_ppdb_materials m LEFT JOIN tb_pengaturan_ppdb c ON c.kode_unit=m.kode_unit AND c.th_ajaran=m.th_ajaran LEFT JOIN tb_pendaftaran p ON p.kode_unit=m.kode_unit AND p.th_ajaran=m.th_ajaran AND p.id_pendaftaran=? WHERE m.id=? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'si', $registrationCode, $materialId);
mysqli_stmt_execute($stmt);
$material = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$material) {
    http_response_code(404);
    exit('File materi tidak ditemukan.');
}

$authorized = $isAdmin;
if (!$authorized) {
    $token = trim((string) ($_GET['token'] ?? ''));
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        $tokenHash = hash('sha256', $token);
        foreach (['token_formulir', 'token_bukti', 'token_whatsapp'] as $key) {
            if (!empty($material[$key]) && hash_equals((string) $material[$key], $tokenHash)) {
                $authorized = true;
                break;
            }
        }
    }
}
if (!$authorized) {
    http_response_code(403);
    exit('Akses materi tidak diizinkan.');
}

$nameKey = $fileKind === 'preview' ? 'nama_file_pratinjau' : 'nama_file_asli';
$pathKey = $fileKind === 'preview' ? 'path_file_pratinjau' : 'path_file_asli';
$mimeKey = $fileKind === 'preview' ? 'mime_file_pratinjau' : 'mime_file_asli';
$storedPath = (string) ($material[$pathKey] ?? '');
$mime = (string) ($material[$mimeKey] ?? '');
$filePath = ppdb_resolve_stored_file('ppdb-materials', $storedPath);
if (!$filePath || !in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/vnd.adobe.photoshop'], true)) {
    http_response_code(404);
    exit('File materi tidak tersedia.');
}

$fileName = basename((string) ($material[$nameKey] ?? 'materi'));
$disposition = $fileKind === 'preview' && in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true) ? 'inline' : 'attachment';
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($filePath));
header('Content-Disposition: '.$disposition.'; filename="materi"; filename*=UTF-8\'\''.rawurlencode($fileName));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
readfile($filePath);
