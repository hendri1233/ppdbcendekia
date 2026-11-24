<?php
/*
 * Cron Job Hostinger: membuat dan mengunggah PDF cetak peserta ke Google Drive per unit,
 * tanpa menunggu admin membuka panel. Disarankan berjalan setiap 5 menit:
 *   /usr/bin/php /home/<user>/domains/cendekiatakengon.or.id/public_html/cron-cetak-drive.php
 * Hanya dapat dijalankan dari baris perintah server; dibuka lewat browser selalu 404.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Alamat situs untuk callback OAuth Google; tidak ada HTTP_HOST saat dijalankan cron.
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cendekiatakengon.or.id';
chdir(__DIR__);
require_once __DIR__.'/app_helpers.php';
require_once __DIR__.'/koneksi.php';
require_once __DIR__.'/cetak_drive.php';

set_time_limit(280);
// Satu proses saja: bila cron sebelumnya belum selesai, putaran ini dilewati.
$lock = mysqli_fetch_row(mysqli_query($conn, "SELECT GET_LOCK('ppdb-cetak-cron', 0)"));
if ((int) ($lock[0] ?? 0) !== 1) {
    echo "Masih berjalan, dilewati.\n";
    exit;
}

$started = microtime(true);
$uploaded = 0;
try {
    if (cetak_drive_has_pending($conn)) {
        // Selesai sebelum putaran cron berikutnya (5 menit).
        $uploaded = cetak_drive_sync_pending($conn, $started + 240);
        google_drive_record_error($conn, null);
    }
} catch (Throwable $exception) {
    error_log('SPMB cron cetak Drive failed ('.get_class($exception).'): '.$exception->getMessage());
    google_drive_record_error($conn, google_drive_error_message($exception));
    echo 'Gagal: '.google_drive_error_message($exception)."\n";
}
mysqli_query($conn, "DO RELEASE_LOCK('ppdb-cetak-cron')");
printf("%d PDF diunggah dalam %.0f detik, %d peserta masih antre.\n", $uploaded, microtime(true) - $started, cetak_drive_ready($conn) ? count(cetak_drive_pending_ids($conn)) : 0);
