<?php
require_once __DIR__.'/app_helpers.php';
require_once __DIR__.'/koneksi.php';
require_super_admin();
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

// Logo disematkan langsung; PNG transparan butuh GD, jadi dilewati bila GD tidak tersedia.
$logoFile = __DIR__.'/img/logo-display.png';
$logoTag = extension_loaded('gd') && is_file($logoFile)
    ? '<img class="logo" src="data:image/png;base64,'.base64_encode((string) file_get_contents($logoFile)).'" alt="">'
    : '';
$unitName = ppdb_unit_display_name($unit['nama_unit']);
$internalUrl = ppdb_public_base_url().'/daftar.php?internal=1';

$html = '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>
    @page { margin: 40px 44px 54px; }
    body { color: #0f172a; font-family: Helvetica, Arial, sans-serif; font-size: 11px; line-height: 1.45; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: middle; }
    .head .logo-cell { width: 56px; }
    .logo { width: 46px; height: 46px; }
    .org { color: #1d4ed8; font-size: 10px; font-weight: bold; letter-spacing: 0.6px; }
    h1 { margin: 2px 0 0; color: #0c1f4a; font-size: 21px; line-height: 1.2; }
    .rule { height: 3px; margin: 12px 0 14px; background: #1d4ed8; }
    .facts { margin-bottom: 12px; }
    .facts td { width: 25%; padding: 9px 12px; background: #eff6ff; border-right: 3px solid #fff; vertical-align: top; }
    .facts td.last { border-right: 0; }
    .facts span { display: block; color: #475569; font-size: 9px; }
    .facts strong { display: block; margin-top: 2px; color: #0c1f4a; font-size: 12px; }
    .unit-name { margin: 0 0 10px; color: #0c1f4a; font-size: 14px; font-weight: bold; }
    .note { margin-bottom: 14px; padding: 9px 12px; color: #334155; background: #f8fafc; border-left: 3px solid #1d4ed8; font-size: 10px; }
    .tokens thead { display: table-header-group; }
    .tokens tr { page-break-inside: avoid; }
    .tokens th { padding: 8px 10px; color: #fff; background: #0c1f4a; font-size: 10px; font-weight: bold; text-align: left; border: 1px solid #0c1f4a; }
    .tokens td { height: 24px; padding: 3px 10px; border: 1px solid #cbd5e1; vertical-align: middle; }
    .tokens tbody tr.alt td { background: #f8fafc; }
    .tokens .col-no { width: 34px; text-align: center; }
    .tokens td.col-no { color: #475569; }
    .tokens .col-token { width: 150px; }
    .tokens td.col-token { color: #0c1f4a; font-family: "DejaVu Sans Mono", monospace; font-size: 13px; font-weight: bold; letter-spacing: 0.5px; }
</style></head><body>
    <table class="head"><tr>
        '.($logoTag !== '' ? '<td class="logo-cell">'.$logoTag.'</td>' : '').'
        <td><div class="org">YAYASAN WAKAF CENDEKIA TAKENGON</div><h1>Token Pendaftaran Internal SDM</h1></td>
    </tr></table>
    <div class="rule"></div>
    <p class="unit-name">'.h($unitName).'</p>
    <table class="facts"><tr>
        <td><span>Jenjang</span><strong>'.h($unit['jenjang']).'</strong></td>
        <td><span>NPSN</span><strong>'.h($unit['npsn']).'</strong></td>
        <td><span>Tahun ajaran</span><strong>'.h($academicYear).'</strong></td>
        <td class="last"><span>Token aktif</span><strong>'.count($tokens).' kode</strong></td>
    </tr></table>
    <div class="note"><strong>Dokumen rahasia.</strong> Satu kode hanya untuk satu pendaftar dan hanya dapat digunakan sekali. Tulis nama penerima di samping kode, lalu bagikan kode secara pribadi. Kode diketik persis seperti tertulis, termasuk huruf besar dan kecilnya, di '.h($internalUrl).'</div>
    <table class="tokens"><thead><tr><th class="col-no">No.</th><th class="col-token">Kode token</th><th>Nama penerima</th></tr></thead><tbody>';
foreach ($tokens as $index => $token) {
    $html .= '<tr'.($index % 2 === 1 ? ' class="alt"' : '').'><td class="col-no">'.($index + 1).'</td><td class="col-token">'.h($token).'</td><td></td></tr>';
}
$html .= '</tbody></table></body></html>';

$options = new Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Helvetica');
$dompdf = new Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
// Nomor halaman dan tanggal cetak di setiap halaman.
$canvas = $dompdf->getCanvas();
$footerFont = $dompdf->getFontMetrics()->getFont('Helvetica');
$footerColor = [0.28, 0.33, 0.41];
$footerY = $canvas->get_height() - 34;
$canvas->page_text(44, $footerY, 'Dicetak '.date('d-m-Y H:i').' - '.$unitName, $footerFont, 8, $footerColor);
$canvas->page_text($canvas->get_width() - 124, $footerY, 'Halaman {PAGE_NUM} dari {PAGE_COUNT}', $footerFont, 8, $footerColor);
$safeUnitCode = preg_replace('/[^A-Za-z0-9-]/', '', $unitCode);
$safeYear = str_replace('/', '-', $academicYear);
$dompdf->stream('token-internal-'.$safeUnitCode.'-'.$safeYear.'.pdf', ['Attachment' => true]);
exit;
