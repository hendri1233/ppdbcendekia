<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';

$registrationId = $_GET['id'] ?? '';
$receiptToken = $_GET['token'] ?? '';
$registration = null;
if (preg_match('/^P[0-9]{9}$/', $registrationId) === 1 && preg_match('/^[a-f0-9]{64}$/', $receiptToken) === 1) {
    $tokenHash = hash('sha256', $receiptToken);
    $stmt = mysqli_prepare($conn, 'SELECT p.id_pendaftaran,p.th_ajaran,p.tgl_daftar,p.nm_peserta,p.tgl_lahir,p.jenis_kelamin,p.pendaftar,p.status_ppdb,p.nomor_antrian,p.token_bukti,u.nama_unit FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON p.kode_unit=u.kode_unit WHERE p.id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
    mysqli_stmt_execute($stmt);
    $registration = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$registration || !hash_equals((string) $registration['token_bukti'], $tokenHash)) {
        $registration = null;
    }
}
$dapodikAge = $registration ? ppdb_dapodik_age($registration['tgl_lahir'], $registration['th_ajaran']) : null;
$adminWhatsAppMessage = $registration
    ? 'Assalamu’alaikum, saya ingin bertanya mengenai pendaftaran ananda '.$registration['nm_peserta'].' dengan kode '.$registration['id_pendaftaran'].'.'
    : 'Assalamu’alaikum, saya ingin bertanya mengenai pendaftaran PPDB Cendekia.';
$adminWhatsAppUrl = ppdb_admin_whatsapp_url($adminWhatsAppMessage);
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123d31">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $registration ? 'Registrasi awal berhasil' : 'Bukti tidak ditemukan'; ?> | PPDB Cendekia</title>
    <link rel="icon" href="img/logo-display.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Source+Serif+4:opsz,wght@8..60,600;8..60,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/tokens.css">
    <link rel="stylesheet" href="css/result.css?v=20261017-2">
</head>
<body>
<main class="result-shell">
    <a class="result-brand" href="index.php">
        <img class="brand-logo" src="img/logo-display.png" alt="" width="48" height="47">
        <span><strong>Wakaf Cendekia</strong><small>Takengon · PPDB</small></span>
    </a>

    <?php if ($registration) { ?>
        <section class="result-panel">
            <div class="success-symbol" aria-hidden="true">&check;</div>
            <p class="result-eyebrow">Registrasi awal tercatat</p>
            <h1>Terima kasih,<br><?php echo h($registration['nm_peserta']); ?>.</h1>
            <p class="result-description">Data awal ananda sudah kami terima. Panitia akan menghubungi nomor WhatsApp pendaftar untuk instruksi pembayaran.</p>

            <div class="registration-code" role="group" aria-label="Kode pendaftaran">
                <span>Kode pendaftaran</span>
                <strong><?php echo h($registration['id_pendaftaran']); ?></strong>
            </div>
            <p class="code-hint">Simpan kode ini. Kode diperlukan untuk membuka formulir lengkap setelah pembayaran disetujui.</p>

            <dl class="summary-list">
                <div><dt>Nama lengkap ananda</dt><dd><?php echo h($registration['nm_peserta']); ?></dd></div>
                <div><dt>Tanggal lahir</dt><dd><?php echo h(ppdb_format_date_id($registration['tgl_lahir'])); ?></dd></div>
                <div><dt>Umur ananda terdeteksi berdasarkan Dapodik dari tanggal <?php echo h($dapodikAge['reference_date'] ?? '-'); ?> berumur:</dt><dd><?php echo h(ppdb_dapodik_age_label($dapodikAge)); ?></dd></div>
                <div><dt>Jenis kelamin</dt><dd><?php echo h(ppdb_jenis_kelamin_label($registration['jenis_kelamin'])); ?></dd></div>
                <div><dt>Didaftarkan oleh</dt><dd><?php echo h(ppdb_pendaftar_label($registration['pendaftar'])); ?></dd></div>
                <div><dt>Unit pendidikan</dt><dd><?php echo h($registration['nama_unit'] ?? 'Belum ditentukan'); ?></dd></div>
                <div><dt>Tahun ajaran</dt><dd><?php echo h($registration['th_ajaran']); ?></dd></div>
                <div><dt>Nomor antrean</dt><dd><?php echo $registration['nomor_antrian'] ? '#'.(int) $registration['nomor_antrian'] : 'Data arsip'; ?></dd></div>
                <div><dt>Tanggal daftar</dt><dd><?php echo h(ppdb_format_date_id($registration['tgl_daftar'])); ?></dd></div>
            </dl>

            <ol class="next-steps" aria-label="Langkah berikutnya">
                <li><span aria-hidden="true">1</span><div><strong>Menunggu panggilan panitia</strong><small>Panitia menghubungi lewat WhatsApp dengan tautan pembayaran.</small></div></li>
                <li><span aria-hidden="true">2</span><div><strong>Bayar dan unggah bukti</strong><small>Unggah bukti transfer pada halaman status pendaftaran.</small></div></li>
                <li><span aria-hidden="true">3</span><div><strong>Isi formulir lengkap</strong><small>Gunakan kode pendaftaran di atas beserta tautan dari WhatsApp.</small></div></li>
            </ol>

            <a class="download-button" href="status-pendaftaran.php?id=<?php echo rawurlencode($registration['id_pendaftaran']); ?>&amp;token=<?php echo rawurlencode($receiptToken); ?>">Lihat status pendaftaran <span aria-hidden="true">&rarr;</span></a>
            <a class="secondary-download" href="cetak-bukti.php?id=<?php echo rawurlencode($registration['id_pendaftaran']); ?>&amp;token=<?php echo rawurlencode($receiptToken); ?>"><span aria-hidden="true">▤</span> Unduh bukti pendaftaran (PDF)</a>
            <p class="result-note">Halaman status memuat token akses pribadi. Jangan bagikan tautannya kepada orang lain.</p>
            <div class="admin-contact">
                <span>Butuh bantuan dari panitia?</span>
                <a class="whatsapp-button" href="<?php echo h($adminWhatsAppUrl); ?>" target="_blank" rel="noopener noreferrer" aria-label="Hubungi admin PPDB melalui WhatsApp di 0851 8483 9694">
                    <svg viewBox="0 0 32 32" aria-hidden="true" focusable="false"><path d="M16.04 3C8.87 3 3.04 8.77 3.04 15.88c0 2.27.6 4.48 1.75 6.43L3 29l6.9-1.8a13.1 13.1 0 0 0 6.14 1.52h.01c7.17 0 13-5.78 13-12.89 0-3.45-1.36-6.69-3.83-9.12A12.94 12.94 0 0 0 16.04 3Zm0 23.54h-.01c-1.93 0-3.83-.52-5.48-1.51l-.39-.23-4.09 1.07 1.09-3.96-.25-.4a10.62 10.62 0 0 1-1.65-5.63c0-5.92 4.85-10.74 10.79-10.74 2.88 0 5.59 1.12 7.62 3.14a10.61 10.61 0 0 1 3.16 7.59c0 5.92-4.85 10.74-10.79 10.74Zm5.92-8.04c-.32-.16-1.9-.93-2.2-1.04-.3-.11-.51-.16-.73.16-.21.32-.84 1.04-1.03 1.25-.19.21-.38.24-.7.08-.32-.16-1.36-.5-2.59-1.59-.96-.85-1.61-1.9-1.8-2.22-.19-.32-.02-.49.14-.65.14-.14.32-.37.48-.56.16-.19.21-.32.32-.53.11-.21.05-.4-.03-.56-.08-.16-.73-1.74-1-2.38-.26-.62-.53-.54-.73-.55l-.62-.01c-.21 0-.56.08-.86.4-.3.32-1.12 1.09-1.12 2.67s1.15 3.1 1.31 3.31c.16.21 2.26 3.43 5.47 4.81.76.33 1.36.52 1.82.66.76.24 1.45.21 2 .13.61-.09 1.9-.77 2.17-1.52.27-.75.27-1.39.19-1.52-.08-.13-.3-.21-.62-.37Z"/></svg>
                    <span><?php echo h(ppdb_admin_whatsapp_display()); ?></span>
                </a>
            </div>
        </section>
    <?php } else { ?>
        <section class="result-panel result-error">
            <p class="result-eyebrow">Tautan tidak valid</p>
            <h1>Bukti pendaftaran<br>tidak ditemukan.</h1>
            <p class="result-description">Periksa kembali tautan bukti atau hubungi panitia PPDB.</p>
            <a class="download-button" href="index.php">Kembali ke beranda</a>
        </section>
    <?php } ?>

    <footer>Yayasan Wakaf Cendekia Takengon · <a href="mailto:wakafcendekiatakengon@gmail.com">Hubungi panitia</a></footer>
</main>
</body>
</html>
