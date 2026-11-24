<?php
/*
 * Pekerjaan lambat panel admin dijalankan di latar belakang agar halaman tidak menunggu:
 * sinkronisasi spreadsheet/Drive yang mengantre dan pengisian lokasi peta. Dipanggil js/admin.js.
 */
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
require_once 'cetak_drive.php';
require_once 'web_stats.php';
require_admin();

$unitCode = ppdb_is_super_admin() ? '' : (string) $_SESSION['admin_unit_code'];
// Sesi dilepas agar halaman admin lain tidak ikut menunggu permintaan ini.
session_write_close();
ignore_user_abort(true);
set_time_limit(150);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$lock = mysqli_fetch_row(mysqli_query($conn, "SELECT GET_LOCK('ppdb-admin-latar', 0)"));
if ((int) ($lock[0] ?? 0) !== 1) {
    echo json_encode(['ok' => true, 'running' => true]);
    exit;
}

$startedAt = microtime(true);
$synced = ['synced' => 0, 'failed' => 0];
if (ppdb_admin_sync_is_due($conn)) {
    $synced = google_sheets_sync_units($conn);
}
// PDF cetak peserta yang belum ada atau berubah, termasuk peserta lama, dicicil tiap kunjungan admin.
try {
    if (cetak_drive_has_pending($conn)) {
        $printDeadline = min($startedAt + 120, microtime(true) + 60);
        google_drive_sync_safely($conn, static fn () => cetak_drive_sync_pending($conn, $printDeadline));
    }
} catch (Throwable $exception) {
    error_log('SPMB cetak Drive background failed: '.$exception->getMessage());
}

ppdb_geo_network(true);
ppdb_unit_link_locations($conn, $unitCode, 30);
ppdb_registrant_locations($conn, $unitCode);

mysqli_query($conn, "DO RELEASE_LOCK('ppdb-admin-latar')");
echo json_encode(['ok' => true] + $synced);
