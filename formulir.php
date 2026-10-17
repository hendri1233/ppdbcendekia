<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
require_once 'ppdb_material_helpers.php';
require_once 'formulir_helpers.php';
start_app_session();

$materialLabels = [
    'brosur' => 'Brosur PPDB',
    'rincian_biaya' => 'Rincian biaya',
    'sop' => 'SOP pendaftaran',
];
$documentSlots = [
    'foto' => ['label' => 'Foto peserta didik', 'required' => false, 'hint' => 'Foto formal terbaru, latar polos.'],
    'nisn' => ['label' => 'Foto / scan NISN', 'required' => false, 'hint' => 'Tangkapan layar dari laman Cek NISN. Kosongkan bila belum ada.'],
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
    $stmt = mysqli_prepare($conn, 'SELECT p.id_pendaftaran,p.nm_peserta,p.tgl_lahir,p.jenis_kelamin,p.status_ppdb,p.status_data,p.revisi_dibuka,p.status_pembayaran,p.pendaftar,p.no_hp_pendaftar,p.th_ajaran,p.kode_unit,p.token_formulir,p.token_bukti,p.token_whatsapp,u.nama_unit,u.jenjang FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit WHERE p.id_pendaftaran=? LIMIT 1');
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
    return in_array($registration['status_ppdb'], ['pembayaran_terverifikasi', 'menunggu_formulir', 'formulir_terisi'], true)
        || !empty($registration['revisi_dibuka']);
}

/**
 * Status yang masih boleh menyimpan draf atau mengirim formulir. Peserta yang
 * sudah diterima hanya boleh mengubah formulir bila panitia membuka revisi.
 */
function formulir_is_editable(string $status, bool $revisionOpen = false): bool
{
    return in_array($status, ['pembayaran_terverifikasi', 'menunggu_formulir'], true)
        || ($revisionOpen && $status === 'diterima');
}

/** Ambil hanya isian yang dikenal formulir agar draf tidak menyimpan data liar. */
function formulir_draft_payload(array $input): array
{
    $data = [];
    foreach (formulir_input_fields() as $name => $_field) {
        $value = $input[$name] ?? '';
        if (is_string($value)) {
            $data[$name] = mb_substr($value, 0, 2000);
        }
    }
    foreach (['konfirmasi_lanjut', 'materi_dikonfirmasi'] as $flag) {
        $data[$flag] = ($input[$flag] ?? '') === '1' ? '1' : '';
    }
    return $data;
}

/** Simpan satu dokumen peserta dan kembalikan path berkas lama yang tergantikan. */
function formulir_upsert_document(mysqli $conn, string $registrationId, string $slot, array $stored): ?string
{
    $oldPath = null;
    $existing = mysqli_prepare($conn, 'SELECT path_file FROM tb_dokumen_peserta WHERE id_pendaftaran=? AND jenis_dokumen=? LIMIT 1');
    mysqli_stmt_bind_param($existing, 'ss', $registrationId, $slot);
    mysqli_stmt_execute($existing);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($existing));
    mysqli_stmt_close($existing);
    if ($row && $row['path_file'] !== $stored['path']) {
        $oldPath = $row['path_file'];
    }

    $originalName = (string) $stored['original_name'];
    $storedPath = (string) $stored['path'];
    $storedMime = (string) $stored['mime'];
    $documentUpsert = mysqli_prepare($conn, 'INSERT INTO tb_dokumen_peserta (id_pendaftaran,jenis_dokumen,nama_file_asli,path_file,mime_type,ukuran_byte) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nama_file_asli=VALUES(nama_file_asli),path_file=VALUES(path_file),mime_type=VALUES(mime_type),ukuran_byte=VALUES(ukuran_byte),diunggah_pada=NOW()');
    mysqli_stmt_bind_param($documentUpsert, 'sssssi', $registrationId, $slot, $originalName, $storedPath, $storedMime, $stored['size']);
    mysqli_stmt_execute($documentUpsert);
    mysqli_stmt_close($documentUpsert);
    return $oldPath;
}

function formulir_delete_files(array $paths): void
{
    foreach ($paths as $path) {
        if ($path && is_file($path)) {
            unlink($path);
        }
    }
}

function formulir_load_documents(mysqli $conn, string $registrationId): array
{
    $documents = [];
    $documentQuery = mysqli_prepare($conn, 'SELECT jenis_dokumen,nama_file_asli,ukuran_byte,diunggah_pada FROM tb_dokumen_peserta WHERE id_pendaftaran=?');
    mysqli_stmt_bind_param($documentQuery, 's', $registrationId);
    mysqli_stmt_execute($documentQuery);
    $result = mysqli_stmt_get_result($documentQuery);
    while ($row = mysqli_fetch_assoc($result)) {
        $documents[$row['jenis_dokumen']] = $row;
    }
    mysqli_stmt_close($documentQuery);
    return $documents;
}

$code = strtoupper(trim($_GET['kode'] ?? $_POST['kode'] ?? ''));
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
// Terima koma atau titik dari input desimal, lalu simpan dengan format titik
// agar konsisten untuk draf, validasi, dan kolom DECIMAL di basis data.
foreach (['tinggi_badan_cm', 'berat_badan_kg', 'lingkar_kepala_cm'] as $decimalField) {
    if (isset($_POST[$decimalField]) && is_string($_POST[$decimalField])) {
        $decimalValue = trim($_POST[$decimalField]);
        if (preg_match('/^[0-9]+(?:[,.][0-9]{1,2})?$/D', $decimalValue)) {
            $_POST[$decimalField] = str_replace(',', '.', $decimalValue);
        }
    }
}
$errors = [];
$notice = '';
$sheetSyncFailed = false;
$registration = formulir_lookup($conn, $code);
$authorized = $registration !== null && formulir_token_matches($registration, $token);
$formOpen = $authorized && formulir_is_open($registration);
$alreadySubmitted = $authorized && in_array($registration['status_ppdb'], ['formulir_terisi', 'diterima', 'daftar_tunggu'], true);
$isRegistrantWithdrawal = $authorized
    && $registration['status_ppdb'] === 'ditolak'
    && $registration['status_data'] === 'belum_diperiksa'
    && $registration['status_pembayaran'] === 'terverifikasi';
$materials = [];
if ($authorized && $formOpen) {
    $materialQuery = mysqli_prepare($conn, 'SELECT id,jenis_materi,isi_html,nama_file_asli,mime_file_asli,nama_file_pratinjau,path_file_pratinjau,mime_file_pratinjau FROM tb_ppdb_materials WHERE kode_unit=? AND th_ajaran=?');
    mysqli_stmt_bind_param($materialQuery, 'ss', $registration['kode_unit'], $registration['th_ajaran']);
    mysqli_stmt_execute($materialQuery);
    $materialResult = mysqli_stmt_get_result($materialQuery);
    while ($material = mysqli_fetch_assoc($materialResult)) {
        $materials[$material['jenis_materi']] = $material;
    }
    mysqli_stmt_close($materialQuery);
}
$allMaterialsPublished = true;
foreach ($materialLabels as $materialType => $_materialLabel) {
    $material = $materials[$materialType] ?? null;
    if (!$material || (trim((string) $material['isi_html']) === '' && !ppdb_material_has_preview($material))) {
        $allMaterialsPublished = false;
        break;
    }
}
$uploadedDocuments = $authorized ? formulir_load_documents($conn, $registration['id_pendaftaran']) : [];

// Simpan draf (dipanggil otomatis oleh js/formulir.js). Balasan berupa JSON.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan_draf') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    if (!$formOpen || !formulir_is_editable($registration['status_ppdb'], !empty($registration['revisi_dibuka']))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Formulir ini sudah tidak dapat diubah.']);
        exit;
    }
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'message' => 'Sesi berakhir. Muat ulang halaman; isian yang sudah tersimpan tidak hilang.']);
        exit;
    }
    $draftData = json_encode(formulir_draft_payload($_POST), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $draftStep = max(0, min(20, (int) ($_POST['langkah'] ?? 0)));
    $draftUpsert = mysqli_prepare($conn, 'INSERT INTO tb_formulir_draf (id_pendaftaran,data_json,langkah) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data_json=VALUES(data_json),langkah=VALUES(langkah)');
    mysqli_stmt_bind_param($draftUpsert, 'ssi', $registration['id_pendaftaran'], $draftData, $draftStep);
    mysqli_stmt_execute($draftUpsert);
    mysqli_stmt_close($draftUpsert);

    // Berkas yang dipilih langsung disimpan agar tidak perlu diunggah ulang.
    $draftErrors = [];
    foreach ($documentSlots as $slot => $meta) {
        $file = $_FILES['dokumen__'.$slot] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        try {
            $stored = ppdb_store_private_upload($file, 'participant-documents/'.$registration['id_pendaftaran'], $documentMimes, 8 * 1024 * 1024);
            try {
                formulir_delete_files([formulir_upsert_document($conn, $registration['id_pendaftaran'], $slot, $stored)]);
            } catch (Throwable $exception) {
                formulir_delete_files([$stored['path']]);
                throw $exception;
            }
        } catch (RuntimeException $exception) {
            $draftErrors[] = $meta['label'].': '.$exception->getMessage();
        } catch (Throwable $exception) {
            error_log('PPDB draft upload failed: '.$exception->getMessage());
            $draftErrors[] = $meta['label'].' belum dapat disimpan.';
        }
    }

    $documents = [];
    foreach (formulir_load_documents($conn, $registration['id_pendaftaran']) as $slot => $document) {
        $documents[$slot] = ['name' => $document['nama_file_asli'], 'size' => (int) $document['ukuran_byte']];
    }
    // Jam diambil dari basis data agar sama dengan zona waktu yang tercatat.
    $savedAtQuery = mysqli_prepare($conn, "SELECT DATE_FORMAT(updated_at,'%H:%i') AS jam FROM tb_formulir_draf WHERE id_pendaftaran=? LIMIT 1");
    mysqli_stmt_bind_param($savedAtQuery, 's', $registration['id_pendaftaran']);
    mysqli_stmt_execute($savedAtQuery);
    $savedAt = (string) (mysqli_fetch_assoc(mysqli_stmt_get_result($savedAtQuery))['jam'] ?? '');
    mysqli_stmt_close($savedAtQuery);
    echo json_encode(['ok' => true, 'saved_at' => $savedAt, 'documents' => $documents, 'errors' => $draftErrors], JSON_UNESCAPED_UNICODE);
    exit;
}

// Isian awal: kiriman yang gagal divalidasi, lalu draf tersimpan.
$draftSavedAt = null;
$formValues = [];
$currentStep = 0;
if ($formOpen) {
    $draftQuery = mysqli_prepare($conn, 'SELECT data_json,langkah,updated_at FROM tb_formulir_draf WHERE id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($draftQuery, 's', $registration['id_pendaftaran']);
    mysqli_stmt_execute($draftQuery);
    $draftRow = mysqli_fetch_assoc(mysqli_stmt_get_result($draftQuery));
    mysqli_stmt_close($draftQuery);
    if ($draftRow) {
        $decodedDraft = json_decode($draftRow['data_json'], true);
        if (is_array($decodedDraft)) {
            $formValues = $decodedDraft;
            $currentStep = (int) $draftRow['langkah'];
            $draftSavedAt = $draftRow['updated_at'];
        }
    } else {
        // Formulir yang dibuka kembali untuk revisi: muat isian yang pernah dikirim.
        $savedValues = formulir_saved_values($conn, $registration['id_pendaftaran']);
        if ($savedValues) {
            $formValues = $savedValues;
            // Data awal dan materi sudah dikonfirmasi saat pengiriman pertama.
            $formValues['konfirmasi_lanjut'] = '1';
            $formValues['materi_dikonfirmasi'] = '1';
            $draftSavedAt = 'revisi';
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formValues = $_POST;
    $currentStep = (int) ($_POST['langkah'] ?? $currentStep);
}
// Formulir yang dibuka untuk revisi tidak perlu mengulang konfirmasi data awal dan materi.
if ($formOpen && !empty($registration['revisi_dibuka']) || $draftSavedAt === 'revisi') {
    $formValues['konfirmasi_lanjut'] = '1';
    $formValues['materi_dikonfirmasi'] = '1';
}
$canShowFullForm = ($formValues['konfirmasi_lanjut'] ?? '') === '1' && ($formValues['materi_dikonfirmasi'] ?? '') === '1';

if ($registration && !$authorized && preg_match('/^P[0-9]{9}$/', $code)) {
    $errors[] = 'Kode pendaftaran tidak dikenali atau tautan tidak sah. Gunakan kode yang diterima melalui WhatsApp dari panitia.';
}

// Aturan wajib/opsional dari super admin dan isian khusus jenjang (mis. TK A/B).
$dataSections = formulir_sections_for($conn, (string) ($registration['jenjang'] ?? ''));
$inputFields = formulir_input_fields($dataSections);
$guidePath = __DIR__ . '/petunjuk_teknis_spmb_wakaf_cendekia.json';
$spmbGuide = null;
$guideLoadError = '';
if (!is_file($guidePath)) {
    $guideLoadError = 'file tidak ditemukan';
} else {
    $guideContents = file_get_contents($guidePath);
    if ($guideContents === false) {
        $guideLoadError = 'file tidak dapat dibaca';
    } else {
        $spmbGuide = json_decode($guideContents, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($spmbGuide)) {
            $guideLoadError = 'JSON tidak valid: ' . json_last_error_msg();
        }
    }
}
$spmbGuideAvailable = $guideLoadError === '';
if (!$spmbGuideAvailable) {
    error_log('Unable to load the SPMB guide JSON from ' . $guidePath . ': ' . $guideLoadError);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['workflow_action'] ?? '') === 'decline_formulir') {
    if (!$authorized) {
        $errors[] = 'Tautan pendaftaran tidak sah. Gunakan tautan resmi dari panitia.';
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Sesi formulir berakhir. Muat ulang halaman sebelum mencoba kembali.';
    } else {
        try {
            mysqli_begin_transaction($conn);
            $lock = mysqli_prepare($conn, 'SELECT status_ppdb,status_pembayaran FROM tb_pendaftaran WHERE id_pendaftaran=? FOR UPDATE');
            mysqli_stmt_bind_param($lock, 's', $registration['id_pendaftaran']);
            if (!mysqli_stmt_execute($lock)) {
                mysqli_stmt_close($lock);
                throw new RuntimeException('Status pendaftaran belum dapat diverifikasi. Silakan coba kembali.');
            }
            $lockedStatus = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);
            if (
                !$lockedStatus
                || !in_array($lockedStatus['status_ppdb'], ['pembayaran_terverifikasi', 'menunggu_formulir'], true)
                || $lockedStatus['status_pembayaran'] !== 'terverifikasi'
            ) {
                throw new RuntimeException('Pendaftaran ini tidak dapat dibatalkan pada tahap saat ini. Hubungi panitia PPDB.');
            }

            $decline = mysqli_prepare($conn, "UPDATE tb_pendaftaran SET status_ppdb='ditolak',status_data='belum_diperiksa',data_diperiksa_oleh=NULL,data_diperiksa_pada=NULL WHERE id_pendaftaran=?");
            mysqli_stmt_bind_param($decline, 's', $registration['id_pendaftaran']);
            if (!mysqli_stmt_execute($decline)) {
                mysqli_stmt_close($decline);
                throw new RuntimeException('Pilihan Anda belum dapat disimpan. Silakan coba kembali.');
            }
            mysqli_stmt_close($decline);
            enqueue_google_sheets_registration($conn, $registration['id_pendaftaran']);
            if (!mysqli_commit($conn)) {
                throw new RuntimeException('Pilihan Anda belum dapat disimpan. Silakan coba kembali.');
            }

            $sheetSyncFailed = !sync_google_sheets_registration($conn, $registration['id_pendaftaran']);
            $registration['status_ppdb'] = 'ditolak';
            $registration['status_data'] = 'belum_diperiksa';
            $registration['status_pembayaran'] = $lockedStatus['status_pembayaran'];
            $formOpen = false;
            $isRegistrantWithdrawal = true;
            $notice = 'Pilihan Anda telah dicatat di sistem dan tersedia pada panel panitia PPDB. Jika Anda salah memilih, panitia dapat mengirimkan tautan formulir kembali.';
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            if ($exception instanceof RuntimeException) {
                $errors[] = $exception->getMessage();
            } else {
                error_log('PPDB formulir withdrawal failed: '.$exception->getMessage());
                $errors[] = 'Pilihan Anda belum dapat disimpan. Silakan coba kembali atau hubungi panitia.';
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $formOpen) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Sesi formulir berakhir. Muat ulang halaman sebelum mencoba kembali.';
    }
    if (($_POST['konfirmasi_data'] ?? '') !== '1') {
        $errors[] = 'Anda harus menyetujui konfirmasi data sebelum mengirim formulir.';
    }
    if (($_POST['konfirmasi_lanjut'] ?? '') !== '1') {
        $errors[] = 'Isian data registrasi awal belum dikonfirmasi.';
    }
    if (($_POST['materi_dikonfirmasi'] ?? '') !== '1') {
        $errors[] = 'Baca brosur, rincian biaya, dan SOP sebelum melanjutkan.';
    }
    if (!$allMaterialsPublished) {
        $errors[] = 'Materi pendaftaran belum lengkap. Hubungi panitia PPDB sebelum melanjutkan.';
    }
    $errors = array_merge($errors, formulir_validate_fields($_POST, $dataSections));
    // NIK: kekeliruan pasti ditolak; ketidakcocokan dengan data lain hanya ditandai.
    $identityChecks = formulir_identity_checks($_POST, $registration, $inputFields);
    $errors = array_merge($errors, $identityChecks['errors']);

    // Dokumen yang sudah tersimpan lewat draf tidak perlu diunggah ulang.
    // Nama berkas dikirim sebagai dokumen__foto, dokumen__kk, dan seterusnya.
    // Notasi kurung siku tidak selalu diurai oleh PHP, sehingga slot dapat
    // tertukar. Nama datar di bawah ini aman di setiap konfigurasi.
    $uploads = [];
    foreach ($documentSlots as $slot => $meta) {
        $file = $_FILES['dokumen__'.$slot] ?? null;
        $hasFile = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$hasFile) {
            if (!empty($meta['required']) && !isset($uploadedDocuments[$slot])) {
                $errors[] = $meta['label'].' wajib diunggah.';
            }
            continue;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errors[] = $meta['label'].' gagal diunggah. Silakan pilih ulang berkas.';
            continue;
        }
        if ($errors) {
            continue;
        }
        try {
            $uploads[$slot] = ppdb_store_private_upload($file, 'participant-documents/'.$registration['id_pendaftaran'], $documentMimes, 8 * 1024 * 1024);
        } catch (RuntimeException $exception) {
            $errors[] = $meta['label'].': '.$exception->getMessage();
        }
    }

    if (!$errors) {
        $value = static fn (string $name): string => trim((string) ($_POST[$name] ?? ''));

        $fullAddressParts = [$value('alamat_jalan')];
        if ($value('dusun') !== '') {
            $fullAddressParts[] = 'Dusun '.$value('dusun');
        }
        if ($value('rt') !== '' || $value('rw') !== '') {
            $fullAddressParts[] = 'RT '.($value('rt') !== '' ? $value('rt') : '-').' / RW '.($value('rw') !== '' ? $value('rw') : '-');
        }
        foreach (['desa_kelurahan', 'kecamatan', 'kabupaten', 'provinsi', 'kode_pos'] as $addressPart) {
            if ($value($addressPart) !== '') {
                $fullAddressParts[] = $value($addressPart);
            }
        }
        $fullAddress = implode(', ', $fullAddressParts);

        // Isian bersyarat yang tersembunyi (mis. data wali saat tidak ada wali) disimpan kosong.
        $fieldValue = static function (array $field) use ($value): ?string {
            $fieldInput = formulir_condition_met($field, $_POST) ? $value($field['name']) : '';
            return $fieldInput === '' && !empty($field['numeric']) ? null : $fieldInput;
        };

        $detailValues = ['id_pendaftaran' => $registration['id_pendaftaran'], 'catatan_validasi' => $identityChecks['flags'] ? implode("\n", $identityChecks['flags']) : null];
        $dataStatus = $identityChecks['flags'] ? 'perlu_perbaikan' : 'belum_diperiksa';
        $parentRows = ['ayah' => [], 'ibu' => [], 'wali' => []];
        foreach ($inputFields as $name => $field) {
            if ($field['store'] === 'detail') {
                $detailValues[$name] = $fieldValue($field);
            } elseif ($field['store'] === 'ortu') {
                $parentRows[$field['peran']][$field['column']] = $fieldValue($field);
            }
        }
        $parentRows['wali']['tinggal_bersama_ananda'] = $value('jenis_tinggal') === 'Bersama wali' ? 'Ya' : 'Tidak';

        $replacedFiles = [];
        try {
            mysqli_begin_transaction($conn);
            $lock = mysqli_prepare($conn, 'SELECT status_ppdb,revisi_dibuka,data_diperiksa_pada FROM tb_pendaftaran WHERE id_pendaftaran=? FOR UPDATE');
            mysqli_stmt_bind_param($lock, 's', $registration['id_pendaftaran']);
            mysqli_stmt_execute($lock);
            $lockedStatus = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);
            if (!$lockedStatus || !formulir_is_editable($lockedStatus['status_ppdb'], !empty($lockedStatus['revisi_dibuka']))) {
                throw new RuntimeException('Formulir ini sudah tidak dapat diisi. Hubungi panitia PPDB.');
            }
            // Isian lama dibaca sebelum ditimpa; bila ada, kiriman ini adalah revisi.
            $oldValues = formulir_saved_values($conn, $registration['id_pendaftaran']);

            $detailColumns = array_keys($detailValues);
            $updateColumns = array_slice($detailColumns, 1);
            $detailInsert = mysqli_prepare($conn, 'INSERT INTO tb_detail_dapodik (`'.implode('`, `', $detailColumns).'`) VALUES ('.implode(', ', array_fill(0, count($detailColumns), '?')).') ON DUPLICATE KEY UPDATE '.implode(', ', array_map(static fn ($column) => '`'.$column.'`=VALUES(`'.$column.'`)', $updateColumns)));
            $detailParams = array_values($detailValues);
            mysqli_stmt_bind_param($detailInsert, str_repeat('s', count($detailParams)), ...$detailParams);
            mysqli_stmt_execute($detailInsert);
            mysqli_stmt_close($detailInsert);

            foreach ($parentRows as $role => $columns) {
                $parentColumns = array_merge(['id_pendaftaran', 'peran'], FORMULIR_PARENT_EXTRA_COLUMNS);
                $parentParams = [$registration['id_pendaftaran'], $role];
                foreach (FORMULIR_PARENT_EXTRA_COLUMNS as $column) {
                    $parentParams[] = (string) ($columns[$column] ?? '');
                }
                $parentUpsert = mysqli_prepare($conn, 'INSERT INTO tb_orang_tua_peserta (`'.implode('`, `', $parentColumns).'`) VALUES ('.implode(', ', array_fill(0, count($parentColumns), '?')).') ON DUPLICATE KEY UPDATE '.implode(', ', array_map(static fn ($column) => '`'.$column.'`=VALUES(`'.$column.'`)', FORMULIR_PARENT_EXTRA_COLUMNS)));
                mysqli_stmt_bind_param($parentUpsert, str_repeat('s', count($parentParams)), ...$parentParams);
                mysqli_stmt_execute($parentUpsert);
                mysqli_stmt_close($parentUpsert);
            }

            foreach ($uploads as $slot => $stored) {
                $replacedFiles[] = formulir_upsert_document($conn, $registration['id_pendaftaran'], $slot, $stored);
            }

            $nisn = formulir_condition_met($inputFields['NISN'], $_POST) ? $value('NISN') : '';
            // Sekolah asal di bawah yayasan cukup dipilih; namanya diisikan otomatis.
            $previousSchool = $value('jenjang_sekolah_asal');
            $sourceSchool = $previousSchool === 'Sekolah lain' ? $value('asal_sekolah') : (str_ends_with($previousSchool, 'IT Cendekia') ? $previousSchool : '');
            $birthPlace = $value('tmp_lahir');
            $religion = $value('agama');
            $informationSource = $value('sumber_informasi');
            // Peserta yang sudah diterima tetap diterima; revisi hanya menandai data untuk dicek ulang.
            $statusUpdate = mysqli_prepare($conn, "UPDATE tb_pendaftaran SET status_ppdb=IF(status_ppdb='diterima','diterima','formulir_terisi'),revisi_dibuka=0,status_data=?,NISN=?,asal_sekolah=?,tmp_lahir=?,agama=?,alamat=?,sumber_informasi=? WHERE id_pendaftaran=?");
            mysqli_stmt_bind_param($statusUpdate, 'ssssssss', $dataStatus, $nisn, $sourceSchool, $birthPlace, $religion, $fullAddress, $informationSource, $registration['id_pendaftaran']);
            mysqli_stmt_execute($statusUpdate);
            mysqli_stmt_close($statusUpdate);

            $isRevision = $oldValues !== [];
            if ($isRevision) {
                $newValues = [];
                foreach ($inputFields as $name => $field) {
                    $newValues[$name] = (string) ($fieldValue($field) ?? '');
                }
                $newValues['NISN'] = $nisn;
                $newValues['asal_sekolah'] = $sourceSchool;
                $changes = formulir_diff_values($oldValues, $newValues, $dataSections);
                // Dokumen yang diunggah ulang sejak revisi dibuka (lewat draf maupun kiriman ini).
                $since = (string) ($lockedStatus['data_diperiksa_pada'] ?? '');
                $documentQuery = mysqli_prepare($conn, 'SELECT jenis_dokumen,nama_file_asli FROM tb_dokumen_peserta WHERE id_pendaftaran=? AND diunggah_pada >= ?');
                mysqli_stmt_bind_param($documentQuery, 'ss', $registration['id_pendaftaran'], $since);
                mysqli_stmt_execute($documentQuery);
                $documentResult = mysqli_stmt_get_result($documentQuery);
                while ($since !== '' && $document = mysqli_fetch_assoc($documentResult)) {
                    $changes[] = ['Dokumen pendukung', $documentSlots[$document['jenis_dokumen']]['label'] ?? $document['jenis_dokumen'], 'Berkas lama', 'Diunggah ulang: '.$document['nama_file_asli']];
                }
                mysqli_stmt_close($documentQuery);
                $changesJson = json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $changeCount = count($changes);
                $revisionInsert = mysqli_prepare($conn, 'INSERT INTO tb_formulir_revisi (id_pendaftaran,perubahan_json,jumlah_perubahan) VALUES (?,?,?)');
                mysqli_stmt_bind_param($revisionInsert, 'ssi', $registration['id_pendaftaran'], $changesJson, $changeCount);
                mysqli_stmt_execute($revisionInsert);
                mysqli_stmt_close($revisionInsert);
            }

            $draftDelete = mysqli_prepare($conn, 'DELETE FROM tb_formulir_draf WHERE id_pendaftaran=?');
            mysqli_stmt_bind_param($draftDelete, 's', $registration['id_pendaftaran']);
            mysqli_stmt_execute($draftDelete);
            mysqli_stmt_close($draftDelete);

            enqueue_google_sheets_registration($conn, $registration['id_pendaftaran']);
            mysqli_commit($conn);
            formulir_delete_files($replacedFiles);

            sync_google_sheets_registration($conn, $registration['id_pendaftaran']);
            $statusQuery = ['id' => $registration['id_pendaftaran'], 'token' => $token];
            if ($isRevision) {
                $statusQuery['revisi'] = (string) $changeCount;
            }
            header('Location: status-pendaftaran.php?'.http_build_query($statusQuery));
            exit;
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
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
        formulir_delete_files(array_column($uploads, 'path'));
    }
}

$showFullForm = $formOpen && $canShowFullForm && $allMaterialsPublished;

// Langkah formulir: bagian data, lalu dokumen dan konfirmasi.
$steps = [];
foreach ($dataSections as $sectionKey => $section) {
    $steps[] = ['key' => $sectionKey, 'title' => $section['title']];
}
$steps[] = ['key' => 'dokumen', 'title' => 'Dokumen & kirim'];
$currentStep = max(0, min(count($steps) - 1, $currentStep));

function formulir_step_actions(int $index, int $total): void
{
    echo '<div class="step-actions">';
    echo $index > 0 ? '<button type="button" class="step-button step-button--back" data-step-prev>&larr; Sebelumnya</button>' : '<span></span>';
    echo '<span class="draft-status" data-draft-status aria-live="polite"></span>';
    if ($index < $total - 1) {
        echo '<button type="button" class="submit-button" data-step-next>Simpan &amp; lanjut <span aria-hidden="true">&rarr;</span></button>';
    }
    echo '</div>';
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
    <link rel="icon" href="img/logo-display.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Source+Serif+4:opsz,wght@8..60,600;8..60,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/tokens.css">
    <link rel="stylesheet" href="css/daftar.css?v=<?php echo (int) filemtime(__DIR__.'/css/daftar.css'); ?>">
</head>
<body>
<a class="skip-link" href="#form-utama">Lompat ke formulir</a>
<div class="page-shell">
    <header class="site-header">
        <a class="brand" href="index.php" aria-label="Yayasan Wakaf Cendekia Takengon, beranda">
            <img class="brand-logo" src="img/logo-display.png" alt="" width="46" height="45">
            <span class="brand-copy"><strong>Wakaf Cendekia</strong><small>Takengon · PPDB online</small></span>
        </a>
        <?php if ($authorized) { ?><a class="back-link" href="status-pendaftaran.php?id=<?php echo rawurlencode($registration['id_pendaftaran']); ?>&amp;token=<?php echo rawurlencode($token); ?>">Lihat status pendaftaran</a><?php } ?>
    </header>

    <main>
        <?php if (!$authorized) { ?>
            <section class="gate-panel">
                <p class="eyebrow">Tahap 3 dari 3 · Formulir lengkap</p>
                <h1>Masukkan kode<br><span>pendaftaran Anda.</span></h1>
                <p class="intro-copy">Kode ini hanya berlaku bagi pendaftar yang pembayarannya sudah disetujui panitia. Kode dikirim melalui WhatsApp.</p>

                <?php if ($errors) { ?>
                    <div class="error-summary" role="alert"><strong>Kode tidak dapat diverifikasi</strong><ul><?php foreach ($errors as $error) { ?><li><?php echo h($error); ?></li><?php } ?></ul></div>
                <?php } ?>

                <form class="gate-form" action="formulir.php" method="get" autocomplete="off">
                    <div class="field field-wide" data-field="kode">
                        <label for="kode">Kode pendaftaran <span class="required-mark" aria-hidden="true">*</span></label>
                        <input id="kode" name="kode" value="<?php echo h($code); ?>" placeholder="Contoh: P202600123" maxlength="10" pattern="[Pp][0-9]{9}" required autofocus>
                        <small class="field-hint">Kode terdiri huruf P diikuti 9 digit angka, contoh P202600123.</small>
                    </div>
                    <button type="submit" class="submit-button">Periksa kode <span aria-hidden="true">&rarr;</span></button>
                </form>
            </section>

        <?php } elseif (!$formOpen && $isRegistrantWithdrawal) { ?>
            <section class="gate-panel">
                <p class="eyebrow">STATUS PILIHAN ANDA</p>
                <h1>Pendaftaran tidak<br><span>dilanjutkan.</span></h1>
                <?php if ($notice !== '') { ?><div class="alert-success" role="status"><?php echo h($notice); ?></div><?php } ?>
                <?php if ($sheetSyncFailed) { ?><div class="error-summary" role="alert">Status pendaftaran sudah tersimpan dan terlihat di panel admin, tetapi sinkronisasi ke spreadsheet gagal. Panitia dapat memeriksanya langsung pada data peserta.</div><?php } ?>
                <?php if ($errors) { ?><div class="error-summary" role="alert"><ul><?php foreach ($errors as $error) { ?><li><?php echo h($error); ?></li><?php } ?></ul></div><?php } ?>
                <p class="intro-copy">Panitia PPDB telah menerima informasi bahwa Anda memilih tidak melanjutkan pengisian formulir untuk <?php echo h($registration['nama_unit'] ?? 'unit pilihan Anda'); ?>. Formulir sementara tidak dapat dibuka. Jika pilihan ini tidak disengaja, silakan hubungi panitia untuk meminta tautan pengisian dikirim kembali.</p>
                <a class="submit-button" href="status-pendaftaran.php?id=<?php echo rawurlencode($registration['id_pendaftaran']); ?>&amp;token=<?php echo rawurlencode($token); ?>">Lihat status pendaftaran <span aria-hidden="true">&rarr;</span></a>
            </section>

        <?php } elseif (!$formOpen) { ?>
            <section class="gate-panel">
                <p class="eyebrow">Formulir belum tersedia</p>
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
                        <p class="eyebrow">Konfirmasi data awal</p>
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
                <p class="confirm-card__note" data-confirm-note>Data di atas tidak dapat diubah pada tahap ini. Pilih <strong>Ya</strong> untuk membaca brosur, rincian biaya, dan SOP terlebih dahulu.</p>
                <input type="hidden" name="konfirmasi_lanjut" value="<?php echo (($formValues['konfirmasi_lanjut'] ?? '') === '1') ? '1' : ''; ?>" data-confirm-value form="form-utama">
            </dialog>

            <dialog class="confirm-dialog intro-dialog" data-final-intro-dialog aria-labelledby="pengantar-lanjutan-judul">
                <div class="confirm-card__head">
                    <div>
                        <p class="eyebrow">PENGANTAR SEBELUM MENGISI</p>
                        <h2 id="pengantar-lanjutan-judul">Mohon siapkan diri dan kelengkapan</h2>
                    </div>
                    <span class="confirm-badge"><?php echo h($registration['nama_unit'] ?? 'Unit pilihan'); ?> · <?php echo h($registration['th_ajaran']); ?></span>
                </div>
                <div class="intro-dialog__body" id="pengantar-lanjutan-ringkas">
                    <?php if ($spmbGuideAvailable) { ?>
                        <p class="intro-dialog__greeting"><?php echo h($spmbGuide['openingGreeting'] ?? ''); ?></p>
                        <?php foreach (($spmbGuide['sections'] ?? []) as $guideSection) { ?>
                            <section class="intro-dialog__section">
                                <h3><?php
                                    $heading = (string) ($guideSection['heading'] ?? '');
                                    $guideAcademicYear = (string) ($spmbGuide['academicYear'] ?? '');
                                    echo h($guideAcademicYear !== '' ? str_replace($guideAcademicYear, $registration['th_ajaran'], $heading) : $heading);
                                ?></h3>
                                <?php foreach (($guideSection['paragraphs'] ?? []) as $paragraph) { ?>
                                    <?php
                                        $paragraph = (string) $paragraph;
                                        echo '<p>'.h($guideAcademicYear !== '' ? str_replace($guideAcademicYear, $registration['th_ajaran'], $paragraph) : $paragraph).'</p>';
                                    ?>
                                <?php } ?>
                                <?php if (!empty($guideSection['description'])) { ?><p><?php echo h($guideSection['description']); ?></p><?php } ?>
                                <?php if (!empty($guideSection['programs'])) { ?>
                                    <ol>
                                        <?php foreach ($guideSection['programs'] as $program) { ?><li><?php echo h($program); ?></li><?php } ?>
                                    </ol>
                                <?php } ?>
                                <?php if (!empty($guideSection['intro'])) { ?><p><?php echo h($guideSection['intro']); ?></p><?php } ?>
                                <?php foreach (($guideSection['instructions'] ?? []) as $instruction) { ?>
                                    <article class="intro-dialog__instruction">
                                        <h4><?php echo h($instruction['title'] ?? ''); ?></h4>
                                        <?php if (!empty($instruction['text'])) {
                                            $instructionText = (string) $instruction['text'];
                                            echo '<p>'.h($guideAcademicYear !== '' ? str_replace($guideAcademicYear, $registration['th_ajaran'], $instructionText) : $instructionText).'</p>';
                                        } ?>
                                        <?php if (!empty($instruction['note'])) { ?><p class="intro-dialog__note"><?php echo h($instruction['note']); ?></p><?php } ?>
                                        <?php if (!empty($instruction['ageGuidelines'])) {
                                            $unitGuidelines = array_filter(
                                                $instruction['ageGuidelines'],
                                                static function (array $guideline) use ($registration): bool {
                                                    $unit = (string) ($registration['jenjang'] ?? '');
                                                    return $unit === 'TK'
                                                        ? str_starts_with((string) ($guideline['unit'] ?? ''), 'TK ')
                                                        : strtoupper((string) ($guideline['unit'] ?? '')) === $unit
                                                            || str_starts_with((string) ($guideline['unit'] ?? ''), $unit . ' ');
                                                }
                                            );
                                            if ($unitGuidelines) { ?>
                                                <ul class="intro-dialog__unit-note">
                                                    <?php foreach ($unitGuidelines as $guideline) { ?>
                                                        <li>
                                                            <strong><?php echo h($guideline['unit'] ?? ''); ?>:</strong>
                                                            <?php echo h('Usia ' . ($guideline['minimumYears'] ?? '') . ' sampai ' . ($guideline['maximumYears'] ?? '') . ' tahun.'); ?>
                                                            <?php if (!empty($guideline['note'])) { ?> <?php echo h($guideline['note']); ?><?php } ?>
                                                        </li>
                                                    <?php } ?>
                                                </ul>
                                            <?php } else { ?>
                                                <p class="intro-dialog__unit-note">Panduan usia untuk jenjang <?php echo h($registration['jenjang'] ?? $registration['nama_unit'] ?? 'pilihan Anda'); ?> tidak dicantumkan pada petunjuk ini. Silakan konfirmasi kepada panitia.</p>
                                            <?php }
                                        } ?>
                                        <?php if (!empty($instruction['requiredDocuments'])) { ?>
                                            <ul>
                                                <?php foreach ($instruction['requiredDocuments'] as $document) { ?><li><?php echo h($document); ?></li><?php } ?>
                                            </ul>
                                        <?php } ?>
                                    </article>
                                <?php } ?>
                                <?php if (!empty($guideSection['paragraph'])) { ?><p><?php echo h($guideSection['paragraph']); ?></p><?php } ?>
                            </section>
                        <?php } ?>
                        <p class="intro-dialog__greeting"><?php echo h($spmbGuide['closingGreeting'] ?? ''); ?></p>
                        <p><strong><?php echo h($spmbGuide['signOff']['greeting'] ?? ''); ?></strong><br><?php echo h($spmbGuide['signOff']['from'] ?? ''); ?></p>
                    <?php } else { ?>
                        <p class="error-summary" role="alert">Petunjuk teknis belum dapat dimuat. Silakan hubungi panitia PPDB sebelum melanjutkan.</p>
                    <?php } ?>
                </div>
                <div class="confirm-card__actions">
                    <p class="materials-stage__notice" data-final-intro-warning role="alert" hidden></p>
                    <button type="button" class="choice-yes" data-final-intro-continue <?php echo $spmbGuideAvailable ? '' : 'disabled'; ?>>Saya memahami, mulai mengisi</button>
                    <button type="button" class="choice-no intro-dialog__decline" data-intro-decline>Tidak lanjut</button>
                </div>
                <form method="post" data-decline-form>
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="kode" value="<?php echo h($registration['id_pendaftaran']); ?>">
                    <input type="hidden" name="token" value="<?php echo h($token); ?>">
                    <input type="hidden" name="workflow_action" value="decline_formulir">
                </form>
            </dialog>

            <section class="materials-stage" id="materi-pendaftaran" data-material-stage hidden aria-labelledby="materi-title">
                <div class="materials-stage__heading">
                    <p class="eyebrow">Tahap 2 dari 3 · Materi pendaftaran</p>
                    <h2 id="materi-title">Baca informasi sebelum melanjutkan</h2>
                    <p>Brosur, rincian biaya, dan SOP berikut disediakan panitia untuk unit dan tahun ajaran pendaftaran Anda.</p>
                </div>
                <?php foreach ($materialLabels as $materialType => $materialLabel) {
                    $material = $materials[$materialType] ?? null;
                    $body = ppdb_sanitize_rich_text((string) ($material['isi_html'] ?? ''));
                    $previewMime = (string) ($material['mime_file_pratinjau'] ?? '');
                    $fileQuery = [
                        'id' => (int) ($material['id'] ?? 0),
                        'file' => 'preview',
                        'kode' => $registration['id_pendaftaran'],
                        'token' => $token,
                    ];
                    $previewUrl = 'ppdb-materi-file.php?'.http_build_query($fileQuery);
                    $downloadUrl = 'ppdb-materi-file.php?'.http_build_query(array_merge($fileQuery, ['file' => 'original']));
                ?>
                    <article class="participant-material">
                        <h3><?php echo h($materialLabel); ?></h3>
                        <?php if ($body !== '') { ?><div class="participant-material__content"><?php echo $body; ?></div><?php } ?>
                        <?php if ($material && ppdb_material_has_preview($material)) { ?>
                            <?php if ($previewMime === 'application/pdf') { ?>
                                <div class="participant-material__mobile-pdf" data-pdf-viewer data-pdf-url="<?php echo h($previewUrl); ?>">
                                    <div class="participant-material__pdf-toolbar" role="group" aria-label="Kontrol dokumen PDF">
                                        <button type="button" data-pdf-previous disabled aria-label="Halaman sebelumnya">&larr;</button>
                                        <span data-pdf-page aria-live="polite">Memuat PDF…</span>
                                        <button type="button" data-pdf-next disabled aria-label="Halaman berikutnya">&rarr;</button>
                                        <button type="button" data-pdf-zoom-out disabled aria-label="Perkecil tampilan">&minus;</button>
                                        <button type="button" data-pdf-zoom-in disabled aria-label="Perbesar tampilan">+</button>
                                    </div>
                                    <p class="participant-material__pdf-status" data-pdf-status role="status">Dokumen sedang dimuat…</p>
                                    <div class="participant-material__pdf-canvas-wrap" data-pdf-canvas-wrap hidden>
                                        <canvas data-pdf-canvas aria-label="Halaman PDF"></canvas>
                                    </div>
                                    <a class="participant-material__pdf-fallback" href="<?php echo h($previewUrl); ?>" target="_blank" rel="noopener noreferrer" data-pdf-fallback hidden>Buka PDF di penampil perangkat</a>
                                </div>
                                <iframe class="participant-material__pdf" src="<?php echo h($previewUrl); ?>" title="Pratinjau <?php echo h($materialLabel); ?>"></iframe>
                            <?php } elseif (in_array($previewMime, ['image/jpeg', 'image/png'], true)) { ?>
                                <img class="participant-material__image" src="<?php echo h($previewUrl); ?>" alt="Pratinjau <?php echo h($materialLabel); ?>">
                            <?php } ?>
                        <?php } elseif (!$body && (!$material || empty($material['nama_file_asli']))) { ?>
                            <p class="participant-material__missing">Materi ini belum tersedia. Hubungi panitia PPDB.</p>
                        <?php } ?>
                        <?php if ($material && !empty($material['nama_file_asli']) && !ppdb_material_has_preview($material)) { ?>
                            <p class="participant-material__missing">File materi saat ini tidak tersedia. Hubungi panitia PPDB.</p>
                        <?php } ?>
                        <?php if ($material && ppdb_material_has_original($material)) { ?>
                            <a class="participant-material__download" href="<?php echo h($downloadUrl); ?>">Unduh file asli: <?php echo h($material['nama_file_asli']); ?></a>
                        <?php } ?>
                    </article>
                <?php } ?>
                <?php if (!$allMaterialsPublished) { ?><p class="materials-stage__notice" role="status">Materi belum lengkap untuk unit dan tahun ajaran ini. Hubungi panitia agar informasi dapat diterbitkan.</p><?php } ?>
                <label class="materials-read-confirm">
                    <input type="checkbox" data-materials-read>
                    <span>Saya telah membaca brosur, rincian biaya, dan SOP di atas.</span>
                </label>
                <button class="submit-button materials-next" type="button" data-materials-next data-materials-ready="<?php echo $allMaterialsPublished ? '1' : '0'; ?>" <?php echo !$allMaterialsPublished ? 'disabled' : ''; ?>>Lanjut ke formulir <span aria-hidden="true">&rarr;</span></button>
            </section>

            <div id="formulir-terbuka" <?php echo $showFullForm ? '' : 'hidden'; ?>>
                <div class="form-stage-intro">
                    <p class="eyebrow">Tahap 3 dari 3</p>
                    <h2>Formulir data lengkap</h2>
                    <p>Isian tersimpan otomatis. Jika terhenti, buka kembali tautan dari WhatsApp untuk melanjutkan dari langkah terakhir.</p>
                    <?php if ($draftSavedAt === 'revisi' && $_SERVER['REQUEST_METHOD'] !== 'POST') { ?><p class="draft-restored" role="status">Panitia membuka kembali formulir ini untuk diperbaiki. Isian yang pernah Anda kirim sudah dimuat; perbaiki bagian yang keliru lalu kirim ulang.</p><?php } elseif ($draftSavedAt && $_SERVER['REQUEST_METHOD'] !== 'POST') { ?><p class="draft-restored" role="status">Isian Anda yang tersimpan pada <?php echo h(date('d-m-Y H:i', strtotime($draftSavedAt))); ?> sudah dimuat kembali.</p><?php } ?>
                </div>
                <ol class="form-steps" data-step-nav aria-label="Langkah formulir">
                    <?php foreach ($steps as $stepIndex => $step) { ?>
                        <li><button type="button" data-step-target="<?php echo (int) $stepIndex; ?>"<?php echo $stepIndex === $currentStep ? ' aria-current="step"' : ''; ?>><span class="form-steps__number"><?php echo $stepIndex + 1; ?></span><span class="form-steps__label"><?php echo h($step['title']); ?></span></button></li>
                    <?php } ?>
                </ol>
                <form class="registration-form" id="form-utama" action="formulir.php" method="post" enctype="multipart/form-data" autocomplete="on" novalidate data-current-step="<?php echo (int) $currentStep; ?>" data-birth-date="<?php echo h((string) $registration['tgl_lahir']); ?>" data-gender="<?php echo h((string) $registration['jenis_kelamin']); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="kode" value="<?php echo h($registration['id_pendaftaran']); ?>">
                    <input type="hidden" name="token" value="<?php echo h($token); ?>">
                    <input type="hidden" name="langkah" value="<?php echo (int) $currentStep; ?>" data-step-value>
                    <input type="hidden" name="materi_dikonfirmasi" value="<?php echo $showFullForm ? '1' : ''; ?>" data-material-confirm-value>

                    <?php $stepIndex = 0; foreach ($dataSections as $sectionKey => $section) { ?>
                        <section class="form-section" data-step="<?php echo $stepIndex; ?>" aria-labelledby="bagian-<?php echo h($sectionKey); ?>">
                            <div class="section-heading"><span class="section-number"><?php echo sprintf('%02d', $stepIndex + 1); ?></span><div><h2 id="bagian-<?php echo h($sectionKey); ?>"><?php echo h($section['title']); ?></h2><p><?php echo h($section['description']); ?></p></div></div>
                            <div class="field-grid">
                                <?php foreach ($section['fields'] as $field) { if (($field['name'] ?? '') !== 'sumber_informasi') { formulir_render_field($field, $formValues); } } ?>
                            </div>
                            <?php if ($sectionKey === 'alamat') { ?><p class="operator-note">Koordinat lintang dan bujur dicatat operator sekolah melalui peta digital.</p><?php } ?>
                            <?php formulir_step_actions($stepIndex, count($steps)); ?>
                        </section>
                    <?php $stepIndex++; } ?>

                    <section class="form-section" data-step="<?php echo $stepIndex; ?>" aria-labelledby="dokumen-title">
                        <div class="section-heading"><span class="section-number"><?php echo sprintf('%02d', $stepIndex + 1); ?></span><div><h2 id="dokumen-title">Dokumen pendukung</h2><p>Unggah berkas hasil pindai atau foto. Format JPG, PNG, atau PDF · maks. 8 MB per berkas. Berkas langsung tersimpan setelah dipilih.</p></div></div>
                        <div class="document-grid">
                            <?php foreach ($documentSlots as $slot => $meta) {
                                $already = $uploadedDocuments[$slot] ?? null;
                            ?>
                                <div class="document-card<?php echo $already ? ' has-file' : ''; ?>" data-upload-card="<?php echo h($slot); ?>" data-upload-required="<?php echo !empty($meta['required']) ? '1' : '0'; ?>">
                                    <div class="document-card__head">
                                        <strong><?php echo h($meta['label']); ?></strong>
                                        <?php if (!empty($meta['required'])) { ?><span class="required-mark" aria-hidden="true">*</span><?php } else { ?><span class="optional-mark">Opsional</span><?php } ?>
                                    </div>
                                    <p class="document-card__hint"><?php echo h($meta['hint']); ?></p>
                                    <p class="document-card__done" data-upload-saved<?php echo $already ? '' : ' hidden'; ?>><?php if ($already) { ?>Tersimpan · <?php echo h($already['nama_file_asli']); ?> (<?php echo h(number_format((int) $already['ukuran_byte'] / 1048576, 2)); ?> MB)<?php } ?></p>
                                    <label class="file-drop" for="dokumen-<?php echo h($slot); ?>">
                                        <input id="dokumen-<?php echo h($slot); ?>" type="file" name="dokumen__<?php echo h($slot); ?>" accept="image/jpeg,image/png,application/pdf"<?php echo !empty($meta['required']) && !$already ? ' required' : ''; ?>>
                                        <span class="file-drop__label"><?php echo $already ? 'Ganti berkas' : 'Pilih berkas'; ?></span>
                                        <span class="file-drop__name" data-file-name>Belum ada berkas baru dipilih</span>
                                    </label>
                                </div>
                            <?php } ?>
                        </div>
                        <div class="field-grid document-source-field">
                            <?php formulir_render_field($inputFields['sumber_informasi'], $formValues); ?>
                        </div>
                        <div class="form-footer">
                            <label class="consent-check" for="konfirmasi_data">
                                <input type="checkbox" id="konfirmasi_data" name="konfirmasi_data" value="1" required <?php echo (($_POST['konfirmasi_data'] ?? '') === '1') ? 'checked' : ''; ?>>
                                <span>Saya memastikan seluruh data di atas benar dan dapat dipertanggungjawabkan.</span>
                            </label>
                            <p>NIPD dan tanggal masuk sekolah ditetapkan operator setelah peserta diterima. Data bertanda opsional boleh dikosongkan.</p>
                            <button type="submit" name="submit" value="1" class="submit-button" data-final-submit>Kirim formulir lengkap <span aria-hidden="true">&rarr;</span></button>
                        </div>
                        <?php formulir_step_actions($stepIndex, count($steps)); ?>
                    </section>
                </form>
            </div>
        <?php } ?>
    </main>

    <footer class="site-footer">
        <span>Yayasan Wakaf Cendekia Takengon</span>
        <a href="mailto:wakafcendekiatakengon@gmail.com">Hubungi panitia</a>
        <a class="admin-wa-link" href="<?php echo h(ppdb_admin_whatsapp_url('Assalamu’alaikum, saya memerlukan bantuan terkait formulir PPDB.')); ?>" target="_blank" rel="noopener noreferrer">
            <svg viewBox="0 0 32 32" aria-hidden="true" focusable="false"><path d="M16.04 3C8.87 3 3.04 8.77 3.04 15.88c0 2.27.6 4.48 1.75 6.43L3 29l6.9-1.8a13.1 13.1 0 0 0 6.14 1.52h.01c7.17 0 13-5.78 13-12.89 0-3.45-1.36-6.69-3.83-9.12A12.94 12.94 0 0 0 16.04 3Zm0 23.54h-.01c-1.93 0-3.83-.52-5.48-1.51l-.39-.23-4.09 1.07 1.09-3.96-.25-.4a10.62 10.62 0 0 1-1.65-5.63c0-5.92 4.85-10.74 10.79-10.74 2.88 0 5.59 1.12 7.62 3.14a10.61 10.61 0 0 1 3.16 7.59c0 5.92-4.85 10.74-10.79 10.74Zm5.92-8.04c-.32-.16-1.9-.93-2.2-1.04-.3-.11-.51-.16-.73.16-.21.32-.84 1.04-1.03 1.25-.19.21-.38.24-.7.08-.32-.16-1.36-.5-2.59-1.59-.96-.85-1.61-1.9-1.8-2.22-.19-.32-.02-.49.14-.65.14-.14.32-.37.48-.56.16-.19.21-.32.32-.53.11-.21.05-.4-.03-.56-.08-.16-.73-1.74-1-2.38-.26-.62-.53-.54-.73-.55l-.62-.01c-.21 0-.56.08-.86.4-.3.32-1.12 1.09-1.12 2.67s1.15 3.1 1.31 3.31c.16.21 2.26 3.43 5.47 4.81.76.33 1.36.52 1.82.66.76.24 1.45.21 2 .13.61-.09 1.9-.77 2.17-1.52.27-.75.27-1.39.19-1.52-.08-.13-.3-.21-.62-.37Z"/></svg>
            WhatsApp Admin <?php echo h(ppdb_admin_whatsapp_display()); ?>
        </a>
    </footer>
</div>
<script src="js/daftar.js?v=<?php echo (int) filemtime(__DIR__.'/js/daftar.js'); ?>"></script>
<script src="js/formulir.js?v=<?php echo (int) filemtime(__DIR__.'/js/formulir.js'); ?>"></script>
<script src="js/reveal.js?v=<?php echo (int) filemtime(__DIR__.'/js/reveal.js'); ?>"></script>
<script type="module" src="js/materials-pdf.js?v=<?php echo (int) filemtime(__DIR__.'/js/materials-pdf.js'); ?>"></script>
</body>
</html>
