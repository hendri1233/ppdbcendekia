<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

// Unggahan dokumen ke Google Drive bisa memakan waktu hingga 90 detik.
@set_time_limit(180);
// "Tulis ulang semua" mengisi seluruh spreadsheet unit, misalnya saat tahun ajaran baru.
$result = google_sheets_sync_units($conn, ($_POST['mode'] ?? '') === 'all');

start_app_session();
$_SESSION['google_sheets_sync_result'] = $result;
header('Location: admin.php#spreadsheet');
exit;