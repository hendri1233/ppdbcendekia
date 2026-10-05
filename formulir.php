<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
start_app_session();

$documentSlots = [
    'foto' => ['label' => 'Foto peserta didik', 'required' => true, 'hint' => 'Foto formal terbaru, latar polos.'],
    'nisn' => ['label' => 'Foto / scan NISN', 'required' => false, 'hint' => 'Kosongkan bila ananda belum memiliki NISN.'],
    'kk' => ['label' => 'Scan Kartu Keluarga', 'required' => true, 'hint' => 'Halaman depan yang memuat nama ayah dan ibu.'],
    'akta_lahir' => ['label' => 'Scan Akta Kelahiran', 'required' => true, 'hint' => 'Akta kelahiran ananda.'],
    'ktp_ayah' => ['label' => 'Scan KTP Ayah', 'required' => true, 'hint' => 'Kartu Tanda Penduduk ayah / wali.'],
    'ktp_ibu' => ['label' => 'Scan KTP Ibu', 'required' => true, 'hint' => 'Kartu Tanda Penduduk ibu.'],
];
$documentMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];

function formulir_lookup(mysqli $conn, string $code): ?array
{
    if (!preg_match('/^P[0-9]{9}$/', $code)) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT p.id_pendaftaran,p.nm_peserta,p.tgl_lahir,p.jenis_kelamin,p.status_ppdb,p.status_data,p.status_pembayaran,p.pendaftar,p.no_hp_pendaftar,p.th_ajaran,p.kode_unit,p.token_formulir,p.token_bukti,p.token_whatsapp,u.nama_unit FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit WHERE p.id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $code);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function formulir_token_matches(array $registration, string $token): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }
    $hash = hash('sha256', $token);
    foreach (['token_formulir', 'token_bukti', 'token_whatsapp'] as $key) {
        if (hash_equals((string) ($registration[$key] ?? ''), $hash)) {
            return true;
        }
    }
    return false;
}

/** Status yang boleh membuka isian formulir lengkap. */
function formulir_is_open(array $registration): bool
{
    return in_array($registration['status_ppdb'], ['pembayaran_terverifikasi', 'menunggu_formulir', 'formulir_terisi'], true);
}

$code = strtoupper(trim($_GET['kode'] ?? $_POST['kode'] ?? ''));
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errors = [];
$notice = '';
$registration = formulir_lookup($conn, $code);
$authorized = $registration !== null && formulir_token_matches($registration, $token);
$formOpen = $authorized && formulir_is_open($registration);
$alreadySubmitted = $authorized && $registration['status_ppdb'] === 'formulir_terisi';

if ($registration && !$authorized && preg_match('/^P[0-9]{9}$/', $code)) {
    $errors[] = 'Kode pendaftaran tidak dikenali atau tautan tidak sah. Gunakan kode yang diterima melalui WhatsApp dari panitia.';
}

$educationOptions = ['Tidak sekolah', 'SD/sederajat', 'SMP/sederajat', 'SMA/sederajat', 'D1', 'D2', 'D3', 'D4/S1', 'S2', 'S3'];
$incomeOptions = ['Tidak berpenghasilan', 'Di bawah Rp500.000', 'Rp500.000–Rp1.000.000', 'Rp1.000.000–Rp2.000.000', 'Rp2.000.000–Rp5.000.000', 'Di atas Rp5.000.000'];
$formValues = $_POST;

function formulir_render_field(array $field, array $values): void
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
        foreach (['maxlength', 'min', 'max', 'step', 'inputmode', 'pattern', 'placeholder'] as $attribute) {
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

$identityFields = [
    ['name' => 'nik', 'label' => 'NIK calon peserta didik', 'required' => true, 'maxlength' => 16, 'inputmode' => 'numeric', 'pattern' => '[0-9]{16}', 'placeholder' => '16 digit angka'],
    ['name' => 'nomor_kk', 'label' => 'Nomor Kartu Keluarga', 'required' => true, 'maxlength' => 16, 'inputmode' => 'numeric', 'pattern' => '[0-9]{16}', 'placeholder' => '16 digit angka'],
    ['name' => 'NISN', 'label' => 'NISN', 'maxlength' => 10, 'inputmode' => 'numeric', 'pattern' => '[0-9]{10}', 'placeholder' => '10 digit angka', 'hint' => 'Kosongkan jika belum memiliki NISN.'],
    ['name' => 'tmp_lahir', 'label' => 'Tempat lahir', 'required' => true, 'maxlength' => 50],
    ['name' => 'nomor_registrasi_akta', 'label' => 'Nomor registrasi akta kelahiran', 'maxlength' => 100, 'wide' => true],
    ['name' => 'agama', 'label' => 'Agama/kepercayaan', 'type' => 'select', 'required' => true, 'options' => ['Islam' => 'Islam', 'Kristen' => 'Kristen', 'Katolik' => 'Katolik', 'Hindu' => 'Hindu', 'Buddha' => 'Buddha', 'Khonghucu' => 'Khonghucu', 'Kepercayaan' => 'Kepercayaan lainnya']],
    ['name' => 'kewarganegaraan', 'label' => 'Kewarganegaraan', 'type' => 'select', 'required' => true, 'options' => ['WNI' => 'WNI', 'WNA' => 'WNA']],
    ['name' => 'kebutuhan_khusus', 'label' => 'Berkebutuhan khusus', 'maxlength' => 120, 'placeholder' => 'Tidak ada jika tidak memiliki', 'wide' => true],
    ['name' => 'asal_sekolah', 'label' => 'Sekolah asal', 'maxlength' => 100],
    ['name' => 'pernah_paud_tk', 'label' => 'Pernah mengikuti PAUD/TK', 'type' => 'select', 'required' => true, 'options' => ['Ya' => 'Ya', 'Tidak' => 'Tidak', 'Belum diketahui' => 'Belum diketahui']],
];
$addressFields = [
    ['name' => 'alamat_jalan', 'label' => 'Alamat jalan/dusun', 'required' => true, 'maxlength' => 200, 'wide' => true],
    ['name' => 'rt', 'label' => 'RT', 'maxlength' => 5],
    ['name' => 'rw', 'label' => 'RW', 'maxlength' => 5],
    ['name' => 'desa_kelurahan', 'label' => 'Desa/kelurahan', 'required' => true, 'maxlength' => 100],
    ['name' => 'kecamatan', 'label' => 'Kecamatan', 'required' => true, 'maxlength' => 100],
    ['name' => 'kabupaten', 'label' => 'Kabupaten/kota', 'required' => true, 'maxlength' => 100],
    ['name' => 'provinsi', 'label' => 'Provinsi', 'required' => true, 'maxlength' => 100],
    ['name' => 'jenis_tinggal', 'label' => 'Jenis tinggal', 'type' => 'select', 'required' => true, 'options' => ['Bersama orang tua' => 'Bersama orang tua', 'Bersama wali' => 'Bersama wali', 'Kos' => 'Kos', 'Asrama' => 'Asrama', 'Panti asuhan' => 'Panti asuhan', 'Lainnya' => 'Lainnya']],
    ['name' => 'alat_transportasi', 'label' => 'Alat transportasi ke sekolah', 'type' => 'select', 'required' => true, 'options' => ['Jalan kaki' => 'Jalan kaki', 'Sepeda' => 'Sepeda', 'Sepeda motor' => 'Sepeda motor', 'Mobil' => 'Mobil', 'Angkutan umum' => 'Angkutan umum', 'Antar jemput' => 'Antar jemput', 'Lainnya' => 'Lainnya']],
];
$parentGroups = [];
foreach (['ayah' => 'Data ayah', 'ibu' => 'Data ibu', 'wali' => 'Data wali (jika ada)'] as $prefix => $title) {
    $parentGroups[] = [
        'title' => $title,
        'fields' => [
            ['name' => $prefix.'_nama', 'label' => 'Nama lengkap', 'maxlength' => 100],
            ['name' => $prefix.'_nik', 'label' => 'NIK', 'maxlength' => 16, 'inputmode' => 'numeric', 'pattern' => '[0-9]{16}', 'placeholder' => '16 digit angka'],
            ['name' => $prefix.'_tahun_lahir', 'label' => 'Tahun lahir', 'type' => 'number', 'min' => 1900, 'max' => date('Y')],
            ['name' => $prefix.'_pendidikan', 'label' => 'Pendidikan terakhir', 'type' => 'select', 'options' => array_combine($educationOptions, $educationOptions)],
            ['name' => $prefix.'_pekerjaan', 'label' => 'Pekerjaan', 'maxlength' => 100],
            ['name' => $prefix.'_penghasilan', 'label' => 'Penghasilan bulanan', 'type' => 'select', 'options' => array_combine($incomeOptions, $incomeOptions)],
        ],
    ];
}
$periodicFields = [
    ['name' => 'tinggi_badan_cm', 'label' => 'Tinggi badan (cm)', 'type' => 'number', 'min' => 30, 'max' => 250, 'step' => '0.1'],
    ['name' => 'berat_badan_kg', 'label' => 'Berat badan (kg)', 'type' => 'number', 'min' => 1, 'max' => 300, 'step' => '0.1'],
    ['name' => 'lingkar_kepala_cm', 'label' => 'Lingkar kepala (cm)', 'type' => 'number', 'min' => 20, 'max' => 100, 'step' => '0.1'],
    ['name' => 'jarak_ke_sekolah_km', 'label' => 'Jarak rumah ke sekolah (km)', 'type' => 'number', 'min' => 0, 'max' => 500, 'step' => '0.1'],
    ['name' => 'waktu_tempuh_menit', 'label' => 'Waktu tempuh (menit)', 'type' => 'number', 'min' => 0, 'max' => 1440],
    ['name' => 'jumlah_saudara_kandung', 'label' => 'Jumlah saudara kandung', 'type' => 'number', 'min' => 0, 'max' => 30],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formOpen) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Sesi formulir berakhir. Muat ulang halaman sebelum mencoba kembali.';
    }
    if (($_POST['konfirmasi_data'] ?? '') !== '1') {
        $errors[] = 'Anda harus menyetujui konfirmasi data sebelum mengirim formulir.';
    }
    if (($_POST['konfirmasi_lanjut'] ?? '') !== '1') {
        $errors[] = 'Isian data registrasi awal belum dikonfirmasi.';
    }

    foreach (['nik' => 'NIK', 'nomor_kk' => 'Nomor KK'] as $field => $label) {
        if (!preg_match('/^[0-9]{16}$/', trim($_POST[$field] ?? ''))) {
            $errors[] = $label.' harus terdiri dari 16 digit angka.';
        }
    }
    if (($nisn = trim($_POST['NISN'] ?? '')) !== '' && !preg_match('/^[0-9]{10}$/', $nisn)) {
        $errors[] = 'NISN harus terdiri dari 10 digit angka.';
    }
    if (trim($_POST['tmp_lahir'] ?? '') === '') {
        $errors[] = 'Tempat lahir wajib diisi.';
    }
    foreach (['ayah_nik' => 'NIK ayah', 'ibu_nik' => 'NIK ibu', 'wali_nik' => 'NIK wali'] as $field => $label) {
        $parentNik = trim($_POST[$field] ?? '');
        if ($parentNik !== '' && !preg_match('/^[0-9]{16}$/', $parentNik)) {
            $errors[] = $label.' harus terdiri dari 16 digit jika diisi.';
        }
    }
    foreach (['ayah_tahun_lahir', 'ibu_tahun_lahir', 'wali_tahun_lahir'] as $field) {
        $birthYear = trim($_POST[$field] ?? '');
        if ($birthYear !== '' && (!preg_match('/^[0-9]{4}$/', $birthYear) || (int) $birthYear < 1900 || (int) $birthYear > (int) date('Y'))) {
            $errors[] = 'Periksa kembali tahun lahir orang tua/wali.';
        }
    }
    foreach (['alamat_jalan', 'desa_kelurahan', 'kecamatan', 'kabupaten', 'provinsi'] as $requiredAddress) {
        if (trim($_POST[$requiredAddress] ?? '') === '') {
            $errors[] = 'Lengkapi alamat jalan/dusun, desa, kecamatan, kabupaten, dan provinsi.';
            break;
        }
    }
    $allowedValues = [
        'kewarganegaraan' => ['WNI', 'WNA'],
        'pernah_paud_tk' => ['Ya', 'Tidak', 'Belum diketahui'],
        'jenis_pendaftaran' => ['Siswa baru', 'Pindahan', 'Kembali aktif'],
        'agama' => ['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Khonghucu', 'Kepercayaan'],
        'jenis_tinggal' => ['Bersama orang tua', 'Bersama wali', 'Kos', 'Asrama', 'Panti asuhan', 'Lainnya'],
        'alat_transportasi' => ['Jalan kaki', 'Sepeda', 'Sepeda motor', 'Mobil', 'Angkutan umum', 'Antar jemput', 'Lainnya'],
    ];
    foreach ($allowedValues as $field => $validValues) {
        if (!in_array($_POST[$field] ?? '', $validValues, true)) {
            $errors[] = 'Pilih nilai yang valid untuk '.str_replace('_', ' ', $field).'.';
        }
    }
    foreach (['tinggi_badan_cm' => [30, 250], 'berat_badan_kg' => [1, 300], 'lingkar_kepala_cm' => [20, 100], 'jarak_ke_sekolah_km' => [0, 500], 'waktu_tempuh_menit' => [0, 1440], 'jumlah_saudara_kandung' => [0, 30]] as $field => [$minimum, $maximum]) {
        $numericValue = trim($_POST[$field] ?? '');
        if ($numericValue !== '' && (!is_numeric($numericValue) || (float) $numericValue < $minimum || (float) $numericValue > $maximum)) {
            $errors[] = 'Periksa kembali nilai '.str_replace('_', ' ', $field).'.';
        }
    }

    // Dokumen wajib diperiksa lebih dulu agar tidak ada berkas orphan.
    // Nama berkas dikirim sebagai dokumen__foto, dokumen__kk, dan seterusnya.
    // Notasi kurung siku tidak selalu diurai oleh PHP, sehingga slot dapat
    // tertukar. Nama datar di bawah ini aman di setiap konfigurasi.
    $uploads = [];
    foreach ($documentSlots as $slot => $meta) {
        $fieldName = 'dokumen__'.$slot;
        $file = $_FILES[$fieldName] ?? null;
        $hasFile = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$hasFile) {
            if (!empty($meta['required'])) {
                $errors[] = $meta['label'].' wajib diunggah.';
            }
            continue;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errors[] = $meta['label'].' gagal diunggah. Silakan pilih ulang berkas.';
            continue;
        }
        try {
            $uploads[$slot] = ppdb_store_private_upload($file, 'participant-documents/'.$registration['id_pendaftaran'], $documentMimes, 8 * 1024 * 1024);
        } catch (RuntimeException $exception) {
            $errors[] = $meta['label'].': '.$exception->getMessage();
        }
    }

    if (!$errors) {
        $fullAddressParts = [trim($_POST['alamat_jalan'] ?? '')];
        $rt = trim($_POST['rt'] ?? '');
        $rw = trim($_POST['rw'] ?? '');
        if ($rt !== '' || $rw !== '') {
            $fullAddressParts[] = 'RT '.($rt !== '' ? $rt : '-').' / RW '.($rw !== '' ? $rw : '-');
        }
        foreach (['desa_kelurahan', 'kecamatan', 'kabupaten', 'provinsi'] as $addressPart) {
            $value = trim($_POST[$addressPart] ?? '');
            if ($value !== '') {
                $fullAddressParts[] = $value;
            }
        }
        $fullAddress = implode(', ', $fullAddressParts);

        $detailFields = [
            'nik', 'nomor_kk', 'nomor_registrasi_akta', 'kewarganegaraan', 'kebutuhan_khusus', 'alamat_jalan', 'rt', 'rw', 'desa_kelurahan', 'kecamatan', 'kabupaten', 'provinsi', 'jenis_tinggal', 'alat_transportasi',
            'ayah_nama', 'ayah_nik', 'ayah_tahun_lahir', 'ayah_pendidikan', 'ayah_pekerjaan', 'ayah_penghasilan',
            'ibu_nama', 'ibu_nik', 'ibu_tahun_lahir', 'ibu_pendidikan', 'ibu_pekerjaan', 'ibu_penghasilan',
            'wali_nama', 'wali_nik', 'wali_tahun_lahir', 'wali_pendidikan', 'wali_pekerjaan', 'wali_penghasilan',
            'tinggi_badan_cm', 'berat_badan_kg', 'lingkar_kepala_cm', 'jarak_ke_sekolah_km', 'waktu_tempuh_menit', 'jumlah_saudara_kandung', 'jenis_pendaftaran', 'pernah_paud_tk',
        ];
        $nullableNumericFields = ['ayah_tahun_lahir', 'ibu_tahun_lahir', 'wali_tahun_lahir', 'tinggi_badan_cm', 'berat_badan_kg', 'lingkar_kepala_cm', 'jarak_ke_sekolah_km', 'waktu_tempuh_menit', 'jumlah_saudara_kandung'];

        try {
            mysqli_begin_transaction($conn);
            $lock = mysqli_prepare($conn, 'SELECT status_ppdb FROM tb_pendaftaran WHERE id_pendaftaran=? FOR UPDATE');
            mysqli_stmt_bind_param($lock, 's', $registration['id_pendaftaran']);
            mysqli_stmt_execute($lock);
            $lockedStatus = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);
            if (!$lockedStatus || !in_array($lockedStatus['status_ppdb'], ['pembayaran_terverifikasi', 'menunggu_formulir'], true)) {
                throw new RuntimeException('Formulir ini sudah tidak dapat diisi. Hubungi panitia PPDB.');
            }

            $detailValues = [$registration['id_pendaftaran']];
            foreach ($detailFields as $field) {
                $value = trim($_POST[$field] ?? '');
                $detailValues[] = $value === '' ? (in_array($field, $nullableNumericFields, true) ? null : '') : $value;
            }
            $detailColumns = array_merge(['id_pendaftaran'], $detailFields);
            $columnSql = '`'.implode('`, `', $detailColumns).'`';
            $placeholders = implode(', ', array_fill(0, count($detailColumns), '?'));
            $detailInsert = mysqli_prepare($conn, 'INSERT INTO tb_detail_dapodik ('.$columnSql.') VALUES ('.$placeholders.') ON DUPLICATE KEY UPDATE '.implode(', ', array_map(static fn ($column) => '`'.$column.'`=VALUES(`'.$column.'`)', $detailFields)));
            $references = [];
            foreach ($detailValues as $key => &$value) {
                $references[$key] = &$value;
            }
            mysqli_stmt_bind_param($detailInsert, str_repeat('s', count($detailValues)), ...$references);
            mysqli_stmt_execute($detailInsert);
            mysqli_stmt_close($detailInsert);

            foreach ($uploads as $slot => $stored) {
                $originalName = (string) $stored['original_name'];
                $storedPath = (string) $stored['path'];
                $storedMime = (string) $stored['mime'];
                $documentUpsert = mysqli_prepare($conn, 'INSERT INTO tb_dokumen_peserta (id_pendaftaran,jenis_dokumen,nama_file_asli,path_file,mime_type,ukuran_byte) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nama_file_asli=VALUES(nama_file_asli),path_file=VALUES(path_file),mime_type=VALUES(mime_type),ukuran_byte=VALUES(ukuran_byte),diunggah_pada=NOW()');
                mysqli_stmt_bind_param($documentUpsert, 'sssssi', $registration['id_pendaftaran'], $slot, $originalName, $storedPath, $storedMime, $stored['size']);
                mysqli_stmt_execute($documentUpsert);
                mysqli_stmt_close($documentUpsert);
            }

            $sourceSchool = trim($_POST['asal_sekolah'] ?? '');
            $birthPlace = trim($_POST['tmp_lahir'] ?? '');
            $religion = (string) ($_POST['agama'] ?? '');
            $informationSource = trim($_POST['sumber_informasi'] ?? '');
            $statusUpdate = mysqli_prepare($conn, "UPDATE tb_pendaftaran SET status_ppdb='formulir_terisi',status_data='belum_diperiksa',NISN=?,asal_sekolah=?,tmp_lahir=?,agama=?,alamat=?,sumber_informasi=? WHERE id_pendaftaran=?");
            mysqli_stmt_bind_param($statusUpdate, 'sssssss', $nisn, $sourceSchool, $birthPlace, $religion, $fullAddress, $informationSource, $registration['id_pendaftaran']);
            mysqli_stmt_execute($statusUpdate);
            mysqli_stmt_close($statusUpdate);

            enqueue_google_sheets_registration($conn, $registration['id_pendaftaran']);
            mysqli_commit($conn);

            $statusToken = $token;
            sync_google_sheets_registration($conn, $registration['id_pendaftaran']);
            header('Location: status-pendaftaran.php?'.http_build_query(['id' => $registration['id_pendaftaran'], 'token' => $statusToken]));
            exit;
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            foreach ($uploads as $stored) {
                if (is_file($stored['path'])) {
                    unlink($stored['path']);
                }
            }
            if ($exception instanceof RuntimeException) {
                $errors[] = $exception->getMessage();
            } else {
                error_log('PPDB formulir submission failed: '.$exception->getMessage());
                $errors[] = 'Formulir belum dapat disimpan. Silakan coba lagi.';
            }
        }
    }

    // Berkas yang gagal disimpan harus dikembalikan agar tidak menggantung di server.
    if ($errors) {
        foreach ($uploads as $stored) {
            if (is_file($stored['path'])) {
                unlink($stored['path']);
            }
        }
    }
}

$uploadedDocuments = [];
if ($authorized) {
    $documentQuery = mysqli_prepare($conn, 'SELECT jenis_dokumen,nama_file_asli,ukuran_byte,diunggah_pada FROM tb_dokumen_peserta WHERE id_pendaftaran=?');
    mysqli_stmt_bind_param($documentQuery, 's', $registration['id_pendaftaran']);
    mysqli_stmt_execute($documentQuery);
    while ($row = mysqli_fetch_assoc(mysqli_stmt_get_result($documentQuery))) {
        $uploadedDocuments[$row['jenis_dokumen']] = $row;
    }
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
    <title>Formulir Lengkap | PPDB Cendekia Takengon</title>
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
        <?php if ($authorized) { ?><a class="back-link" href="status-pendaftaran.php?id=<?php echo rawurlencode($registration['id_pendaftaran']); ?>&amp;token=<?php echo rawurlencode($token); ?>">Lihat status pendaftaran</a><?php } ?>
    </header>

    <main>
        <?php if (!$authorized) { ?>
            <section class="gate-panel">
                <p class="eyebrow">TAHAP 3 DARI 3 · FORMULIR LENGKAP</p>
                <h1>Masukkan kode<br><span>pendaftaran Anda.</span></h1>
                <p class="intro-copy">Kode ini hanya berlaku bagi pendaftar yang pembayarannya sudah disetujui panitia. Kode dikirim melalui WhatsApp.</p>

                <?php if ($errors) { ?>
                    <div class="error-summary" role="alert"><strong>Kode tidak dapat diverifikasi</strong><ul><?php foreach ($errors as $error) { ?><li><?php echo h($error); ?></li><?php } ?></ul></div>
                <?php } ?>

                <form class="gate-form" action="formulir.php" method="get" autocomplete="off">
                    <div class="field field-wide" data-field="kode">
                        <label for="kode"><span class="field-index" aria-hidden="true">01</span>Kode pendaftaran <span class="required-mark" aria-hidden="true">*</span></label>
                        <input id="kode" name="kode" value="<?php echo h($code); ?>" placeholder="Contoh: P202600123" maxlength="10" pattern="[Pp][0-9]{9}" required autofocus>
                        <small class="field-hint">Kode terdiri huruf P diikuti 9 digit angka, contoh P202600123.</small>
                    </div>
                    <button type="submit" class="submit-button">Periksa kode <span aria-hidden="true">&rarr;</span></button>
                </form>
            </section>

        <?php } elseif (!$formOpen) { ?>
            <section class="gate-panel">
                <p class="eyebrow">FORMULIR BELUM TERSEDIA</p>
                <h1><?php echo $alreadySubmitted ? 'Formulir sudah<br><span>Anda kirim.</span>' : 'Pembayaran belum<br><span>disetujui.</span>'; ?></h1>
                <?php if ($alreadySubmitted) { ?>
                    <p class="intro-copy">Formulir lengkap untuk kode <?php echo h($registration['id_pendaftaran']); ?> sudah diterima dan sedang diperiksa panitia. Hubungi panitia bila perlu perbaikan.</p>
                <?php } else { ?>
                    <p class="intro-copy">Kode <?php echo h($registration['id_pendaftaran']); ?> belum membuka formulir lengkap. Tautan formulir hanya berlaku setelah bukti pembayaran disetujui panitia.</p>
                <?php } ?>
                <a class="submit-button" href="status-pendaftaran.php?id=<?php echo rawurlencode($registration['id_pendaftaran']); ?>&amp;token=<?php echo rawurlencode($token); ?>">Lihat status pendaftaran <span aria-hidden="true">&rarr;</span></a>
            </section>

        <?php } else { ?>
            <?php if ($errors) { ?>
                <div class="error-summary" role="alert" aria-labelledby="formulir-error">
                    <strong id="formulir-error">Formulir belum terkirim</strong>
                    <ul><?php foreach ($errors as $error) { ?><li><?php echo h($error); ?></li><?php } ?></ul>
                </div>
            <?php } ?>

            <dialog class="confirm-dialog" id="konfirmasi-awal" data-confirmation aria-labelledby="konfirmasi-judul">
                <div class="confirm-card__head">
                    <div>
                        <p class="eyebrow">KONFIRMASI DATA AWAL</p>
                        <h2 id="konfirmasi-judul">Periksa kembali data ini</h2>
                    </div>
                    <span class="confirm-badge">Kode <?php echo h($registration['id_pendaftaran']); ?></span>
                </div>
                <dl class="confirm-grid">
                    <div><dt>Nama lengkap ananda</dt><dd><?php echo h($registration['nm_peserta']); ?></dd></div>
                    <div><dt>Tanggal lahir</dt><dd><?php echo h(ppdb_format_date_id($registration['tgl_lahir'])); ?></dd></div>
                    <div><dt>Jenis kelamin</dt><dd><?php echo h(ppdb_jenis_kelamin_label($registration['jenis_kelamin'])); ?></dd></div>
                    <div><dt>Unit yang dipilih</dt><dd><?php echo h($registration['nama_unit'] ?? '-'); ?> · <?php echo h($registration['th_ajaran']); ?></dd></div>
                </dl>
                <div class="confirm-card__actions">
                    <button type="button" class="choice-yes" data-confirm-choice="1">Ya, data ini benar</button>
                    <button type="button" class="choice-no" data-confirm-choice="0">Tidak, ada yang keliru</button>
                </div>
                <p class="confirm-card__note" data-confirm-note>Data di atas tidak dapat diubah pada tahap ini. Pilih <strong>Ya</strong> untuk melanjutkan isi formulir lengkap.</p>
                <input type="hidden" name="konfirmasi_lanjut" value="<?php echo (($_POST['konfirmasi_lanjut'] ?? '') === '1') ? '1' : ''; ?>" data-confirm-value form="form-utama">
            </dialog>

            <div id="formulir-terbuka" <?php echo (($_POST['konfirmasi_lanjut'] ?? '') === '1') ? '' : 'hidden'; ?>>
                <form class="registration-form" id="form-utama" action="formulir.php" method="post" enctype="multipart/form-data" autocomplete="on" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="kode" value="<?php echo h($registration['id_pendaftaran']); ?>">
                    <input type="hidden" name="token" value="<?php echo h($token); ?>">

                    <section class="form-section" aria-labelledby="dokumen-title">
                        <div class="section-heading"><span class="section-number">01</span><div><h2 id="dokumen-title">Dokumen pendukung</h2><p>Unggah berkas hasil pindai atau foto. Format JPG, PNG, atau PDF · maks. 8 MB per berkas.</p></div></div>
                        <div class="document-grid">
                            <?php foreach ($documentSlots as $slot => $meta) {
                                $already = $uploadedDocuments[$slot] ?? null;
                            ?>
                                <div class="document-card" data-upload-card="<?php echo h($slot); ?>">
                                    <div class="document-card__head">
                                        <strong><?php echo h($meta['label']); ?></strong>
                                        <?php if (!empty($meta['required'])) { ?><span class="required-mark" aria-hidden="true">*</span><?php } else { ?><span class="optional-mark">Opsional</span><?php } ?>
                                    </div>
                                    <p class="document-card__hint"><?php echo h($meta['hint']); ?></p>
                                    <?php if ($already) { ?>
                                        <p class="document-card__done">Tersimpan · <?php echo h($already['nama_file_asli']); ?> (<?php echo h(number_format((int) $already['ukuran_byte'] / 1048576, 2)); ?> MB)</p>
                                    <?php } ?>
                                    <label class="file-drop" for="dokumen-<?php echo h($slot); ?>">
                                        <input id="dokumen-<?php echo h($slot); ?>" type="file" name="dokumen__<?php echo h($slot); ?>" accept="image/jpeg,image/png,application/pdf" <?php echo !empty($meta['required']) ? 'required' : ''; ?>
                                        <span class="file-drop__label">Pilih berkas</span>
                                        <span class="file-drop__name" data-file-name>Belum ada berkas dipilih</span>
                                    </label>
                                </div>
                            <?php } ?>
                        </div>
                    </section>

                    <section class="form-section" aria-labelledby="identitas-title">
                        <div class="section-heading"><span class="section-number">02</span><div><h2 id="identitas-title">Identitas peserta didik</h2><p>Gunakan ejaan dan angka sesuai dokumen kependudukan.</p></div></div>
                        <div class="field-grid">
                            <?php foreach ($identityFields as $field) { formulir_render_field($field, $formValues); } ?>
                            <?php formulir_render_field(['name' => 'jenis_pendaftaran', 'label' => 'Jenis pendaftaran', 'type' => 'select', 'required' => true, 'options' => ['Siswa baru' => 'Siswa baru', 'Pindahan' => 'Pindahan / mutasi', 'Kembali aktif' => 'Kembali aktif']], $formValues); ?>
                        </div>
                    </section>

                    <section class="form-section" aria-labelledby="alamat-title">
                        <div class="section-heading"><span class="section-number">03</span><div><h2 id="alamat-title">Alamat tempat tinggal</h2><p>Isi alamat domisili saat ini.</p></div></div>
                        <div class="field-grid">
                            <?php foreach ($addressFields as $field) { formulir_render_field($field, $formValues); } ?>
                        </div>
                        <p class="operator-note">Koordinat lintang dan bujur dicatat operator sekolah melalui peta digital.</p>
                    </section>

                    <?php foreach ($parentGroups as $index => $group) { ?>
                        <section class="form-section" aria-labelledby="keluarga-title-<?php echo (int) $index; ?>">
                            <div class="section-heading"><span class="section-number">0<?php echo 4 + $index; ?></span><div><h2 id="keluarga-title-<?php echo (int) $index; ?>"><?php echo h($group['title']); ?></h2><p>Tulis nama tanpa gelar; isi NIK dan tahun lahir sesuai Kartu Keluarga.</p></div></div>
                            <div class="field-grid">
                                <?php foreach ($group['fields'] as $field) { formulir_render_field($field, $formValues); } ?>
                            </div>
                        </section>
                    <?php } ?>

                    <section class="form-section" aria-labelledby="periodik-title">
                        <div class="section-heading"><span class="section-number">07</span><div><h2 id="periodik-title">Data periodik</h2><p>Data terbaru jika sudah diketahui.</p></div></div>
                        <div class="field-grid">
                            <?php foreach ($periodicFields as $field) { formulir_render_field($field, $formValues); } ?>
                            <?php formulir_render_field(['name' => 'sumber_informasi', 'label' => 'Mengetahui PPDB dari', 'type' => 'select', 'options' => ['Keluarga atau alumni' => 'Keluarga atau alumni', 'Guru atau karyawan' => 'Guru atau karyawan', 'Brosur' => 'Brosur', 'Media sosial' => 'Media sosial', 'Website' => 'Website', 'Lainnya' => 'Lainnya'], 'wide' => true], $formValues); ?>
                        </div>
                    </section>

                    <div class="form-footer">
                        <label class="consent-check" for="konfirmasi_data">
                            <input type="checkbox" id="konfirmasi_data" name="konfirmasi_data" value="1" required <?php echo (($_POST['konfirmasi_data'] ?? '') === '1') ? 'checked' : ''; ?>>
                            <span>Saya memastikan seluruh data di atas benar dan dapat dipertanggungjawabkan.</span>
                        </label>
                        <p>NIPD dan tanggal masuk sekolah ditetapkan operator setelah peserta diterima. Data bertanda opsional boleh dikosongkan.</p>
                        <button type="submit" name="submit" value="1" class="submit-button">Kirim formulir lengkap <span aria-hidden="true">&rarr;</span></button>
                    </div>
                </form>
            </div>
        <?php } ?>
    </main>

    <footer class="site-footer"><span>Yayasan Wakaf Cendekia Takengon</span><a href="mailto:wakafcendekiatakengon@gmail.com">Hubungi panitia</a></footer>
</div>
<script src="js/daftar.js"></script>
<script src="js/formulir.js"></script>
<script src="js/reveal.js"></script>
</body>
</html>
