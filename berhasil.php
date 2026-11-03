<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';

$registrationId = $_GET['id'] ?? '';
$receiptToken = $_GET['token'] ?? '';
$registration = null;
if (preg_match('/^P[0-9]{9}$/', $registrationId) === 1 && preg_match('/^[a-f0-9]{64}$/', $receiptToken) === 1) {
    $tokenHash = hash('sha256', $receiptToken);
    $stmt = mysqli_prepare($conn, 'SELECT p.id_pendaftaran,p.th_ajaran,p.tgl_daftar,p.nm_peserta,p.tgl_lahir,p.jenis_kelamin,p.pendaftar,p.status_ppdb,p.nomor_antrian,p.token_bukti,'.(ppdb_column_exists($conn, 'tb_pendaftaran', 'tingkat_kelas') ? 'p.tingkat_kelas,' : '').'u.nama_unit FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON p.kode_unit=u.kode_unit WHERE p.id_pendaftaran=? LIMIT 1');
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
    : 'Assalamu’alaikum, saya ingin bertanya mengenai pendaftaran SPMB Cendekia.';
$adminWhatsAppUrl = ppdb_admin_whatsapp_url($adminWhatsAppMessage);
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $registration ? 'Registrasi awal berhasil' : 'Bukti tidak ditemukan'; ?> | SPMB Cendekia</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300;0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/tokens.css?v=<?php echo (int) @filemtime(__DIR__.'/css/tokens.css'); ?>">
    <link rel="stylesheet" href="css/result.css?v=<?php echo (int) filemtime(__DIR__.'/css/result.css'); ?>">
    <link rel="stylesheet" href="css/alur.css?v=<?php echo (int) @filemtime(__DIR__.'/css/alur.css'); ?>">
    <meta name="theme-color" content="#ffffff">
    <link rel="icon" type="image/x-icon" href="img/favicons/favicon.ico" sizes="16x16 32x32 48x48">
    <link rel="icon" type="image/png" sizes="48x48" href="img/favicons/favicon-48x48.png">
    <link rel="icon" type="image/png" sizes="16x16" href="img/favicons/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicons/favicon-32x32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="img/favicons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="img/favicons/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="img/favicons/android-chrome-512x512.png">
</head>
<body>
<main class="result-shell">
    <a class="result-brand" href="./">
        <img class="brand-logo" src="img/logo-display.png" alt="" width="48" height="47">
        <span><strong>Wakaf Cendekia</strong><small>Takengon · SPMB</small></span>
    </a>

    <?php if ($registration) { ?>
        <section class="result-panel">
            <header class="acceptance-hero">
                <span class="acceptance-seal" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M9.5 16.2 5.3 12l-1.6 1.6 5.8 5.8L20.3 8.6 18.7 7z"/></svg></span>
                <p class="acceptance-eyebrow">Registrasi awal tercatat</p>
                <h1>Terima kasih, Ayah/Bunda</h1>
                <p class="acceptance-name"><?php echo h($registration['nm_peserta']); ?></p>
                <p class="acceptance-unit"><?php echo h($registration['nama_unit'] ?? 'Belum ditentukan'); ?> · Tahun ajaran <?php echo h($registration['th_ajaran']); ?></p>
            </header>

            <div class="registration-code" role="group" aria-label="Kode pendaftaran">
                <span>Kode pendaftaran</span>
                <strong><?php echo h($registration['id_pendaftaran']); ?></strong>
                <button class="flow-copy" type="button" data-copy="<?php echo h($registration['id_pendaftaran']); ?>" aria-label="Salin kode pendaftaran"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3h10a2 2 0 0 1 2 2v12h-2V5H9V3ZM5 7h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Zm0 2v10h10V9H5Z"/></svg><span>Salin</span></button>
            </div>
            <p class="code-hint">Simpan kode ini. Kode, tanggal lahir ananda, dan nomor WhatsApp diperlukan untuk kembali ke pendaftaran dan melihat hasilnya.</p>

            <a class="download-button" href="formulir.php?kode=<?php echo rawurlencode($registration['id_pendaftaran']); ?>&amp;token=<?php echo rawurlencode($receiptToken); ?>">Lanjutkan pendaftaran <span aria-hidden="true">&rarr;</span></a>

            <h2 class="acceptance-heading">Tahap berikutnya</h2>
            <ol class="next-steps" aria-label="Langkah berikutnya">
                <li><span aria-hidden="true">1</span><div><strong>Bayar dan unggah bukti</strong><small>Transfer sesuai petunjuk lalu unggah buktinya.</small></div></li>
                <li><span aria-hidden="true">2</span><div><strong>Isi formulir lengkap</strong><small>Konfirmasi data, baca materi, lalu isi formulir dan unggah dokumen.</small></div></li>
                <li><span aria-hidden="true">3</span><div><strong>Langsung diterima</strong><small>Setelah formulir terkirim, tautan grup WhatsApp unit dan surat keterangan diterima langsung tampil.</small></div></li>
            </ol>

            <h2 class="acceptance-heading">Data pendaftaran</h2>
            <dl class="summary-list">
                <div><dt>Nama lengkap ananda</dt><dd><?php echo h($registration['nm_peserta']); ?></dd></div>
                <div><dt>Tanggal lahir</dt><dd><?php echo h(ppdb_format_date_id($registration['tgl_lahir'])); ?></dd></div>
                <div><dt>Umur ananda terdeteksi berdasarkan Dapodik dari tanggal <?php echo h($dapodikAge['reference_date'] ?? '-'); ?> berumur:</dt><dd><?php echo h(ppdb_dapodik_age_label($dapodikAge)); ?></dd></div>
                <div><dt>Jenis kelamin</dt><dd><?php echo h(ppdb_jenis_kelamin_label($registration['jenis_kelamin'])); ?></dd></div>
                <div><dt>Didaftarkan oleh</dt><dd><?php echo h(ppdb_pendaftar_label($registration['pendaftar'])); ?></dd></div>
                <div><dt>Unit pendidikan</dt><dd><?php echo h($registration['nama_unit'] ?? 'Belum ditentukan'); ?></dd></div>
                <?php if (!empty($registration['tingkat_kelas'])) { ?><div><dt>Daftar ke jenjang</dt><dd><?php echo h($registration['tingkat_kelas']); ?></dd></div><?php } ?>
                <div><dt>Tahun ajaran</dt><dd><?php echo h($registration['th_ajaran']); ?></dd></div>
                <div><dt>Nomor antrean</dt><dd><?php echo $registration['nomor_antrian'] ? '#'.(int) $registration['nomor_antrian'] : 'Data arsip'; ?></dd></div>
                <div><dt>Tanggal daftar</dt><dd><?php echo h(ppdb_format_date_id($registration['tgl_daftar'])); ?></dd></div>
            </dl>


            <a class="secondary-download" href="cetak-bukti.php?id=<?php echo rawurlencode($registration['id_pendaftaran']); ?>&amp;token=<?php echo rawurlencode($receiptToken); ?>">Unduh bukti pendaftaran (PDF)</a>
            <p class="result-note">Jika halaman ini tertutup, buka menu <a href="lanjutkan-pendaftaran.php">Lanjutkan pendaftaran</a> untuk kembali.</p>
            <div class="admin-contact">
                <span>Butuh bantuan dari panitia?</span>
                <a class="whatsapp-button" href="<?php echo h($adminWhatsAppUrl); ?>" target="_blank" rel="noopener noreferrer" aria-label="Hubungi admin SPMB melalui WhatsApp di 0851 8483 9694">
                    <svg viewBox="0 0 32 32" aria-hidden="true" focusable="false"><path d="M16.04 3C8.87 3 3.04 8.77 3.04 15.88c0 2.27.6 4.48 1.75 6.43L3 29l6.9-1.8a13.1 13.1 0 0 0 6.14 1.52h.01c7.17 0 13-5.78 13-12.89 0-3.45-1.36-6.69-3.83-9.12A12.94 12.94 0 0 0 16.04 3Zm0 23.54h-.01c-1.93 0-3.83-.52-5.48-1.51l-.39-.23-4.09 1.07 1.09-3.96-.25-.4a10.62 10.62 0 0 1-1.65-5.63c0-5.92 4.85-10.74 10.79-10.74 2.88 0 5.59 1.12 7.62 3.14a10.61 10.61 0 0 1 3.16 7.59c0 5.92-4.85 10.74-10.79 10.74Zm5.92-8.04c-.32-.16-1.9-.93-2.2-1.04-.3-.11-.51-.16-.73.16-.21.32-.84 1.04-1.03 1.25-.19.21-.38.24-.7.08-.32-.16-1.36-.5-2.59-1.59-.96-.85-1.61-1.9-1.8-2.22-.19-.32-.02-.49.14-.65.14-.14.32-.37.48-.56.16-.19.21-.32.32-.53.11-.21.05-.4-.03-.56-.08-.16-.73-1.74-1-2.38-.26-.62-.53-.54-.73-.55l-.62-.01c-.21 0-.56.08-.86.4-.3.32-1.12 1.09-1.12 2.67s1.15 3.1 1.31 3.31c.16.21 2.26 3.43 5.47 4.81.76.33 1.36.52 1.82.66.76.24 1.45.21 2 .13.61-.09 1.9-.77 2.17-1.52.27-.75.27-1.39.19-1.52-.08-.13-.3-.21-.62-.37Z"/></svg>
                    <span><?php echo h(ppdb_admin_whatsapp_display()); ?></span>
                </a>
            </div>
        </section>
    <?php } else { ?>
        <section class="result-panel result-error">
            <p class="result-eyebrow">Tautan tidak valid</p>
            <h1>Bukti pendaftaran<br>tidak ditemukan.</h1>
            <p class="result-description">Periksa kembali tautan bukti atau hubungi panitia SPMB.</p>
            <a class="download-button" href="./">Kembali ke beranda</a>
        </section>
    <?php } ?>

    <footer>Yayasan Wakaf Cendekia Takengon · <a href="mailto:wakafcendekiatakengon@gmail.com">Hubungi panitia</a></footer>
</main>
<script src="js/alur.js?v=<?php echo (int) @filemtime(__DIR__.'/js/alur.js'); ?>"></script>
</body>
</html>
