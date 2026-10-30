<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_drive_sync.php';
require_super_admin();

/*
 * Menghubungkan Google Drive yayasan (OAuth, cakupan drive.file) agar dokumen peserta
 * dapat disalin ke Drive. Alamat callback halaman ini harus didaftarkan sebagai
 * "Authorized redirect URI" pada OAuth Client ID di Google Cloud Console.
 */

function google_drive_auth_finish(bool $ok, string $text): void
{
    $_SESSION['google_drive_flash'] = ['ok' => $ok, 'text' => $text];
    header('Location: admin.php#spreadsheet');
    exit;
}

if (!google_drive_configured($conn)) {
    google_drive_auth_finish(false, 'Google Drive belum dapat dihubungkan: jalankan migrasi 029 dan isi GOOGLE_OAUTH_CLIENT_ID serta GOOGLE_OAUTH_CLIENT_SECRET di .env.');
}

// Putuskan: cabut izin di Google lalu hapus token dari database.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null) || ($_POST['action'] ?? '') !== 'disconnect') {
        http_response_code(403);
        exit('Permintaan tidak valid.');
    }
    $account = google_drive_account($conn);
    if ($account) {
        try {
            google_drive_oauth_client()->revokeToken($account['refresh_token']);
        } catch (Throwable $exception) {
            error_log('SPMB Google Drive revoke failed: '.$exception->getMessage());
        }
    }
    mysqli_query($conn, 'DELETE FROM tb_google_drive_akun WHERE id=1');
    google_drive_auth_finish(true, 'Google Drive diputuskan. Berkas yang sudah ada di Drive tidak dihapus.');
}

// Kembali dari halaman izin Google.
if (isset($_GET['code']) || isset($_GET['error'])) {
    $expectedState = (string) ($_SESSION['google_drive_oauth_state'] ?? '');
    unset($_SESSION['google_drive_oauth_state']);
    if ($expectedState === '' || !hash_equals($expectedState, (string) ($_GET['state'] ?? ''))) {
        google_drive_auth_finish(false, 'Sesi izin Google kedaluwarsa. Ulangi "Hubungkan Google Drive".');
    }
    if (isset($_GET['error'])) {
        google_drive_auth_finish(false, 'Izin Google Drive dibatalkan ('.substr((string) $_GET['error'], 0, 60).').');
    }
    try {
        $client = google_drive_oauth_client();
        $token = $client->fetchAccessTokenWithAuthCode((string) $_GET['code']);
        if (isset($token['error'])) {
            throw new RuntimeException('Google menolak kode izin ('.$token['error'].').');
        }
        $refreshToken = (string) ($token['refresh_token'] ?? '');
        if ($refreshToken === '') {
            throw new RuntimeException('Google tidak mengirim refresh token. Cabut akses aplikasi di myaccount.google.com/permissions lalu hubungkan ulang.');
        }
        $about = (new \Google\Service\Drive($client))->about->get(['fields' => 'user(emailAddress)']);
        $email = (string) $about->getUser()->getEmailAddress();
    } catch (Throwable $exception) {
        error_log('SPMB Google Drive connect failed: '.$exception->getMessage());
        google_drive_auth_finish(false, 'Google Drive belum terhubung: '.($exception instanceof RuntimeException ? $exception->getMessage() : 'galat dari Google.'));
    }

    // Akun lain tidak dapat membuka folder buatan akun sebelumnya (drive.file): mulai dari awal.
    $previous = google_drive_account($conn);
    if ($previous && strcasecmp($previous['email'], $email) !== 0) {
        mysqli_query($conn, 'DELETE FROM tb_google_drive_folders');
        mysqli_query($conn, 'UPDATE tb_dokumen_peserta SET drive_file_id=NULL,drive_source_path=NULL');
        mysqli_query($conn, 'UPDATE tb_bukti_pembayaran SET drive_file_id=NULL,drive_source_path=NULL');
    }
    $adminId = (int) $_SESSION['admin_id'];
    $save = mysqli_prepare($conn, 'INSERT INTO tb_google_drive_akun (id,email,refresh_token,terhubung_pada,terhubung_oleh) VALUES (1,?,?,NOW(),?) ON DUPLICATE KEY UPDATE email=VALUES(email),refresh_token=VALUES(refresh_token),terhubung_pada=NOW(),terhubung_oleh=VALUES(terhubung_oleh)');
    mysqli_stmt_bind_param($save, 'ssi', $email, $refreshToken, $adminId);
    mysqli_stmt_execute($save);
    mysqli_stmt_close($save);
    google_drive_record_error($conn, null);
    google_drive_auth_finish(true, 'Google Drive terhubung sebagai '.$email.'. Tekan "Tulis ulang semua unit" untuk menyalin dokumen yang sudah ada.');
}

// Mulai alur izin.
$state = bin2hex(random_bytes(16));
$_SESSION['google_drive_oauth_state'] = $state;
$client = google_drive_oauth_client();
$client->setState($state);
header('Location: '.$client->createAuthUrl());
exit;
