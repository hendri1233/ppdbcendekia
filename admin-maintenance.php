<?php
/* Menyalakan atau mematikan mode maintenance situs publik dari menu profil admin. */
require_once 'app_helpers.php';
require_super_admin();
require_once 'koneksi.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

ppdb_maintenance_set($conn, ($_POST['aktif'] ?? '') === '1');

// Kembali ke halaman admin asal; hanya nama berkas lokal yang diterima.
$back = (string) ($_POST['kembali'] ?? '');
if (!preg_match('/^[a-z0-9_-]+\.php(\?[^\s#]*)?$/i', $back)) {
    $back = 'admin.php';
}
header('Location: '.$back);
exit;
