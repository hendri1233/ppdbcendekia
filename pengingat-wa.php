<?php
/**
 * Pengingat WhatsApp untuk orang tua yang belum melanjutkan pendaftaran.
 * GET  ?id=P…  : pratinjau pesan, nomor tujuan, dan riwayat pengingat (JSON).
 * POST id, csrf: kirim pesan otomatis lewat Wablas lalu catat waktunya (JSON).
 */
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'wablas.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pengingat_respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost && !verify_csrf_token($_POST['csrf_token'] ?? null)) {
    pengingat_respond(['ok' => false, 'text' => 'Sesi berakhir. Muat ulang halaman lalu coba lagi.'], 403);
}

$registrationId = (string) ($isPost ? ($_POST['id'] ?? '') : ($_GET['id'] ?? ''));
if (!preg_match('/^P[0-9]{9}$/', $registrationId)) {
    pengingat_respond(['ok' => false, 'text' => 'Data peserta tidak ditemukan.'], 404);
}

$tracked = ppdb_column_exists($conn, 'tb_pendaftaran', 'pengingat_wa_terakhir');
$sql = 'SELECT p.id_pendaftaran,p.kode_unit,p.nm_peserta,p.th_ajaran,p.status_ppdb,p.status_pembayaran,p.no_hp,p.no_hp_pendaftar,p.no_hp_ayah,p.no_hp_ibu,p.tanggal_batas_bayar,p.token_bukti,'
    .($tracked ? 'p.pengingat_wa_terakhir,p.pengingat_wa_jumlah,' : '')
    .'u.nama_unit,q.biaya_pendaftaran FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit LEFT JOIN tb_pengaturan_ppdb q ON q.kode_unit=p.kode_unit AND q.th_ajaran=p.th_ajaran WHERE p.id_pendaftaran=?';
if (ppdb_is_super_admin()) {
    $stmt = mysqli_prepare($conn, $sql.' LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
} else {
    $unitCode = (string) $_SESSION['admin_unit_code'];
    $stmt = mysqli_prepare($conn, $sql.' AND p.kode_unit=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ss', $registrationId, $unitCode);
}
mysqli_stmt_execute($stmt);
$registration = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$registration) {
    pengingat_respond(['ok' => false, 'text' => 'Data peserta tidak ditemukan.'], 404);
}

$stage = ppdb_reminder_stage($registration);
$formWindow = ppdb_form_window($conn, (string) $registration['kode_unit'], (string) $registration['th_ajaran']);
$blocked = $stage === '' ? ppdb_reminder_unavailable_note($registration) : '';
if ($blocked === '' && ppdb_form_deadline_passed($formWindow['batas'] ?? null)) {
    $blocked = 'Batas pengisian formulir unit ini sudah lewat, sehingga peserta dinyatakan gugur. Pengingat tidak dikirim.';
}
if ($blocked === '' && empty($registration['token_bukti'])) {
    $blocked = 'Tautan pribadi peserta belum tersedia. Buka halaman detail untuk memeriksa datanya.';
}

$recipients = ppdb_registrant_recipients($registration);
$primaryPhone = $recipients ? (string) array_key_first($recipients) : '';
if ($blocked === '' && $primaryPhone === '') {
    $blocked = 'Nomor WhatsApp pendaftar, ayah, maupun ibu tidak valid.';
}

$lastSent = $tracked && !empty($registration['pengingat_wa_terakhir']) ? (string) $registration['pengingat_wa_terakhir'] : '';
$history = static function (string $lastSent, int $count): array {
    if ($lastSent === '') {
        return ['last' => '', 'count' => $count, 'recent' => false];
    }
    $hours = (strtotime(ppdb_now_wib()) - strtotime($lastSent)) / 3600;
    return ['last' => ppdb_format_day_date_id($lastSent).' pukul '.date('H.i', strtotime($lastSent)).' WIB', 'count' => $count, 'recent' => $hours < 24];
};

$message = '';
$links = [];
if ($blocked === '') {
    ppdb_resume_token($conn, $registrationId);
    $message = ppdb_reminder_whatsapp_message($registration, $stage, ppdb_continue_url($registration), $formWindow);
    foreach ($recipients as $number => $role) {
        $links[] = ['role' => $role, 'display' => ppdb_display_phone((string) $number), 'url' => ppdb_whatsapp_link((string) $number, $message)];
    }
}

$stageLabels = ['bayar' => 'Belum bayar & unggah bukti', 'formulir' => 'Belum mengisi formulir lengkap'];
$base = [
    'id' => $registrationId,
    'name' => (string) $registration['nm_peserta'],
    'unit' => ppdb_unit_display_name($registration['nama_unit'] ?? ''),
    'stage' => $stage,
    'stage_label' => $stageLabels[$stage] ?? ppdb_status_info($registration['status_ppdb'])['label'],
    'blocked' => $blocked,
    'wablas' => ppdb_wablas_enabled(),
    'phone' => $primaryPhone !== '' ? ppdb_display_phone($primaryPhone) : '',
    'phone_role' => $primaryPhone !== '' ? $recipients[$primaryPhone] : '',
    'deadline' => !empty($formWindow['batas']) ? ppdb_format_day_date_id($formWindow['batas']).' pukul '.date('H.i', strtotime($formWindow['batas'])).' WIB' : '',
    'message' => $message,
    'links' => $links,
    'history' => $history($lastSent, $tracked ? (int) $registration['pengingat_wa_jumlah'] : 0),
    'tracked' => $tracked,
];

if (!$isPost) {
    pengingat_respond(['ok' => true] + $base);
}

if ($blocked !== '') {
    pengingat_respond(['ok' => false, 'text' => $blocked] + $base, 422);
}
if (!ppdb_wablas_enabled()) {
    pengingat_respond(['ok' => false, 'text' => 'WhatsApp otomatis (Wablas) belum aktif. Kirim manual lewat tombol di bawah.'] + $base, 503);
}

$delivery = ppdb_wablas_send($primaryPhone, $message);
if (!$delivery['ok']) {
    error_log('Pengingat WhatsApp '.$registrationId.' gagal: '.$delivery['error']);
    pengingat_respond(['ok' => false, 'text' => 'Pengingat belum terkirim ('.$delivery['error'].'). Kirim manual lewat tombol di bawah.'] + $base, 502);
}

$count = (int) $base['history']['count'];
if ($tracked) {
    $now = ppdb_now_wib();
    $save = mysqli_prepare($conn, 'UPDATE tb_pendaftaran SET pengingat_wa_terakhir=?,pengingat_wa_jumlah=pengingat_wa_jumlah+1 WHERE id_pendaftaran=?');
    mysqli_stmt_bind_param($save, 'ss', $now, $registrationId);
    mysqli_stmt_execute($save);
    mysqli_stmt_close($save);
    $base['history'] = $history($now, $count + 1);
}
pengingat_respond(['ok' => true, 'sent' => true, 'text' => 'Pengingat terkirim ke '.$base['phone'].' ('.$base['phone_role'].').'] + $base);
