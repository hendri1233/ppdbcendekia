<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'ppdb_material_helpers.php';
require_once 'google_sheets_sync.php';
require_once 'web_stats.php';
require_once 'prestasi_helpers.php';
require_once 'wablas.php';
start_app_session();
if (isset($_GET['internal'])) {
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: private, no-store');
}

$offerResult = mysqli_query($conn, "SELECT q.kode_unit,q.th_ajaran,q.kuota_internal,q.kuota_eksternal,".ppdb_internal_open_condition_sql($conn)." AS internal_dibuka,".ppdb_route_open_sql($conn, 'eksternal_dibuka').",".ppdb_schedule_columns_sql($conn).",q.biaya_pendaftaran,q.nomor_antrian_terakhir,q.bank_nama,q.nomor_rekening,q.nama_pemilik_rekening,q.masa_pembayaran_hari,u.nama_unit,u.jenjang,(SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=q.kode_unit AND p.th_ajaran=q.th_ajaran AND p.jalur_pendaftaran='eksternal' AND p.status_ppdb NOT IN ('ditolak','data_lama')) AS eksternal_terisi FROM tb_pengaturan_ppdb q JOIN tb_unit_pendidikan u ON u.kode_unit=q.kode_unit ORDER BY q.th_ajaran,FIELD(u.jenjang,'KB','TPA','TK','SD','SMP')");
$openOffers = [];
$fullOffers = [];
// Semua unit tetap tampil di pilihan; unit yang belum dibuka, sudah ditutup, atau penuh memunculkan popup.
$allOffers = [];
$settingsByRoute = [];
while ($offer = mysqli_fetch_assoc($offerResult)) {
    $offer['kuota_eksternal'] = (int) $offer['kuota_eksternal'];
    $offer['eksternal_terisi'] = (int) $offer['eksternal_terisi'];
    $offerKey = $offer['kode_unit'].'|'.$offer['th_ajaran'];
    $settingsByRoute[$offerKey] = $offer;
    if ((int) substr($offer['th_ajaran'], 0, 4) < 2027) {
        continue;
    }
    $unitLabel = ppdb_unit_display_name($offer['nama_unit']);
    if ($offer['kuota_eksternal'] > 0 && $offer['eksternal_terisi'] >= $offer['kuota_eksternal']) {
        $offer['availability'] = 'penuh';
        $offer['availability_message'] = 'Mohon maaf Ayah/Bunda, pendaftaran '.$unitLabel.' sudah penuh.';
        $fullOffers[] = $offer;
    } elseif ((int) $offer['eksternal_dibuka'] && $offer['kuota_eksternal'] > 0) {
        $offer['availability'] = 'dibuka';
        $offer['availability_message'] = '';
        $openOffers[] = $offer;
    } elseif (ppdb_schedule_phase($offer['jadwal_buka'], $offer['jadwal_tutup']) === 'selesai') {
        $offer['availability'] = 'ditutup';
        $offer['availability_message'] = 'Mohon maaf Ayah/Bunda, pendaftaran '.$unitLabel.' sudah ditutup pada '.ppdb_format_datetime_id($offer['jadwal_tutup']).'.';
    } else {
        $offer['availability'] = 'belum';
        $offer['availability_message'] = ppdb_schedule_phase($offer['jadwal_buka'], null) === 'belum'
            ? 'SPMB '.$unitLabel.' mulai dibuka '.ppdb_format_datetime_id($offer['jadwal_buka']).'. Pendaftaran ditutup apabila kuota sudah penuh.'
            : 'Pendaftaran '.$unitLabel.' belum dibuka.';
    }
    $allOffers[] = $offer;
}

// Brosur per unit/tahun dari Materi & kuisioner, ditampilkan layar penuh setelah unit dipilih.
$brochures = [];
$brochureResult = mysqli_query($conn, "SELECT id,kode_unit,th_ajaran,path_file_pratinjau,mime_file_pratinjau FROM tb_ppdb_materials WHERE jenis_materi='brosur' AND path_file_pratinjau IS NOT NULL");
while ($brochureResult && $brochure = mysqli_fetch_assoc($brochureResult)) {
    if (ppdb_material_has_preview($brochure)) {
        $brochures[$brochure['kode_unit'].'|'.$brochure['th_ajaran']] = [
            'url' => 'ppdb-materi-file?id='.(int) $brochure['id'].'&file=preview',
            'type' => $brochure['mime_file_pratinjau'] === 'application/pdf' ? 'pdf' : 'image',
        ];
    }
}

/** Atribut data brosur untuk elemen pemicu; kosong bila unit belum punya brosur. */
function brochure_attributes(array $brochures, string $routeKey, string $unitName): string
{
    if (!isset($brochures[$routeKey])) {
        return '';
    }
    return ' data-brochure-url="'.h($brochures[$routeKey]['url']).'" data-brochure-type="'.h($brochures[$routeKey]['type']).'" data-brochure-title="'.h('Brosur '.$unitName).'"';
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
                $tokenQuery = mysqli_prepare($conn, "SELECT t.id FROM tb_token_internal t JOIN tb_pengaturan_ppdb q ON q.kode_unit=t.kode_unit AND q.th_ajaran=t.th_ajaran WHERE BINARY t.kode_token=BINARY ? AND t.used_at IS NULL AND t.revoked_at IS NULL AND ".ppdb_internal_open_condition_sql($conn)." AND q.kuota_internal>0 LIMIT 1");
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
                    header('Location: daftar?internal=1&form=1');
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
            error_log('SPMB internal token validation failed: '.$exception->getMessage());
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
        $internalQuery = mysqli_prepare($conn, "SELECT t.id,t.kode_unit,t.th_ajaran,t.used_at,t.revoked_at,q.kuota_internal,".ppdb_internal_open_condition_sql($conn)." AS internal_dibuka,".ppdb_schedule_columns_sql($conn).",q.biaya_pendaftaran,q.bank_nama,q.nomor_rekening,q.nama_pemilik_rekening,q.masa_pembayaran_hari,u.nama_unit,u.jenjang,(SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=t.kode_unit AND p.th_ajaran=t.th_ajaran AND p.jalur_pendaftaran='internal' AND p.status_ppdb NOT IN ('ditolak','data_lama')) AS internal_terisi FROM tb_token_internal t JOIN tb_pengaturan_ppdb q ON q.kode_unit=t.kode_unit AND q.th_ajaran=t.th_ajaran JOIN tb_unit_pendidikan u ON u.kode_unit=t.kode_unit WHERE t.id=? LIMIT 1");
        mysqli_stmt_bind_param($internalQuery, 'i', $internalTokenId);
        mysqli_stmt_execute($internalQuery);
        $internalCode = mysqli_fetch_assoc(mysqli_stmt_get_result($internalQuery));
        mysqli_stmt_close($internalQuery);
        if (!$internalCode || $internalCode['used_at'] !== null || $internalCode['revoked_at'] !== null) {
            unset($_SESSION[$internalSessionKey]);
            $tokenEntryRequested = true;
        } elseif (!(int) $internalCode['internal_dibuka'] || (int) $internalCode['kuota_internal'] < 1) {
            // Jadwal buka tidak menahan jalur internal, jadi pesannya hanya boleh merujuk jadwal tutup.
            $routeError = ppdb_schedule_closed_message(['jadwal_tutup' => $internalCode['jadwal_tutup'] ?? null], 'Pendaftaran internal untuk unit dan tahun ajaran ini sedang ditutup.');
        } elseif ((int) $internalCode['internal_terisi'] >= (int) $internalCode['kuota_internal']
            && $_SERVER['REQUEST_METHOD'] !== 'POST') {
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
            $routeError = ppdb_schedule_closed_message($candidate, 'Pendaftaran eksternal untuk unit dan tahun ajaran tersebut sedang ditutup.');
        } elseif ($candidate['eksternal_terisi'] >= $candidate['kuota_eksternal']
            && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $routeError = 'Kuota eksternal untuk unit dan tahun ajaran tersebut sudah terpenuhi.';
        } else {
            $routeOffer = $candidate;
        }
    }
}

if ($routeOffer && $routeError === '') {
    ppdb_track_unit_link_visit($conn, (string) $routeOffer['kode_unit']);
}

$formAction = 'daftar';
if ($routeOffer && $routeType === 'internal') {
    $formAction .= '?internal=1&form=1';
}

$pendaftarOptions = ['Ayah', 'Bunda', 'Ayah dan Bunda', 'Wali'];
$tkLevelOptions = PPDB_TK_LEVELS;

// Jenjang TK yang kuotanya habis pada jalur pendaftaran ini tidak dapat dipilih.
$tkRouteOffer = $routeType === 'internal' ? $routeOffer : null;
if ($routeType !== 'internal') {
    foreach ($openOffers as $offer) {
        if ($offer['jenjang'] === 'TK' && (!$tkRouteOffer || ($routeOffer && $offer['kode_unit'] === $routeOffer['kode_unit'] && $offer['th_ajaran'] === $routeOffer['th_ajaran']))) {
            $tkRouteOffer = $offer;
        }
    }
}
$tkFullLevels = $tkRouteOffer && ($tkRouteOffer['jenjang'] ?? '') === 'TK'
    ? ppdb_full_levels(ppdb_level_quotas($conn, (string) $tkRouteOffer['kode_unit'], (string) $tkRouteOffer['th_ajaran']), $routeType === 'internal' ? 'internal' : 'eksternal')
    : [];

/** Ketersediaan kuota per jenis kelamin dalam JSON, untuk label pilihan jenis kelamin di formulir. */
function gender_availability_json(mysqli $conn, array $offer, string $route): string
{
    if (($offer['jenjang'] ?? '') !== 'SMP') {
        return '';
    }
    $availability = [];
    foreach (ppdb_gender_quotas($conn, (string) $offer['kode_unit'], (string) $offer['th_ajaran']) as $gender => $routes) {
        [$quota, $filled] = $routes[$route];
        $availability[$gender] = ['left' => max(0, $quota - $filled)];
    }
    return $availability ? (string) json_encode($availability) : '';
}

/** Pilihan jenjang tujuan khusus unit TK, tampil di samping pilihan unit. */
function render_tk_level_field(array $options, string $value, bool $visible, array $fullLevels = []): void
{
    echo '<div class="field" data-tk-level'.($visible ? '' : ' hidden').'>';
    echo '<label for="tingkat_kelas">Daftar ke jenjang <span class="required-mark" aria-hidden="true">*</span></label>';
    echo '<select id="tingkat_kelas" name="tingkat_kelas"'.($visible ? ' required' : ' disabled').'>';
    echo '<option value="">Pilih TK A atau TK B</option>';
    foreach ($options as $option) {
        $full = in_array($option, $fullLevels, true);
        echo '<option value="'.h($option).'"'.($full ? ' disabled' : ($value === $option ? ' selected' : '')).'>'.h($option.($full ? ' (kuota penuh)' : '')).'</option>';
    }
    echo '</select></div>';
}
$formValues = $_POST;
$errors = [];

function render_initial_field(array $field, array $values, int $index): void
{
    $name = $field['name'];
    $value = (string) ($values[$name] ?? '');
    $wideClass = !empty($field['wide']) ? ' field-wide' : '';
    echo '<div class="field'.$wideClass.'" data-field="'.h($name).'">';
    echo '<label for="'.h($name).'">'.h($field['label']);
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
            echo '<small class="field-hint" data-dapodik-age aria-live="polite">Pilih unit untuk melihat umur pada tanggal acuan Dapodik.</small>';
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
    $tkLevel = null;
    if ($routeOffer && ($routeOffer['jenjang'] ?? '') === 'TK') {
        $tkLevel = trim($_POST['tingkat_kelas'] ?? '');
        if (!in_array($tkLevel, $tkLevelOptions, true)) {
            $errors[] = 'Pilih jenjang tujuan ananda: TK A atau TK B.';
        }
    }
    // Unit SMP jalur eksternal memilih jalur seleksi; jalur prestasi mengisi data tambahan.
    $selectionRoute = null;
    $achievementData = null;
    if ($routeType === 'eksternal' && $routeOffer && ($routeOffer['jenjang'] ?? '') === 'SMP' && prestasi_is_ready($conn)) {
        $selectionRoute = trim((string) ($_POST['jalur_seleksi'] ?? ''));
        if (!in_array($selectionRoute, ['prestasi', 'umum'], true)) {
            $errors[] = 'Pilih jalur pendaftaran SMP: prestasi atau umum.';
        } elseif ($selectionRoute === 'prestasi') {
            [$achievementData, $achievementErrors] = prestasi_validate($_POST, $_FILES);
            array_push($errors, ...$achievementErrors);
        }
    }
    foreach (['no_hp_pendaftar' => 'Nomor WhatsApp pendaftar', 'no_hp_ayah' => 'Nomor WhatsApp ayah', 'no_hp_ibu' => 'Nomor WhatsApp ibu'] as $field => $label) {
        if (!ppdb_is_valid_whatsapp_number(trim($_POST[$field] ?? ''))) {
            $errors[] = $label.' harus berupa nomor Indonesia yang aktif, contoh 081234567890.';
        }
    }

    if (!$errors) {
        $storedLetterPath = null;
        try {
            mysqli_begin_transaction($conn);
            $offerLock = mysqli_prepare($conn, 'SELECT kuota_internal,kuota_eksternal,'.ppdb_internal_open_condition_sql($conn, '').' AS internal_dibuka,'.ppdb_route_open_sql($conn, 'eksternal_dibuka', '').',nomor_antrian_terakhir FROM tb_pengaturan_ppdb WHERE kode_unit = ? AND th_ajaran = ? FOR UPDATE');
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
            $duplicateQuery = mysqli_prepare($conn, "SELECT no_hp_pendaftar FROM tb_pendaftaran
                WHERE kode_unit=? AND th_ajaran=? AND tgl_lahir=? AND jenis_kelamin=?
                    AND status_ppdb NOT IN ('ditolak','data_lama')
                FOR UPDATE");
            mysqli_stmt_bind_param($duplicateQuery, 'ssss', $unitCode, $academicYear, $birthDate, $gender);
            mysqli_stmt_execute($duplicateQuery);
            $duplicateResults = mysqli_stmt_get_result($duplicateQuery);
            $duplicateFound = false;
            $normalizedRegistrantPhone = ppdb_normalize_whatsapp_number($registrantPhone);
            while ($existingRegistration = mysqli_fetch_assoc($duplicateResults)) {
                $existingPhone = ppdb_normalize_whatsapp_number((string) $existingRegistration['no_hp_pendaftar']);
                if ($normalizedRegistrantPhone !== '' && $existingPhone === $normalizedRegistrantPhone) {
                    $duplicateFound = true;
                    break;
                }
            }
            mysqli_stmt_close($duplicateQuery);
            if ($duplicateFound) {
                $unitName = (string) ($routeOffer['nama_unit'] ?? 'unit yang dipilih');
                throw new RuntimeException('Data tanggal lahir, jenis kelamin, dan nomor WhatsApp pendaftar ini sudah terdaftar di unit '.$unitName.' untuk tahun ajaran '.$academicYear.'. Pendaftaran duplikat tidak dapat diproses. Hubungi panitia jika Anda memerlukan bantuan.');
            }
            $countRegistrations = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM tb_pendaftaran WHERE kode_unit=? AND th_ajaran=? AND jalur_pendaftaran=? AND status_ppdb NOT IN ('ditolak','data_lama')");
            mysqli_stmt_bind_param($countRegistrations, 'sss', $unitCode, $academicYear, $routeType);
            mysqli_stmt_execute($countRegistrations);
            $registrationCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countRegistrations))['total'];
            mysqli_stmt_close($countRegistrations);
            if ($registrationCount >= (int) $lockedOffer[$quotaColumn]) {
                throw new RuntimeException('Kuota jalur ini untuk unit dan tahun ajaran tersebut sudah terpenuhi.');
            }
            // Baris pengaturan unit sudah dikunci di atas, jadi hitungan per jenjang aman dari pendaftar bersamaan.
            // Unit SMP: kuota laki-laki dan perempuan dihitung terpisah pada setiap jalur.
            if (in_array($gender, ppdb_full_levels(ppdb_gender_quotas($conn, $unitCode, $academicYear), $routeType), true)) {
                throw new RuntimeException('Mohon maaf, kuota '.strtolower(PPDB_GENDERS[$gender]).' untuk jalur ini sudah penuh.');
            }
            if ($tkLevel !== null && in_array($tkLevel, ppdb_full_levels(ppdb_level_quotas($conn, $unitCode, $academicYear), $routeType), true)) {
                throw new RuntimeException('Kuota '.$tkLevel.' untuk jalur ini sudah penuh. Silakan pilih jenjang lain atau hubungi panitia.');
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

            // Antrean mengikuti pendaftar yang masih tercatat, jadi data uji yang dihapus tidak memakai nomor.
            $queueQuery = mysqli_prepare($conn, 'SELECT COALESCE(MAX(nomor_antrian),0) AS terakhir FROM tb_pendaftaran WHERE kode_unit=? AND th_ajaran=? FOR UPDATE');
            mysqli_stmt_bind_param($queueQuery, 'ss', $unitCode, $academicYear);
            mysqli_stmt_execute($queueQuery);
            $queueNumber = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($queueQuery))['terakhir'] + 1;
            mysqli_stmt_close($queueQuery);
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

            // Kolom dari migrasi 025; dilewati bila migrasi belum dijalankan agar pendaftaran tetap berjalan.
            if ($tkLevel !== null && ppdb_column_exists($conn, 'tb_pendaftaran', 'tingkat_kelas')) {
                $saveLevel = mysqli_prepare($conn, 'UPDATE tb_pendaftaran SET tingkat_kelas=? WHERE id_pendaftaran=?');
                mysqli_stmt_bind_param($saveLevel, 'ss', $tkLevel, $registrationId);
                mysqli_stmt_execute($saveLevel);
                mysqli_stmt_close($saveLevel);
            }

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

            if ($selectionRoute !== null) {
                $saveSelection = mysqli_prepare($conn, 'UPDATE tb_pendaftaran SET jalur_seleksi=? WHERE id_pendaftaran=?');
                mysqli_stmt_bind_param($saveSelection, 'ss', $selectionRoute, $registrationId);
                mysqli_stmt_execute($saveSelection);
                mysqli_stmt_close($saveSelection);
            }
            if ($achievementData !== null) {
                prestasi_save($conn, $registrationId, $achievementData);
                $storedLetterPath = prestasi_store_letter($conn, $registrationId, $_FILES['surat_prestasi']);
            }

            ppdb_activate_registration($conn, $registrationId);
            enqueue_google_sheets_registration($conn, $registrationId);
            mysqli_commit($conn);
            unset($_SESSION[$internalSessionKey]);

            // Pendaftar langsung diarahkan; pesan WhatsApp dan spreadsheet menyusul di belakang.
            header('Location: berhasil?id='.urlencode($registrationId).'&token='.urlencode($receiptToken));
            ppdb_finish_response();
            ppdb_send_registration_whatsapp($conn, $registrationId);
            sync_google_sheets_registration($conn, $registrationId);
            exit;
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            if ($storedLetterPath && is_file($storedLetterPath)) {
                unlink($storedLetterPath);
            }
            if ($exception instanceof RuntimeException) {
                $errors[] = $exception->getMessage();
            } else {
                error_log('SPMB initial registration transaction failed: '.$exception->getMessage());
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
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Registrasi awal SPMB Yayasan Wakaf Cendekia Takengon. Isi data dasar, tunggu verifikasi, lalu lengkapi formulir.">
    <title>Registrasi Awal | SPMB Yayasan Wakaf Cendekia Takengon</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300;0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/tokens.css?v=<?php echo (int) @filemtime(__DIR__.'/css/tokens.css'); ?>">
    <link rel="stylesheet" href="css/daftar.css?v=<?php echo (int) @filemtime(__DIR__.'/css/daftar.css'); ?>">
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
<body class="page-daftar" data-academic-year="<?php echo h($routeOffer['th_ajaran'] ?? ''); ?>">
<a class="skip-link" href="#form-utama">Lompat ke formulir</a>
<div class="page-shell">
    <header class="site-header">
        <a class="brand" href="./" aria-label="Yayasan Wakaf Cendekia Takengon, beranda">
            <img class="brand-logo" src="img/logo-display.png" alt="" width="46" height="45">
            <span class="brand-copy"><strong>Wakaf Cendekia</strong><small>Takengon · SPMB online</small></span>
        </a>
        <span class="daftar-year">SPMB <?php echo h($routeOffer['th_ajaran'] ?? '2027/2028'); ?></span>
        <a class="back-link" href="./">Kembali ke beranda</a>
    </header>

    <main class="initial-layout">
        <section class="intro intro-rail">
            <div class="daftar-hero__copy">
                <p class="eyebrow">Tahap 1 dari 3 · Registrasi awal</p>
                <h1>Daftar langsung,<br><span>selesai di satu tempat.</span></h1>
                <p class="intro-copy">Isi tujuh isian singkat berikut, lalu lanjutkan langsung di situs ini: pembayaran, konfirmasi data, formulir lengkap, dan unggah dokumen.</p>
                <ul class="daftar-chips" aria-label="Ringkasan registrasi awal">
                    <li>Sekitar 3 menit</li>
                    <li>Tanpa NIK &amp; dokumen</li>
                    <li>Seluruh tahap langsung di situs ini</li>
                </ul>
            </div>
            <ol class="journey-list" aria-label="Alur pendaftaran">
                <li class="is-active"><span aria-hidden="true">1</span><div><strong>Registrasi awal</strong><small>7 isian dasar, tanpa NIK</small></div></li>
                <li><span aria-hidden="true">2</span><div><strong>Pembayaran</strong><small>Transfer lalu unggah bukti di situs ini</small></div></li>
                <li><span aria-hidden="true">3</span><div><strong>Formulir lengkap</strong><small>Data detail dan dokumen pendukung</small></div></li>
            </ol>
        </section>

        <div class="daftar-body">
        <aside class="daftar-aside" aria-label="Bantuan pengisian">
            <div class="progress-ring">
                <div class="progress-ring__top">
                    <span class="progress-ring__label">Kelengkapan isian</span>
                    <span class="progress-ring__count" data-progress-count role="status" aria-live="polite">0 / 0</span>
                </div>
                <div class="progress-ring__track"><div class="progress-ring__bar" data-progress-bar></div></div>
            </div>
            <?php if ($routeType === 'eksternal' && !$tokenEntryRequested && empty($_SESSION[$internalSessionKey])) { ?>
            <div class="intro-meta"><span class="meta-dot" aria-hidden="true"></span> <?php echo $openOffers ? 'Pendaftaran mengikuti kuota yang dibuka tiap unit.' : ($fullOffers ? 'Kuota pendaftaran saat ini telah terpenuhi.' : 'Pendaftaran belum dibuka. Pilih unit untuk melihat informasinya.'); ?></div>
            <?php } ?>
            <div class="daftar-card">
                <h2>Siapkan sebelum mengisi</h2>
                <ul class="daftar-checklist">
                    <li>Nama lengkap ananda sesuai akta kelahiran</li>
                    <li>Tanggal lahir ananda</li>
                    <li>Nomor WhatsApp pendaftar, ayah, dan ibu</li>
                </ul>
            </div>
            <div class="daftar-card daftar-card--help">
                <h2>Butuh bantuan?</h2>
                <p>Hubungi nomor WhatsApp resmi SPMB. Panitia tidak pernah meminta transfer lewat nomor lain.</p>
                <a class="daftar-help-link" href="<?php echo h(ppdb_admin_whatsapp_url()); ?>" target="_blank" rel="noopener"><?php echo h(ppdb_admin_whatsapp_display()); ?></a>
            </div>
        </aside>

        <div class="initial-main">
            <?php if ($routeType === 'eksternal' && !$tokenEntryRequested && empty($_SESSION[$internalSessionKey])) { ?>
                <?php if ($routeError !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') { ?>
                    <div class="error-summary" role="alert"><strong>Pilihan pendaftaran tidak tersedia</strong><ul><li><?php echo h($routeError); ?></li></ul></div>
                <?php } ?>
                <?php if ($errors) { ?>
                    <div class="error-summary" role="alert" aria-labelledby="error-title">
                        <strong id="error-title">Periksa kembali data pendaftaran</strong>
                        <ul><?php foreach ($errors as $error) { ?><li><?php echo h($error); ?></li><?php } ?></ul>
                    </div>
                <?php } ?>
                <?php if (!$allOffers) { ?>
                    <div class="error-summary" role="status"><strong>Pendaftaran eksternal belum tersedia</strong><ul><li>Belum ada kuota eksternal yang dibuka untuk tahun ajaran 2027/2028 atau kuota telah terpenuhi. Silakan hubungi panitia SPMB.</li></ul></div>
                <?php } ?>
                <form class="registration-form" id="form-utama" action="daftar" method="post" enctype="multipart/form-data" autocomplete="on" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <section class="form-section" aria-labelledby="data-title">
                        <div class="section-heading">
                            <div><p class="route-tag">Pendaftaran</p><h2 id="data-title">Data dasar ananda &amp; pendaftar</h2><p>Pilih unit, lalu isi tujuh data awal. NIK dan dokumen menyusul setelah pembayaran.</p></div>
                        </div>
                        <p class="contact-notice">Ayah/Bunda, sebelum mengisi data, mohon simpan terlebih dahulu nomor WhatsApp resmi SPMB Cendekia <a href="<?php echo h(ppdb_admin_whatsapp_url()); ?>" target="_blank" rel="noopener"><?php echo h(ppdb_admin_whatsapp_display()); ?></a> untuk menghindari penipuan yang mengatasnamakan sekolah.</p>
                        <div class="field-grid">
                            <?php $tkSelected = ($routeOffer['jenjang'] ?? '') === 'TK'; ?>
                            <div class="field<?php echo $tkSelected ? '' : ' field-wide'; ?>" data-unit-field>
                                <label for="external_route">Unit pendidikan <span class="required-mark" aria-hidden="true">*</span></label>
                                <select id="external_route" name="external_route" required <?php echo !$allOffers ? 'disabled' : ''; ?>>
                                    <option value="">Pilih unit</option>
                                    <?php foreach ($allOffers as $offer) {
                                        $offerValue = $offer['kode_unit'].'|'.$offer['th_ajaran'];
                                        $offerSelected = $selectedUnitCode === $offer['kode_unit'] && $selectedAcademicYear === $offer['th_ajaran'];
                                        $offerAvailable = $offer['availability'] === 'dibuka';
                                        $offerAttributes = $offerAvailable
                                            ? brochure_attributes($brochures, $offerValue, $offer['nama_unit'])
                                            : ' data-unavailable="'.h($offer['availability']).'" data-unavailable-message="'.h($offer['availability_message']).'"';
                                    ?>
                                        <option value="<?php echo h($offerValue); ?>" data-jenjang="<?php echo h($offer['jenjang']); ?>" data-name="<?php echo h($offer['nama_unit']); ?>" data-gender="<?php echo h(gender_availability_json($conn, $offer, 'eksternal')); ?>"<?php echo $offerAttributes; ?><?php echo $offerSelected ? ' selected' : ''; ?>><?php echo h($offer['nama_unit']); ?><?php echo ['penuh' => ' (penuh)', 'ditutup' => ' (ditutup)', 'belum' => ' (belum dibuka)'][$offer['availability']] ?? ''; ?></option>
                                    <?php } ?>
                                </select>
                                <button type="button" class="brochure-trigger" data-brochure-open hidden>Lihat brosur unit</button>
                            </div>
                            <?php render_tk_level_field($tkLevelOptions, (string) ($formValues['tingkat_kelas'] ?? ''), $tkSelected, $tkFullLevels); ?>
                            <?php
                            $smpSelected = ($routeOffer['jenjang'] ?? '') === 'SMP';
                            $selectedJalur = is_string($formValues['jalur_seleksi'] ?? null) ? $formValues['jalur_seleksi'] : '';
                            if (prestasi_is_ready($conn)) { prestasi_render_jalur_field($selectedJalur, $smpSelected); }
                            ?>
                        </div>
                        <?php if (prestasi_is_ready($conn)) { prestasi_render_section($formValues, $smpSelected && $selectedJalur === 'prestasi'); } ?>
                        <div class="initial-block">
                            <h3 class="initial-block__title">Data ananda dan pendaftar</h3>
                            <div class="field-grid">
                                <div class="initial-fields" data-initial-fields>
                                    <?php foreach ($initialFields as $index => $field) { render_initial_field($field, $formValues, $index + 2); } ?>
                                </div>
                            </div>
                        </div>
                        <label class="consent-check" for="konfirmasi_data">
                            <input type="checkbox" id="konfirmasi_data" name="konfirmasi_data" value="1" required <?php echo (($_POST['konfirmasi_data'] ?? '') === '1') ? 'checked' : ''; ?>>
                            <span>Saya memastikan data di atas sudah benar dan nomor WhatsApp yang diisi aktif.</span>
                        </label>
                    </section>
                    <div class="form-footer">
                        <p>Setelah dikirim, Anda menerima kode pendaftaran dan langsung lanjut ke pembayaran, lalu formulir lengkap.</p>
                        <button type="submit" name="submit" value="1" class="submit-button" <?php echo !$openOffers ? 'disabled' : ''; ?>>Kirim registrasi awal</button>
                    </div>
                </form>
            <?php } elseif ($tokenEntryRequested && !$routeOffer && $routeError === '') { ?>
                <section class="form-section" aria-labelledby="internal-code-title">
                    <div class="section-heading">
                        <span class="section-number">01</span>
                        <div><h2 id="internal-code-title">Pendaftaran</h2><p>Masukkan kode 9 karakter yang terdiri dari huruf besar, huruf kecil, dan angka. Setiap kode hanya berlaku untuk satu peserta dan satu kali pendaftaran.</p></div>
                    </div>
                    <?php if ($tokenEntryError !== '') { ?><div class="error-summary" role="alert"><strong>Kode belum dapat digunakan</strong><ul><li><?php echo h($tokenEntryError); ?></li></ul></div><?php } ?>
                    <form class="registration-form" action="daftar?internal=1" method="post">
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
                            <button class="submit-button" type="submit">Lanjutkan pendaftaran</button>
                        </div>
                    </form>
                </section>
            <?php } elseif (!$routeOffer || $routeError !== '') { ?>
                <div class="error-summary" role="alert"><strong>Pendaftaran tidak dapat dilanjutkan</strong><ul><li><?php echo h($routeError !== '' ? $routeError : 'Tautan pendaftaran tidak valid.'); ?></li></ul></div>
            <?php } else { ?>
                <section class="form-section" aria-labelledby="route-title">
                    <div class="section-heading">
                        <span class="section-number">01</span>
                        <div><h2 id="route-title">Pendaftaran</h2>
                            <p><?php echo h($routeOffer['nama_unit']); ?></p>
                            <?php $routeBrochure = brochure_attributes($brochures, $routeOffer['kode_unit'].'|'.$routeOffer['th_ajaran'], $routeOffer['nama_unit']); ?>
                            <?php if ($routeBrochure !== '') { ?><button type="button" class="brochure-trigger" data-brochure-open data-brochure-auto<?php echo $routeBrochure; ?>>Lihat brosur unit</button><?php } ?>
                        </div>
                    </div>
                    <p class="contact-notice">Ayah/Bunda, sebelum mengisi data, mohon simpan terlebih dahulu nomor WhatsApp resmi SPMB Cendekia <a href="<?php echo h(ppdb_admin_whatsapp_url()); ?>" target="_blank" rel="noopener"><?php echo h(ppdb_admin_whatsapp_display()); ?></a> untuk menghindari penipuan yang mengatasnamakan sekolah.</p>
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
                        <?php if (($routeOffer['jenjang'] ?? '') === 'TK') { render_tk_level_field($tkLevelOptions, (string) ($formValues['tingkat_kelas'] ?? ''), true, $tkFullLevels); } ?>
                        <?php if ($routeOffer && gender_availability_json($conn, $routeOffer, 'internal') !== '') { ?><div hidden data-gender-route="<?php echo h(gender_availability_json($conn, $routeOffer, 'internal')); ?>"></div><?php } ?>
                        <?php foreach ($initialFields as $index => $field) { render_initial_field($field, $formValues, $index + 3); } ?>
                    </div>
                    <label class="consent-check" for="konfirmasi_data">
                        <input type="checkbox" id="konfirmasi_data" name="konfirmasi_data" value="1" required <?php echo (($_POST['konfirmasi_data'] ?? '') === '1') ? 'checked' : ''; ?>>
                        <span>Saya memastikan data di atas sudah benar dan nomor WhatsApp yang diisi aktif.</span>
                    </label>
                </section>

                <div class="form-footer">
                    <p>Setelah dikirim, Anda menerima kode pendaftaran dan langsung lanjut ke pembayaran, lalu formulir lengkap.</p>
                    <button type="submit" name="submit" value="1" class="submit-button">Kirim registrasi awal</button>
                </div>
            </form>
            <?php } ?>
        </div>
        </div>
    </main>

    <footer class="site-footer"><span>Yayasan Wakaf Cendekia Takengon</span><a href="mailto:wakafcendekiatakengon@gmail.com">Hubungi panitia</a></footer>
</div>
<dialog class="unavailable-dialog" data-unavailable-dialog aria-labelledby="unavailable-title" aria-describedby="unavailable-message">
    <button type="button" class="unavailable-dialog__close" data-unavailable-close aria-label="Tutup informasi"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
    <span class="unavailable-dialog__icon" aria-hidden="true">
        <svg class="unavailable-dialog__icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        <svg class="unavailable-dialog__icon-full" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18.5 14c1.8.9 3 2.9 3 5.5"/></svg>
    </span>
    <h2 id="unavailable-title" data-unavailable-title>Pendaftaran belum dibuka</h2>
    <p id="unavailable-message" class="unavailable-dialog__message" data-unavailable-message></p>
    <?php if (prestasi_is_ready($conn)) { ?>
    <div class="unavailable-dialog__prepare" data-unavailable-prepare hidden>
        <p class="unavailable-dialog__prepare-title">Khusus jalur prestasi</p>
        <p class="prestasi-template">Silakan unduh template surat prestasi yang dilengkapi oleh sekolah asal (SD/MI).</p>
        <p class="prestasi-template"><a href="assets/template/surat-keterangan-prestasi.docx" download>Unduh Surat</a></p>
    </div>
    <?php } ?>
</dialog>
<dialog class="brochure-viewer" data-brochure-viewer aria-labelledby="brochure-title">
    <div class="brochure-viewer__bar">
        <h2 id="brochure-title" data-brochure-title>Brosur unit</h2>
        <div class="brochure-viewer__tools">
            <span class="brochure-viewer__pages" data-brochure-pages hidden aria-live="polite"></span>
            <button type="button" data-brochure-zoom-out aria-label="Perkecil">&minus;</button>
            <button type="button" class="brochure-viewer__zoom" data-brochure-zoom-reset aria-label="Kembalikan ukuran">100%</button>
            <button type="button" data-brochure-zoom-in aria-label="Perbesar">+</button>
            <button type="button" class="brochure-viewer__close" data-brochure-close aria-label="Tutup brosur"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
        </div>
    </div>
    <div class="brochure-viewer__stage" data-brochure-stage tabindex="0">
        <p class="brochure-viewer__status" data-brochure-status role="status">Memuat brosur…</p>
        <div class="brochure-viewer__content" data-brochure-content></div>
    </div>
    <div class="brochure-viewer__footer">
        <a data-brochure-download href="#" target="_blank" rel="noopener">Buka file asli</a>
        <button type="button" class="brochure-viewer__continue" data-brochure-close>Lanjut mengisi formulir</button>
    </div>
</dialog>
<script src="js/daftar.js?v=<?php echo (int) @filemtime(__DIR__.'/js/daftar.js'); ?>"></script>
<script src="js/unit-picker.js?v=<?php echo (int) @filemtime(__DIR__.'/js/unit-picker.js'); ?>"></script>
<script src="js/reveal.js?v=<?php echo (int) @filemtime(__DIR__.'/js/reveal.js'); ?>"></script>
<script type="module" src="js/brosur.js?v=<?php echo (int) @filemtime(__DIR__.'/js/brosur.js'); ?>"></script>
<script src="js/alur.js?v=<?php echo (int) @filemtime(__DIR__.'/js/alur.js'); ?>"></script>
</body>
</html>
