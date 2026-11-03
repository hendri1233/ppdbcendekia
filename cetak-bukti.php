<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'cms_helpers.php';
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
$stmt = mysqli_prepare($conn, 'SELECT p.id_pendaftaran, p.th_ajaran, p.tgl_daftar, p.nm_peserta, p.tmp_lahir, p.tgl_lahir, p.jenis_kelamin, p.jalur_pendaftaran, p.status_ppdb, p.status_pembayaran, p.data_diperiksa_pada, '.(ppdb_column_exists($conn, 'tb_pendaftaran', 'tingkat_kelas') ? 'p.tingkat_kelas, ' : '').'u.nama_unit, u.jenjang, u.npsn, u.email AS email_unit, g.link_grup, p.token_bukti, p.token_whatsapp, p.token_formulir FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON p.kode_unit = u.kode_unit LEFT JOIN tb_unit_whatsapp_groups g ON g.kode_unit = p.kode_unit WHERE p.id_pendaftaran = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 's', $registrationId);
mysqli_stmt_execute($stmt);
$registration = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

start_app_session();
$tokenAuthorized = $registration && $tokenIsValid && (hash_equals((string) $registration['token_bukti'], $tokenHash) || hash_equals((string) ($registration['token_whatsapp'] ?? ''), $tokenHash) || hash_equals((string) ($registration['token_formulir'] ?? ''), $tokenHash));
$adminAuthorized = !empty($_SESSION['admin_id']);
if (!$registration || (!$tokenAuthorized && !$adminAuthorized)) {
    http_response_code(404);
    exit('Bukti pendaftaran tidak ditemukan.');
}

if ($registration['status_ppdb'] === 'diterima') {
    // Nama pimpinan mengikuti CMS; gambar tanda tangan dan stempel dipasang bila berkasnya tersedia.
    $leaderName = cms_setting($conn, 'leader_name', 'Ilawarni');
    $leaderRole = cms_setting($conn, 'leader_role', 'Pimpinan Yayasan');
    $imageTag = static function (string $path, string $class): string {
        return is_file($path) ? '<img class="'.$class.'" src="data:image/jpeg;base64,'.base64_encode((string) file_get_contents($path)).'" alt="">' : '';
    };
    $logoTag = $imageTag(__DIR__.'/img/logo-pdf.jpg', 'logo');
    $watermarkTag = $imageTag(__DIR__.'/img/logo-pdf.jpg', 'watermark');
    $signatureTag = $imageTag(__DIR__.'/assets/legal/ttd-stempel.jpg', 'signature');
    $issuedOn = ppdb_format_date_id(substr((string) ($registration['data_diperiksa_pada'] ?: date('Y-m-d')), 0, 10));
    $letterNumber = ppdb_acceptance_number($registration);
    $birthPlace = trim((string) $registration['tmp_lahir']);
    $unitName = (string) ($registration['nama_unit'] ?? '-');
    $npsn = trim((string) ($registration['npsn'] ?? ''));

    $parentName = '';
    if (ppdb_column_exists($conn, 'tb_detail_dapodik', 'ayah_nama')) {
        $parentQuery = mysqli_prepare($conn, 'SELECT ayah_nama,ibu_nama,wali_nama FROM tb_detail_dapodik WHERE id_pendaftaran=? LIMIT 1');
        mysqli_stmt_bind_param($parentQuery, 's', $registrationId);
        mysqli_stmt_execute($parentQuery);
        $parents = mysqli_fetch_assoc(mysqli_stmt_get_result($parentQuery)) ?: [];
        mysqli_stmt_close($parentQuery);
        $parentName = implode(' / ', array_filter([trim((string) ($parents['ayah_nama'] ?? '')), trim((string) ($parents['ibu_nama'] ?? ''))]));
        if ($parentName === '') {
            $parentName = trim((string) ($parents['wali_nama'] ?? ''));
        }
    }

    $rows = [
        ['Nama peserta didik', '<strong>'.h($registration['nm_peserta']).'</strong>'],
        ['Tempat, tanggal lahir', h(($birthPlace !== '' ? $birthPlace.', ' : '').ppdb_format_date_id($registration['tgl_lahir']))],
        ['Jenis kelamin', h(ppdb_jenis_kelamin_label($registration['jenis_kelamin']))],
    ];
    if ($parentName !== '') {
        $rows[] = ['Nama orang tua/wali', h($parentName)];
    }
    $rows[] = ['Kode pendaftaran', h($registration['id_pendaftaran'])];
    $rows[] = ['Unit pendidikan', h($unitName.(!empty($registration['tingkat_kelas']) ? ' · '.$registration['tingkat_kelas'] : '')).($npsn !== '' ? ' (NPSN '.h($npsn).')' : '')];
    $rows[] = ['Tahun ajaran', h($registration['th_ajaran'])];
    $rows[] = ['Status pembayaran', $registration['status_pembayaran'] === 'terverifikasi' ? 'Terverifikasi' : 'Bukti transfer dalam pemeriksaan panitia'];
    $rowsHtml = '';
    foreach ($rows as [$label, $value]) {
        $rowsHtml .= '<tr><td class="label">'.h($label).'</td><td class="colon">:</td><td>'.$value.'</td></tr>';
    }
    $rulesHtml = '';
    foreach (ppdb_acceptance_rules($registration['jenjang'] ?? null) as $rule) {
        $rulesHtml .= '<li>'.h($rule).'</li>';
    }
    $groupLink = trim((string) ($registration['link_grup'] ?? ''));
    $verifyUrl = ppdb_public_base_url().'/verifikasi-surat.php?kode='.rawurlencode($registrationId).'&sig='.ppdb_acceptance_signature($registration);
    $html = '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>
        @page { margin: 0; }
        body { margin: 0; color: #1f2622; font-family: "Source Serif", "DejaVu Serif", serif; font-size: 11.8px; line-height: 1.26; }
        .watermark { position: fixed; top: 320px; left: 197px; width: 400px; height: 400px; opacity: 0.05; }
        .top-band { position: fixed; top: 0; left: 0; width: 100%; height: 9px; background: #123f32; }
        .page { padding: 30px 66px 0; }
        .kop { width: 100%; border-collapse: collapse; }
        .kop td { vertical-align: middle; }
        .kop-logo { width: 84px; }
        .logo { width: 76px; height: 76px; }
        .kop-text { text-align: center; padding-right: 84px; }
        .kop-org { color: #123f32; font-size: 18.5px; font-weight: bold; letter-spacing: .3px; line-height: 1.2; }
        .kop-unit { color: #123f32; font-size: 12.5px; font-weight: bold; }
        .kop-meta { margin-top: 2px; color: #4c5852; font-size: 8.8px; line-height: 1.1; }
        .kop-line { margin-top: 8px; border-top: 2.2px solid #123f32; border-bottom: .6px solid #123f32; height: 2px; }
        .title { margin-top: 12px; color: #123f32; font-size: 15.5px; font-weight: bold; text-align: center; letter-spacing: .6px; text-decoration: underline; }
        .number { text-align: center; font-size: 11px; }
        p { margin: 8px 0 0; text-align: justify; }
        .data { width: 100%; margin: 6px 0 0 14px; border-collapse: collapse; }
        .data td { padding: 0.5px 0; vertical-align: top; }
        .label { width: 140px; }
        .colon { width: 12px; }
        .verdict { margin-top: 8px; padding: 6px 12px; color: #123f32; text-align: center; font-weight: bold; font-size: 12.5px; border-top: 1px solid #123f32; border-bottom: 1px solid #123f32; }
        h2 { margin: 10px 0 0; color: #123f32; font-size: 11.5px; font-weight: bold; }
        ol { margin: 3px 0 0; padding-left: 18px; }
        li { margin-bottom: 1.5px; text-align: justify; }
        .sign { width: 100%; margin-top: 10px; border-collapse: collapse; }
        .sign td { vertical-align: top; }
        .verify { width: 56%; padding-right: 26px; color: #4c5852; font-size: 8.5px; line-height: 1.1; }
        .verify strong { color: #123f32; }
        .verify a { color: #123f32; word-break: break-all; }
        .signer { text-align: left; }
        .signature { width: 130px; height: 58px; }
        .signature-space { height: 54px; }
        .signer-name { font-weight: bold; text-decoration: underline; }
        .footer { position: fixed; left: 66px; width: 662px; bottom: 16px; padding-top: 4px; color: #6a756f; border-top: .6px solid #b9c4be; font-size: 7.8px; text-align: center; }
    </style></head><body>
        <div class="top-band"></div>
        '.$watermarkTag.'
        <div class="page">
            <table class="kop"><tr>
                <td class="kop-logo">'.$logoTag.'</td>
                <td class="kop-text">
                    <div class="kop-org">YAYASAN WAKAF CENDEKIA TAKENGON</div>
                    <div class="kop-unit">Panitia Penerimaan Peserta Didik Baru · '.h($unitName).'</div>
                    <div class="kop-meta">Badan Hukum SK Kemenkumham AHU-0000410.AH.01.12 Tahun 2022 · Nazhir Wakaf Uang BWI Kode 3.3.00369<br>Jalan Pertamina–Kebet, Kecamatan Bebesen, Kabupaten Aceh Tengah, Aceh<br>WhatsApp '.h(ppdb_admin_whatsapp_display()).' · wakafcendekiatakengon@gmail.com · cendekiatakengon.or.id</div>
                </td>
            </tr></table>
            <div class="kop-line"></div>

            <div class="title">SURAT KETERANGAN DITERIMA</div>
            <div class="number">Nomor: '.h($letterNumber).'</div>

            <p>Yang bertanda tangan di bawah ini, '.h($leaderRole).', berdasarkan hasil pendaftaran SPMB Tahun Ajaran '.h($registration['th_ajaran']).' dan kelengkapan data yang disampaikan pendaftar, dengan ini menerangkan bahwa:</p>
            <table class="data">'.$rowsHtml.'</table>
            <div class="verdict">DINYATAKAN DITERIMA SEBAGAI PESERTA DIDIK BARU</div>

            <h2>Ketentuan dan Tata Tertib</h2>
            <p style="margin-top:2px">Dengan diterimanya peserta didik tersebut, peserta didik dan orang tua/wali bersedia mematuhi ketentuan berikut serta peraturan sekolah yang berlaku:</p>
            <ol>'.$rulesHtml.'</ol>
            <p style="margin-top:5px">Pelanggaran terhadap ketentuan di atas ditindaklanjuti melalui pembinaan sesuai peraturan sekolah. Surat ini dapat ditinjau kembali atau dibatalkan apabila data maupun bukti pembayaran yang disampaikan terbukti tidak benar.'.($groupLink !== '' ? ' Informasi lanjutan disampaikan melalui <a href="'.h($groupLink).'" style="color:#123f32">grup WhatsApp unit</a>.' : '').'</p>
            <p style="margin-top:5px">Demikian surat keterangan ini dibuat untuk dipergunakan sebagaimana mestinya.</p>

            <table class="sign"><tr>
                <td class="verify"><strong>Verifikasi keaslian surat</strong><br>Keaslian surat ini dapat diperiksa melalui tautan berikut:<br><a href="'.h($verifyUrl).'">'.h($verifyUrl).'</a></td>
                <td class="signer">Takengon, '.h($issuedOn).'<br>'.h($leaderRole).',<br>'.($signatureTag !== '' ? $signatureTag : '<div class="signature-space"></div>').'<div class="signer-name">'.h($leaderName).'</div></td>
            </tr></table>
        </div>
        <div class="footer">Surat ini diterbitkan melalui sistem SPMB Yayasan Wakaf Cendekia Takengon · Nomor '.h($letterNumber).'</div>
    </body></html>';

    // Metrik font disimpan di folder sementara karena folder vendor belum tentu dapat ditulis.
    $fontDir = sys_get_temp_dir().'/ppdb-dompdf-fonts';
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Serif');
    $options->set('chroot', __DIR__);
    if (is_dir($fontDir) || @mkdir($fontDir, 0755, true)) {
        $options->set('fontDir', $fontDir);
        $options->set('fontCache', $fontDir);
    }
    $dompdf = new Dompdf($options);
    $fontMetrics = $dompdf->getFontMetrics();
    foreach ([
        ['normal', 'normal', 'SourceSerif4-400.ttf'],
        ['bold', 'normal', 'SourceSerif4-700.ttf'],
        ['normal', 'italic', 'SourceSerif4-400italic.ttf'],
    ] as [$weight, $style, $file]) {
        $styleKey = $fontMetrics->getType($weight.' '.$style);
        $installed = $fontMetrics->getFamily('source serif')[$styleKey] ?? '';
        if ($installed === '' || !is_file($installed.'.ttf')) {
            @$fontMetrics->registerFont(['family' => 'Source Serif', 'weight' => $weight, 'style' => $style], __DIR__.'/assets/fonts/'.$file);
        }
    }
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('surat-keterangan-diterima-'.$registrationId.'.pdf', ['Attachment' => true]);
    exit;
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
    <div class="footer"><strong>Yayasan Wakaf Cendekia Takengon</strong><br>Jalan Pertamina–Kebet, Kecamatan Bebesen, Kabupaten Aceh Tengah<br>WhatsApp Admin SPMB: 0851 8483 9694</div>
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
