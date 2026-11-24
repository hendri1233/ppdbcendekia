<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'cetak_registrasi_data.php';
require_admin();

// Dua jenis berkas: registrasi (bawaan) dan data lengkap peserta yang sudah diterima.
$isCompleteFile = ($_GET['berkas'] ?? '') === 'lengkap';

$registrationId = trim((string) ($_GET['id'] ?? ''));
$participant = cetak_registrasi_participant($conn, $registrationId);
if (!$participant || (!ppdb_is_super_admin() && $participant['kode_unit'] !== $_SESSION['admin_unit_code'])) {
    http_response_code(404);
    exit('Data peserta tidak ditemukan.');
}

$printData = cetak_registrasi_build($conn, $participant, $isCompleteFile);
$printSections = $printData['sections'];
$attachments = $printData['attachments'];
$emptyCount = $printData['empty_count'];
$documentTitle = $printData['title'];
$hasPdfAttachment = in_array('application/pdf', array_column($attachments, 'mime'), true);

/** Isian kosong tetap dicetak agar panitia tahu bagian yang perlu direvisi wali murid. */
function cetak_registrasi_rows(array $rows): void
{
    echo '<table class="data">';
    foreach ($rows as [$label, $value]) {
        echo '<tr><th scope="row">'.h($label).'</th>';
        echo cetak_registrasi_is_empty($value) ? '<td class="is-empty">Belum diisi</td></tr>' : '<td>'.nl2br(h((string) $value)).'</td></tr>';
    }
    echo '</table>';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $isCompleteFile ? 'Data lengkap' : 'Registrasi'; ?> <?php echo h($registrationId); ?> · <?php echo h($participant['nm_peserta']); ?></title>
    <link rel="stylesheet" href="css/cetak-registrasi.css?v=<?php echo (int) filemtime(__DIR__.'/css/cetak-registrasi.css'); ?>">
</head>
<body>
    <div class="toolbar">
        <span data-print-status aria-live="polite">Menyiapkan lampiran...</span>
        <button type="button" data-print-now>Cetak</button>
    </div>

    <section class="sheet">
        <!-- Baris tfoot berulang di tiap halaman cetak, menyisakan ruang untuk kaki halaman. -->
        <table class="print-frame"><tbody><tr><td>
        <header class="kop">
            <img src="img/logo-512.png" alt="Logo Yayasan Wakaf Cendekia Takengon" width="76" height="76">
            <div>
                <strong>YAYASAN WAKAF CENDEKIA TAKENGON</strong>
                <span>Panitia Penerimaan Peserta Didik Baru · <?php echo h($participant['nama_unit']); ?></span>
                <small>Jalan Pertamina–Kebet, Kecamatan Bebesen, Kabupaten Aceh Tengah, Aceh<br>WhatsApp <?php echo h(ppdb_admin_whatsapp_display()); ?> · wakafcendekiatakengon@gmail.com · cendekiatakengon.or.id</small>
            </div>
        </header>
        <h1><?php echo h($documentTitle); ?></h1>
        <p class="subtitle">Tahun ajaran <?php echo h($participant['th_ajaran']); ?></p>
        <div class="code"><span>KODE PENDAFTARAN</span><strong><?php echo h($registrationId); ?></strong></div>

        <?php if ($emptyCount > 0) { ?>
            <p class="empty-summary"><?php echo $emptyCount; ?> isian belum diisi. Bila diperlukan, buka revisi formulir untuk wali murid.</p>
        <?php } ?>

        <?php foreach ($printSections as $index => [$sectionTitle, $sectionRows]) { ?>
            <h2><?php echo chr(65 + $index).'. '.h($sectionTitle); ?></h2>
            <?php cetak_registrasi_rows($sectionRows); ?>
        <?php } ?>

        <h2><?php echo chr(65 + count($printSections)); ?>. Daftar lampiran</h2>
        <?php if ($attachments) { ?>
            <ol class="attachment-list">
                <?php foreach ($attachments as $attachment) { ?><li><?php echo h($attachment['label']); ?></li><?php } ?>
            </ol>
        <?php } else { ?>
            <p>Belum ada berkas yang diunggah.</p>
        <?php } ?>

        <div class="signature">
            <p>Takengon, <?php echo h(ppdb_format_date_id(date('Y-m-d'))); ?></p>
            <p>Panitia SPMB</p>
            <div class="signature__line"></div>
        </div>
        </td></tr></tbody><tfoot><tr><td class="print-frame__space"></td></tr></tfoot></table>
    </section>

    <?php foreach ($attachments as $index => $attachment) {
        $title = 'Lampiran '.($index + 1).' · '.$attachment['label'];
        $attachmentHead = '<header class="attachment-head"><img src="img/logo-512.png" alt="" width="34" height="34"><h2>'.h($title).'</h2></header>'; ?>
        <?php if ($attachment['mime'] === 'application/pdf') { ?>
            <section class="sheet attachment" data-pdf-url="<?php echo h($attachment['url']); ?>" data-pdf-title="<?php echo h($title); ?>">
                <?php echo $attachmentHead; ?>
                <p class="attachment-note">Memuat berkas PDF...</p>
            </section>
        <?php } else { ?>
            <section class="sheet attachment">
                <?php echo $attachmentHead; ?>
                <img src="<?php echo h($attachment['url']); ?>" alt="<?php echo h($attachment['label']); ?>" data-attachment-image>
            </section>
        <?php } ?>
    <?php } ?>

    <div class="page-footer">Diterbitkan melalui sistem SPMB Yayasan Wakaf Cendekia Takengon · Kode <?php echo h($registrationId); ?> · <?php echo h($participant['nm_peserta']); ?></div>

    <?php if ($hasPdfAttachment) { ?>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <?php } ?>
    <script src="js/cetak-registrasi.js?v=<?php echo (int) filemtime(__DIR__.'/js/cetak-registrasi.js'); ?>"></script>
</body>
</html>
