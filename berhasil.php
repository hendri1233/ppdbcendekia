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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/result.css">
</head>
<body>
<main class="result-shell">
    <a class="result-brand" href="index.php">
        <img class="brand-logo" src="img/logo-display.png" alt="" width="48" height="47">
        <span><strong>Wakaf Cendekia</strong><small>TAKENGON · PPDB</small></span>
    </a>

    <?php if ($registration) { ?>
        <section class="result-panel">
            <div class="success-symbol" aria-hidden="true">&check;</div>
            <p class="result-eyebrow">REGISTRASI AWAL TERCATAT</p>
            <h1>Terima kasih,<br><?php echo h($registration['nm_peserta']); ?>.</h1>
            <p class="result-description">Data awal ananda sudah kami terima. Panitia akan menghubungi nomor WhatsApp pendaftar untuk instruksi pembayaran.</p>

            <div class="registration-code" role="group" aria-label="Kode pendaftaran">
                <span>KODE PENDAFTARAN</span>
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
            <a class="secondary-download" href="cetak-bukti.php?id=<?php echo rawurlencode($registration['id_pendaftaran']); ?>&amp;token=<?php echo rawurlencode($receiptToken); ?>">Unduh bukti PDF</a>
            <p class="result-note">Halaman status memuat token akses pribadi. Jangan bagikan tautannya kepada orang lain.</p>
        </section>
    <?php } else { ?>
        <section class="result-panel result-error">
            <p class="result-eyebrow">TAUTAN TIDAK VALID</p>
            <h1>Bukti pendaftaran<br>tidak ditemukan.</h1>
            <p class="result-description">Periksa kembali tautan bukti atau hubungi panitia PPDB.</p>
            <a class="download-button" href="index.php">Kembali ke beranda</a>
        </section>
    <?php } ?>

    <footer>Yayasan Wakaf Cendekia Takengon · <a href="mailto:wakafcendekiatakengon@gmail.com">Hubungi panitia</a></footer>
</main>
</body>
</html>
