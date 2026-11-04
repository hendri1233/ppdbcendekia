<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'prestasi_helpers.php';
require_once 'formulir_helpers.php';
require_admin();

// Dua jenis berkas: registrasi (bawaan) dan data lengkap peserta yang sudah diterima.
$isCompleteFile = ($_GET['berkas'] ?? '') === 'lengkap';

$registrationId = trim((string) ($_GET['id'] ?? ''));
$stmt = mysqli_prepare($conn, 'SELECT p.*,u.nama_unit,u.jenjang FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit WHERE p.id_pendaftaran=? LIMIT 1');
mysqli_stmt_bind_param($stmt, 's', $registrationId);
mysqli_stmt_execute($stmt);
$participant = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$participant || (!ppdb_is_super_admin() && $participant['kode_unit'] !== $_SESSION['admin_unit_code'])) {
    http_response_code(404);
    exit('Data peserta tidak ditemukan.');
}

$birthPlace = trim((string) $participant['tmp_lahir']);
$registrationRows = [
    'Tanggal pendaftaran' => ppdb_format_date_id($participant['tgl_daftar']),
    'Unit pendidikan' => $participant['nama_unit'],
    ...(!empty($participant['tingkat_kelas']) ? ['Daftar ke jenjang' => $participant['tingkat_kelas']] : []),
    'Jalur pendaftaran' => $participant['jalur_pendaftaran'] === 'internal' ? 'Internal' : 'Umum',
    'Nomor antrean' => $participant['nomor_antrian'] ? '#'.(int) $participant['nomor_antrian'] : '',
    'Nama ananda' => $participant['nm_peserta'],
    'Tempat, tanggal lahir' => ($birthPlace !== '' ? $birthPlace.', ' : '').ppdb_format_date_id($participant['tgl_lahir']),
    'Umur (Dapodik)' => ppdb_dapodik_age_label(ppdb_dapodik_age($participant['tgl_lahir'], $participant['th_ajaran'])),
    'Jenis kelamin' => ppdb_jenis_kelamin_label($participant['jenis_kelamin']),
    'Yang mendaftarkan' => ppdb_pendaftar_label($participant['pendaftar']),
    'WhatsApp pendaftar' => ppdb_display_phone((string) $participant['no_hp_pendaftar']),
    'WhatsApp ayah' => ppdb_display_phone((string) ($participant['no_hp_ayah'] ?? '')),
    'WhatsApp ibu' => ppdb_display_phone((string) ($participant['no_hp_ibu'] ?? '')),
];

$selectionData = prestasi_load($conn, $registrationId);
$isAchievementRoute = ($selectionData['jalur_seleksi'] ?? '') === 'prestasi';
$achievementRows = [];
if ($selectionData) {
    $achievementRows[] = ['Jalur seleksi', prestasi_jalur_label($selectionData['jalur_seleksi'])];
}
if ($isAchievementRoute) {
    foreach ([
        'Prestasi akademik' => 'jenis_prestasi',
        'Kelas 5 semester 1' => 'peringkat_k5_s1',
        'Kelas 5 semester 2' => 'peringkat_k5_s2',
        'Kelas 6 semester 1' => 'peringkat_k6_s1',
        'Kategori prestasi' => 'kategori_hafalan',
        'Jumlah hafalan' => 'jumlah_hafalan',
        'Riwayat lomba' => 'riwayat_lomba',
        'Lomba yang dimenangkan' => 'daftar_lomba',
    ] as $label => $column) {
        if (trim((string) ($selectionData[$column] ?? '')) !== '') {
            $achievementRows[] = [$label, $selectionData[$column]];
        }
    }
}

$documentLabels = $isCompleteFile
    ? ['foto' => 'Foto peserta didik', 'kk' => 'Scan Kartu Keluarga', 'akta_lahir' => 'Scan Akta Kelahiran', 'ktp_ayah' => 'Scan KTP Ayah', 'ktp_ibu' => 'Scan KTP Ibu']
    : ($isAchievementRoute ? ['surat_prestasi' => 'Surat pengantar prestasi'] : []);
$documents = [];
$documentQuery = mysqli_prepare($conn, 'SELECT id,jenis_dokumen,mime_type FROM tb_dokumen_peserta WHERE id_pendaftaran=?');
mysqli_stmt_bind_param($documentQuery, 's', $registrationId);
mysqli_stmt_execute($documentQuery);
foreach (mysqli_fetch_all(mysqli_stmt_get_result($documentQuery), MYSQLI_ASSOC) as $document) {
    $documents[$document['jenis_dokumen']] = $document;
}
mysqli_stmt_close($documentQuery);

$attachments = [];
foreach ($documentLabels as $slot => $label) {
    if (isset($documents[$slot])) {
        $attachments[] = ['label' => $label, 'url' => 'dokumen-admin.php?id='.(int) $documents[$slot]['id'], 'mime' => $documents[$slot]['mime_type']];
    }
}
if (!$isCompleteFile) {
    $paymentQuery = mysqli_prepare($conn, 'SELECT id,mime_type FROM tb_bukti_pembayaran WHERE id_pendaftaran=? ORDER BY id DESC LIMIT 1');
    mysqli_stmt_bind_param($paymentQuery, 's', $registrationId);
    mysqli_stmt_execute($paymentQuery);
    $latestPayment = mysqli_fetch_assoc(mysqli_stmt_get_result($paymentQuery));
    mysqli_stmt_close($paymentQuery);
    if ($latestPayment) {
        $attachments[] = ['label' => 'Bukti transfer', 'url' => 'bukti-pembayaran-admin.php?id='.(int) $latestPayment['id'], 'mime' => $latestPayment['mime_type']];
    }
}
$hasPdfAttachment = in_array('application/pdf', array_column($attachments, 'mime'), true);

$printSections = [['Data registrasi', array_map(null, array_keys($registrationRows), $registrationRows)]];
if ($achievementRows) {
    $printSections[] = ['Jalur dan prestasi', $achievementRows];
}
if ($isCompleteFile) {
    $savedValues = formulir_saved_values($conn, $registrationId);
    foreach (formulir_sections_for(null, (string) ($participant['jenjang'] ?? '')) as $section) {
        $rows = [];
        foreach ($section['fields'] as $field) {
            if (($field['type'] ?? 'text') === 'heading' || !formulir_condition_met($field, $savedValues)) {
                continue;
            }
            $value = formulir_normalize_value($savedValues[$field['name']] ?? '', $field);
            if (($field['type'] ?? '') === 'select' && $value !== '') {
                $value = (string) ($field['options'][$value] ?? $value);
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $value = ppdb_format_date_id($value);
            }
            $rows[] = [$field['label'], $value];
        }
        if ($rows) {
            $printSections[] = [$section['title'], $rows];
        }
    }
}
$emptyCount = 0;
foreach ($printSections as [, $sectionRows]) {
    $emptyCount += count(array_filter($sectionRows, static fn (array $row): bool => cetak_registrasi_is_empty($row[1])));
}
$documentTitle = $isCompleteFile ? 'DATA LENGKAP PESERTA DIDIK' : 'DATA REGISTRASI PESERTA DIDIK';

function cetak_registrasi_is_empty(mixed $value): bool
{
    return in_array(trim((string) $value), ['', '-'], true);
}

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
                <small>Badan Hukum SK Kemenkumham AHU-0000410.AH.01.12 Tahun 2022 · Nazhir Wakaf Uang BWI Kode 3.3.00369<br>Jalan Pertamina–Kebet, Kecamatan Bebesen, Kabupaten Aceh Tengah, Aceh<br>WhatsApp <?php echo h(ppdb_admin_whatsapp_display()); ?> · wakafcendekiatakengon@gmail.com · cendekiatakengon.or.id</small>
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
