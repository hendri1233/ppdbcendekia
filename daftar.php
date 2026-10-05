<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
start_app_session();

$offerResult = mysqli_query($conn, "SELECT q.kode_unit,q.th_ajaran,q.kuota,q.biaya_pendaftaran,q.nomor_antrian_terakhir,q.bank_nama,q.nomor_rekening,q.nama_pemilik_rekening,q.masa_pembayaran_hari,u.nama_unit,(SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=q.kode_unit AND p.th_ajaran=q.th_ajaran AND p.status_ppdb NOT IN ('ditolak','data_lama')) AS jumlah_pendaftar FROM tb_pengaturan_ppdb q JOIN tb_unit_pendidikan u ON u.kode_unit=q.kode_unit WHERE q.pendaftaran_dibuka=1 AND q.kuota>0 ORDER BY q.th_ajaran,FIELD(u.jenjang,'TK','SD','SMP')");
$openOffers = [];
$fullOffers = [];
$availableAcademicYears = [];
$openOfferKeys = [];
$fullOfferKeys = [];
while ($offer = mysqli_fetch_assoc($offerResult)) {
    $offerKey = $offer['kode_unit'].'|'.$offer['th_ajaran'];
    if ((int) $offer['jumlah_pendaftar'] >= (int) $offer['kuota']) {
        $fullOffers[] = $offer;
        $fullOfferKeys[$offerKey] = true;
    } else {
        $openOffers[] = $offer;
        $availableAcademicYears[$offer['th_ajaran']] = $offer['th_ajaran'];
        $openOfferKeys[$offerKey] = true;
    }
}
$availableAcademicYears = array_values($availableAcademicYears);

$pendaftarOptions = ['Ayah', 'Bunda', 'Ayah dan Bunda', 'Wali'];
$formValues = $_POST;
$errors = [];

function render_initial_field(array $field, array $values, int $index): void
{
    $name = $field['name'];
    $value = (string) ($values[$name] ?? '');
    $wideClass = !empty($field['wide']) ? ' field-wide' : '';
    echo '<div class="field'.$wideClass.'" data-field="'.h($name).'">';
    echo '<label for="'.h($name).'"><span class="field-index" aria-hidden="true">'.str_pad((string) $index, 2, '0', STR_PAD_LEFT).'</span>'.h($field['label']);
    if (!empty($field['required'])) {
        echo ' <span class="required-mark" aria-hidden="true">*</span>';
    } else {
        echo ' <span class="optional-mark">Opsional</span>';
    }
    echo '</label>';

    $type = $field['type'] ?? 'text';
    if ($type === 'select') {
        echo '<select id="'.h($name).'" name="'.h($name).'"'.(!empty($field['required']) ? ' required' : '').'>';
        echo '<option value="">'.h($field['placeholder'] ?? 'Pilih salah satu').'</option>';
        foreach ($field['options'] as $optionValue => $optionLabel) {
            echo '<option value="'.h($optionValue).'"'.($value === (string) $optionValue ? ' selected' : '').'>'.h($optionLabel).'</option>';
        }
        echo '</select>';
    } else {
        $attributes = '';
        foreach (['maxlength', 'min', 'max', 'step', 'inputmode', 'pattern', 'placeholder', 'autocomplete'] as $attribute) {
            if (isset($field[$attribute])) {
                $attributes .= ' '.$attribute.'="'.h($field[$attribute]).'"';
            }
        }
        echo '<input id="'.h($name).'" type="'.h($type).'" name="'.h($name).'" value="'.h($value).'"'.$attributes.(!empty($field['required']) ? ' required' : '').'>';
    }

    if (!empty($field['hint'])) {
        echo '<small class="field-hint">'.h($field['hint']).'</small>';
    }
    echo '</div>';
}

$initialFields = [
    ['name' => 'nama_ananda', 'label' => 'Nama lengkap ananda', 'type' => 'text', 'required' => true, 'maxlength' => 50, 'autocomplete' => 'name', 'placeholder' => 'Sesuai akta kelahiran', 'wide' => true],
    ['name' => 'tanggal_lahir', 'label' => 'Tanggal lahir ananda', 'type' => 'date', 'required' => true, 'max' => date('Y-m-d')],
    ['name' => 'jenis_kelamin', 'label' => 'Jenis kelamin ananda', 'type' => 'select', 'required' => true, 'options' => ['laki-laki' => 'Laki-laki', 'perempuan' => 'Perempuan']],
    ['name' => 'pendaftar', 'label' => 'Yang mendaftarkan ananda', 'type' => 'select', 'required' => true, 'options' => array_combine($pendaftarOptions, $pendaftarOptions)],
    ['name' => 'no_hp_pendaftar', 'label' => 'Nomor WhatsApp pendaftar', 'type' => 'tel', 'required' => true, 'maxlength' => 20, 'inputmode' => 'tel', 'pattern' => '(\+62|62|0)8[0-9]{8,11}', 'placeholder' => '081234567890', 'hint' => 'Nomor yang akan dihubungi panitia.'],
    ['name' => 'no_hp_ayah', 'label' => 'Nomor WhatsApp / HP ayah', 'type' => 'tel', 'required' => true, 'maxlength' => 20, 'inputmode' => 'tel', 'pattern' => '(\+62|62|0)8[0-9]{8,11}', 'placeholder' => '081234567890'],
    ['name' => 'no_hp_ibu', 'label' => 'Nomor WhatsApp / HP ibu', 'type' => 'tel', 'required' => true, 'maxlength' => 20, 'inputmode' => 'tel', 'pattern' => '(\+62|62|0)8[0-9]{8,11}', 'placeholder' => '081234567890'],
];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $academicYear = trim($_POST['th_ajaran'] ?? '');
    $unitCode = trim($_POST['kode_unit'] ?? '');
    $studentName = trim($_POST['nama_ananda'] ?? '');
    $birthDate = trim($_POST['tanggal_lahir'] ?? '');
    $gender = trim($_POST['jenis_kelamin'] ?? '');
    $registrant = trim($_POST['pendaftar'] ?? '');
    $registrantPhone = trim($_POST['no_hp_pendaftar'] ?? '');
    $fatherPhone = trim($_POST['no_hp_ayah'] ?? '');
    $motherPhone = trim($_POST['no_hp_ibu'] ?? '');

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Sesi formulir berakhir. Muat ulang halaman sebelum mencoba kembali.';
    }
    if (($_POST['konfirmasi_data'] ?? '') !== '1') {
        $errors[] = 'Pastikan data yang diisi sudah benar sebelum melanjutkan.';
    }
    if (!in_array($academicYear, $availableAcademicYears, true)) {
        $errors[] = 'Pilih tahun ajaran yang tersedia.';
    }
    if (!isset($openOfferKeys[$unitCode.'|'.$academicYear])) {
        $errors[] = isset($fullOfferKeys[$unitCode.'|'.$academicYear])
            ? 'Kuota untuk unit dan tahun ajaran tersebut sudah terpenuhi. Silakan tunggu dan cek kembali secara berkala untuk informasi kuota tambahan.'
            : 'Pendaftaran untuk unit dan tahun ajaran tersebut sedang ditutup.';
    }
    if ($studentName === '' || strlen($studentName) > 50) {
        $errors[] = 'Nama lengkap ananda wajib diisi dan maksimal 50 karakter.';
    }
    $parsedBirthDate = DateTime::createFromFormat('Y-m-d', $birthDate);
    if (!$parsedBirthDate || $parsedBirthDate->format('Y-m-d') !== $birthDate || $birthDate > date('Y-m-d')) {
        $errors[] = 'Tanggal lahir ananda harus diisi dengan tanggal yang valid.';
    }
    if (!in_array($gender, ['laki-laki', 'perempuan'], true)) {
        $errors[] = 'Pilih jenis kelamin ananda.';
    }
    if (!in_array($registrant, $pendaftarOptions, true)) {
        $errors[] = 'Pilih siapa yang mendaftarkan ananda.';
    }
    foreach (['no_hp_pendaftar' => 'Nomor WhatsApp pendaftar', 'no_hp_ayah' => 'Nomor WhatsApp ayah', 'no_hp_ibu' => 'Nomor WhatsApp ibu'] as $field => $label) {
        if (!ppdb_is_valid_whatsapp_number(trim($_POST[$field] ?? ''))) {
            $errors[] = $label.' harus berupa nomor Indonesia yang aktif, contoh 081234567890.';
        }
    }

    if (!$errors) {
        try {
            mysqli_begin_transaction($conn);
            $offerLock = mysqli_prepare($conn, 'SELECT kuota,pendaftaran_dibuka,nomor_antrian_terakhir FROM tb_pengaturan_ppdb WHERE kode_unit = ? AND th_ajaran = ? FOR UPDATE');
            mysqli_stmt_bind_param($offerLock, 'ss', $unitCode, $academicYear);
            mysqli_stmt_execute($offerLock);
            $lockedOffer = mysqli_fetch_assoc(mysqli_stmt_get_result($offerLock));
            mysqli_stmt_close($offerLock);
            if (!$lockedOffer || !(int) $lockedOffer['pendaftaran_dibuka'] || (int) $lockedOffer['kuota'] < 1) {
                throw new RuntimeException('Pendaftaran untuk unit dan tahun ajaran tersebut baru saja ditutup.');
            }
            $countRegistrations = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM tb_pendaftaran WHERE kode_unit=? AND th_ajaran=? AND status_ppdb NOT IN ('ditolak','data_lama')");
            mysqli_stmt_bind_param($countRegistrations, 'ss', $unitCode, $academicYear);
            mysqli_stmt_execute($countRegistrations);
            $registrationCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countRegistrations))['total'];
            mysqli_stmt_close($countRegistrations);
            if ($registrationCount >= (int) $lockedOffer['kuota']) {
                throw new RuntimeException('Kuota untuk unit dan tahun ajaran tersebut sudah terpenuhi. Silakan tunggu dan cek kembali secara berkala untuk informasi kuota tambahan.');
            }

            $sequenceYear = (int) date('Y');
            $sequenceInit = mysqli_prepare($conn, 'INSERT INTO tb_sequence_pendaftaran (tahun,nomor_terakhir) VALUES (?,0) ON DUPLICATE KEY UPDATE nomor_terakhir=nomor_terakhir');
            mysqli_stmt_bind_param($sequenceInit, 'i', $sequenceYear);
            mysqli_stmt_execute($sequenceInit);
            mysqli_stmt_close($sequenceInit);
            $sequenceLock = mysqli_prepare($conn, 'SELECT nomor_terakhir FROM tb_sequence_pendaftaran WHERE tahun = ? FOR UPDATE');
            mysqli_stmt_bind_param($sequenceLock, 'i', $sequenceYear);
            mysqli_stmt_execute($sequenceLock);
            $sequence = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($sequenceLock))['nomor_terakhir'];
            mysqli_stmt_close($sequenceLock);
            $nextSequence = $sequence + 1;
            if ($nextSequence > 99999) {
                throw new RuntimeException('Nomor pendaftaran untuk tahun ini sudah mencapai batas.');
            }
            $sequenceUpdate = mysqli_prepare($conn, 'UPDATE tb_sequence_pendaftaran SET nomor_terakhir = ? WHERE tahun = ?');
            mysqli_stmt_bind_param($sequenceUpdate, 'ii', $nextSequence, $sequenceYear);
            mysqli_stmt_execute($sequenceUpdate);
            mysqli_stmt_close($sequenceUpdate);

            $queueNumber = (int) $lockedOffer['nomor_antrian_terakhir'] + 1;
            $queueUpdate = mysqli_prepare($conn, 'UPDATE tb_pengaturan_ppdb SET nomor_antrian_terakhir = ? WHERE kode_unit = ? AND th_ajaran = ?');
            mysqli_stmt_bind_param($queueUpdate, 'iss', $queueNumber, $unitCode, $academicYear);
            mysqli_stmt_execute($queueUpdate);
            mysqli_stmt_close($queueUpdate);

            $registrationId = 'P'.$sequenceYear.sprintf('%05d', $nextSequence);
            $receiptToken = bin2hex(random_bytes(32));
            $receiptTokenHash = hash('sha256', $receiptToken);

            $mainInsert = mysqli_prepare($conn, "INSERT INTO tb_pendaftaran
                (id_pendaftaran, tgl_daftar, waktu_daftar, th_ajaran, kode_unit, nomor_antrian, status_ppdb, status_data, status_pembayaran, nm_peserta, tgl_lahir, jenis_kelamin, pendaftar, no_hp, no_hp_pendaftar, no_hp_ayah, no_hp_ibu, token_bukti)
                VALUES (?, CURDATE(), NOW(), ?, ?, ?, 'menunggu_kontak', 'belum_diperiksa', 'belum_bayar', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($mainInsert, 'sssi'.str_repeat('s', 9), $registrationId, $academicYear, $unitCode, $queueNumber, $studentName, $birthDate, $gender, $registrant, $registrantPhone, $registrantPhone, $fatherPhone, $motherPhone, $receiptTokenHash);
            if (!mysqli_stmt_execute($mainInsert)) {
                mysqli_stmt_close($mainInsert);
                throw new RuntimeException('Pendaftaran belum dapat disimpan. Silakan coba lagi.');
            }
            mysqli_stmt_close($mainInsert);

            $detailInsert = mysqli_prepare($conn, 'INSERT INTO tb_detail_dapodik (id_pendaftaran) VALUES (?)');
            mysqli_stmt_bind_param($detailInsert, 's', $registrationId);
            mysqli_stmt_execute($detailInsert);
            mysqli_stmt_close($detailInsert);

            enqueue_google_sheets_registration($conn, $registrationId);
            mysqli_commit($conn);

            sync_google_sheets_registration($conn, $registrationId);
            header('Location: berhasil.php?id='.urlencode($registrationId).'&token='.urlencode($receiptToken));
            exit;
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            if ($exception instanceof RuntimeException) {
                $errors[] = $exception->getMessage();
            } else {
                error_log('PPDB initial registration transaction failed: '.$exception->getMessage());
                $errors[] = 'Pendaftaran belum dapat disimpan. Periksa kembali data lalu coba lagi.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123d31">
    <meta name="description" content="Registrasi awal PPDB Yayasan Wakaf Cendekia Takengon. Isi data dasar, tunggu verifikasi, lalu lengkapi formulir.">
    <title>Registrasi Awal | PPDB Yayasan Wakaf Cendekia Takengon</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/daftar.css">
</head>
<body>
<a class="skip-link" href="#form-utama">Lompat ke formulir</a>
<div class="page-shell">
    <header class="site-header">
        <a class="brand" href="index.php" aria-label="Yayasan Wakaf Cendekia Takengon, beranda">
            <img class="brand-logo" src="img/logo-display.png" alt="" width="46" height="45">
            <span class="brand-copy"><strong>Wakaf Cendekia</strong><small>TAKENGON · PPDB ONLINE</small></span>
        </a>
        <a class="back-link" href="index.php">Kembali ke beranda</a>
    </header>

    <main class="initial-layout">
        <section class="intro intro-rail">
            <p class="eyebrow">TAHAP 1 DARI 3 · REGISTRASI AWAL</p>
            <h1>Cukup data dasar,<br><span>sisanya kami pandu.</span></h1>
            <p class="intro-copy">Isi tujuh isian singkat berikut. Panitia menghubungi Anda lewat WhatsApp untuk pembayaran, lalu Anda melengkapi formulir dan mengunggah dokumen.</p>
            <div class="progress-ring">
                <div class="progress-ring__top">
                    <span class="progress-ring__label">Kelengkapan isian</span>
                    <span class="progress-ring__count" data-progress-count role="status" aria-live="polite">0 / 0</span>
                </div>
                <div class="progress-ring__track"><div class="progress-ring__bar" data-progress-bar></div></div>
            </div>
            <ol class="journey-list" aria-label="Alur pendaftaran">
                <li class="is-active"><span aria-hidden="true">1</span><div><strong>Registrasi awal</strong><small>7 isian dasar, tanpa NIK</small></div></li>
                <li><span aria-hidden="true">2</span><div><strong>Verifikasi &amp; pembayaran</strong><small>Panitia menghubungi lewat WhatsApp</small></div></li>
                <li><span aria-hidden="true">3</span><div><strong>Formulir lengkap</strong><small>Data detail dan dokumen pendukung</small></div></li>
            </ol>
            <div class="intro-meta"><span class="meta-dot" aria-hidden="true"></span> <?php echo $openOffers ? 'Pendaftaran mengikuti kuota yang dibuka tiap unit.' : ($fullOffers ? 'Kuota pendaftaran saat ini telah terpenuhi.' : 'Pendaftaran belum dibuka oleh admin.'); ?></div>
        </section>

        <div class="initial-main">
            <?php if ($fullOffers) { ?>
                <div class="error-summary" role="status">
                    <strong>Kuota pendaftaran sudah terpenuhi</strong>
                    <ul>
                        <?php foreach ($fullOffers as $fullOffer) { ?><li><?php echo h($fullOffer['nama_unit']); ?> · <?php echo h($fullOffer['th_ajaran']); ?> sudah mencapai kuota <?php echo (int) $fullOffer['kuota']; ?> peserta.</li><?php } ?>
                        <li>Silakan tunggu dan cek kembali secara berkala untuk informasi kuota tambahan.</li>
                    </ul>
                </div>
            <?php } elseif (!$openOffers) { ?><div class="error-summary" role="status"><strong>Pendaftaran sedang ditutup</strong><ul><li>Belum ada unit dan tahun ajaran yang dibuka admin. Silakan hubungi panitia PPDB.</li></ul></div><?php } ?>

            <?php if ($errors) { ?>
                <div class="error-summary" role="alert" aria-labelledby="error-title">
                    <strong id="error-title">Periksa kembali data pendaftaran</strong>
                    <ul><?php foreach ($errors as $error) { ?><li><?php echo h($error); ?></li><?php } ?></ul>
                </div>
            <?php } ?>

            <form class="registration-form" id="form-utama" action="daftar.php" method="post" autocomplete="on" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">

                <section class="form-section" aria-labelledby="unit-title">
                    <div class="section-heading">
                        <span class="section-number">01</span>
                        <div><h2 id="unit-title">Pilih unit pendidikan</h2><p>Unit menentukan kuota dan administrasi yang berlaku.</p></div>
                    </div>
                    <div class="field-grid">
                        <div class="field" data-field="th_ajaran">
                            <label for="th_ajaran"><span class="field-index">1</span>Tahun ajaran <span class="required-mark" aria-hidden="true">*</span></label>
                            <select id="th_ajaran" name="th_ajaran" required>
                                <option value="">Pilih tahun ajaran</option>
                                <?php foreach ($availableAcademicYears as $yearOption) { ?>
                                    <option value="<?php echo h($yearOption); ?>" <?php echo (($_POST['th_ajaran'] ?? '') === $yearOption) ? 'selected' : ''; ?>><?php echo h($yearOption); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="field" data-field="kode_unit">
                            <label for="kode_unit"><span class="field-index">2</span>Unit pendidikan <span class="required-mark" aria-hidden="true">*</span></label>
                            <select id="kode_unit" name="kode_unit" required>
                                <option value="">Pilih unit pendidikan</option>
                                <?php foreach ($openOffers as $offer) { ?>
                                    <option value="<?php echo h($offer['kode_unit']); ?>" data-year="<?php echo h($offer['th_ajaran']); ?>" data-fee="<?php echo h(number_format((float) $offer['biaya_pendaftaran'], 2, '.', '')); ?>" data-bank="<?php echo h($offer['bank_nama']); ?>" data-account="<?php echo h($offer['nomor_rekening']); ?>" data-account-name="<?php echo h($offer['nama_pemilik_rekening']); ?>" <?php echo (($_POST['kode_unit'] ?? '') === $offer['kode_unit'] && ($_POST['th_ajaran'] ?? '') === $offer['th_ajaran']) ? 'selected' : ''; ?>><?php echo h($offer['nama_unit']); ?> · kuota <?php echo (int) $offer['kuota']; ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                    <div class="offer-summary" id="offer-summary" hidden></div>
                </section>

                <section class="form-section" aria-labelledby="data-title">
                    <div class="section-heading">
                        <span class="section-number">02</span>
                        <div><h2 id="data-title">Data dasar ananda &amp; pendaftar</h2><p>Tujuh isian ini memulai proses. NIK dan dokumen menyusul setelah pembayaran.</p></div>
                    </div>
                    <div class="field-grid">
                        <?php foreach ($initialFields as $index => $field) { render_initial_field($field, $formValues, $index + 3); } ?>
                    </div>
                    <label class="consent-check" for="konfirmasi_data">
                        <input type="checkbox" id="konfirmasi_data" name="konfirmasi_data" value="1" required <?php echo (($_POST['konfirmasi_data'] ?? '') === '1') ? 'checked' : ''; ?>>
                        <span>Saya memastikan data di atas sudah benar dan dapat dihubungi panitia melalui WhatsApp.</span>
                    </label>
                </section>

                <div class="form-footer">
                    <p>Setelah dikirim Anda menerima kode pendaftaran untuk memantau status. Formulir lengkap hanya dibuka setelah bukti pembayaran disetujui.</p>
                    <button type="submit" name="submit" value="1" class="submit-button" <?php echo !$openOffers ? 'disabled' : ''; ?>>Kirim registrasi awal <span aria-hidden="true">&rarr;</span></button>
                </div>
            </form>
        </div>
    </main>

    <footer class="site-footer"><span>Yayasan Wakaf Cendekia Takengon</span><a href="mailto:wakafcendekiatakengon@gmail.com">Hubungi panitia</a></footer>
</div>
<script src="js/daftar.js"></script>
<script src="js/reveal.js"></script>
</body>
</html>
