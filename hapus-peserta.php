<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
require_once 'google_drive_sync.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$registrationId = $_POST['id'] ?? '';
if (preg_match('/^P[0-9]{9}$/', $registrationId)) {
    $unitQuery = mysqli_prepare($conn, 'SELECT kode_unit,th_ajaran FROM tb_pendaftaran WHERE id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($unitQuery, 's', $registrationId);
    mysqli_stmt_execute($unitQuery);
    $deletedUnit = mysqli_fetch_assoc(mysqli_stmt_get_result($unitQuery));
    mysqli_stmt_close($unitQuery);
    if (ppdb_is_super_admin()) {
        $delete = mysqli_prepare($conn, 'DELETE FROM tb_pendaftaran WHERE id_pendaftaran=?');
        mysqli_stmt_bind_param($delete, 's', $registrationId);
    } else {
        $unitCode = (string) $_SESSION['admin_unit_code'];
        $delete = mysqli_prepare($conn, 'DELETE FROM tb_pendaftaran WHERE id_pendaftaran=? AND kode_unit=?');
        mysqli_stmt_bind_param($delete, 'ss', $registrationId, $unitCode);
    }
    mysqli_stmt_execute($delete);
    $deleted = mysqli_stmt_affected_rows($delete) === 1;
    mysqli_stmt_close($delete);
    // Folder dokumen peserta di Google Drive ikut dipindah ke sampah.
    if ($deleted) {
        google_drive_trash_registration($conn, $registrationId);
    }
    // Tulis ulang spreadsheet unit agar baris peserta yang dihapus ikut hilang.
    if ($deleted && $deletedUnit) {
        try {
            google_sheets_rebuild_unit($conn, $deletedUnit['kode_unit'], $deletedUnit['th_ajaran']);
            $_SESSION['wa_notice'] = ['ok' => true, 'text' => 'Pendaftaran '.$registrationId.' dihapus dan spreadsheet unit sudah diperbarui.'];
        } catch (Throwable $exception) {
            error_log('SPMB Google Sheets sync after delete failed ('.get_class($exception).'): '.$exception->getMessage());
            $_SESSION['wa_notice'] = ['ok' => false, 'text' => 'Pendaftaran '.$registrationId.' dihapus, tetapi spreadsheet unit belum diperbarui: '.google_sheets_error_message($exception).' Tekan "Tulis ulang semua unit" di dasbor.'];
        }
    }
}

header('Location: daftar_peserta.php');
exit;