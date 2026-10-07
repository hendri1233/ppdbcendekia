<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
start_app_session();
if (isset($_GET['internal'])) {
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: private, no-store');
}

$offerResult = mysqli_query($conn, "SELECT q.kode_unit,q.th_ajaran,q.kuota_internal,q.kuota_eksternal,q.internal_dibuka,q.eksternal_dibuka,q.biaya_pendaftaran,q.nomor_antrian_terakhir,q.bank_nama,q.nomor_rekening,q.nama_pemilik_rekening,q.masa_pembayaran_hari,u.nama_unit,(SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=q.kode_unit AND p.th_ajaran=q.th_ajaran AND p.jalur_pendaftaran='eksternal' AND p.status_ppdb NOT IN ('ditolak','data_lama')) AS eksternal_terisi FROM tb_pengaturan_ppdb q JOIN tb_unit_pendidikan u ON u.kode_unit=q.kode_unit ORDER BY q.th_ajaran,FIELD(u.jenjang,'KB','TPA','TK','SD','SMP')");
$openOffers = [];
$fullOffers = [];
$settingsByRoute = [];
while ($offer = mysqli_fetch_assoc($offerResult)) {
    $offer['kuota_eksternal'] = (int) $offer['kuota_eksternal'];
    $offer['eksternal_terisi'] = (int) $offer['eksternal_terisi'];
    $offerKey = $offer['kode_unit'].'|'.$offer['th_ajaran'];
    $settingsByRoute[$offerKey] = $offer;
    if ((int) substr($offer['th_ajaran'], 0, 4) < 2027) {
        continue;
    }
    if ((int) $offer['eksternal_dibuka'] && $offer['kuota_eksternal'] > 0
        && $offer['eksternal_terisi'] >= $offer['kuota_eksternal']) {
        $fullOffers[] = $offer;
    } elseif ((int) $offer['eksternal_dibuka'] && $offer['kuota_eksternal'] > 0) {
        $openOffers[] = $offer;
    }
}

$internalSessionKey = 'ppdb_internal_token_id';
$tokenEntryError = '';
$tokenInput = '';
$tokenEntryRequested = isset($_GET['internal']) && $_GET['internal'] === '1';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validate_internal_token') {
    $tokenEntryRequested = true;
    $tokenInput = is_string($_POST['kode_token'] ?? null) ? trim($_POST['kode_token']) : '';
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $tokenEntryError = 'Sesi formulir kedaluwarsa. Muat ulang halaman dan coba kembali.';
    } elseif (!preg_match('/^[A-Za-z0-9]{9}$/D', $tokenInput)
        || !preg_match('/[A-Z]/', $tokenInput)
        || !preg_match('/[a-z]/', $tokenInput)
        || !preg_match('/[0-9]/', $tokenInput)) {
        $tokenEntryError = 'Masukkan kode 9 karakter yang mengandung huruf besar, huruf kecil, dan angka.';
    } else {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $ipHash = hash('sha256', is_string($clientIp) ? $clientIp : '');
        try {
            mysqli_begin_transaction($conn);
            $rateInit = mysqli_prepare($conn, 'INSERT INTO tb_token_internal_attempts (ip_hash,window_started_at,attempts) VALUES (?,NOW(),0) ON DUPLICATE KEY UPDATE ip_hash=VALUES(ip_hash)');
            mysqli_stmt_bind_param($rateInit, 's', $ipHash);
            mysqli_stmt_execute($rateInit);
            mysqli_stmt_close($rateInit);
            $rateLock = mysqli_prepare($conn, 'SELECT window_started_at,attempts,(window_started_at<=DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS expired FROM tb_token_internal_attempts WHERE ip_hash=? FOR UPDATE');
            mysqli_stmt_bind_param($rateLock, 's', $ipHash);
            mysqli_stmt_execute($rateLock);
            $rate = mysqli_fetch_assoc(mysqli_stmt_get_result($rateLock));
            mysqli_stmt_close($rateLock);
            if ((int) $rate['expired']) {
                $resetRate = mysqli_prepare($conn, 'UPDATE tb_token_internal_attempts SET window_started_at=NOW(),attempts=0 WHERE ip_hash=?');
                mysqli_stmt_bind_param($resetRate, 's', $ipHash);
                mysqli_stmt_execute($resetRate);
                mysqli_stmt_close($resetRate);
                $rate['attempts'] = 0;
            }
            if ((int) $rate['attempts'] >= 10) {
                mysqli_commit($conn);
                $tokenEntryError = 'Terlalu banyak percobaan. Silakan coba kembali setelah 15 menit.';
            } else {
                $tokenQuery = mysqli_prepare($conn, "SELECT t.id FROM tb_token_internal t JOIN tb_pengaturan_ppdb q ON q.kode_unit=t.kode_unit AND q.th_ajaran=t.th_ajaran WHERE BINARY t.kode_token=BINARY ? AND t.used_at IS NULL AND t.revoked_at IS NULL AND q.internal_dibuka=1 AND q.kuota_internal>0 LIMIT 1");
                mysqli_stmt_bind_param($tokenQuery, 's', $tokenInput);
                mysqli_stmt_execute($tokenQuery);
                $validToken = mysqli_fetch_assoc(mysqli_stmt_get_result($tokenQuery));
                mysqli_stmt_close($tokenQuery);
                if ($validToken) {
                    $clearRate = mysqli_prepare($conn, 'UPDATE tb_token_internal_attempts SET window_started_at=NOW(),attempts=0 WHERE ip_hash=?');
                    mysqli_stmt_bind_param($clearRate, 's', $ipHash);
                    mysqli_stmt_execute($clearRate);
                    mysqli_stmt_close($clearRate);
                    mysqli_commit($conn);
                    session_regenerate_id(true);
                    $_SESSION[$internalSessionKey] = (int) $validToken['id'];
                    header('Location: daftar.php?internal=1&form=1');
                    exit;
                }
                $countAttempt = mysqli_prepare($conn, 'UPDATE tb_token_internal_attempts SET attempts=attempts+1 WHERE ip_hash=?');
                mysqli_stmt_bind_param($countAttempt, 's', $ipHash);
                mysqli_stmt_execute($countAttempt);
                mysqli_stmt_close($countAttempt);
                mysqli_commit($conn);
                $tokenEntryError = 'Kode tidak valid, sudah digunakan, atau jalur internal sedang ditutup.';
            }
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            error_log('PPDB internal token validation failed: '.$exception->getMessage());
            $tokenEntryError = 'Kode belum dapat diperiksa. Silakan coba kembali.';
        }
    }
}

$routeType = 'eksternal';
$routeOffer = null;
$routeError = '';
$routeSelected = true;
$selectedUnitCode = '';
$selectedAcademicYear = '';
if ($tokenEntryRequested || !empty($_SESSION[$internalSessionKey])) {
    $internalTokenId = filter_var($_SESSION[$internalSessionKey] ?? null, FILTER_VALIDATE_INT);
    if ($internalTokenId !== false && $internalTokenId !== null && $internalTokenId > 0) {
        $internalQuery = mysqli_prepare($conn, "SELECT t.id,t.kode_unit,t.th_ajaran,t.used_at,t.revoked_at,q.kuota_internal,q.internal_dibuka,q.biaya_pendaftaran,q.bank_nama,q.nomor_rekening,q.nama_pemilik_rekening,q.masa_pembayaran_hari,u.nama_unit,(SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=t.kode_unit AND p.th_ajaran=t.th_ajaran AND p.jalur_pendaftaran='internal' AND p.status_ppdb NOT IN ('ditolak','data_lama')) AS internal_terisi FROM tb_token_internal t JOIN tb_pengaturan_ppdb q ON q.kode_unit=t.kode_unit AND q.th_ajaran=t.th_ajaran JOIN tb_unit_pendidikan u ON u.kode_unit=t.kode_unit WHERE t.id=? LIMIT 1");
        mysqli_stmt_bind_param($internalQuery, 'i', $internalTokenId);
        mysqli_stmt_execute($internalQuery);
        $internalCode = mysqli_fetch_assoc(mysqli_stmt_get_result($internalQuery));
        mysqli_stmt_close($internalQuery);
        if (!$internalCode || $internalCode['used_at'] !== null || $internalCode['revoked_at'] !== null) {
            unset($_SESSION[$internalSessionKey]);
            $tokenEntryRequested = true;
        } elseif (!(int) $internalCode['internal_dibuka'] || (int) $internalCode['kuota_internal'] < 1) {
            $routeError = 'Pendaftaran internal untuk unit dan tahun ajaran ini sedang ditutup.';
        } elseif ((int) $internalCode['internal_terisi'] >= (int) $internalCode['kuota_internal']) {
            $routeError = 'Kuota internal sudah terpenuhi. Hubungi panitia untuk informasi lebih lanjut.';
        } else {
            $routeType = 'internal';
            $routeOffer = $internalCode;
        }
    }
} else {
    $selectedRoute = is_string($_POST['external_route'] ?? null)
        ? trim($_POST['external_route'])
        : '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $selectedRoute === '') {
        $selectedUnitCode = is_string($_GET['unit'] ?? null) ? trim($_GET['unit']) : '';
        $selectedAcademicYear = is_string($_GET['tahun'] ?? null) ? trim($_GET['tahun']) : '';
    } else {
        [$selectedUnitCode, $selectedAcademicYear] = array_pad(explode('|', $selectedRoute, 2), 2, '');
    }
    $offerKey = $selectedUnitCode.'|'.$selectedAcademicYear;
    $candidate = $settingsByRoute[$offerKey] ?? null;
    if ($selectedUnitCode !== '' || $selectedAcademicYear !== '') {
        if (!$candidate || !(int) $candidate['eksternal_dibuka'] || $candidate['kuota_eksternal'] < 1) {
            $routeError = 'Pendaftaran eksternal untuk unit dan tahun ajaran tersebut sedang ditutup.';
        } elseif ($candidate['eksternal_terisi'] >= $candidate['kuota_eksternal']) {
            $routeError = 'Kuota eksternal untuk unit dan tahun ajaran tersebut sudah terpenuhi.';
        } else {
            $routeOffer = $candidate;
        }
    }
}

$formAction = 'daftar.php';
if ($routeOffer && $routeType === 'internal') {
    $formAction .= '?internal=1&form=1';
}

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
        if ($name === 'tanggal_lahir') {
            echo '<small class="field-hint" data-dapodik-age aria-live="polite">Pilih unit dan tahun ajaran untuk melihat umur pada tanggal acuan Dapodik.</small>';
        }
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'validate_internal_token') {
    $academicYear = (string) ($routeType === 'internal' ? ($routeOffer['th_ajaran'] ?? '') : $selectedAcademicYear);
    $unitCode = (string) ($routeType === 'internal' ? ($routeOffer['kode_unit'] ?? '') : $selectedUnitCode);
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
    if (!$routeOffer || $routeError !== '') {
        $errors[] = $routeError !== '' ? $routeError : 'Buka tautan pendaftaran unit yang ingin didaftarkan.';
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
            $offerLock = mysqli_prepare($conn, 'SELECT kuota_internal,kuota_eksternal,internal_dibuka,eksternal_dibuka,nomor_antrian_terakhir FROM tb_pengaturan_ppdb WHERE kode_unit = ? AND th_ajaran = ? FOR UPDATE');
            mysqli_stmt_bind_param($offerLock, 'ss', $unitCode, $academicYear);
            mysqli_stmt_execute($offerLock);
            $lockedOffer = mysqli_fetch_assoc(mysqli_stmt_get_result($offerLock));
            mysqli_stmt_close($offerLock);
            $isInternal = $routeType === 'internal';
            $quotaColumn = $isInternal ? 'kuota_internal' : 'kuota_eksternal';
            $openColumn = $isInternal ? 'internal_dibuka' : 'eksternal_dibuka';
            if (!$lockedOffer || !(int) $lockedOffer[$openColumn] || (int) $lockedOffer[$quotaColumn] < 1) {
                throw new RuntimeException('Pendaftaran untuk jalur, unit, dan tahun ajaran tersebut baru saja ditutup.');
            }
            $countRegistrations = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM tb_pendaftaran WHERE kode_unit=? AND th_ajaran=? AND jalur_pendaftaran=? AND status_ppdb NOT IN ('ditolak','data_lama')");
            mysqli_stmt_bind_param($countRegistrations, 'sss', $unitCode, $academicYear, $routeType);
            mysqli_stmt_execute($countRegistrations);
            $registrationCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countRegistrations))['total'];
            mysqli_stmt_close($countRegistrations);
            if ($registrationCount >= (int) $lockedOffer[$quotaColumn]) {
                throw new RuntimeException('Kuota jalur ini untuk unit dan tahun ajaran tersebut sudah terpenuhi.');
            }

            $lockedInternalTokenId = null;
            if ($isInternal) {
                $lockedInternalTokenId = (int) $routeOffer['id'];
                $tokenLock = mysqli_prepare($conn, 'SELECT id FROM tb_token_internal WHERE id=? AND kode_unit=? AND th_ajaran=? AND used_at IS NULL AND revoked_at IS NULL FOR UPDATE');
                mysqli_stmt_bind_param($tokenLock, 'iss', $lockedInternalTokenId, $unitCode, $academicYear);
                mysqli_stmt_execute($tokenLock);
                $lockedToken = mysqli_fetch_assoc(mysqli_stmt_get_result($tokenLock));
                mysqli_stmt_close($tokenLock);
                if (!$lockedToken) {
                    throw new RuntimeException('Kode internal sudah digunakan atau dicabut.');
                }
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

            $legacyInviteId = null;
            $mainInsert = mysqli_prepare($conn, "INSERT INTO tb_pendaftaran
                (id_pendaftaran, tgl_daftar, waktu_daftar, th_ajaran, kode_unit, jalur_pendaftaran, undangan_internal_id, token_internal_id, nomor_antrian, status_ppdb, status_data, status_pembayaran, nm_peserta, tgl_lahir, jenis_kelamin, pendaftar, no_hp, no_hp_pendaftar, no_hp_ayah, no_hp_ibu, token_bukti)
                VALUES (?, CURDATE(), NOW(), ?, ?, ?, ?, ?, ?, 'menunggu_kontak', 'belum_diperiksa', 'belum_bayar', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($mainInsert, 'ssssii'.'i'.str_repeat('s', 9), $registrationId, $academicYear, $unitCode, $routeType, $legacyInviteId, $lockedInternalTokenId, $queueNumber, $studentName, $birthDate, $gender, $registrant, $registrantPhone, $registrantPhone, $fatherPhone, $motherPhone, $receiptTokenHash);
            if (!mysqli_stmt_execute($mainInsert)) {
                mysqli_stmt_close($mainInsert);
                throw new RuntimeException('Pendaftaran belum dapat disimpan. Silakan coba lagi.');
            }
            mysqli_stmt_close($mainInsert);

            if ($lockedInternalTokenId !== null) {
                $markTokenUsed = mysqli_prepare($conn, 'UPDATE tb_token_internal SET used_at=NOW() WHERE id=? AND used_at IS NULL AND revoked_at IS NULL');
                mysqli_stmt_bind_param($markTokenUsed, 'i', $lockedInternalTokenId);
                mysqli_stmt_execute($markTokenUsed);
                if (mysqli_stmt_affected_rows($markTokenUsed) !== 1) {
                    mysqli_stmt_close($markTokenUsed);
                    throw new RuntimeException('Kode internal sudah digunakan.');
                }
                mysqli_stmt_close($markTokenUsed);
            }
            $detailInsert = mysqli_prepare($conn, 'INSERT INTO tb_detail_dapodik (id_pendaftaran) VALUES (?)');
            mysqli_stmt_bind_param($detailInsert, 's', $registrationId);
            mysqli_stmt_execute($detailInsert);
            mysqli_stmt_close($detailInsert);

            enqueue_google_sheets_registration($conn, $registrationId);
            mysqli_commit($conn);
            unset($_SESSION[$internalSessionKey]);

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
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Registrasi awal PPDB Yayasan Wakaf Cendekia Takengon. Isi data dasar, tunggu verifikasi, lalu lengkapi formulir.">
    <title>Registrasi Awal | PPDB Yayasan Wakaf Cendekia Takengon</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/daftar.css">
</head>
<body data-academic-year="<?php echo h($routeOffer['th_ajaran'] ?? ''); ?>">
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
            <?php if ($routeType === 'eksternal' && !$tokenEntryRequested && empty($_SESSION[$internalSessionKey])) { ?>
                <section class="form-section" aria-labelledby="route-title">
                    <div class="section-heading">
                        <span class="section-number">01</span>
                        <div><h2 id="route-title">Pendaftaran umum</h2><p>Pilih unit dan tahun ajaran, lalu lengkapi registrasi awal. Jalur eksternal ditetapkan otomatis.</p></div>
                    </div>
                </section>
                <?php if ($routeError !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') { ?>
                    <div class="error-summary" role="alert"><strong>Pilihan pendaftaran tidak tersedia</strong><ul><li><?php echo h($routeError); ?></li></ul></div>
                <?php } ?>
                <?php if ($errors) { ?>
                    <div class="error-summary" role="alert" aria-labelledby="error-title">
                        <strong id="error-title">Periksa kembali data pendaftaran</strong>
                        <ul><?php foreach ($errors as $error) { ?><li><?php echo h($error); ?></li><?php } ?></ul>
                    </div>
                <?php } ?>
                <?php if (!$openOffers) { ?>
                    <div class="error-summary" role="status"><strong>Pendaftaran eksternal belum tersedia</strong><ul><li>Belum ada kuota eksternal yang dibuka untuk tahun ajaran 2027/2028 atau kuota telah terpenuhi. Silakan hubungi panitia PPDB.</li></ul></div>
                <?php } ?>
                <form class="registration-form" id="form-utama" action="daftar.php" method="post" autocomplete="on" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <section class="form-section" aria-labelledby="data-title">
                        <div class="section-heading">
                            <span class="section-number">02</span>
                            <div><h2 id="data-title">Data dasar ananda &amp; pendaftar</h2><p>Pilih unit dan tahun ajaran, lalu isi tujuh data awal. NIK dan dokumen menyusul setelah pembayaran.</p></div>
                        </div>
                        <div class="field-grid">
                            <div class="field field-wide">
                                <label for="external_route"><span class="field-index" aria-hidden="true">01</span>Unit pendidikan dan tahun ajaran <span class="required-mark" aria-hidden="true">*</span></label>
                                <select id="external_route" name="external_route" required <?php echo !$openOffers ? 'disabled' : ''; ?>>
                                    <option value="">Pilih unit dan tahun ajaran</option>
                                    <?php foreach ($openOffers as $offer) {
                                        $offerValue = $offer['kode_unit'].'|'.$offer['th_ajaran'];
                                        $offerSelected = $selectedUnitCode === $offer['kode_unit'] && $selectedAcademicYear === $offer['th_ajaran'];
                                    ?>
                                        <option value="<?php echo h($offerValue); ?>"<?php echo $offerSelected ? ' selected' : ''; ?>><?php echo h($offer['nama_unit']); ?> · <?php echo h($offer['th_ajaran']); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <?php foreach ($initialFields as $index => $field) { render_initial_field($field, $formValues, $index + 2); } ?>
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
            <?php } elseif ($tokenEntryRequested && !$routeOffer && $routeError === '') { ?>
                <section class="form-section" aria-labelledby="internal-code-title">
                    <div class="section-heading">
                        <span class="section-number">01</span>
                        <div><h2 id="internal-code-title">Pendaftaran</h2><p>Masukkan kode 9 karakter yang terdiri dari huruf besar, huruf kecil, dan angka. Setiap kode hanya berlaku untuk satu peserta dan satu kali pendaftaran.</p></div>
                    </div>
                    <?php if ($tokenEntryError !== '') { ?><div class="error-summary" role="alert"><strong>Kode belum dapat digunakan</strong><ul><li><?php echo h($tokenEntryError); ?></li></ul></div><?php } ?>
                    <form class="registration-form" action="daftar.php?internal=1" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="validate_internal_token">
                        <div class="field-grid">
                            <div class="field field-wide">
                                <label for="kode_token">Kode internal 9 karakter <span class="required-mark" aria-hidden="true">*</span></label>
                                <input id="kode_token" name="kode_token" type="text" inputmode="text" autocomplete="one-time-code" pattern="[A-Za-z0-9]{9}" minlength="9" maxlength="9" value="<?php echo h($tokenInput); ?>" required>
                            </div>
                        </div>
                        <div class="form-footer">
                            <p>Jangan bagikan kode ini kepada orang lain. Kode akan ditandai terpakai setelah registrasi awal berhasil dikirim.</p>
                            <button class="submit-button" type="submit">Lanjutkan pendaftaran <span aria-hidden="true">&rarr;</span></button>
                        </div>
                    </form>
                </section>
            <?php } elseif (!$routeOffer || $routeError !== '') { ?>
                <div class="error-summary" role="alert"><strong>Pendaftaran tidak dapat dilanjutkan</strong><ul><li><?php echo h($routeError !== '' ? $routeError : 'Tautan pendaftaran tidak valid.'); ?></li></ul></div>
            <?php } else { ?>
                <section class="form-section" aria-labelledby="route-title">
                    <div class="section-heading">
                        <span class="section-number">01</span>
                        <div><h2 id="route-title"><?php echo $routeType === 'internal' ? 'Undangan pendaftaran internal' : 'Pendaftaran umum'; ?></h2>
                            <p><?php echo h($routeOffer['nama_unit']); ?> · Tahun ajaran <?php echo h($routeOffer['th_ajaran']); ?></p>
                        </div>
                    </div>
                    <?php if ($routeType === 'internal') { ?>
                        <p class="field-hint">Kode internal ini berlaku untuk satu peserta dan hanya dapat digunakan sekali.</p>
                    <?php } ?>
                    <div class="offer-summary">
                        <strong>Jalur <?php echo $routeType === 'internal' ? 'internal SDM yayasan' : 'eksternal umum'; ?></strong>
                        <span>Unit dan tahun ajaran terikat pada tautan ini dan tidak dapat diubah di formulir.</span>
                    </div>
                </section>

                <?php if ($errors) { ?>
                    <div class="error-summary" role="alert" aria-labelledby="error-title">
                        <strong id="error-title">Periksa kembali data pendaftaran</strong>
                        <ul><?php foreach ($errors as $error) { ?><li><?php echo h($error); ?></li><?php } ?></ul>
                    </div>
                <?php } ?>

            <form class="registration-form" id="form-utama" action="<?php echo h($formAction); ?>" method="post" autocomplete="on" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">

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
                    <button type="submit" name="submit" value="1" class="submit-button">Kirim registrasi awal <span aria-hidden="true">&rarr;</span></button>
                </div>
            </form>
            <?php } ?>
        </div>
    </main>

    <footer class="site-footer"><span>Yayasan Wakaf Cendekia Takengon</span><a href="mailto:wakafcendekiatakengon@gmail.com">Hubungi panitia</a></footer>
</div>
<script src="js/daftar.js"></script>
<script src="js/reveal.js"></script>
</body>
</html>
