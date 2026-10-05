<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$registrationId = $_POST['id'] ?? '';
if (preg_match('/^P[0-9]{9}$/', $registrationId)) {
    $delete = mysqli_prepare($conn, 'DELETE FROM tb_pendaftaran WHERE id_pendaftaran = ?');
    mysqli_stmt_bind_param($delete, 's', $registrationId);
    mysqli_stmt_execute($delete);
    mysqli_stmt_close($delete);
}

header('Location: daftar_peserta.php');
exit;