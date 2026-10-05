<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
start_app_session();

function return_to_applicant_status(string $registrationId, string $token, string $message = ''): void
{
    $query = http_build_query(['id' => $registrationId, 'token' => $token, 'upload_error' => $message]);
    header('Location: status-pendaftaran.php?' . $query);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$registrationId = $_POST['id'] ?? '';
$receiptToken = $_POST['token'] ?? '';
if (!preg_match('/^P[0-9]{9}$/', $registrationId) || !preg_match('/^[a-f0-9]{64}$/', $receiptToken)) {
    http_response_code(404);
    exit('Pendaftaran tidak ditemukan.');
}

$tokenHash = hash('sha256', $receiptToken);
$lookup = mysqli_prepare($conn, 'SELECT p.status_ppdb,p.status_pembayaran,p.tanggal_batas_bayar,p.token_bukti,p.token_whatsapp,p.token_formulir FROM tb_pendaftaran p WHERE p.id_pendaftaran=? LIMIT 1');
mysqli_stmt_bind_param($lookup, 's', $registrationId);
mysqli_stmt_execute($lookup);
$registration = mysqli_fetch_assoc(mysqli_stmt_get_result($lookup));
mysqli_stmt_close($lookup);
if (!$registration) {
    http_response_code(404);
    exit('Pendaftaran tidak ditemukan.');
}
$tokenIsValid = hash_equals((string) $registration['token_bukti'], $tokenHash)
    || hash_equals((string) ($registration['token_whatsapp'] ?? ''), $tokenHash)
    || hash_equals((string) ($registration['token_formulir'] ?? ''), $tokenHash);
if (!$tokenIsValid) {
    http_response_code(404);
    exit('Pendaftaran tidak ditemukan.');
}
if ($registration['status_ppdb'] !== 'menunggu_pembayaran' || $registration['status_pembayaran'] === 'terverifikasi') {
    return_to_applicant_status($registrationId, $receiptToken, 'Bukti hanya dapat diunggah setelahdata disetujui dan sebelum status pembayaran selesai.');
}
if ($registration['tanggal_batas_bayar'] && $registration['tanggal_batas_bayar'] < date('Y-m-d')) {
    return_to_applicant_status($registrationId, $receiptToken, 'Batas pembayaran sudah lewat. Hubungi panitia.');
}

$file = $_FILES['bukti_transfer'] ?? null;
$stored = null;
try {
    $stored = ppdb_store_private_upload(
        is_array($file) ? $file : [],
        'payment-proofs',
        ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'],
        5 * 1024 * 1024
    );
} catch (RuntimeException $exception) {
    return_to_applicant_status($registrationId, $receiptToken, $exception->getMessage());
}

try {
    mysqli_begin_transaction($conn);
    $insert = mysqli_prepare($conn, "INSERT INTO tb_bukti_pembayaran (id_pendaftaran,nomor_referensi,nama_file_asli,path_file,mime_type,ukuran_byte,sha256) VALUES (?,?,?,?,?,?,?)");
    mysqli_stmt_bind_param($insert, 'sssssis', $registrationId, $registrationId, $stored['original_name'], $stored['path'], $stored['mime'], $stored['size'], hash_file('sha256', $stored['path']));
    mysqli_stmt_execute($insert);
    mysqli_stmt_close($insert);

    $update = mysqli_prepare($conn, "UPDATE tb_pendaftaran SET status_ppdb='pembayaran_diperiksa',status_pembayaran='menunggu_verifikasi' WHERE id_pendaftaran=?");
    mysqli_stmt_bind_param($update, 's', $registrationId);
    mysqli_stmt_execute($update);
    mysqli_stmt_close($update);
    enqueue_google_sheets_registration($conn, $registrationId);
    mysqli_commit($conn);
} catch (Throwable $exception) {
    mysqli_rollback($conn);
    if (is_file($stored['path'])) {
        unlink($stored['path']);
    }
    error_log('PPDB payment proof upload failed: ' . get_class($exception));
    return_to_applicant_status($registrationId, $receiptToken, 'Bukti belum dapat dikirim. Silakan coba lagi.');
}

sync_google_sheets_registration($conn, $registrationId);

try {
    $adminPhone = ppdb_wablas_admin_phone();
    if ($adminPhone !== '') {
        ppdb_try_send_wablas_message($adminPhone, implode("\n", [
            'Bukti pembayaran PPDB masuk.',
            'Kode pendaftaran: ' . $registrationId,
            'Buka untuk diperiksa:',
            ppdb_public_base_url() . '/detail-peserta.php?id=' . rawurlencode($registrationId),
        ]), 'payment proof admin');
    }
} catch (Throwable $exception) {
    error_log('PPDB payment proof admin notice failed: ' . $exception->getMessage());
}

return_to_applicant_status($registrationId, $receiptToken);
