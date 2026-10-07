<?php

function google_sheets_spreadsheet_id(): string
{
    return getenv('PPDB_GOOGLE_SPREADSHEET_ID') ?: '18lv4ky48jGjMKA8UgWaWf0Erm67IBf_o_mwLQqsDgwI';
}

function google_sheets_spreadsheet_url(): string
{
    return 'https://docs.google.com/spreadsheets/d/'.rawurlencode(google_sheets_spreadsheet_id()).'/edit?usp=drive_link';
}

function google_sheets_tab_title(): string
{
    return getenv('PPDB_GOOGLE_SHEET_TAB') ?: 'Data Peserta Didik';
}

function google_sheets_columns(): array
{
    return [
        'Kode pendaftaran' => 'id_pendaftaran',
        'Tanggal pendaftaran' => 'tgl_daftar',
        'Tahun ajaran' => 'th_ajaran',
           'Nomor antrean' => 'nomor_antrian',
           'Status PPDB' => 'status_ppdb',
           'Status verifikasi data' => 'status_data',
           'Status pembayaran' => 'status_pembayaran',
           'Biaya pendaftaran (Rp)' => 'biaya_pendaftaran',
        'Kode unit' => 'kode_unit',
        'Unit pendidikan' => 'nama_unit',
        'NPSN' => 'npsn',
        'Jenis pendaftaran' => 'jenis_pendaftaran',
        'Nama lengkap peserta didik' => 'nm_peserta',
        'NIK peserta didik' => 'nik',
        'Nomor KK' => 'nomor_kk',
        'NISN' => 'NISN',
        'Jenis kelamin' => 'jenis_kelamin',
        'Tempat lahir' => 'tmp_lahir',
        'Tanggal lahir' => 'tgl_lahir',
        'Nomor registrasi akta' => 'nomor_registrasi_akta',
        'Agama/kepercayaan' => 'agama',
        'Kewarganegaraan' => 'kewarganegaraan',
        'Berkebutuhan khusus' => 'kebutuhan_khusus',
        'Pernah PAUD/TK' => 'pernah_paud_tk',
        'Sekolah asal' => 'asal_sekolah',
        'Nomor telepon orang tua/wali' => 'no_hp',
        'Yang mendaftarkan' => 'pendaftar',
        'WhatsApp pendaftar' => 'no_hp_pendaftar',
        'WhatsApp ayah' => 'no_hp_ayah',
        'WhatsApp ibu' => 'no_hp_ibu',
        'Alamat jalan/dusun' => 'alamat_jalan',
        'RT' => 'rt',
        'RW' => 'rw',
        'Desa/kelurahan' => 'desa_kelurahan',
        'Kecamatan' => 'kecamatan',
        'Kabupaten/kota' => 'kabupaten',
        'Provinsi' => 'provinsi',
        'Lintang' => 'latitude',
        'Bujur' => 'longitude',
        'Jenis tinggal' => 'jenis_tinggal',
        'Alat transportasi' => 'alat_transportasi',
        'Nama ayah' => 'ayah_nama',
        'NIK ayah' => 'ayah_nik',
        'Tahun lahir ayah' => 'ayah_tahun_lahir',
        'Pendidikan ayah' => 'ayah_pendidikan',
        'Pekerjaan ayah' => 'ayah_pekerjaan',
        'Penghasilan bulanan ayah' => 'ayah_penghasilan',
        'Nama ibu' => 'ibu_nama',
        'NIK ibu' => 'ibu_nik',
        'Tahun lahir ibu' => 'ibu_tahun_lahir',
        'Pendidikan ibu' => 'ibu_pendidikan',
        'Pekerjaan ibu' => 'ibu_pekerjaan',
        'Penghasilan bulanan ibu' => 'ibu_penghasilan',
        'Nama wali' => 'wali_nama',
        'NIK wali' => 'wali_nik',
        'Tahun lahir wali' => 'wali_tahun_lahir',
        'Pendidikan wali' => 'wali_pendidikan',
        'Pekerjaan wali' => 'wali_pekerjaan',
        'Penghasilan bulanan wali' => 'wali_penghasilan',
        'Tinggi badan (cm)' => 'tinggi_badan_cm',
        'Berat badan (kg)' => 'berat_badan_kg',
        'Lingkar kepala (cm)' => 'lingkar_kepala_cm',
        'Jarak ke sekolah (km)' => 'jarak_ke_sekolah_km',
        'Waktu tempuh (menit)' => 'waktu_tempuh_menit',
        'Jumlah saudara kandung' => 'jumlah_saudara_kandung',
        'NIPD' => 'nipd',
        'Tanggal masuk sekolah' => 'tanggal_masuk_sekolah',
        'Sumber informasi' => 'sumber_informasi',
    ];
}

function google_sheets_quoted_tab(): string
{
    return "'".str_replace("'", "''", google_sheets_tab_title())."'!";
}

function google_sheets_client(): \Google\Client
{
    require_once __DIR__.'/vendor/autoload.php';
    $credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
    if (!$credentialsPath || !is_file($credentialsPath)) {
        throw new RuntimeException('Service account Google belum dikonfigurasi.');
    }

    $realCredentialsPath = realpath($credentialsPath);
    $projectPath = realpath(__DIR__);
    if ($realCredentialsPath && $projectPath && strpos($realCredentialsPath, $projectPath.DIRECTORY_SEPARATOR) === 0) {
        throw new RuntimeException('Simpan kredensial Google di luar direktori web proyek.');
    }

    $client = new \Google\Client();
    $client->setApplicationName('PPDB Yayasan Wakaf Cendekia Takengon');
    $client->setAuthConfig($realCredentialsPath ?: $credentialsPath);
    $client->setScopes([\Google\Service\Sheets::SPREADSHEETS]);
    return $client;
}

function google_sheets_ensure_tab(\Google\Service\Sheets $service): void
{
    $spreadsheetId = google_sheets_spreadsheet_id();
    $spreadsheet = $service->spreadsheets->get($spreadsheetId, ['fields' => 'sheets.properties.title']);
    foreach ($spreadsheet->getSheets() as $sheet) {
        if ($sheet->getProperties()->getTitle() === google_sheets_tab_title()) {
            return;
        }
    }

    $request = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
        'requests' => [['addSheet' => ['properties' => ['title' => google_sheets_tab_title()]]]],
    ]);
    $service->spreadsheets->batchUpdate($spreadsheetId, $request);
}

function google_sheets_registration_values(mysqli $conn, string $registrationId): array
{
    $stmt = mysqli_prepare($conn, 'SELECT p.*, u.nama_unit, u.npsn, c.biaya_pendaftaran, d.* FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit = p.kode_unit LEFT JOIN tb_pengaturan_ppdb c ON c.kode_unit = p.kode_unit AND c.th_ajaran = p.th_ajaran LEFT JOIN tb_detail_dapodik d ON d.id_pendaftaran = p.id_pendaftaran WHERE p.id_pendaftaran = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
    mysqli_stmt_execute($stmt);
    $record = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$record) {
        throw new RuntimeException('Data pendaftaran tidak ditemukan.');
    }

    $values = [];
    foreach (google_sheets_columns() as $column) {
        $values[] = (string) ($record[$column] ?? '');
    }
    return $values;
}

function sync_google_sheets_registration(mysqli $conn, string $registrationId): bool
{
    $attempted = false;
    try {
        $values = google_sheets_registration_values($conn, $registrationId);
        $client = google_sheets_client();
        $service = new \Google\Service\Sheets($client);
        google_sheets_ensure_tab($service);

        $spreadsheetId = google_sheets_spreadsheet_id();
        $tab = google_sheets_quoted_tab();
        $headers = array_keys(google_sheets_columns());
        $headerResponse = $service->spreadsheets_values->get($spreadsheetId, $tab.'A1:ZZ1');
        $existingHeaders = $headerResponse->getValues()[0] ?? [];
        if (!$existingHeaders) {
            $service->spreadsheets_values->update(
                $spreadsheetId,
                $tab.'A1',
                new \Google\Service\Sheets\ValueRange(['values' => [$headers]]),
                ['valueInputOption' => 'RAW']
            );
        } elseif ($existingHeaders !== $headers) {
            throw new RuntimeException('Header pada tab Data Peserta Didik tidak sesuai.');
        }

        $attempted = true;
        $idResponse = $service->spreadsheets_values->get($spreadsheetId, $tab.'A2:A');
        $rowNumber = null;
        foreach ($idResponse->getValues() ?? [] as $index => $row) {
            if (($row[0] ?? '') === $registrationId) {
                $rowNumber = $index + 2;
                break;
            }
        }
        $body = new \Google\Service\Sheets\ValueRange(['values' => [$values]]);
        if ($rowNumber !== null) {
            $service->spreadsheets_values->update($spreadsheetId, $tab.'A'.$rowNumber, $body, ['valueInputOption' => 'RAW']);
        } else {
            $service->spreadsheets_values->append($spreadsheetId, $tab.'A:ZZ', $body, [
                'valueInputOption' => 'RAW',
                'insertDataOption' => 'INSERT_ROWS',
            ]);
        }

        $update = mysqli_prepare($conn, "UPDATE tb_google_sync_outbox SET status = 'synced', attempts = attempts + 1, last_error = NULL, last_attempt_at = NOW(), synced_at = NOW() WHERE id_pendaftaran = ?");
        mysqli_stmt_bind_param($update, 's', $registrationId);
        mysqli_stmt_execute($update);
        mysqli_stmt_close($update);
        return true;
    } catch (Throwable $exception) {
        if ($exception instanceof RuntimeException && strpos($exception->getMessage(), 'Service account') === 0) {
            $safeError = $exception->getMessage();
        } elseif ($exception instanceof \Google\Service\Exception) {
            $reasons = array_column($exception->getErrors(), 'reason');
            if (in_array('accessNotConfigured', $reasons, true)) {
                $safeError = 'Google Sheets API belum aktif pada project ppdb-510016. Aktifkan API lalu coba ulang.';
            } elseif (in_array('forbidden', $reasons, true) || in_array('insufficientPermissions', $reasons, true)) {
                $safeError = 'Service account belum memiliki akses Editor ke spreadsheet.';
            } else {
                $safeError = 'Google Sheets API menolak permintaan (HTTP '.$exception->getCode().'). Periksa API dan izin service account.';
            }
        } else {
            $safeError = 'Google Sheets belum dapat disinkronkan. Periksa konfigurasi lalu coba ulang.';
        }
        $update = mysqli_prepare($conn, "UPDATE tb_google_sync_outbox SET status = 'failed', attempts = attempts + ?, last_error = ?, last_attempt_at = NOW() WHERE id_pendaftaran = ?");
        $attemptIncrement = $attempted ? 1 : 0;
        mysqli_stmt_bind_param($update, 'iss', $attemptIncrement, $safeError, $registrationId);
        mysqli_stmt_execute($update);
        mysqli_stmt_close($update);
        error_log('PPDB Google Sheets sync failed ('.get_class($exception).').');
        return false;
    }
}

function enqueue_google_sheets_registration(mysqli $conn, string $registrationId): void
{
    $stmt = mysqli_prepare($conn, "INSERT INTO tb_google_sync_outbox (id_pendaftaran, status) VALUES (?, 'pending') ON DUPLICATE KEY UPDATE status = 'pending', last_error = NULL");
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}