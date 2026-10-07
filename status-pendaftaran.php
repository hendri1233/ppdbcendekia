<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
start_app_session();

$registrationId = $_GET['id'] ?? '';
$receiptToken = $_GET['token'] ?? '';
$registration = null;
$tokenIsValid = preg_match('/^P[0-9]{9}$/', $registrationId) === 1 && preg_match('/^[a-f0-9]{64}$/', $receiptToken) === 1;
if ($tokenIsValid) {
    $tokenHash = hash('sha256', $receiptToken);
    $stmt = mysqli_prepare($conn, 'SELECT p.id_pendaftaran,p.nm_peserta,p.tgl_lahir,p.jenis_kelamin,p.pendaftar,p.no_hp_pendaftar,p.no_hp_ayah,p.no_hp_ibu,p.th_ajaran,p.tgl_daftar,p.waktu_daftar,p.nomor_antrian,p.status_ppdb,p.status_data,p.status_pembayaran,p.tanggal_batas_bayar,u.nama_unit,u.jenjang,q.biaya_pendaftaran,q.bank_nama,q.nomor_rekening,q.nama_pemilik_rekening,q.masa_pembayaran_hari,b.status_verifikasi AS status_bukti,b.catatan_admin AS catatan_bukti,b.diunggah_pada,b.nomor_referensi FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit LEFT JOIN tb_pengaturan_ppdb q ON q.kode_unit=p.kode_unit AND q.th_ajaran=p.th_ajaran LEFT JOIN tb_bukti_pembayaran b ON b.id=(SELECT MAX(b2.id) FROM tb_bukti_pembayaran b2 WHERE b2.id_pendaftaran=p.id_pendaftaran) WHERE p.id_pendaftaran=? AND (p.token_bukti=? OR p.token_whatsapp=? OR p.token_formulir=?) LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ssss', $registrationId, $tokenHash, $tokenHash, $tokenHash);
    mysqli_stmt_execute($stmt);
    $registration = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

if (!$registration) {
    http_response_code(404);
}

$statusInfo = $registration ? ppdb_status_info($registration['status_ppdb']) : null;
$currentStep = $statusInfo['step'] ?? 0;
$paymentDeadlinePassed = $registration && $registration['tanggal_batas_bayar'] && $registration['tanggal_batas_bayar'] < date('Y-m-d');
$uploadError = trim($_GET['upload_error'] ?? '');

$journeySteps = [
    ['title' => 'Registrasi awal', 'description' => 'Data dasar ananda diterima.'],
    ['title' => 'Pembayaran', 'description' => 'Panitia mengirim tautan, Anda unggah bukti.'],
    ['title' => 'Formulir lengkap', 'description' => 'Isi data detail dan dokumen pendukung.'],
    ['title' => 'Pemeriksaan', 'description' => 'Panitia memverifikasi kelengkapan berkas.'],
    ['title' => 'Keputusan', 'description' => 'Diterima, daftar tunggu, atau tindak lanjut.'],
];

$documentCount = 0;
if ($registration) {
    $documentQuery = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM tb_dokumen_peserta WHERE id_pendaftaran=?');
    mysqli_stmt_bind_param($documentQuery, 's', $registration['id_pendaftaran']);
    mysqli_stmt_execute($documentQuery);
    $documentCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($documentQuery))['total'];
    mysqli_stmt_close($documentQuery);
}

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
    <title><?php echo $registration ? 'Status pendaftaran '.$registration['id_pendaftaran'] : 'Pendaftaran tidak ditemukan'; ?> | PPDB Cendekia</title>
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
        <?php if ($uploadError !== '') { ?>
            <div class="payment-error" role="alert"><?php echo h($uploadError); ?></div>
        <?php } ?>

        <section class="result-panel status-panel">
            <div class="status-head">
                <div>
                    <p class="result-eyebrow">STATUS PENDAFTARAN</p>
                    <h1><?php echo h($statusInfo['label']); ?></h1>
                    <p class="result-description"><?php echo h($statusInfo['public']); ?></p>
                </div>
                <div class="registration-code" role="group" aria-label="Kode pendaftaran">
                    <span>KODE PENDAFTARAN</span>
                    <strong><?php echo h($registration['id_pendaftaran']); ?></strong>
                </div>
            </div>

            <ol class="journey-track" aria-label="Progres pendaftaran">
                <?php foreach ($journeySteps as $index => $step) {
                    $stepNumber = $index + 1;
                    $state = $stepNumber < $currentStep ? 'done' : ($stepNumber === $currentStep ? 'current' : 'todo');
                ?>
                    <li class="journey-step is-<?php echo $state; ?>">
                        <span class="journey-dot" aria-hidden="true"><?php echo $state === 'done' ? '&check;' : $stepNumber; ?></span>
                        <div>
                            <strong><?php echo h($step['title']); ?></strong>
                            <small><?php echo h($step['description']); ?></small>
                        </div>
                    </li>
                <?php } ?>
            </ol>

            <dl class="summary-list">
                <div><dt>Nama ananda</dt><dd><?php echo h($registration['nm_peserta']); ?></dd></div>
                <div><dt>Tanggal lahir</dt><dd><?php echo h(ppdb_format_date_id($registration['tgl_lahir'])); ?></dd></div>
                <div><dt>Jenis kelamin</dt><dd><?php echo h(ppdb_jenis_kelamin_label($registration['jenis_kelamin'])); ?></dd></div>
                <div><dt>Didaftarkan oleh</dt><dd><?php echo h(ppdb_pendaftar_label($registration['pendaftar'])); ?></dd></div>
                <div><dt>Unit pendidikan</dt><dd><?php echo h($registration['nama_unit'] ?? 'Belum ditentukan'); ?></dd></div>
                <div><dt>Tahun ajaran</dt><dd><?php echo h($registration['th_ajaran']); ?></dd></div>
                <div><dt>Nomor antrean</dt><dd><?php echo $registration['nomor_antrian'] ? '#'.(int) $registration['nomor_antrian'] : 'Data arsip'; ?></dd></div>
                <div><dt>Tanggal daftar</dt><dd><?php echo h(ppdb_format_date_id($registration['tgl_daftar'])); ?></dd></div>
            </dl>

            <?php
            $panel = '';
            if ($registration['status_ppdb'] === 'menunggu_kontak') {
                $panel = 'kontak';
            } elseif ($registration['status_ppdb'] === 'menunggu_pembayaran') {
                $panel = 'bayar';
            } elseif ($registration['status_ppdb'] === 'pembayaran_diperiksa') {
                $panel = 'cek-bayar';
            } elseif (in_array($registration['status_ppdb'], ['pembayaran_terverifikasi', 'menunggu_formulir'], true)) {
                $panel = 'formulir';
            } elseif ($registration['status_ppdb'] === 'formulir_terisi') {
                $panel = 'cek-formulir';
            }
            ?>

            <?php if ($panel === 'kontak') { ?>
                <div class="payment-guidance">
                    <strong>Panitia akan menghubungi Anda</strong>
                    <span>Tautan pembayaran dikirim ke nomor WhatsApp pendaftar: <strong><?php echo h(ppdb_display_phone($registration['no_hp_pendaftar'])); ?></strong></span>
                    <span>Nomor ayah: <?php echo h(ppdb_display_phone($registration['no_hp_ayah'])); ?> · Ibu: <?php echo h(ppdb_display_phone($registration['no_hp_ibu'])); ?></span>
                </div>
            <?php } elseif ($panel === 'bayar') { ?>
                <?php if ($registration['status_pembayaran'] === 'ditolak') { ?>
                    <div class="payment-error" role="alert">Bukti pembayaran sebelumnya belum disetujui. <?php echo h($registration['catatan_bukti']); ?></div>
                <?php } ?>
                <?php if ($paymentDeadlinePassed) { ?>
                    <div class="payment-guidance is-warning">Batas pembayaran telah lewat. Hubungi panitia sebelum mengirim pembayaran.</div>
                <?php } else { ?>
                    <div class="payment-guidance">
                        <strong>Instruksi pembayaran</strong>
                        <span>Jumlah: <?php echo (float) $registration['biaya_pendaftaran'] > 0 ? 'Rp '.h(number_format((float) $registration['biaya_pendaftaran'], 0, ',', '.')) : 'Tidak dipungut biaya'; ?></span>
                        <?php if ($registration['bank_nama'] && $registration['nomor_rekening'] && $registration['nama_pemilik_rekening']) { ?>
                            <span><?php echo h($registration['bank_nama']); ?> · <?php echo h($registration['nomor_rekening']); ?></span>
                            <span>a.n. <?php echo h($registration['nama_pemilik_rekening']); ?></span>
                        <?php } ?>
                        <span>Gunakan kode <?php echo h($registration['id_pendaftaran']); ?> sebagai berita transfer.</span>
                    </div>
                    <form class="payment-upload" action="upload-bukti-pembayaran.php" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="id" value="<?php echo h($registration['id_pendaftaran']); ?>">
                        <input type="hidden" name="token" value="<?php echo h($receiptToken); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                        <label for="bukti_transfer">Bukti transfer <span>JPG, PNG, atau PDF · maks. 5 MB</span></label>
                        <input id="bukti_transfer" type="file" name="bukti_transfer" accept="image/jpeg,image/png,application/pdf" required>
                        <button class="download-button" type="submit">Unggah bukti pembayaran <span aria-hidden="true">&uarr;</span></button>
                    </form>
                <?php } ?>
            <?php } elseif ($panel === 'cek-bayar') { ?>
                <div class="payment-guidance">
                    <strong>Bukti transfer sedang diperiksa</strong>
                    <span>Diunggah<?php echo $registration['diunggah_pada'] ? ' pada '.h(date('d-m-Y H:i', strtotime($registration['diunggah_pada']))) : ''; ?>.</span>
                    <span>Setelah disetujui, Anda menerima tautan formulir lengkap melalui WhatsApp.</span>
                </div>
            <?php } elseif ($panel === 'formulir') { ?>
                <div class="payment-guidance">
                    <strong>Pembayaran disetujui</strong>
                    <span>Tautan formulir lengkap dikirim melalui WhatsApp. Gunakan kode pendaftaran <strong><?php echo h($registration['id_pendaftaran']); ?></strong> pada halaman formulir untuk melanjutkan.</span>
                </div>
                <a class="download-button" href="formulir.php?kode=<?php echo rawurlencode($registration['id_pendaftaran']); ?>&amp;token=<?php echo rawurlencode($receiptToken); ?>">Buka formulir lengkap <span aria-hidden="true">&rarr;</span></a>
            <?php } elseif ($panel === 'cek-formulir') { ?>
                <div class="payment-guidance">
                    <strong>Formulir lengkap diterima</strong>
                    <span><?php echo $documentCount; ?> dokumen pendukung telah diunggah. Data sedang diperiksa panitia.</span>
                </div>
            <?php } ?>

            <a class="secondary-download" href="cetak-bukti.php?id=<?php echo rawurlencode($registration['id_pendaftaran']); ?>&amp;token=<?php echo rawurlencode($receiptToken); ?>">Unduh ringkasan PDF</a>
            <p class="result-note">Halaman ini memuat token akses pribadi. Jangan bagikan tautannya kepada orang lain.</p>
        </section>
    <?php } else { ?>
        <section class="result-panel result-error">
            <p class="result-eyebrow">TAUTAN TIDAK VALID</p>
            <h1>Status pendaftaran<br>tidak ditemukan.</h1>
            <p class="result-description">Periksa kembali tautan dari pesan WhatsApp, atau hubungi panitia PPDB.</p>
            <a class="download-button" href="index.php">Kembali ke beranda</a>
        </section>
    <?php } ?>

    <footer>Yayasan Wakaf Cendekia Takengon · <a href="mailto:wakafcendekiatakengon@gmail.com">Hubungi panitia</a></footer>
</main>
<script src="js/status.js"></script>
</body>
</html>
