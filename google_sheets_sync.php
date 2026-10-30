<?php
require_once __DIR__.'/app_helpers.php';
require_once __DIR__.'/formulir_helpers.php';

/*
 * Sinkronisasi data peserta ke Google Sheets: satu file spreadsheet per unit dan
 * tahun ajaran. Setiap sinkronisasi menulis ulang seluruh tab dari database agar
 * kolom, urutan, dan format selalu sama dengan data terbaru. Spreadsheet adalah
 * cerminan database; perubahan manual di dalam tab akan tertimpa.
 */

const GOOGLE_SHEETS_TAB = 'Data Peserta Didik';
const GOOGLE_SHEETS_DOCUMENT_LABELS = [
    'foto' => 'Foto peserta didik',
    'nisn' => 'Foto / scan NISN',
    'kk' => 'Scan Kartu Keluarga',
    'akta_lahir' => 'Scan Akta Kelahiran',
    'ktp_ayah' => 'Scan KTP Ayah',
    'ktp_ibu' => 'Scan KTP Ibu',
];

/** ID spreadsheet unit untuk satu tahun ajaran; kosong bila belum diatur. */
function google_sheets_spreadsheet_id(mysqli $conn, string $unitCode, string $academicYear): string
{
    if (!ppdb_column_exists($conn, 'tb_pengaturan_ppdb', 'google_spreadsheet_id')) {
        return '';
    }
    $stmt = mysqli_prepare($conn, 'SELECT google_spreadsheet_id FROM tb_pengaturan_ppdb WHERE kode_unit=? AND th_ajaran=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ss', $unitCode, $academicYear);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return trim((string) ($row['google_spreadsheet_id'] ?? ''));
}

function google_sheets_spreadsheet_url(string $spreadsheetId): string
{
    return 'https://docs.google.com/spreadsheets/d/'.rawurlencode($spreadsheetId).'/edit';
}

/** Terima ID atau tautan spreadsheet; kembalikan ID, '' bila kosong, null bila tidak valid. */
function google_sheets_parse_spreadsheet_id(string $input): ?string
{
    $input = trim($input);
    if ($input === '') {
        return '';
    }
    if (preg_match('~/spreadsheets/d/([A-Za-z0-9_-]+)~', $input, $match)) {
        $input = $match[1];
    }
    return preg_match('/^[A-Za-z0-9_-]{20,100}$/', $input) ? $input : null;
}

/**
 * Path kredensial dari .env. Bila berkas tidak ada di sana, cari google/<nama berkas> pada
 * folder-folder induk situs, karena root File Manager hosting tidak selalu sama dengan path server.
 */
function google_sheets_credentials_path(): string
{
    $configured = (string) (getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: '');
    if ($configured === '' || is_file($configured)) {
        return $configured;
    }
    $fileName = basename($configured);
    $ancestor = dirname(__DIR__);
    for ($depth = 0; $depth < 6; $depth++) {
        $candidate = $ancestor.DIRECTORY_SEPARATOR.'google'.DIRECTORY_SEPARATOR.$fileName;
        if (is_file($candidate)) {
            return $candidate;
        }
        $parent = dirname($ancestor);
        if ($parent === $ancestor) {
            break;
        }
        $ancestor = $parent;
    }
    return $configured;
}

/** Alasan kredensial belum dapat dipakai, untuk ditampilkan ke super admin; '' bila siap. */
function google_sheets_credentials_problem(): string
{
    $path = google_sheets_credentials_path();
    if ($path === '') {
        return 'GOOGLE_APPLICATION_CREDENTIALS tidak terbaca. Pastikan baris tersebut berada di baris tersendiri pada berkas .env di folder yang sama dengan koneksi.php.';
    }
    if (!is_file($path)) {
        $basedir = (string) ini_get('open_basedir');
        return 'Berkas kredensial tidak ditemukan di '.$path.' maupun di folder google/ pada folder induk situs. Folder situs di server: '.__DIR__.'.'.($basedir !== '' ? ' PHP hanya boleh membaca: '.$basedir.'.' : '');
    }
    if (!is_readable($path)) {
        return 'Berkas kredensial di '.$path.' tidak dapat dibaca PHP. Periksa izin berkas (644).';
    }
    if (google_sheets_service_account_email() === '') {
        return 'Berkas kredensial di '.$path.' bukan JSON service account yang valid. Unduh ulang key JSON dari Google Cloud.';
    }
    return '';
}

/** Email service account dari berkas kredensial, untuk ditampilkan ke admin. */
function google_sheets_service_account_email(): string
{
    $path = google_sheets_credentials_path();
    if ($path === '' || !is_file($path)) {
        return '';
    }
    $credentials = json_decode((string) @file_get_contents($path), true);
    return is_array($credentials) ? (string) ($credentials['client_email'] ?? '') : '';
}

function google_sheets_client(): \Google\Client
{
    require_once __DIR__.'/vendor/autoload.php';
    $credentialsPath = google_sheets_credentials_path();
    $problem = google_sheets_credentials_problem();
    if ($problem !== '') {
        throw new RuntimeException($problem);
    }

    $realCredentialsPath = realpath($credentialsPath);
    $projectPath = realpath(__DIR__);
    if ($realCredentialsPath && $projectPath && strpos($realCredentialsPath, $projectPath.DIRECTORY_SEPARATOR) === 0) {
        throw new RuntimeException('Simpan kredensial Google di luar direktori web proyek.');
    }

    $client = new \Google\Client();
    $client->setApplicationName('SPMB Yayasan Wakaf Cendekia Takengon');
    $client->setAuthConfig($realCredentialsPath ?: $credentialsPath);
    $client->setScopes([\Google\Service\Sheets::SPREADSHEETS]);
    return $client;
}

function google_sheets_date(?string $value, bool $withTime = false): string
{
    if ($value === null || trim($value) === '' || strpos($value, '0000-00-00') === 0) {
        return '';
    }
    $time = strtotime($value);
    return $time ? date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $time) : $value;
}

/** Ambil baris tabel anak untuk semua pendaftar satu unit dan tahun ajaran sekaligus. */
function google_sheets_child_rows(mysqli $conn, string $sql, string $unitCode, string $academicYear): array
{
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $unitCode, $academicYear);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * Susun isi tab: kelompok kolom (baris 1), judul kolom (baris 2), dan baris data.
 * Kolom formulir mengikuti jenjang unit, kolom kuisioner mengikuti kuisioner unit.
 */
function google_sheets_build_table(mysqli $conn, string $unitCode, string $academicYear): array
{
    $unitStmt = mysqli_prepare($conn, 'SELECT jenjang FROM tb_unit_pendidikan WHERE kode_unit=? LIMIT 1');
    mysqli_stmt_bind_param($unitStmt, 's', $unitCode);
    mysqli_stmt_execute($unitStmt);
    $jenjang = (string) (mysqli_fetch_assoc(mysqli_stmt_get_result($unitStmt))['jenjang'] ?? '');
    mysqli_stmt_close($unitStmt);

    $filter = ' JOIN tb_pendaftaran p ON p.id_pendaftaran=x.id_pendaftaran WHERE p.kode_unit=? AND p.th_ajaran=?';
    $registrations = google_sheets_child_rows($conn, 'SELECT p.*, c.biaya_pendaftaran FROM tb_pendaftaran p LEFT JOIN tb_pengaturan_ppdb c ON c.kode_unit=p.kode_unit AND c.th_ajaran=p.th_ajaran WHERE p.kode_unit=? AND p.th_ajaran=? ORDER BY p.tgl_daftar, p.id_pendaftaran', $unitCode, $academicYear);

    $details = [];
    foreach (google_sheets_child_rows($conn, 'SELECT x.* FROM tb_detail_dapodik x'.$filter, $unitCode, $academicYear) as $row) {
        $details[$row['id_pendaftaran']] = $row;
    }
    $parents = [];
    foreach (google_sheets_child_rows($conn, 'SELECT x.* FROM tb_orang_tua_peserta x'.$filter, $unitCode, $academicYear) as $row) {
        $parents[$row['id_pendaftaran']][$row['peran']] = $row;
    }
    $documents = [];
    foreach (google_sheets_child_rows($conn, 'SELECT x.id_pendaftaran,x.jenis_dokumen,x.diunggah_pada FROM tb_dokumen_peserta x'.$filter, $unitCode, $academicYear) as $row) {
        $documents[$row['id_pendaftaran']][$row['jenis_dokumen']] = $row['diunggah_pada'];
    }
    $payments = [];
    foreach (google_sheets_child_rows($conn, 'SELECT x.* FROM tb_bukti_pembayaran x'.$filter.' ORDER BY x.id', $unitCode, $academicYear) as $row) {
        $payments[$row['id_pendaftaran']] = $row;
    }
    $revisions = [];
    foreach (google_sheets_child_rows($conn, 'SELECT x.id_pendaftaran, COUNT(*) AS total, MAX(x.dikirim_pada) AS terakhir FROM tb_formulir_revisi x'.$filter.' GROUP BY x.id_pendaftaran', $unitCode, $academicYear) as $row) {
        $revisions[$row['id_pendaftaran']] = $row;
    }

    // Pertanyaan kuisioner: daftar terbaru unit, ditambah pertanyaan lama yang sudah dijawab.
    $questions = [];
    $answers = [];
    if (ppdb_table_exists($conn, 'tb_ppdb_questionnaires')) {
        $stmt = mysqli_prepare($conn, 'SELECT questions_json FROM tb_ppdb_questionnaires WHERE kode_unit=? AND th_ajaran=? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'ss', $unitCode, $academicYear);
        mysqli_stmt_execute($stmt);
        $definition = json_decode((string) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['questions_json'] ?? ''), true);
        mysqli_stmt_close($stmt);
        foreach (is_array($definition) ? $definition : [] as $question) {
            if (isset($question['key'])) {
                $questions[(string) $question['key']] = (string) ($question['prompt'] ?? $question['key']);
            }
        }
        foreach (google_sheets_child_rows($conn, 'SELECT x.id_pendaftaran,x.jawaban_json,x.submitted_at FROM tb_ppdb_questionnaire_answers x'.$filter, $unitCode, $academicYear) as $row) {
            $decoded = json_decode($row['jawaban_json'], true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded['questions'] ?? [] as $question) {
                $key = (string) ($question['key'] ?? '');
                if ($key !== '' && !isset($questions[$key])) {
                    $questions[$key] = (string) ($question['prompt'] ?? $key);
                }
            }
            $answers[$row['id_pendaftaran']] = ['answers' => $decoded['answers'] ?? [], 'submitted_at' => $row['submitted_at']];
        }
    }

    // Kolom: [kelompok, judul, kunci nilai, lebar piksel].
    $columns = [
        ['Identitas', 'No', '_no', 44],
        ['Identitas', 'Kode pendaftaran', 'id_pendaftaran', 120],
        ['Identitas', 'Nama lengkap peserta didik', 'nm_peserta', 230],
        ['Status pendaftaran', 'Tanggal daftar', '_tgl_daftar', 110],
        ['Status pendaftaran', 'Tahun ajaran', 'th_ajaran', 100],
        ['Status pendaftaran', 'Jalur', '_jalur', 120],
    ];
    if ($jenjang === 'TK') {
        $columns[] = ['Status pendaftaran', 'Kelompok TK', '_kelompok_tk', 100];
    }
    array_push($columns,
        ['Status pendaftaran', 'Nomor antrean', 'nomor_antrian', 90],
        ['Status pendaftaran', 'Status SPMB', '_status_ppdb', 220],
        ['Status pendaftaran', 'Status verifikasi data', '_status_data', 140],
        ['Status pendaftaran', 'Status pembayaran', '_status_pembayaran', 140],
        ['Status pendaftaran', 'Batas pembayaran', '_batas_bayar', 110],
        ['Registrasi awal', 'Jenis kelamin', '_jenis_kelamin', 110],
        ['Registrasi awal', 'Tanggal lahir', '_tgl_lahir', 110],
        ['Registrasi awal', 'Yang mendaftarkan', '_pendaftar', 130],
        ['Registrasi awal', 'WhatsApp pendaftar', 'no_hp_pendaftar', 140],
        ['Registrasi awal', 'WhatsApp ayah', 'no_hp_ayah', 140],
        ['Registrasi awal', 'WhatsApp ibu', 'no_hp_ibu', 140],
        ['Registrasi awal', 'Alamat saat registrasi', 'alamat', 260]
    );
    foreach (formulir_sections_for(null, $jenjang) as $sectionKey => $section) {
        foreach ($section['fields'] as $field) {
            if (($field['type'] ?? 'text') === 'heading' || $field['name'] === 'kelompok_tk') {
                continue;
            }
            $wide = !empty($field['wide']) || ($field['type'] ?? '') === 'textarea';
            $columns[] = [$section['title'], $field['label'], $field['name'], $wide ? 240 : 160];
        }
        if ($sectionKey === 'alamat') {
            $columns[] = [$section['title'], 'Lintang', 'latitude', 110];
            $columns[] = [$section['title'], 'Bujur', 'longitude', 110];
        }
    }
    array_push($columns,
        ['Administrasi sekolah', 'NIPD', 'nipd', 110],
        ['Administrasi sekolah', 'Tanggal masuk sekolah', '_tanggal_masuk', 120]
    );
    foreach (GOOGLE_SHEETS_DOCUMENT_LABELS as $type => $label) {
        $columns[] = ['Dokumen', $label, '_doc_'.$type, 150];
    }
    array_push($columns,
        ['Pembayaran', 'Biaya pendaftaran (Rp)', '_biaya', 120],
        ['Pembayaran', 'Nomor referensi bukti', '_pay_ref', 150],
        ['Pembayaran', 'Status bukti pembayaran', '_pay_status', 150],
        ['Pembayaran', 'Bukti diunggah', '_pay_uploaded', 130],
        ['Pembayaran', 'Bukti diperiksa', '_pay_checked', 130],
        ['Pembayaran', 'Catatan admin', '_pay_note', 220]
    );
    foreach ($questions as $key => $prompt) {
        $columns[] = ['Kuisioner', $prompt, '_q_'.$key, 220];
    }
    if ($questions) {
        $columns[] = ['Kuisioner', 'Kuisioner dikirim', '_q_submitted', 130];
    }
    array_push($columns,
        ['Riwayat revisi', 'Jumlah revisi formulir', '_rev_count', 110],
        ['Riwayat revisi', 'Revisi terakhir', '_rev_last', 130]
    );

    $dataLabels = ['belum_diperiksa' => 'Belum diperiksa', 'terverifikasi' => 'Terverifikasi', 'perlu_perbaikan' => 'Perlu perbaikan'];
    $paymentLabels = ['belum_bayar' => 'Belum bayar', 'menunggu_verifikasi' => 'Menunggu verifikasi', 'terverifikasi' => 'Terverifikasi', 'ditolak' => 'Ditolak'];
    $proofLabels = ['menunggu_verifikasi' => 'Menunggu verifikasi', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];

    $rows = [];
    foreach ($registrations as $index => $registration) {
        $id = $registration['id_pendaftaran'];
        // Kolom di tb_pendaftaran diutamakan; sisanya dari detail Dapodik dan profil orang tua.
        $record = $registration;
        foreach ($details[$id] ?? [] as $key => $value) {
            if (!array_key_exists($key, $record) || $record[$key] === null || $record[$key] === '') {
                $record[$key] = $value;
            }
        }
        $record += formulir_flatten_parent_profiles($parents[$id] ?? []);

        $payment = $payments[$id] ?? null;
        $record['_no'] = (string) ($index + 1);
        $record['_tgl_daftar'] = google_sheets_date($registration['tgl_daftar']);
        $record['_jalur'] = $registration['jalur_pendaftaran'] === 'internal' ? 'Internal SDM' : 'Eksternal umum';
        $record['_kelompok_tk'] = ($registration['tingkat_kelas'] ?? '') ?: ($record['kelompok_tk'] ?? '');
        $record['_status_ppdb'] = ppdb_status_info($registration['status_ppdb'])['label'];
        $record['_status_data'] = $dataLabels[$registration['status_data']] ?? (string) $registration['status_data'];
        $record['_status_pembayaran'] = $paymentLabels[$registration['status_pembayaran']] ?? (string) $registration['status_pembayaran'];
        $record['_batas_bayar'] = google_sheets_date($registration['tanggal_batas_bayar'] ?? null);
        $record['_jenis_kelamin'] = ppdb_jenis_kelamin_label($registration['jenis_kelamin']);
        $record['_tgl_lahir'] = google_sheets_date($registration['tgl_lahir']);
        $record['_pendaftar'] = ppdb_pendaftar_label($registration['pendaftar']);
        $record['_tanggal_masuk'] = google_sheets_date($record['tanggal_masuk_sekolah'] ?? null);
        foreach (array_keys(GOOGLE_SHEETS_DOCUMENT_LABELS) as $type) {
            $uploaded = $documents[$id][$type] ?? null;
            $record['_doc_'.$type] = $uploaded ? 'Ada · '.google_sheets_date($uploaded) : 'Belum';
        }
        $record['_biaya'] = $registration['biaya_pendaftaran'] === null ? '' : number_format((float) $registration['biaya_pendaftaran'], 0, ',', '.');
        $record['_pay_ref'] =$payment['nomor_referensi'] ?? '';
        $record['_pay_status'] = $payment ? ($proofLabels[$payment['status_verifikasi']] ?? $payment['status_verifikasi']) : 'Belum diunggah';
        $record['_pay_uploaded'] = google_sheets_date($payment['diunggah_pada'] ?? null, true);
        $record['_pay_checked'] = google_sheets_date($payment['diperiksa_pada'] ?? null, true);
        $record['_pay_note'] = $payment['catatan_admin'] ?? '';
        foreach (array_keys($questions) as $key) {
            $answer = $answers[$id]['answers'][$key] ?? '';
            $record['_q_'.$key] = is_array($answer) ? implode(', ', array_map('strval', $answer)) : (string) $answer;
        }
        $record['_q_submitted'] = google_sheets_date($answers[$id]['submitted_at'] ?? null, true);
        $record['_rev_count'] = (string) ($revisions[$id]['total'] ?? '0');
        $record['_rev_last'] = google_sheets_date($revisions[$id]['terakhir'] ?? null, true);

        $values = [];
        foreach ($columns as [, , $key]) {
            $values[] = (string) ($record[$key] ?? '');
        }
        $rows[] = $values;
    }

    return ['columns' => $columns, 'rows' => $rows];
}

function google_sheets_color(string $hex): array
{
    return [
        'red' => hexdec(substr($hex, 1, 2)) / 255,
        'green' => hexdec(substr($hex, 3, 2)) / 255,
        'blue' => hexdec(substr($hex, 5, 2)) / 255,
    ];
}

/** Tulis ulang tab unit dari database lalu rapikan format, kelompok kolom, filter, dan freeze. */
function google_sheets_rebuild_unit(mysqli $conn, string $unitCode, string $academicYear): int
{
    $spreadsheetId = google_sheets_spreadsheet_id($conn, $unitCode, $academicYear);
    if ($spreadsheetId === '') {
        throw new RuntimeException('Spreadsheet unit '.$unitCode.' tahun '.$academicYear.' belum diatur di menu Kuota & pembayaran.');
    }
    $service = new \Google\Service\Sheets(google_sheets_client());

    // Kunci per unit agar dua sinkronisasi bersamaan tidak saling menimpa dengan data lama.
    $lockName = 'ppdb_sheet_'.$unitCode.'_'.$academicYear;
    $lockStmt = mysqli_prepare($conn, 'SELECT GET_LOCK(?, 30) AS locked');
    mysqli_stmt_bind_param($lockStmt, 's', $lockName);
    mysqli_stmt_execute($lockStmt);
    $locked = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($lockStmt))['locked'] ?? 0) === 1;
    mysqli_stmt_close($lockStmt);
    if (!$locked) {
        throw new RuntimeException('Sinkronisasi unit lain sedang berjalan. Coba ulang sebentar lagi.');
    }

    try {
        $table = google_sheets_build_table($conn, $unitCode, $academicYear);
        $columns = $table['columns'];
        $columnCount = count($columns);
        $rowCount = count($table['rows']);

        $spreadsheet = $service->spreadsheets->get($spreadsheetId, ['fields' => 'sheets(properties(sheetId,title,gridProperties),bandedRanges(bandedRangeId))']);
        $sheets = $spreadsheet->getSheets();
        $target = null;
        foreach ($sheets as $sheet) {
            if ($sheet->getProperties()->getTitle() === GOOGLE_SHEETS_TAB) {
                $target = $sheet;
                break;
            }
        }
        $prepare = [];
        if ($target === null && count($sheets) === 1) {
            // Spreadsheet baru: ganti nama lembar bawaan alih-alih menambah tab kosong.
            $target = $sheets[0];
            $prepare[] = ['updateSheetProperties' => [
                'properties' => ['sheetId' => $target->getProperties()->getSheetId(), 'title' => GOOGLE_SHEETS_TAB],
                'fields' => 'title',
            ]];
        }
        if ($target === null) {
            $response = $service->spreadsheets->batchUpdate($spreadsheetId, new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest([
                'requests' => [['addSheet' => ['properties' => ['title' => GOOGLE_SHEETS_TAB]]]],
            ]));
            $sheetId = $response->getReplies()[0]->getAddSheet()->getProperties()->getSheetId();
            $grid = ['rowCount' => 1000, 'columnCount' => 26];
            $bandings = [];
        } else {
            $sheetId = $target->getProperties()->getSheetId();
            $gridProperties = $target->getProperties()->getGridProperties();
            $grid = ['rowCount' => (int) $gridProperties->getRowCount(), 'columnCount' => (int) $gridProperties->getColumnCount()];
            $bandings = array_map(static fn ($band) => $band->getBandedRangeId(), $target->getBandedRanges() ?? []);
        }

        $gridRows = max($grid['rowCount'], $rowCount + 50);
        $gridColumns = max($grid['columnCount'], $columnCount);
        $prepare[] = ['unmergeCells' => ['range' => ['sheetId' => $sheetId]]];
        foreach ($bandings as $bandingId) {
            $prepare[] = ['deleteBanding' => ['bandedRangeId' => $bandingId]];
        }
        $prepare[] = ['updateSheetProperties' => [
            'properties' => ['sheetId' => $sheetId, 'gridProperties' => ['rowCount' => $gridRows, 'columnCount' => $gridColumns, 'frozenRowCount' => 0, 'frozenColumnCount' => 0]],
            'fields' => 'gridProperties(rowCount,columnCount,frozenRowCount,frozenColumnCount)',
        ]];
        $prepare[] = ['repeatCell' => ['range' => ['sheetId' => $sheetId], 'cell' => ['userEnteredFormat' => new stdClass()], 'fields' => 'userEnteredFormat']];
        $service->spreadsheets->batchUpdate($spreadsheetId, new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest(['requests' => $prepare]));

        // Baris 1 kelompok kolom, baris 2 judul kolom, data mulai baris 3. RAW menjaga NIK/NISN tetap teks.
        $tab = "'".str_replace("'", "''", GOOGLE_SHEETS_TAB)."'";
        $groupRow = [];
        $previousGroup = null;
        foreach ($columns as [$group]) {
            $groupRow[] = $group === $previousGroup ? '' : $group;
            $previousGroup = $group;
        }
        $service->spreadsheets_values->clear($spreadsheetId, $tab, new \Google\Service\Sheets\ClearValuesRequest());
        $service->spreadsheets_values->update(
            $spreadsheetId,
            $tab.'!A1',
            new \Google\Service\Sheets\ValueRange(['values' => array_merge([$groupRow, array_column($columns, 1)], $table['rows'])]),
            ['valueInputOption' => 'RAW']
        );

        $white = google_sheets_color('#ffffff');
        $format = [
            ['updateSheetProperties' => [
                'properties' => ['sheetId' => $sheetId, 'gridProperties' => ['frozenRowCount' => 2, 'frozenColumnCount' => 3]],
                'fields' => 'gridProperties(frozenRowCount,frozenColumnCount)',
            ]],
            ['repeatCell' => [
                'range' => ['sheetId' => $sheetId, 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => $columnCount],
                'cell' => ['userEnteredFormat' => [
                    'backgroundColor' => google_sheets_color('#183a2e'),
                    'horizontalAlignment' => 'CENTER',
                    'verticalAlignment' => 'MIDDLE',
                    'textFormat' => ['bold' => true, 'fontSize' => 11, 'foregroundColor' => $white],
                ]],
                'fields' => 'userEnteredFormat(backgroundColor,horizontalAlignment,verticalAlignment,textFormat)',
            ]],
            ['repeatCell' => [
                'range' => ['sheetId' => $sheetId, 'startRowIndex' => 1, 'endRowIndex' => 2, 'startColumnIndex' => 0, 'endColumnIndex' => $columnCount],
                'cell' => ['userEnteredFormat' => [
                    'backgroundColor' => google_sheets_color('#2a8463'),
                    'horizontalAlignment' => 'CENTER',
                    'verticalAlignment' => 'MIDDLE',
                    'wrapStrategy' => 'WRAP',
                    'textFormat' => ['bold' => true, 'foregroundColor' => $white],
                ]],
                'fields' => 'userEnteredFormat(backgroundColor,horizontalAlignment,verticalAlignment,wrapStrategy,textFormat)',
            ]],
            ['repeatCell' => [
                'range' => ['sheetId' => $sheetId, 'startRowIndex' => 2, 'endRowIndex' => $rowCount + 2, 'startColumnIndex' => 0, 'endColumnIndex' => $columnCount],
                'cell' => ['userEnteredFormat' => ['verticalAlignment' => 'TOP', 'wrapStrategy' => 'CLIP', 'numberFormat' => ['type' => 'TEXT']]],
                'fields' => 'userEnteredFormat(verticalAlignment,wrapStrategy,numberFormat)',
            ]],
            ['updateDimensionProperties' => [
                'range' => ['sheetId' => $sheetId, 'dimension' => 'ROWS', 'startIndex' => 0, 'endIndex' => 1],
                'properties' => ['pixelSize' => 30],
                'fields' => 'pixelSize',
            ]],
            ['updateDimensionProperties' => [
                'range' => ['sheetId' => $sheetId, 'dimension' => 'ROWS', 'startIndex' => 1, 'endIndex' => 2],
                'properties' => ['pixelSize' => 52],
                'fields' => 'pixelSize',
            ]],
            ['updateBorders' => [
                'range' => ['sheetId' => $sheetId, 'startRowIndex' => 0, 'endRowIndex' => $rowCount + 2, 'startColumnIndex' => 0, 'endColumnIndex' => $columnCount],
                'innerVertical' => ['style' => 'SOLID', 'color' => google_sheets_color('#d5e3dc')],
                'innerHorizontal' => ['style' => 'SOLID', 'color' => google_sheets_color('#e3ece7')],
                'bottom' => ['style' => 'SOLID', 'color' => google_sheets_color('#d5e3dc')],
                'right' => ['style' => 'SOLID', 'color' => google_sheets_color('#d5e3dc')],
            ]],
            ['setBasicFilter' => ['filter' => ['range' => [
                'sheetId' => $sheetId, 'startRowIndex' => 1, 'endRowIndex' => $rowCount + 2, 'startColumnIndex' => 0, 'endColumnIndex' => $columnCount,
            ]]]],
        ];
        if ($rowCount > 0) {
            $format[] = ['addBanding' => ['bandedRange' => [
                'range' => ['sheetId' => $sheetId, 'startRowIndex' => 2, 'endRowIndex' => $rowCount + 2, 'startColumnIndex' => 0, 'endColumnIndex' => $columnCount],
                'rowProperties' => ['firstBandColor' => $white, 'secondBandColor' => google_sheets_color('#f1f7f4')],
            ]]];
        }
        $groupStart = 0;
        foreach ($columns as $index => [$group, , , $width]) {
            $format[] = ['updateDimensionProperties' => [
                'range' => ['sheetId' => $sheetId, 'dimension' => 'COLUMNS', 'startIndex' => $index, 'endIndex' => $index + 1],
                'properties' => ['pixelSize' => $width],
                'fields' => 'pixelSize',
            ]];
            $isLast = $index === $columnCount - 1 || $columns[$index + 1][0] !== $group;
            if ($isLast) {
                if ($index > $groupStart) {
                    $format[] = ['mergeCells' => ['mergeType' => 'MERGE_ALL', 'range' => [
                        'sheetId' => $sheetId, 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => $groupStart, 'endColumnIndex' => $index + 1,
                    ]]];
                }
                // Garis pemisah tebal di antara kelompok kolom.
                $format[] = ['updateBorders' => [
                    'range' => ['sheetId' => $sheetId, 'startRowIndex' => 0, 'endRowIndex' => $rowCount + 2, 'startColumnIndex' => $index, 'endColumnIndex' => $index + 1],
                    'right' => ['style' => 'SOLID_MEDIUM', 'color' => google_sheets_color('#8cc4ad')],
                ]];
                $groupStart = $index + 1;
            }
        }
        $service->spreadsheets->batchUpdate($spreadsheetId, new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest(['requests' => $format]));
        return $rowCount;
    } finally {
        $releaseStmt = mysqli_prepare($conn, 'SELECT RELEASE_LOCK(?)');
        mysqli_stmt_bind_param($releaseStmt, 's', $lockName);
        mysqli_stmt_execute($releaseStmt);
        mysqli_stmt_close($releaseStmt);
    }
}

/** Pesan galat yang aman ditampilkan ke admin. */
function google_sheets_error_message(Throwable $exception): string
{
    if ($exception instanceof \Google\Service\Exception) {
        $reasons = array_column($exception->getErrors() ?: [], 'reason');
        if (in_array('accessNotConfigured', $reasons, true) || in_array('SERVICE_DISABLED', $reasons, true)) {
            return 'Google Sheets API belum aktif pada project Google Cloud. Aktifkan API lalu coba ulang.';
        }
        if ($exception->getCode() === 404) {
            return 'Spreadsheet unit tidak ditemukan. Periksa ID spreadsheet di menu Kuota & pembayaran.';
        }
        if ($exception->getCode() === 403) {
            return 'Service account belum memiliki akses Editor ke spreadsheet unit.';
        }
        return 'Google Sheets API menolak permintaan (HTTP '.$exception->getCode().'). Periksa API dan izin service account.';
    }
    if ($exception instanceof RuntimeException) {
        return mb_substr($exception->getMessage(), 0, 255);
    }
    return 'Google Sheets belum dapat disinkronkan. Periksa konfigurasi lalu coba ulang.';
}

/** Tandai antrean semua pendaftar satu unit dan tahun ajaran setelah tab ditulis ulang. */
function google_sheets_mark_unit(mysqli $conn, string $unitCode, string $academicYear, ?string $error, ?string $onlyRegistrationId = null): void
{
    $status = $error === null ? 'synced' : 'failed';
    $sql = 'UPDATE tb_google_sync_outbox o JOIN tb_pendaftaran p ON p.id_pendaftaran=o.id_pendaftaran SET o.status=?, o.attempts=o.attempts+1, o.last_error=?, o.last_attempt_at=NOW()'
        .($error === null ? ', o.synced_at=NOW()' : '').' WHERE p.kode_unit=? AND p.th_ajaran=?';
    if ($onlyRegistrationId !== null) {
        $sql .= ' AND o.id_pendaftaran=?';
    } elseif ($error !== null) {
        $sql .= " AND o.status IN ('pending','failed')";
    }
    $stmt = mysqli_prepare($conn, $sql);
    if ($onlyRegistrationId !== null) {
        mysqli_stmt_bind_param($stmt, 'sssss', $status, $error, $unitCode, $academicYear, $onlyRegistrationId);
    } else {
        mysqli_stmt_bind_param($stmt, 'ssss', $status, $error, $unitCode, $academicYear);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/** Sinkronkan spreadsheet unit milik satu pendaftar; dipanggil setelah data pendaftar berubah. */
function sync_google_sheets_registration(mysqli $conn, string $registrationId): bool
{
    $stmt = mysqli_prepare($conn, 'SELECT kode_unit,th_ajaran FROM tb_pendaftaran WHERE id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
    mysqli_stmt_execute($stmt);
    $registration = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$registration) {
        return false;
    }
    try {
        google_sheets_rebuild_unit($conn, $registration['kode_unit'], $registration['th_ajaran']);
        google_sheets_mark_unit($conn, $registration['kode_unit'], $registration['th_ajaran'], null);
        return true;
    } catch (Throwable $exception) {
        google_sheets_mark_unit($conn, $registration['kode_unit'], $registration['th_ajaran'], google_sheets_error_message($exception), $registrationId);
        error_log('SPMB Google Sheets sync failed ('.get_class($exception).'): '.$exception->getMessage());
        return false;
    }
}

/**
 * Sinkronkan unit yang punya antrean, atau semua unit yang spreadsheet-nya sudah diatur
 * bila $all. Mengembalikan jumlah unit berhasil dan gagal.
 */
function google_sheets_sync_units(mysqli $conn, bool $all = false): array
{
    if ($all) {
        $sql = 'SELECT DISTINCT p.kode_unit,p.th_ajaran FROM tb_pendaftaran p';
        if (ppdb_column_exists($conn, 'tb_pengaturan_ppdb', 'google_spreadsheet_id')) {
            $sql = "SELECT c.kode_unit,c.th_ajaran FROM tb_pengaturan_ppdb c WHERE c.google_spreadsheet_id<>''";
        }
    } else {
        $sql = "SELECT DISTINCT p.kode_unit,p.th_ajaran FROM tb_google_sync_outbox o JOIN tb_pendaftaran p ON p.id_pendaftaran=o.id_pendaftaran WHERE o.status IN ('pending','failed')";
    }
    $result = ['synced' => 0, 'failed' => 0];
    foreach (mysqli_fetch_all(mysqli_query($conn, $sql), MYSQLI_ASSOC) as $unit) {
        try {
            google_sheets_rebuild_unit($conn, $unit['kode_unit'], $unit['th_ajaran']);
            google_sheets_mark_unit($conn, $unit['kode_unit'], $unit['th_ajaran'], null);
            $result['synced']++;
        } catch (Throwable $exception) {
            google_sheets_mark_unit($conn, $unit['kode_unit'], $unit['th_ajaran'], google_sheets_error_message($exception));
            error_log('SPMB Google Sheets sync failed ('.get_class($exception).'): '.$exception->getMessage());
            $result['failed']++;
        }
    }
    return $result;
}

function enqueue_google_sheets_registration(mysqli $conn, string $registrationId): void
{
    $stmt = mysqli_prepare($conn, "INSERT INTO tb_google_sync_outbox (id_pendaftaran, status) VALUES (?, 'pending') ON DUPLICATE KEY UPDATE status = 'pending', last_error = NULL");
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
