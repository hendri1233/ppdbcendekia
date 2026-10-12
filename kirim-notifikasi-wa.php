<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'wablas.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

// Kembali ke daftar dengan filter yang sama; hanya query string yang diterima.
$returnQuery = (string) ($_POST['return'] ?? '');
if (!preg_match('/^\?[A-Za-z0-9_\-=&%.+]*$/', $returnQuery)) {
    $returnQuery = '';
}

$registrationId = (string) ($_POST['id'] ?? '');
$registration = null;
if (preg_match('/^P[0-9]{9}$/', $registrationId)) {
    $sql = 'SELECT id_pendaftaran,nm_peserta,status_ppdb,kode_unit,no_hp,no_hp_pendaftar,no_hp_ayah,no_hp_ibu FROM tb_pendaftaran WHERE id_pendaftaran=?';
    if (ppdb_is_super_admin()) {
        $lookup = mysqli_prepare($conn, $sql.' LIMIT 1');
        mysqli_stmt_bind_param($lookup, 's', $registrationId);
    } else {
        $unitCode = (string) $_SESSION['admin_unit_code'];
        $lookup = mysqli_prepare($conn, $sql.' AND kode_unit=? LIMIT 1');
        mysqli_stmt_bind_param($lookup, 'ss', $registrationId, $unitCode);
    }
    mysqli_stmt_execute($lookup);
    $registration = mysqli_fetch_assoc(mysqli_stmt_get_result($lookup));
    mysqli_stmt_close($lookup);
}

if (!$registration) {
    $_SESSION['wa_notice'] = ['ok' => false, 'text' => 'Data peserta tidak ditemukan.'];
} elseif (in_array($registration['status_ppdb'], ['diterima', 'data_lama'], true)) {
    $_SESSION['wa_notice'] = ['ok' => false, 'text' => 'Peserta ini tidak memerlukan notifikasi tahap.'];
} elseif (!ppdb_wablas_enabled()) {
    $_SESSION['wa_notice'] = ['ok' => false, 'text' => 'Wablas belum dikonfigurasi. Isi WABLAS_TOKEN pada .env.'];
} else {
    $number = (string) array_key_first(ppdb_registrant_recipients($registration));
    if ($number === '') {
        $_SESSION['wa_notice'] = ['ok' => false, 'text' => 'Nomor WhatsApp '.$registration['nm_peserta'].' tidak valid.'];
    } else {
        $result = ppdb_wablas_send($number, ppdb_status_reminder_message($registration));
        $_SESSION['wa_notice'] = $result['ok']
            ? ['ok' => true, 'text' => 'Notifikasi WhatsApp untuk '.$registration['nm_peserta'].' terkirim ke '.ppdb_display_phone($number).'.']
            : ['ok' => false, 'text' => 'Notifikasi untuk '.$registration['nm_peserta'].' gagal terkirim: '.$result['error']];
    }
}

header('Location: daftar_peserta.php'.$returnQuery);
exit;
