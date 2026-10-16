<?php
/**
 * Revisi formulir: buka kembali formulir lengkap yang sudah pernah dikirim
 * lalu kirim ulang tautan formulir ke WhatsApp pendaftar. Isian lama tetap
 * dimuat di formulir sehingga orang tua cukup memperbaiki bagian yang keliru.
 */
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'wablas.php';
require_admin();

// Permintaan dari tombol Revisi (fetch) dijawab JSON; tanpa JavaScript kembali ke daftar.
$wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
function revisi_respond(bool $wantsJson, string $returnQuery, array $notice): never
{
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($notice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $_SESSION['wa_notice'] = $notice;
    header('Location: daftar_peserta.php'.$returnQuery);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? null)) {
    if ($wantsJson) {
        http_response_code(403);
        revisi_respond(true, '', ['ok' => false, 'text' => 'Sesi berakhir. Muat ulang halaman lalu coba lagi.']);
    }
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

// Kembali ke daftar dengan filter yang sama; hanya query string yang diterima.
$returnQuery = (string) ($_POST['return'] ?? '');
if (!preg_match('/^\?[A-Za-z0-9_\-=&%.+]*$/', $returnQuery)) {
    $returnQuery = '';
}
$registrationId = (string) ($_POST['id'] ?? '');
$note = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['catatan'] ?? '')));
$note = mb_substr($note, 0, 300);

try {
    if (!preg_match('/^P[0-9]{9}$/', $registrationId)) {
        throw new RuntimeException('Data peserta tidak ditemukan.');
    }
    mysqli_begin_transaction($conn);
    $sql = 'SELECT nm_peserta,status_ppdb,status_data,kode_unit,token_formulir FROM tb_pendaftaran WHERE id_pendaftaran=?';
    if (ppdb_is_super_admin()) {
        $lock = mysqli_prepare($conn, $sql.' FOR UPDATE');
        mysqli_stmt_bind_param($lock, 's', $registrationId);
    } else {
        $unitCode = (string) $_SESSION['admin_unit_code'];
        $lock = mysqli_prepare($conn, $sql.' AND kode_unit=? FOR UPDATE');
        mysqli_stmt_bind_param($lock, 'ss', $registrationId, $unitCode);
    }
    mysqli_stmt_execute($lock);
    $registration = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
    mysqli_stmt_close($lock);
    if (!$registration) {
        throw new RuntimeException('Data peserta tidak ditemukan.');
    }
    // Hanya pendaftar yang tautan formulirnya pernah dikirim (pembayaran disetujui).
    if (empty($registration['token_formulir']) || !in_array($registration['status_ppdb'], ['menunggu_formulir', 'formulir_terisi', 'ditolak', 'diterima'], true)) {
        throw new RuntimeException('Revisi hanya untuk peserta yang tautan formulirnya pernah dikirim.');
    }

    // Peserta yang sudah diterima tetap diterima (kursi tidak dilepas); formulir dibuka lewat penanda revisi.
    $adminId = (int) $_SESSION['admin_id'];
    $reopen = mysqli_prepare($conn, $registration['status_ppdb'] === 'diterima'
        ? "UPDATE tb_pendaftaran SET revisi_dibuka=1,status_data='perlu_perbaikan',data_diperiksa_oleh=?,data_diperiksa_pada=NOW() WHERE id_pendaftaran=?"
        : "UPDATE tb_pendaftaran SET status_ppdb='menunggu_formulir',revisi_dibuka=0,status_data='perlu_perbaikan',data_diperiksa_oleh=?,data_diperiksa_pada=NOW() WHERE id_pendaftaran=?");
    mysqli_stmt_bind_param($reopen, 'is', $adminId, $registrationId);
    mysqli_stmt_execute($reopen);
    mysqli_stmt_close($reopen);

    $shareLinks = ppdb_prepare_formulir_link($conn, $registrationId, $note, true);
    mysqli_commit($conn);
} catch (Throwable $exception) {
    mysqli_rollback($conn);
    if (!$exception instanceof RuntimeException) {
        error_log('Revisi formulir gagal: '.$exception->getMessage());
    }
    revisi_respond($wantsJson, $returnQuery, ['ok' => false, 'text' => $exception instanceof RuntimeException ? $exception->getMessage() : 'Revisi belum dapat diproses. Silakan coba lagi.']);
}

$student = $registration['nm_peserta'];
$statusLabel = $registration['status_ppdb'] === 'diterima' ? 'Diterima · revisi dibuka' : 'Menunggu formulir';
if (ppdb_wablas_enabled()) {
    $shareLinks = ppdb_wablas_send_links($shareLinks);
    $sent = array_values(array_filter($shareLinks['links'], static fn ($link) => !empty($link['sent'])));
    $notice = $sent
        ? ['ok' => true, 'sent' => true, 'text' => 'Tautan revisi '.$student.' terkirim ke '.implode(', ', array_column($sent, 'display')).'.']
        : ['ok' => false, 'sent' => false, 'text' => 'Formulir '.$student.' sudah dibuka, tetapi WhatsApp gagal terkirim: '.($shareLinks['links'][0]['send_error'] ?? 'tidak diketahui').'. Kirim manual:', 'links' => $shareLinks['links']];
} else {
    $notice = ['ok' => true, 'sent' => false, 'text' => 'Formulir '.$student.' sudah dibuka. Kirim tautannya lewat WhatsApp:', 'links' => $shareLinks['links']];
}
$notice['status_label'] = $statusLabel;
revisi_respond($wantsJson, $returnQuery, $notice);
