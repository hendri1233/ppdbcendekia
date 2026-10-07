<?php
require_once __DIR__.'/app_helpers.php';
require_once __DIR__.'/koneksi.php';
require_admin();
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$unitCode = is_string($_GET['unit'] ?? null) ? trim($_GET['unit']) : '';
$academicYear = is_string($_GET['tahun'] ?? null) ? trim($_GET['tahun']) : '';
if (!preg_match('/^[A-Z0-9-]{1,10}$/D', $unitCode)
    || !preg_match('/^[0-9]{4}\/[0-9]{4}$/D', $academicYear)
    || (int) substr($academicYear, 0, 4) < 2027) {
    http_response_code(400);
    exit('Unit atau tahun ajaran tidak valid.');
}

$unitQuery = mysqli_prepare($conn, 'SELECT nama_unit,jenjang,npsn FROM tb_unit_pendidikan WHERE kode_unit=? LIMIT 1');
mysqli_stmt_bind_param($unitQuery, 's', $unitCode);
mysqli_stmt_execute($unitQuery);
$unit = mysqli_fetch_assoc(mysqli_stmt_get_result($unitQuery));
mysqli_stmt_close($unitQuery);
if (!$unit) {
    http_response_code(404);
    exit('Unit pendidikan tidak ditemukan.');
}

$tokenQuery = mysqli_prepare($conn, 'SELECT kode_token FROM tb_token_internal WHERE kode_unit=? AND th_ajaran=? AND used_at IS NULL AND revoked_at IS NULL ORDER BY kode_token');
mysqli_stmt_bind_param($tokenQuery, 'ss', $unitCode, $academicYear);
mysqli_stmt_execute($tokenQuery);
$tokenResult = mysqli_stmt_get_result($tokenQuery);
$tokens = [];
while ($token = mysqli_fetch_assoc($tokenResult)) {
    $tokens[] = $token['kode_token'];
}
mysqli_stmt_close($tokenQuery);
if (!$tokens) {
    http_response_code(404);
    exit('Tidak ada token aktif yang belum digunakan untuk unit dan tahun ajaran tersebut.');
}

$html = '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>
    @page { margin: 34px 42px; }
    body { color: #182a24; font-family: "DejaVu Sans", sans-serif; font-size: 10px; }
    .brand { color: #155b46; font-size: 9px; font-weight: bold; letter-spacing: 1px; }
    h1 { margin: 10px 0 5px; font-size: 20px; }
    .subtitle { margin: 0 0 18px; color: #63716b; font-size: 10px; }
    .unit { margin: 18px 0; padding: 12px 14px; background: #eef4ef; border-left: 4px solid #155b46; }
    .unit strong { display: block; margin-bottom: 5px; color: #155b46; font-size: 13px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 8px 10px; border-bottom: 1px solid #dce5de; text-align: left; }
    th { color: #63716b; font-size: 8px; text-transform: uppercase; }
    td.number { width: 12%; color: #63716b; }
    td.token { font-family: "DejaVu Sans Mono", monospace; font-size: 12px; font-weight: bold; letter-spacing: 1px; }
    .note { margin-top: 20px; padding: 11px 13px; color: #63716b; background: #f7f8f5; font-size: 9px; line-height: 1.5; }
    .footer { margin-top: 24px; color: #63716b; font-size: 8px; }
</style></head><body>
    <div class="brand">YAYASAN WAKAF CENDEKIA TAKENGON</div>
    <h1>Token Pendaftaran Internal</h1>
    <p class="subtitle">Daftar token aktif untuk dibagikan kepada SDM yang telah diverifikasi.</p>
    <div class="unit"><strong>'.h($unit['nama_unit']).'</strong>Jenjang '.h($unit['jenjang']).' · NPSN '.h($unit['npsn']).' · Tahun ajaran '.h($academicYear).'</div>
    <table><thead><tr><th>No.</th><th>Kode token</th></tr></thead><tbody>';
foreach ($tokens as $index => $token) {
    $html .= '<tr><td class="number">'.($index + 1).'</td><td class="token">'.h($token).'</td></tr>';
}
$html .= '</tbody></table>
    <div class="note">Setiap kode hanya untuk satu pendaftar dan hanya dapat digunakan sekali. Bagikan satu kode secara pribadi kepada setiap SDM yang telah diverifikasi. Jangan membagikan seluruh dokumen kepada publik.</div>
    <div class="footer">Dokumen dibuat pada '.h(date('d-m-Y H:i')).' · Tautan pendaftaran internal: daftar.php?internal=1</div>
</body></html>';

$options = new Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$safeUnitCode = preg_replace('/[^A-Za-z0-9-]/', '', $unitCode);
$safeYear = str_replace('/', '-', $academicYear);
$dompdf->stream('token-internal-'.$safeUnitCode.'-'.$safeYear.'.pdf', ['Attachment' => true]);
exit;
