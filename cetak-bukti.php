<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'cetak_pdf.php';

$registrationId = $_GET['id'] ?? '';
$receiptToken = $_GET['token'] ?? '';
if (!preg_match('/^P[0-9]{9}$/', $registrationId)) {
    http_response_code(404);
    exit('Bukti pendaftaran tidak ditemukan.');
}

$tokenIsValid = preg_match('/^[a-f0-9]{64}$/', $receiptToken) === 1;
$tokenHash = $tokenIsValid ? hash('sha256', $receiptToken) : '';
$stmt = mysqli_prepare($conn, 'SELECT p.id_pendaftaran, p.th_ajaran, p.tgl_daftar, p.pendaftar, p.nm_peserta, p.tmp_lahir, p.tgl_lahir, p.jenis_kelamin, p.jalur_pendaftaran, p.status_ppdb, p.status_pembayaran, p.data_diperiksa_pada, '.(ppdb_column_exists($conn, 'tb_pendaftaran', 'tingkat_kelas') ? 'p.tingkat_kelas, ' : '').(ppdb_column_exists($conn, 'tb_pendaftaran', 'jalur_seleksi') ? 'p.jalur_seleksi, ' : '').'u.nama_unit, u.jenjang, u.npsn, u.email AS email_unit, pr.phone AS telepon_unit, g.link_grup, p.token_bukti, p.token_whatsapp, p.token_formulir FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON p.kode_unit = u.kode_unit LEFT JOIN tb_unit_whatsapp_groups g ON g.kode_unit = p.kode_unit LEFT JOIN tb_cms_unit_profiles pr ON pr.kode_unit = p.kode_unit WHERE p.id_pendaftaran = ? LIMIT 1');
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

// Kop memakai nomor admin unit dari Profil unit; bila belum diisi memakai nomor yayasan.
$unitAdminPhone = trim((string) ($registration['telepon_unit'] ?? '')) ?: ppdb_admin_whatsapp_display();

if ($registration['status_ppdb'] === 'diterima') {
    $watermarkTag = cetak_image(__DIR__.'/img/logo-pdf.jpg', 'watermark');
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
    $verifyUrl = ppdb_public_base_url().'/verifikasi-surat?kode='.rawurlencode($registrationId).'&sig='.ppdb_acceptance_signature($registration);
    $html = '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>
        @page { margin: 0; }
        body { margin: 0; color: #1f2622; font-family: "Source Serif", "DejaVu Serif", serif; font-size: 11.8px; line-height: 1.26; }
        .watermark { position: fixed; top: 320px; left: 197px; width: 400px; height: 400px; opacity: 0.05; }
        .top-band { position: fixed; top: 0; left: 0; width: 100%; height: 9px; background: #123f32; }
        .page { padding: 30px 66px 0; }
        '.cetak_kop_css().'
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
        .signer-org { color: #123f32; font-weight: bold; }
        .footer { position: fixed; left: 66px; width: 662px; bottom: 16px; padding-top: 4px; color: #6a756f; border-top: .6px solid #b9c4be; font-size: 7.8px; text-align: center; }
    </style></head><body>
        <div class="top-band"></div>
        '.$watermarkTag.'
        <div class="page">
            '.cetak_kop($unitName, $unitAdminPhone).'

            <div class="title">SURAT KETERANGAN PENDAFTARAN</div>
            <div class="number">Nomor: '.h($letterNumber).'</div>

            <p>Yayasan Wakaf Cendekia Takengon, berdasarkan hasil pendaftaran SPMB Tahun Ajaran '.h($registration['th_ajaran']).' dan kelengkapan data yang disampaikan pendaftar, dengan ini menerangkan bahwa:</p>
            <table class="data">'.$rowsHtml.'</table>
            <div class="verdict">TERDAFTAR SEBAGAI CALON PESERTA DIDIK BARU</div>

            <h2>Ketentuan dan Tata Tertib</h2>
            <p style="margin-top:2px">Apabila peserta didik tersebut diterima, peserta didik dan orang tua/wali bersedia mematuhi ketentuan berikut serta peraturan sekolah yang berlaku:</p>
            <ol>'.$rulesHtml.'</ol>
            <p style="margin-top:5px">Pelanggaran terhadap ketentuan di atas ditindaklanjuti melalui pembinaan sesuai peraturan sekolah. Surat ini dapat ditinjau kembali atau dibatalkan apabila data maupun bukti pembayaran yang disampaikan terbukti tidak benar.'.($groupLink !== '' ? ' Informasi lanjutan disampaikan melalui <a href="'.h($groupLink).'" style="color:#123f32">grup WhatsApp unit</a>.' : '').'</p>
            <p style="margin-top:5px">Demikian surat keterangan ini dibuat untuk dipergunakan sebagaimana mestinya.</p>

            <table class="sign"><tr>
                <td class="verify"><strong>Verifikasi keaslian surat</strong><br>Surat ini diterbitkan secara elektronik tanpa tanda tangan. Keasliannya dapat diperiksa melalui tautan berikut:<br><a href="'.h($verifyUrl).'">'.h($verifyUrl).'</a></td>
                <td class="signer">Takengon, '.h($issuedOn).'<br>Atas nama,<br><span class="signer-org">Yayasan Wakaf Cendekia Takengon</span></td>
            </tr></table>
        </div>
        <div class="footer">Surat ini diterbitkan melalui sistem SPMB Yayasan Wakaf Cendekia Takengon · Nomor '.h($letterNumber).'</div>
    </body></html>';

    cetak_stream($html, 'surat-keterangan-pendaftaran-'.$registrationId.'.pdf');
}

$unitName = (string) ($registration['nama_unit'] ?? 'Belum ditentukan');
$birthPlace = trim((string) $registration['tmp_lahir']);
$rows = [
    ['Nama peserta didik', '<strong>'.h($registration['nm_peserta']).'</strong>'],
    ['Tempat, tanggal lahir', h(($birthPlace !== '' ? $birthPlace.', ' : '').ppdb_format_date_id($registration['tgl_lahir']))],
    ['Jenis kelamin', h(ppdb_jenis_kelamin_label($registration['jenis_kelamin']))],
    ['Didaftarkan oleh', h(ppdb_pendaftar_label($registration['pendaftar']))],
    ['Unit pendidikan', h($unitName.(!empty($registration['tingkat_kelas']) ? ' · '.$registration['tingkat_kelas'] : ''))],
    ['Jalur pendaftaran', h(ppdb_jalur_label($registration))],
    ['Tahun ajaran', h($registration['th_ajaran'])],
    ['Tanggal pendaftaran', h(ppdb_format_date_id($registration['tgl_daftar']))],
];
$rowsHtml = '';
foreach ($rows as [$label, $value]) {
    $rowsHtml .= '<tr><td class="label">'.h($label).'</td><td class="colon">:</td><td>'.$value.'</td></tr>';
}
$html = '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>
    @page { margin: 0; }
    body { margin: 0; color: #1f2622; font-family: "Source Serif", "DejaVu Serif", serif; font-size: 11.8px; line-height: 1.3; }
    .watermark { position: fixed; top: 320px; left: 197px; width: 400px; height: 400px; opacity: 0.05; }
    .top-band { position: fixed; top: 0; left: 0; width: 100%; height: 9px; background: #123f32; }
    .page { padding: 30px 66px 0; }
    '.cetak_kop_css().'
    .title { margin-top: 18px; color: #123f32; font-size: 15.5px; font-weight: bold; text-align: center; letter-spacing: .6px; text-decoration: underline; }
    .subtitle { margin-top: 2px; color: #4c5852; font-size: 11px; text-align: center; }
    .code { margin: 18px 0 16px; padding: 12px 16px; text-align: center; border-top: 1px solid #123f32; border-bottom: 1px solid #123f32; }
    .code-label { color: #4c5852; font-size: 9.5px; letter-spacing: 1px; }
    .code-value { margin-top: 1px; color: #123f32; font-size: 24px; font-weight: bold; letter-spacing: 2px; }
    p { margin: 0 0 7px; text-align: justify; }
    .data { width: 100%; margin: 2px 0 0 14px; border-collapse: collapse; }
    .data td { padding: 2px 0; vertical-align: top; }
    .label { width: 150px; }
    .colon { width: 12px; }
    h2 { margin: 18px 0 4px; color: #123f32; font-size: 12px; font-weight: bold; }
    ol { margin: 3px 0 0; padding-left: 18px; }
    li { margin-bottom: 3px; text-align: justify; }
    a { color: #123f32; }
    .note { margin-top: 16px; padding: 9px 12px; color: #4c5852; font-size: 9.5px; background: #f4f6f4; border-left: 2.5px solid #123f32; }
    .footer { position: fixed; left: 66px; width: 662px; bottom: 16px; padding-top: 4px; color: #6a756f; border-top: .6px solid #b9c4be; font-size: 7.8px; text-align: center; }
</style></head><body>
    <div class="top-band"></div>
    '.cetak_image(__DIR__.'/img/logo-pdf.jpg', 'watermark').'
    <div class="page">
        '.cetak_kop($unitName, $unitAdminPhone).'
        <div class="title">BUKTI PENDAFTARAN</div>
        <div class="subtitle">Registrasi awal peserta didik baru</div>
        <div class="code"><div class="code-label">KODE PENDAFTARAN</div><div class="code-value">'.h($registration['id_pendaftaran']).'</div></div>
        <p>Dokumen ini menerangkan bahwa registrasi awal peserta didik berikut telah tercatat pada sistem SPMB Yayasan Wakaf Cendekia Takengon:</p>
        <table class="data">'.$rowsHtml.'</table>
        <h2>Tahap berikutnya</h2>
        <ol>
            <li>Lakukan pembayaran pendaftaran dan unggah bukti transfer, dengan mencantumkan kode pendaftaran pada berita transfer.</li>
            <li>Konfirmasi data, baca brosur, rincian biaya, dan SOP, lalu lengkapi formulir dan dokumen persyaratan.</li>
            <li>Setelah formulir terkirim, hasil pendaftaran dan surat keterangan pendaftaran tampil pada halaman pendaftaran.</li>
        </ol>
        <p style="margin-top:9px">Untuk melanjutkan, mohon menunggu pemeriksaan. Panitia akan mengonfirmasi proses berikutnya melalui WhatsApp.</p>
        <div class="note">Dokumen ini bukan bukti penerimaan. NIK, alamat lengkap, dan data keluarga tidak dicantumkan demi menjaga privasi.</div>
    </div>
    <div class="footer">Diterbitkan melalui sistem SPMB Yayasan Wakaf Cendekia Takengon · Kode '.h($registration['id_pendaftaran']).'</div>
</body></html>';

cetak_stream($html, 'bukti-pendaftaran-'.$registrationId.'.pdf');
