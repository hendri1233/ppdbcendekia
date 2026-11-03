<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';

$code = strtoupper(trim((string) ($_GET['kode'] ?? '')));
$signature = trim((string) ($_GET['sig'] ?? ''));
$letter = null;
if (preg_match('/^P[0-9]{9}$/', $code) && preg_match('/^[a-f0-9]{20}$/', $signature)) {
    $stmt = mysqli_prepare($conn, 'SELECT p.id_pendaftaran,p.nm_peserta,p.th_ajaran,p.status_ppdb,p.data_diperiksa_pada,p.token_bukti,u.nama_unit,u.jenjang FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit WHERE p.id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $code);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($row && hash_equals(ppdb_acceptance_signature($row), $signature)) {
        $letter = $row;
    }
}
$isValid = $letter && $letter['status_ppdb'] === 'diterima';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
if (!$letter) {
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Verifikasi surat keterangan diterima | SPMB Cendekia</title>
    <link rel="stylesheet" href="css/tokens.css?v=<?php echo (int) @filemtime(__DIR__.'/css/tokens.css'); ?>">
    <link rel="stylesheet" href="css/result.css?v=<?php echo (int) filemtime(__DIR__.'/css/result.css'); ?>">
    <link rel="icon" type="image/x-icon" href="img/favicons/favicon.ico" sizes="16x16 32x32 48x48">
</head>
<body>
<main class="result-shell">
    <a class="result-brand" href="./">
        <img class="brand-logo" src="img/logo-display.png" alt="" width="48" height="47">
        <span><strong>Wakaf Cendekia</strong><small>Takengon · SPMB</small></span>
    </a>
    <section class="result-panel">
        <p class="result-eyebrow">Verifikasi surat keterangan diterima</p>
        <?php if ($isValid) { ?>
            <div class="payment-success" role="status"><strong>Surat ini sah dan masih berlaku.</strong> Data di bawah sesuai dengan catatan Panitia SPMB.</div>
        <?php } elseif ($letter) { ?>
            <div class="payment-error" role="status"><strong>Surat ini tidak berlaku.</strong> Penerimaan peserta ini telah dibatalkan atau sedang ditinjau panitia.</div>
        <?php } else { ?>
            <div class="payment-error" role="alert"><strong>Surat tidak dapat diverifikasi.</strong> Tautan tidak sesuai dengan data Panitia SPMB.</div>
        <?php } ?>
        <?php if ($letter) { ?>
            <dl class="summary-list">
                <div><dt>Nomor surat</dt><dd><?php echo h(ppdb_acceptance_number($letter)); ?></dd></div>
                <div><dt>Nama peserta didik</dt><dd><?php echo h($letter['nm_peserta']); ?></dd></div>
                <div><dt>Unit pendidikan</dt><dd><?php echo h($letter['nama_unit'] ?? '-'); ?></dd></div>
                <div><dt>Tahun ajaran</dt><dd><?php echo h($letter['th_ajaran']); ?></dd></div>
            </dl>
        <?php } ?>
        <div class="admin-contact">
            <span>Ada pertanyaan tentang surat ini? Hubungi admin SPMB.</span>
            <a class="whatsapp-button" href="<?php echo h(ppdb_admin_whatsapp_url()); ?>" target="_blank" rel="noopener noreferrer"><span><?php echo h(ppdb_admin_whatsapp_display()); ?></span></a>
        </div>
    </section>
    <footer>Yayasan Wakaf Cendekia Takengon</footer>
</main>
</body>
</html>
