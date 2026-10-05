<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$registrationId = $_GET['id'] ?? '';
$receiptToken = $_GET['token'] ?? '';
if (!preg_match('/^P[0-9]{9}$/', $registrationId)) {
    http_response_code(404);
    exit('Bukti pendaftaran tidak ditemukan.');
}

$tokenIsValid = preg_match('/^[a-f0-9]{64}$/', $receiptToken) === 1;
$tokenHash = $tokenIsValid ? hash('sha256', $receiptToken) : '';
$stmt = mysqli_prepare($conn, 'SELECT p.id_pendaftaran, p.th_ajaran, p.tgl_daftar, p.nm_peserta, u.nama_unit, p.token_bukti, p.token_whatsapp FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON p.kode_unit = u.kode_unit WHERE p.id_pendaftaran = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 's', $registrationId);
mysqli_stmt_execute($stmt);
$registration = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

start_app_session();
$tokenAuthorized = $registration && $tokenIsValid && (hash_equals($registration['token_bukti'], $tokenHash) || hash_equals((string) ($registration['token_whatsapp'] ?? ''), $tokenHash));
$adminAuthorized = !empty($_SESSION['admin_id']);
if (!$registration || (!$tokenAuthorized && !$adminAuthorized)) {
    http_response_code(404);
    exit('Bukti pendaftaran tidak ditemukan.');
}

$dateRegistered = date('d-m-Y', strtotime($registration['tgl_daftar']));
$html = '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>
    @page { margin: 34px 42px; }
    body { color: #182a24; font-family: "DejaVu Sans", sans-serif; font-size: 11px; }
    .topline { height: 7px; background: #155b46; margin-bottom: 28px; }
    .brand { color: #155b46; font-size: 10px; font-weight: bold; letter-spacing: 1px; }
    h1 { margin: 9px 0 5px; font-size: 22px; }
    .subtitle { margin: 0; color: #63716b; font-size: 10px; }
    .code { margin: 26px 0 20px; padding: 17px; background: #eef4ef; border-left: 4px solid #c45b3c; }
    .code-label { color: #63716b; font-size: 8px; font-weight: bold; }
    .code-value { margin-top: 7px; color: #155b46; font-size: 19px; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 11px 8px; border-bottom: 1px solid #dce5de; vertical-align: top; }
    td:first-child { width: 34%; color: #63716b; }
    td:last-child { font-weight: bold; }
    .note { margin-top: 26px; padding: 12px 14px; color: #63716b; background: #f7f8f5; font-size: 9px; line-height: 1.6; }
    .footer { margin-top: 30px; color: #63716b; font-size: 8px; }
</style></head><body>
    <div class="topline"></div>
    <div class="brand">YAYASAN WAKAF CENDEKIA TAKENGON</div>
    <h1>Bukti Pendaftaran</h1>
    <p class="subtitle">Penerimaan Peserta Didik Baru</p>
    <div class="code"><div class="code-label">KODE PENDAFTARAN</div><div class="code-value">'.h($registration['id_pendaftaran']).'</div></div>
    <table>
        <tr><td>Nama peserta didik</td><td>'.h($registration['nm_peserta']).'</td></tr>
        <tr><td>Unit pendidikan</td><td>'.h($registration['nama_unit'] ?? 'Belum ditentukan').'</td></tr>
        <tr><td>Tahun ajaran</td><td>'.h($registration['th_ajaran']).'</td></tr>
        <tr><td>Tanggal pendaftaran</td><td>'.h($dateRegistered).'</td></tr>
    </table>
    <div class="note">Dokumen ini merupakan ringkasan bukti pendaftaran. Data identitas kependudukan, alamat lengkap, dan data keluarga tidak dicantumkan pada dokumen ini.</div>
    <div class="footer">Jalan Pertamina–Kebet, Kecamatan Bebesen, Kabupaten Aceh Tengah · 0821-8164-9543</div>
</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('bukti-pendaftaran-'.$registrationId.'.pdf', ['Attachment' => true]);
exit;