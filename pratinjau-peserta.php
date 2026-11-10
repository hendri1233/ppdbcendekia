<?php
/**
 * Pratinjau data lengkap peserta untuk panel samping di daftar peserta.
 * Mengembalikan potongan HTML; hak akses sama dengan detail-peserta.php.
 */
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'formulir_helpers.php';
require_once 'prestasi_helpers.php';
require_admin();
require_once __DIR__ . '/admin-partials.php';

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

$registrationId = (string) ($_GET['id'] ?? '');
$participant = null;
if (preg_match('/^P[0-9]{9}$/', $registrationId)) {
    // d.* lebih dulu agar kolom tb_pendaftaran tidak tertimpa NULL bila formulir belum diisi.
    $statement = mysqli_prepare($conn, 'SELECT d.*,p.*,u.nama_unit,u.jenjang,c.biaya_pendaftaran FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit LEFT JOIN tb_detail_dapodik d ON d.id_pendaftaran=p.id_pendaftaran LEFT JOIN tb_pengaturan_ppdb c ON c.kode_unit=p.kode_unit AND c.th_ajaran=p.th_ajaran WHERE p.id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($statement, 's', $registrationId);
    mysqli_stmt_execute($statement);
    $participant = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: null;
    mysqli_stmt_close($statement);
}
if (!$participant || (!ppdb_is_super_admin() && $participant['kode_unit'] !== $_SESSION['admin_unit_code'])) {
    http_response_code(404);
    echo '<div class="pv-empty"><strong>Data peserta tidak ditemukan.</strong><p>Peserta mungkin sudah dihapus atau berada di unit lain.</p></div>';
    exit;
}
$participant += formulir_flatten_parent_profiles(formulir_parent_profiles($conn, $registrationId));
$selection = prestasi_load($conn, $registrationId);

$documentLabels = [
    'foto' => 'Foto peserta didik',
    'nisn' => 'Foto / scan NISN',
    'kk' => 'Kartu Keluarga',
    'akta_lahir' => 'Akta kelahiran',
    'ktp_ayah' => 'KTP ayah',
    'ktp_ibu' => 'KTP ibu',
];
if (($selection['jalur_seleksi'] ?? '') === 'prestasi') {
    $documentLabels['surat_prestasi'] = 'Surat pengantar prestasi';
}
$documents = [];
$statement = mysqli_prepare($conn, 'SELECT id,jenis_dokumen,nama_file_asli,mime_type,diunggah_pada FROM tb_dokumen_peserta WHERE id_pendaftaran=?');
mysqli_stmt_bind_param($statement, 's', $registrationId);
mysqli_stmt_execute($statement);
foreach (mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC) as $row) {
    $documents[$row['jenis_dokumen']] = $row;
}
mysqli_stmt_close($statement);

$statement = mysqli_prepare($conn, 'SELECT id,nomor_referensi,status_verifikasi,catatan_admin,diunggah_pada FROM tb_bukti_pembayaran WHERE id_pendaftaran=? ORDER BY id DESC LIMIT 1');
mysqli_stmt_bind_param($statement, 's', $registrationId);
mysqli_stmt_execute($statement);
$payment = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) ?: null;
mysqli_stmt_close($statement);

$meta = ppdb_admin_status_meta($participant['status_ppdb']);
$isRejected = $participant['status_ppdb'] === 'ditolak';
$isAccepted = $participant['status_ppdb'] === 'diterima';
$progressStep = $isRejected ? ppdb_rejected_completed_steps($participant) + 1 : (int) ppdb_status_info($participant['status_ppdb'])['step'];
$stages = ['Registrasi', 'Pembayaran', 'Formulir', 'Verifikasi', 'Hasil'];
$age = ppdb_dapodik_age_label(ppdb_dapodik_age($participant['tgl_lahir'], $participant['th_ajaran']));
$formFilled = trim((string) ($participant['nik'] ?? '')) !== '';
$photo = $documents['foto'] ?? null;
$initials = implode('', array_map(static fn ($word) => mb_substr($word, 0, 1), array_slice(preg_split('/\s+/u', trim((string) $participant['nm_peserta'])) ?: [], 0, 2)));
$paymentLabels = ['menunggu_verifikasi' => 'Menunggu verifikasi', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];

/** Nilai isian formulir siap tampil: label pilihan, tanggal, dan angka dirapikan. */
function pv_value(array $field, $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    if (($field['type'] ?? '') === 'select' && isset($field['options'][$value])) {
        return (string) $field['options'][$value];
    }
    if (!empty($field['numeric']) && is_numeric($value)) {
        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    }
    return $value;
}

/** Satu baris data; nilai kosong ditandai halus. */
function pv_row(string $label, string $value, bool $wide = false): void
{
    echo '<div class="pv-field'.($wide ? ' is-wide' : '').'"><dt>'.h($label).'</dt><dd>'.($value === '' ? '<span class="pv-muted">Belum diisi</span>' : nl2br(h($value))).'</dd></div>';
}

$jumpLabels = ['ananda' => 'Ananda', 'alamat' => 'Alamat', 'ayah' => 'Ayah', 'ibu' => 'Ibu', 'wali' => 'Wali', 'profil' => 'Kesehatan & profil'];
$sectionIcons = ['ananda' => 'user', 'alamat' => 'map', 'ayah' => 'parent', 'ibu' => 'parent', 'wali' => 'parent', 'profil' => 'heart'];
$sections = formulir_sections_for(null, (string) ($participant['jenjang'] ?? ''));
?>
<article class="pv" data-preview-id="<?php echo h($registrationId); ?>" data-print-url="cetak-registrasi.php?id=<?php echo rawurlencode($registrationId).($formFilled ? '&amp;berkas=lengkap' : ''); ?>" data-print-label="<?php echo $formFilled ? 'Cetak formulir' : 'Cetak registrasi'; ?>">
    <header class="pv-hero">
        <div class="pv-avatar">
            <?php if ($photo && str_starts_with((string) $photo['mime_type'], 'image/')) { ?>
                <img src="dokumen-admin.php?id=<?php echo (int) $photo['id']; ?>" alt="Foto <?php echo h($participant['nm_peserta']); ?>" loading="lazy" onerror="this.outerHTML='<span><?php echo h(mb_strtoupper($initials ?: '?')); ?></span>'">
            <?php } else { ?>
                <span><?php echo h(mb_strtoupper($initials ?: '?')); ?></span>
            <?php } ?>
        </div>
        <div class="pv-identity">
            <p class="pv-eyebrow"><?php echo h(ppdb_unit_display_name($participant['nama_unit'] ?? 'Data lama')); ?><?php echo !empty($participant['tingkat_kelas']) ? ' · '.h($participant['tingkat_kelas']) : ''; ?></p>
            <h2 id="pv-title"><?php echo h($participant['nm_peserta']); ?></h2>
            <div class="pv-tags">
                <code class="code-chip"><?php echo h($registrationId); ?></code>
                <span class="status-pill tone-<?php echo h($meta['tone']); ?>"><?php echo h($meta['label']); ?></span>
                <?php if ($participant['nomor_antrian']) { ?><span class="pv-tag">Antrean #<?php echo (int) $participant['nomor_antrian']; ?></span><?php } ?>
                <span class="pv-tag"><?php echo $participant['jalur_pendaftaran'] === 'internal' ? 'Internal · SDM' : 'Eksternal · umum'; ?></span>
            </div>
        </div>
    </header>

    <?php if ($progressStep > 0) { ?>
        <ol class="pv-progress" aria-label="Progres pendaftaran">
            <?php foreach ($stages as $index => $stage) {
                $number = $index + 1;
                $done = $number < $progressStep || $isAccepted;
                $current = !$done && $number === $progressStep;
                $class = $done ? 'is-done' : ($current ? ($isRejected ? 'is-stopped' : 'is-current') : '');
                ?>
                <li class="<?php echo $class; ?>"<?php echo $current ? ' aria-current="step"' : ''; ?>><span><?php echo $done ? '&#10003;' : $number; ?></span><small><?php echo h($stage); ?></small></li>
            <?php } ?>
        </ol>
    <?php } ?>

    <nav class="pv-jump" aria-label="Lompat ke bagian">
        <a href="#pv-ringkasan">Ringkasan</a>
        <?php if ($formFilled) { foreach ($sections as $key => $section) { ?><a href="#pv-<?php echo h($key); ?>"><?php echo h($jumpLabels[$key] ?? $section['title']); ?></a><?php } } ?>
        <a href="#pv-dokumen">Dokumen</a>
        <a href="#pv-pembayaran">Pembayaran</a>
    </nav>

    <section class="pv-card" id="pv-ringkasan">
        <h3><span class="pv-icon pv-icon--info" aria-hidden="true"></span>Ringkasan pendaftaran</h3>
        <dl class="pv-grid">
            <?php
            pv_row('Tanggal lahir', ppdb_format_date_id($participant['tgl_lahir']));
            pv_row('Umur (acuan Dapodik)', (string) $age);
            pv_row('Jenis kelamin', ppdb_jenis_kelamin_label($participant['jenis_kelamin']));
            pv_row('Didaftarkan oleh', ppdb_pendaftar_label($participant['pendaftar']));
            pv_row('Tanggal daftar', ppdb_format_date_id($participant['tgl_daftar']));
            pv_row('Tahun ajaran', (string) $participant['th_ajaran']);
            if (($selection['jalur_seleksi'] ?? '') !== '') {
                pv_row('Jalur seleksi SMP', prestasi_jalur_label($selection['jalur_seleksi']));
            }
            ?>
        </dl>
        <div class="pv-contacts">
            <?php foreach (['Pendaftar' => $participant['no_hp_pendaftar'] ?? '', 'Ayah' => $participant['no_hp_ayah'] ?? '', 'Ibu' => $participant['no_hp_ibu'] ?? ''] as $role => $phone) {
                $number = ppdb_normalize_whatsapp_number((string) $phone);
                if ($number === '') {
                    continue;
                } ?>
                <a class="pv-contact" href="https://wa.me/<?php echo h($number); ?>" target="_blank" rel="noopener noreferrer"><small><?php echo h($role); ?></small><strong><?php echo h(ppdb_display_phone($number)); ?></strong></a>
            <?php } ?>
        </div>
    </section>

    <?php if (!$formFilled) { ?>
        <section class="pv-card pv-card--note">
            <h3><span class="pv-icon pv-icon--form" aria-hidden="true"></span>Formulir lengkap</h3>
            <p>Formulir lengkap belum dikirim. Data ananda, alamat, orang tua, dan profil akan tampil di sini setelah formulir dikirim.</p>
        </section>
    <?php } else {
        foreach ($sections as $key => $section) {
            $rows = [];
            $empty = 0;
            $total = 0;
            $heading = '';
            foreach ($section['fields'] as $field) {
                if (($field['type'] ?? 'text') === 'heading') {
                    $heading = $field['label'];
                    continue;
                }
                if (!formulir_condition_met($field, $participant)) {
                    continue;
                }
                $total++;
                $value = pv_value($field, $participant[$field['name']] ?? '');
                if ($value === '') {
                    $empty++;
                }
                $rows[] = [$heading, $field['label'], $value, !empty($field['wide']) || ($field['type'] ?? '') === 'textarea' || mb_strlen($value) > 38];
                $heading = '';
            }
            ?>
            <section class="pv-card" id="pv-<?php echo h($key); ?>">
                <h3><span class="pv-icon pv-icon--<?php echo h($sectionIcons[$key] ?? 'info'); ?>" aria-hidden="true"></span><?php echo h($section['title']); ?><span class="pv-count<?php echo $empty ? ' is-partial' : ''; ?>"><?php echo $total - $empty; ?>/<?php echo $total; ?> terisi</span></h3>
                <dl class="pv-grid">
                    <?php foreach ($rows as [$group, $label, $value, $wide]) {
                        if ($group !== '') { ?><div class="pv-subhead"><?php echo h($group); ?></div><?php }
                        pv_row($label, $value, $wide);
                    } ?>
                </dl>
            </section>
        <?php }
    } ?>

    <section class="pv-card" id="pv-dokumen">
        <h3><span class="pv-icon pv-icon--file" aria-hidden="true"></span>Dokumen pendukung<span class="pv-count<?php echo count(array_intersect_key($documents, $documentLabels)) < count($documentLabels) ? ' is-partial' : ''; ?>"><?php echo count(array_intersect_key($documents, $documentLabels)); ?>/<?php echo count($documentLabels); ?> ada</span></h3>
        <ul class="pv-docs">
            <?php foreach ($documentLabels as $type => $label) {
                $document = $documents[$type] ?? null; ?>
                <li class="<?php echo $document ? 'is-present' : 'is-missing'; ?>">
                    <span class="pv-doc-mark" aria-hidden="true"><?php echo $document ? '&#10003;' : '&ndash;'; ?></span>
                    <span class="pv-doc-name"><?php echo h($label); ?><?php if ($document) { ?><small>Diunggah <?php echo h(date('d-m-Y H.i', strtotime($document['diunggah_pada']))); ?></small><?php } ?></span>
                    <?php if ($document) { ?><a href="dokumen-admin.php?id=<?php echo (int) $document['id']; ?>" target="_blank" rel="noopener">Buka</a><?php } else { ?><span class="pv-muted">Belum ada</span><?php } ?>
                </li>
            <?php } ?>
        </ul>
    </section>

    <section class="pv-card" id="pv-pembayaran">
        <h3><span class="pv-icon pv-icon--wallet" aria-hidden="true"></span>Pembayaran</h3>
        <dl class="pv-grid">
            <?php
            pv_row('Biaya pendaftaran', $participant['biaya_pendaftaran'] !== null ? 'Rp '.number_format((float) $participant['biaya_pendaftaran'], 0, ',', '.') : '');
            pv_row('Batas pembayaran', $participant['tanggal_batas_bayar'] ? ppdb_format_date_id($participant['tanggal_batas_bayar']) : '');
            pv_row('Status bukti', $payment ? ($paymentLabels[$payment['status_verifikasi']] ?? $payment['status_verifikasi']) : 'Belum diunggah');
            pv_row('Diunggah', $payment ? date('d-m-Y H.i', strtotime($payment['diunggah_pada'])) : '');
            if ($payment && trim((string) $payment['catatan_admin']) !== '') {
                pv_row('Catatan admin', (string) $payment['catatan_admin'], true);
            }
            ?>
        </dl>
        <?php if ($payment) { ?><a class="pv-link" href="bukti-pembayaran-admin.php?id=<?php echo (int) $payment['id']; ?>" target="_blank" rel="noopener">Lihat bukti transfer &nearr;</a><?php } ?>
    </section>
</article>
