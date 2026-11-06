<?php
/* Tombol "Gabung grup WhatsApp": mencatat klik pertama pendaftar lalu membuka grup unit. */
require_once 'app_helpers.php';
require_once 'koneksi.php';

$registrationId = (string) ($_GET['id'] ?? '');
$token = (string) ($_GET['token'] ?? '');
$group = null;
if (preg_match('/^P[0-9]{9}$/', $registrationId) && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $tokenHash = hash('sha256', $token);
    $stmt = mysqli_prepare($conn, "SELECT g.link_grup FROM tb_pendaftaran p JOIN tb_unit_whatsapp_groups g ON g.kode_unit=p.kode_unit WHERE p.id_pendaftaran=? AND p.status_ppdb='diterima' AND (p.token_bukti=? OR p.token_whatsapp=? OR p.token_formulir=?) LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ssss', $registrationId, $tokenHash, $tokenHash, $tokenHash);
    mysqli_stmt_execute($stmt);
    $group = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
$link = trim((string) ($group['link_grup'] ?? ''));
if (!preg_match('#^https://#i', $link)) {
    header('Location: status-pendaftaran?'.http_build_query(['id' => $registrationId, 'token' => $token]));
    exit;
}
if (ppdb_column_exists($conn, 'tb_pendaftaran', 'grup_diklik_pada')) {
    $stmt = mysqli_prepare($conn, 'UPDATE tb_pendaftaran SET grup_diklik_pada=NOW() WHERE id_pendaftaran=? AND grup_diklik_pada IS NULL');
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
header('Location: '.$link);
