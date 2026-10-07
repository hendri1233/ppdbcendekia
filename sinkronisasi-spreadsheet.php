<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$queued = mysqli_query($conn, "SELECT id_pendaftaran FROM tb_google_sync_outbox WHERE status IN ('pending', 'failed') ORDER BY created_at ASC LIMIT 100");
$synced = 0;
$failed = 0;
while ($row = mysqli_fetch_assoc($queued)) {
    if (sync_google_sheets_registration($conn, $row['id_pendaftaran'])) {
        $synced++;
    } else {
        $failed++;
    }
}

start_app_session();
$_SESSION['google_sheets_sync_result'] = ['synced' => $synced, 'failed' => $failed];
header('Location: admin.php#spreadsheet');
exit;