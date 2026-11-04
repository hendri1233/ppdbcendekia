<?php
/* Tautan pendek pada pesan WhatsApp: /lanjut/{kode}-{tanda}, diarahkan ke tahap pendaftaran terakhir. */
require_once 'app_helpers.php';
require_once 'koneksi.php';

$code = strtoupper(trim((string) ($_GET['kode'] ?? '')));
$signature = trim((string) ($_GET['sig'] ?? ''));
$registration = null;
if (preg_match('/^P[0-9]{9}$/', $code) && preg_match('/^[a-f0-9]{12}$/', $signature)) {
    $stmt = mysqli_prepare($conn, 'SELECT id_pendaftaran,token_bukti FROM tb_pendaftaran WHERE id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $code);
    mysqli_stmt_execute($stmt);
    $registration = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
if (!$registration || !hash_equals(ppdb_continue_signature($registration), $signature)) {
    header('Location: '.ppdb_public_base_url().'/lanjutkan-pendaftaran.php');
    exit;
}
header('Location: '.ppdb_public_base_url().'/formulir.php?'.http_build_query(['kode' => $code, 'token' => ppdb_resume_token($conn, $code)]));
