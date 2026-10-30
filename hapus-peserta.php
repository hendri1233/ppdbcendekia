<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
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
    // Tulis ulang spreadsheet unit agar baris peserta yang dihapus ikut hilang.
    if ($deleted && $deletedUnit) {
        try {
            google_sheets_rebuild_unit($conn, $deletedUnit['kode_unit'], $deletedUnit['th_ajaran']);
        } catch (Throwable $exception) {
            error_log('SPMB Google Sheets sync after delete failed: '.$exception->getMessage());
        }
    }
}

header('Location: daftar_peserta.php');
exit;