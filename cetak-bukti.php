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
    @page { margin: 38px 48px; }
    body { color: #1c3028; font-family: "DejaVu Sans", sans-serif; font-size: 10px; line-height: 1.55; }
    .topline { height: 8px; background: #155b46; margin: -8px 0 25px; }
    .masthead { padding-bottom: 16px; border-bottom: 1px solid #dce5de; }
    .brand { color: #155b46; font-size: 10px; font-weight: bold; letter-spacing: 1.2px; }
    .brand-sub { margin-top: 3px; color: #738078; font-size: 8px; letter-spacing: .4px; }
    h1 { margin: 24px 0 4px; color: #183c30; font-size: 24px; }
    .subtitle { margin: 0; color: #63716b; font-size: 10px; }
    .code { margin: 24px 0 20px; padding: 16px 18px; background: #eef4ef; border-left: 4px solid #c45b3c; }
    .code-label { color: #63716b; font-size: 8px; font-weight: bold; letter-spacing: .8px; }
    .code-value { margin-top: 5px; color: #155b46; font-size: 20px; font-weight: bold; letter-spacing: 1px; }
    .section-title { margin: 22px 0 7px; color: #155b46; font-size: 9px; font-weight: bold; letter-spacing: .8px; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 10px 11px; border-bottom: 1px solid #e1e8e3; vertical-align: top; }
    tr:nth-child(odd) td { background: #fafcfb; }
    td:first-child { width: 36%; color: #68776f; }
    td:last-child { color: #233a30; font-weight: bold; }
    .note { margin-top: 24px; padding: 13px 15px; color: #586960; background: #f4f7f4; border: 1px solid #e1e8e3; font-size: 8.5px; line-height: 1.65; }
    .footer { margin-top: 24px; padding-top: 12px; color: #63716b; border-top: 1px solid #dce5de; font-size: 8px; line-height: 1.7; }
    .footer strong { color: #155b46; }
</style></head><body>
    <div class="topline"></div>
    <div class="masthead"><div class="brand">YAYASAN WAKAF CENDEKIA TAKENGON</div><div class="brand-sub">PENERIMAAN PESERTA DIDIK BARU</div></div>
    <h1>Bukti Pendaftaran</h1>
    <p class="subtitle">Ringkasan registrasi awal peserta didik</p>
    <div class="code"><div class="code-label">KODE PENDAFTARAN</div><div class="code-value">'.h($registration['id_pendaftaran']).'</div></div>
    <div class="section-title">INFORMASI PENDAFTARAN</div>
    <table>
        <tr><td>Nama peserta didik</td><td>'.h($registration['nm_peserta']).'</td></tr>
        <tr><td>Unit pendidikan</td><td>'.h($registration['nama_unit'] ?? 'Belum ditentukan').'</td></tr>
        <tr><td>Tahun ajaran</td><td>'.h($registration['th_ajaran']).'</td></tr>
        <tr><td>Tanggal pendaftaran</td><td>'.h($dateRegistered).'</td></tr>
    </table>
    <div class="note"><strong>Informasi:</strong> Dokumen ini merupakan ringkasan bukti pendaftaran awal. Simpan kode pendaftaran untuk memantau proses berikutnya. Data identitas kependudukan, alamat lengkap, dan data keluarga tidak dicantumkan demi menjaga privasi.</div>
    <div class="footer"><strong>Yayasan Wakaf Cendekia Takengon</strong><br>Jalan Pertamina–Kebet, Kecamatan Bebesen, Kabupaten Aceh Tengah<br>WhatsApp Admin PPDB: 0851 8483 9694</div>
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
