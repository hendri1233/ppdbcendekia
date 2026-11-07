<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
require_super_admin();

$error = '';
$notice = '';
$internalEntryUrl = ppdb_public_base_url().'/'.PPDB_INTERNAL_SHORT_LINK;
$academicYears = [];
for ($year = 2027; $year <= 2040; $year++) {
    $academicYears[] = $year.'/'.($year + 1);
}

$adminUnitCode = ppdb_is_super_admin() ? '' : (string) $_SESSION['admin_unit_code'];
$unitCondition = $adminUnitCode === '' ? '' : " WHERE kode_unit='".mysqli_real_escape_string($conn, $adminUnitCode)."'";
$settingsUnitCondition = $adminUnitCode === '' ? '' : " WHERE c.kode_unit='".mysqli_real_escape_string($conn, $adminUnitCode)."'";
$unitResult = mysqli_query($conn, "SELECT kode_unit,nama_unit,jenjang FROM tb_unit_pendidikan".$unitCondition." ORDER BY FIELD(jenjang,'KB','TPA','TK','SD','SMP')");
$units = [];
while ($unit = mysqli_fetch_assoc($unitResult)) {
    $units[$unit['kode_unit']] = $unit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_quota';
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi formulir kedaluwarsa. Muat ulang halaman dan coba kembali.';
    } elseif ($action === 'generate_internal_tokens') {
        $unitCode = is_string($_POST['token_unit'] ?? null) ? $_POST['token_unit'] : '';
        $academicYear = is_string($_POST['token_year'] ?? null) ? $_POST['token_year'] : '';
        if (!isset($units[$unitCode]) || !in_array($academicYear, $academicYears, true)) {
            $error = 'Pilih unit pendidikan dan tahun ajaran yang valid.';
        } else {
            $tokenTransactionStarted = false;
            try {
                mysqli_begin_transaction($conn);
                $tokenTransactionStarted = true;
                $settingQuery = mysqli_prepare($conn, 'SELECT kode_unit FROM tb_pengaturan_ppdb WHERE kode_unit=? AND th_ajaran=? FOR UPDATE');
                mysqli_stmt_bind_param($settingQuery, 'ss', $unitCode, $academicYear);
                mysqli_stmt_execute($settingQuery);
                $tokenSetting = mysqli_fetch_assoc(mysqli_stmt_get_result($settingQuery));
                mysqli_stmt_close($settingQuery);
                if (!$tokenSetting) {
                    throw new RuntimeException('Simpan konfigurasi unit dan tahun ajaran sebelum membuat token.');
                }
                $countTokens = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM tb_token_internal WHERE kode_unit=? AND th_ajaran=?');
                mysqli_stmt_bind_param($countTokens, 'ss', $unitCode, $academicYear);
                mysqli_stmt_execute($countTokens);
                $existingTokens = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countTokens))['total'];
                mysqli_stmt_close($countTokens);
                if ($existingTokens >= 150) {
                    throw new RuntimeException('Pool unit/tahun ini sudah memiliki 150 token.');
                }

                $insertToken = mysqli_prepare($conn, 'INSERT IGNORE INTO tb_token_internal (kode_token,kode_unit,th_ajaran) VALUES (?,?,?)');
                $created = 0;
                for ($index = $existingTokens; $index < 150; $index++) {
                    $attempt = 0;
                    do {
                        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
                        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
                        $digits = '0123456789';
                        $allCharacters = $uppercase.$lowercase.$digits;
                        $characters = [
                            $uppercase[random_int(0, strlen($uppercase) - 1)],
                            $lowercase[random_int(0, strlen($lowercase) - 1)],
                            $digits[random_int(0, strlen($digits) - 1)],
                        ];
                        for ($characterIndex = 3; $characterIndex < 9; $characterIndex++) {
                            $characters[] = $allCharacters[random_int(0, strlen($allCharacters) - 1)];
                        }
                        for ($characterIndex = count($characters) - 1; $characterIndex > 0; $characterIndex--) {
                            $swapIndex = random_int(0, $characterIndex);
                            [$characters[$characterIndex], $characters[$swapIndex]] = [$characters[$swapIndex], $characters[$characterIndex]];
                        }
                        $code = implode('', $characters);
                        mysqli_stmt_bind_param($insertToken, 'sss', $code, $unitCode, $academicYear);
                        mysqli_stmt_execute($insertToken);
                        $inserted = mysqli_stmt_affected_rows($insertToken) === 1;
                        $attempt++;
                    } while (!$inserted && $attempt < 5);
                    if (!$inserted) {
                        throw new RuntimeException('Token belum dapat dibuat; silakan coba kembali.');
                    }
                    $created++;
                }
                mysqli_stmt_close($insertToken);
                mysqli_commit($conn);
                $tokenTransactionStarted = false;
                $notice = $created.' token berhasil dibuat. Kode tersedia pada tabel token internal di bawah.';
            } catch (Throwable $exception) {
                if ($tokenTransactionStarted) {
                    mysqli_rollback($conn);
                }
                if ($exception instanceof RuntimeException) {
                    $error = $exception->getMessage();
                } else {
                    error_log('SPMB internal token generation failed: '.$exception->getMessage());
                    $error = 'Token internal belum dapat dibuat.';
                }
            }
        }
    } elseif ($action === 'revoke_internal_token') {
        $tokenId = filter_var($_POST['token_id'] ?? null, FILTER_VALIDATE_INT);
        if ($tokenId === false || $tokenId < 1) {
            $error = 'Token yang dipilih tidak valid.';
        } else {
            if ($adminUnitCode === '') {
                $revoke = mysqli_prepare($conn, 'UPDATE tb_token_internal SET revoked_at=NOW() WHERE id=? AND used_at IS NULL AND revoked_at IS NULL');
                mysqli_stmt_bind_param($revoke, 'i', $tokenId);
            } else {
                $revoke = mysqli_prepare($conn, 'UPDATE tb_token_internal SET revoked_at=NOW() WHERE id=? AND kode_unit=? AND used_at IS NULL AND revoked_at IS NULL');
                mysqli_stmt_bind_param($revoke, 'is', $tokenId, $adminUnitCode);
            }
            if (mysqli_stmt_execute($revoke) && mysqli_stmt_affected_rows($revoke) === 1) {
                $notice = 'Token internal berhasil dicabut.';
            } else {
                $error = 'Token tidak ditemukan, sudah digunakan, atau sebelumnya dicabut.';
            }
            mysqli_stmt_close($revoke);
        }
    } elseif ($action === 'reactivate_internal_token') {
        $tokenId = filter_var($_POST['token_id'] ?? null, FILTER_VALIDATE_INT);
        if ($tokenId === false || $tokenId < 1 || ($_POST['admin_approval'] ?? '') !== '1') {
            $error = 'Pilih token yang valid dan konfirmasikan persetujuan untuk mengaktifkannya kembali.';
        } else {
            if ($adminUnitCode === '') {
                $reactivate = mysqli_prepare($conn, 'UPDATE tb_token_internal SET revoked_at=NULL WHERE id=? AND used_at IS NULL AND revoked_at IS NOT NULL');
                mysqli_stmt_bind_param($reactivate, 'i', $tokenId);
            } else {
                $reactivate = mysqli_prepare($conn, 'UPDATE tb_token_internal SET revoked_at=NULL WHERE id=? AND kode_unit=? AND used_at IS NULL AND revoked_at IS NOT NULL');
                mysqli_stmt_bind_param($reactivate, 'is', $tokenId, $adminUnitCode);
            }
            if (mysqli_stmt_execute($reactivate) && mysqli_stmt_affected_rows($reactivate) === 1) {
                $notice = 'Token internal berhasil diaktifkan kembali atas persetujuan admin. Token dapat digunakan saat jalur internal unit dan tahun ajarannya dibuka.';
            } else {
                $error = 'Token tidak ditemukan, sudah digunakan, atau sudah aktif.';
            }
            mysqli_stmt_close($reactivate);
        }
    } elseif ($action === 'reuse_internal_token') {
        $tokenId = filter_var($_POST['token_id'] ?? null, FILTER_VALIDATE_INT);
        if ($tokenId === false || $tokenId < 1 || ($_POST['admin_approval'] ?? '') !== '1') {
            $error = 'Pilih token yang valid dan konfirmasikan persetujuan untuk menggunakannya kembali.';
        } else {
            $errorMessage = 'Token tidak ditemukan, belum digunakan, sudah dicabut, atau sudah aktif kembali.';
            $tokenTransactionStarted = false;
            try {
                mysqli_begin_transaction($conn);
                $tokenTransactionStarted = true;
                if ($adminUnitCode === '') {
                    $tokenLock = mysqli_prepare($conn, 'SELECT id,used_at,revoked_at FROM tb_token_internal WHERE id=? FOR UPDATE');
                    mysqli_stmt_bind_param($tokenLock, 'i', $tokenId);
                } else {
                    $tokenLock = mysqli_prepare($conn, 'SELECT id,used_at,revoked_at FROM tb_token_internal WHERE id=? AND kode_unit=? FOR UPDATE');
                    mysqli_stmt_bind_param($tokenLock, 'is', $tokenId, $adminUnitCode);
                }
                mysqli_stmt_execute($tokenLock);
                $lockedToken = mysqli_fetch_assoc(mysqli_stmt_get_result($tokenLock));
                mysqli_stmt_close($tokenLock);
                if (!$lockedToken || $lockedToken['used_at'] === null || $lockedToken['revoked_at'] !== null) {
                    throw new RuntimeException($errorMessage);
                }

                $adminId = (int) $_SESSION['admin_id'];
                $approval = mysqli_prepare($conn, 'INSERT INTO tb_token_internal_reuse_approvals (token_internal_id,approved_by) VALUES (?,?)');
                mysqli_stmt_bind_param($approval, 'ii', $tokenId, $adminId);
                mysqli_stmt_execute($approval);
                mysqli_stmt_close($approval);

                $reactivate = mysqli_prepare($conn, 'UPDATE tb_token_internal SET used_at=NULL WHERE id=? AND used_at IS NOT NULL AND revoked_at IS NULL');
                mysqli_stmt_bind_param($reactivate, 'i', $tokenId);
                mysqli_stmt_execute($reactivate);
                if (mysqli_stmt_affected_rows($reactivate) !== 1) {
                    mysqli_stmt_close($reactivate);
                    throw new RuntimeException($errorMessage);
                }
                mysqli_stmt_close($reactivate);
                mysqli_commit($conn);
                $tokenTransactionStarted = false;
                $notice = 'Persetujuan tercatat. Token internal dapat digunakan kembali saat jalur unit dan tahun ajarannya dibuka.';
            } catch (Throwable $exception) {
                if ($tokenTransactionStarted) {
                    mysqli_rollback($conn);
                }
                if ($exception instanceof RuntimeException) {
                    $error = $exception->getMessage();
                } else {
                    error_log('SPMB internal token reuse approval failed: '.$exception->getMessage());
                    $error = 'Persetujuan penggunaan ulang token belum dapat disimpan.';
                }
            }
        }
    } elseif ($action === 'save_spreadsheets') {
        $sheetInput = is_array($_POST['spreadsheet'] ?? null) ? $_POST['spreadsheet'] : [];
        $parsedSheets = [];
        foreach ($sheetInput as $settingKey => $value) {
            [$sheetUnit, $sheetYear] = array_pad(explode('|', (string) $settingKey, 2), 2, '');
            $sheetId = google_sheets_parse_spreadsheet_id(is_string($value) ? $value : '');
            if (!isset($units[$sheetUnit]) || !in_array($sheetYear, $academicYears, true)) {
                continue;
            }
            if ($sheetId === null) {
                $error = 'ID spreadsheet '.ppdb_unit_display_name($units[$sheetUnit]['nama_unit']).' '.$sheetYear.' tidak valid. Tempel ID atau tautan spreadsheet Google.';
                break;
            }
            $parsedSheets[] = [$sheetId, $sheetUnit, $sheetYear];
        }
        if ($error === '' && !ppdb_column_exists($conn, 'tb_pengaturan_ppdb', 'google_spreadsheet_id')) {
            $error = 'Jalankan migrasi 028_spreadsheet_per_unit.sql sebelum menyimpan spreadsheet unit.';
        }
        if ($error === '') {
            $saveSheet = mysqli_prepare($conn, 'UPDATE tb_pengaturan_ppdb SET google_spreadsheet_id=? WHERE kode_unit=? AND th_ajaran=?');
            foreach ($parsedSheets as [$sheetId, $sheetUnit, $sheetYear]) {
                mysqli_stmt_bind_param($saveSheet, 'sss', $sheetId, $sheetUnit, $sheetYear);
                mysqli_stmt_execute($saveSheet);
            }
            mysqli_stmt_close($saveSheet);
            $notice = 'Spreadsheet unit berhasil disimpan. Tekan "Tulis ulang semua unit" di dasbor untuk mengisi datanya.';
        }
    } else {
    $unitCode = $_POST['kode_unit'] ?? '';
    $academicYear = $_POST['th_ajaran'] ?? '';
    $quotaInternal = filter_var($_POST['kuota_internal'] ?? null, FILTER_VALIDATE_INT);
    $quotaExternal = filter_var($_POST['kuota_eksternal'] ?? null, FILTER_VALIDATE_INT);
    $feeInput = trim($_POST['biaya_pendaftaran'] ?? '');
    $bankName = PPDB_BANK_NAME;
    $accountNumber = trim($_POST['nomor_rekening'] ?? '');
    $accountName = trim($_POST['nama_pemilik_rekening'] ?? '');
    $paymentDays = filter_var($_POST['masa_pembayaran_hari'] ?? null, FILTER_VALIDATE_INT);
    $internalOpen = isset($_POST['internal_dibuka']) ? 1 : 0;
    $externalOpen = isset($_POST['eksternal_dibuka']) ? 1 : 0;
    $isOpen = $internalOpen || $externalOpen ? 1 : 0;
    // Jadwal buka-tutup unit dalam WIB; kosong berarti tanpa batas waktu.
    $hasSchedule = ppdb_column_exists($conn, 'tb_pengaturan_ppdb', 'jadwal_buka');
    $scheduleInput = [];
    $scheduleError = '';
    foreach (['jadwal_buka', 'jadwal_tutup'] as $scheduleField) {
        $rawSchedule = is_string($_POST[$scheduleField] ?? null) ? trim($_POST[$scheduleField]) : '';
        $parsedSchedule = $rawSchedule === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $rawSchedule);
        if ($parsedSchedule === false) {
            $scheduleError = 'Format jadwal buka atau tutup tidak valid.';
        }
        $scheduleInput[$scheduleField] = $parsedSchedule ? $parsedSchedule->format('Y-m-d H:i:s') : null;
    }
    if ($scheduleError === '' && $scheduleInput['jadwal_buka'] && $scheduleInput['jadwal_tutup'] && $scheduleInput['jadwal_tutup'] <= $scheduleInput['jadwal_buka']) {
        $scheduleError = 'Jadwal tutup harus setelah jadwal buka.';
    }
    // Batas pengisian formulir boleh melewati jadwal tutup, tetapi harus setelah jadwal buka.
    $hasFormDeadline = ppdb_column_exists($conn, 'tb_pengaturan_ppdb', 'batas_formulir');
    $rawDeadline = is_string($_POST['batas_formulir'] ?? null) ? trim($_POST['batas_formulir']) : '';
    $parsedDeadline = $rawDeadline === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $rawDeadline);
    $formDeadline = $parsedDeadline ? $parsedDeadline->format('Y-m-d H:i:s') : null;
    if ($scheduleError === '' && $parsedDeadline === false) {
        $scheduleError = 'Format batas pengisian formulir tidak valid.';
    } elseif ($scheduleError === '' && $formDeadline && $scheduleInput['jadwal_buka'] && $formDeadline <= $scheduleInput['jadwal_buka']) {
        $scheduleError = 'Batas pengisian formulir harus setelah jadwal buka.';
    }

    // Unit TK per jenjang dan unit SMP per jenis kelamin: jumlahnya menjadi kuota per jalur.
    $levelQuotaInput = null;
    $unitJenjang = $units[$unitCode]['jenjang'] ?? '';
    $levelKeys = [];
    if ($unitJenjang === 'TK' && ppdb_table_exists($conn, 'tb_kuota_tingkat')) {
        $levelKeys = PPDB_TK_LEVELS;
    } elseif ($unitJenjang === 'SMP' && ppdb_table_exists($conn, 'tb_kuota_jenis_kelamin')) {
        $levelKeys = array_keys(PPDB_GENDERS);
    }
    if ($levelKeys) {
        $levelQuotaInput = [];
        $quotaInternal = 0;
        $quotaExternal = 0;
        foreach ($levelKeys as $level) {
            foreach (['internal', 'eksternal'] as $route) {
                $value = filter_var($_POST['kuota_tingkat'][$level][$route] ?? null, FILTER_VALIDATE_INT);
                if ($value === false || $value < 0 || $value > 10000) {
                    $quotaInternal = false;
                    break 2;
                }
                $levelQuotaInput[$level][$route] = $value;
                $route === 'internal' ? $quotaInternal += $value : $quotaExternal += $value;
            }
        }
    }

    if (!isset($units[$unitCode]) || !in_array($academicYear, $academicYears, true)) {
        $error = 'Pilih unit pendidikan dan tahun ajaran yang valid.';
    } elseif ($quotaInternal === false || $quotaExternal === false || $quotaInternal < 0 || $quotaExternal < 0 || $quotaInternal + $quotaExternal > 10000) {
        $error = 'Jumlah kuota internal dan eksternal harus berupa angka nonnegatif dengan total maksimal 10.000.';
    } elseif ($feeInput === '' || !preg_match('/^[0-9]{1,10}(\.[0-9]{1,2})?$/', $feeInput)) {
        $error = 'Biaya pendaftaran harus berupa angka Rupiah yang valid.';
    } elseif ($paymentDays === false || $paymentDays < 1 || $paymentDays > 30) {
        $error = 'Masa pembayaran harus antara 1 dan 30 hari.';
    } elseif (strlen($bankName) > 80 || strlen($accountNumber) > 50 || strlen($accountName) > 120) {
        $error = 'Data rekening melebihi batas karakter.';
    } elseif ((float) $feeInput > 0 && ($accountNumber === '' || $accountName === '')) {
        $error = 'Lengkapi nomor rekening dan nama pemilik sebelum menetapkan biaya.';
    } elseif (($internalOpen && $quotaInternal < 1) || ($externalOpen && $quotaExternal < 1)) {
        $error = 'Kuota jalur harus lebih dari nol untuk membuka pendaftaran jalur tersebut.';
    } elseif ($scheduleError !== '') {
        $error = $scheduleError;
    } else {
        mysqli_begin_transaction($conn);
        try {
            $settingLock = mysqli_prepare($conn, 'SELECT kode_unit FROM tb_pengaturan_ppdb WHERE kode_unit=? AND th_ajaran=? FOR UPDATE');
            mysqli_stmt_bind_param($settingLock, 'ss', $unitCode, $academicYear);
            mysqli_stmt_execute($settingLock);
            mysqli_stmt_get_result($settingLock);
            mysqli_stmt_close($settingLock);

            $reservedQuery = mysqli_prepare($conn, "SELECT jalur_pendaftaran,COUNT(*) AS total FROM tb_pendaftaran WHERE kode_unit=? AND th_ajaran=? AND status_ppdb NOT IN ('ditolak','data_lama') GROUP BY jalur_pendaftaran");
            mysqli_stmt_bind_param($reservedQuery, 'ss', $unitCode, $academicYear);
            mysqli_stmt_execute($reservedQuery);
            $reserved = ['internal' => 0, 'eksternal' => 0];
            $reservedResult = mysqli_stmt_get_result($reservedQuery);
            while ($reservedRow = mysqli_fetch_assoc($reservedResult)) {
                $reserved[$reservedRow['jalur_pendaftaran']] = (int) $reservedRow['total'];
            }
            mysqli_stmt_close($reservedQuery);

            $levelError = '';
            if ($levelQuotaInput !== null) {
                $currentLevels = $unitJenjang === 'SMP' ? ppdb_gender_quotas($conn, $unitCode, $academicYear, true) : ppdb_level_quotas($conn, $unitCode, $academicYear);
                foreach ($levelQuotaInput as $level => $routes) {
                    foreach ($routes as $route => $value) {
                        $filled = $currentLevels[$level][$route][1] ?? 0;
                        if ($value < $filled && $levelError === '') {
                            $levelError = 'Kuota '.(PPDB_GENDERS[$level] ?? $level).' '.$route.' tidak dapat lebih kecil dari pendaftar aktifnya ('.$filled.').';
                        }
                    }
                }
            }

            if ($levelError !== '') {
                mysqli_rollback($conn);
                $error = $levelError;
            } elseif ($quotaInternal < $reserved['internal'] || $quotaExternal < $reserved['eksternal']) {
                mysqli_rollback($conn);
                $error = 'Kuota tidak dapat lebih kecil dari jumlah pendaftar aktif (internal '.$reserved['internal'].', eksternal '.$reserved['eksternal'].').';
            } else {
                $adminId = (int) $_SESSION['admin_id'];
                $totalQuota = $quotaInternal + $quotaExternal;
                $update = mysqli_prepare($conn, 'INSERT INTO tb_pengaturan_ppdb (kode_unit,th_ajaran,kuota,kuota_internal,kuota_eksternal,biaya_pendaftaran,bank_nama,nomor_rekening,nama_pemilik_rekening,masa_pembayaran_hari,pendaftaran_dibuka,internal_dibuka,eksternal_dibuka,diperbarui_oleh) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE kuota=VALUES(kuota), kuota_internal=VALUES(kuota_internal), kuota_eksternal=VALUES(kuota_eksternal), biaya_pendaftaran=VALUES(biaya_pendaftaran), bank_nama=VALUES(bank_nama), nomor_rekening=VALUES(nomor_rekening), nama_pemilik_rekening=VALUES(nama_pemilik_rekening), masa_pembayaran_hari=VALUES(masa_pembayaran_hari), pendaftaran_dibuka=VALUES(pendaftaran_dibuka), internal_dibuka=VALUES(internal_dibuka), eksternal_dibuka=VALUES(eksternal_dibuka), diperbarui_oleh=VALUES(diperbarui_oleh)');
                mysqli_stmt_bind_param($update, 'ssiiidsssiiiii', $unitCode, $academicYear, $totalQuota, $quotaInternal, $quotaExternal, $feeInput, $bankName, $accountNumber, $accountName, $paymentDays, $isOpen, $internalOpen, $externalOpen, $adminId);
                $saved = mysqli_stmt_execute($update);
                if ($saved && $hasSchedule) {
                    $scheduleSave = mysqli_prepare($conn, 'UPDATE tb_pengaturan_ppdb SET jadwal_buka=?,jadwal_tutup=? WHERE kode_unit=? AND th_ajaran=?');
                    mysqli_stmt_bind_param($scheduleSave, 'ssss', $scheduleInput['jadwal_buka'], $scheduleInput['jadwal_tutup'], $unitCode, $academicYear);
                    $saved = mysqli_stmt_execute($scheduleSave);
                    mysqli_stmt_close($scheduleSave);
                }
                if ($saved && $hasFormDeadline) {
                    $deadlineSave = mysqli_prepare($conn, 'UPDATE tb_pengaturan_ppdb SET batas_formulir=? WHERE kode_unit=? AND th_ajaran=?');
                    mysqli_stmt_bind_param($deadlineSave, 'sss', $formDeadline, $unitCode, $academicYear);
                    $saved = mysqli_stmt_execute($deadlineSave);
                    mysqli_stmt_close($deadlineSave);
                }
                if ($saved && $levelQuotaInput !== null) {
                    $levelSave = mysqli_prepare($conn, ($unitJenjang === 'SMP' ? 'INSERT INTO tb_kuota_jenis_kelamin (kode_unit,th_ajaran,jenis_kelamin,kuota_internal,kuota_eksternal)' : 'INSERT INTO tb_kuota_tingkat (kode_unit,th_ajaran,tingkat_kelas,kuota_internal,kuota_eksternal)').' VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE kuota_internal=VALUES(kuota_internal),kuota_eksternal=VALUES(kuota_eksternal)');
                    foreach ($levelQuotaInput as $level => $routes) {
                        mysqli_stmt_bind_param($levelSave, 'sssii', $unitCode, $academicYear, $level, $routes['internal'], $routes['eksternal']);
                        $saved = $saved && mysqli_stmt_execute($levelSave);
                    }
                    mysqli_stmt_close($levelSave);
                }
                if ($saved) {
                    mysqli_commit($conn);
                    $notice = 'Pengaturan SPMB berhasil disimpan.';
                } else {
                    mysqli_rollback($conn);
                    $error = 'Pengaturan belum dapat disimpan.';
                }
                mysqli_stmt_close($update);
            }
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            error_log('SPMB quota update failed: '.$exception->getMessage());
            $error = 'Pengaturan belum dapat disimpan.';
        }
    }
    }
}

$settings = mysqli_query($conn, "SELECT c.*,u.nama_unit,u.jenjang,
    (SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=c.kode_unit AND p.th_ajaran=c.th_ajaran AND p.jalur_pendaftaran='internal' AND p.status_ppdb NOT IN ('ditolak','data_lama')) AS internal_terisi,
    (SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=c.kode_unit AND p.th_ajaran=c.th_ajaran AND p.jalur_pendaftaran='eksternal' AND p.status_ppdb NOT IN ('ditolak','data_lama')) AS eksternal_terisi,
    (SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=c.kode_unit AND p.th_ajaran=c.th_ajaran AND p.jalur_pendaftaran='internal' AND p.status_ppdb='diterima') AS internal_diterima,
    (SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=c.kode_unit AND p.th_ajaran=c.th_ajaran AND p.jalur_pendaftaran='eksternal' AND p.status_ppdb='diterima') AS eksternal_diterima
    ,(SELECT COUNT(*) FROM tb_token_internal t WHERE t.kode_unit=c.kode_unit AND t.th_ajaran=c.th_ajaran AND t.used_at IS NULL AND t.revoked_at IS NULL) AS internal_token_belum_digunakan
    FROM tb_pengaturan_ppdb c JOIN tb_unit_pendidikan u ON u.kode_unit=c.kode_unit".$settingsUnitCondition." ORDER BY c.th_ajaran DESC,FIELD(u.jenjang,'KB','TPA','TK','SD','SMP')");
$settingsRows = [];
$settingsByKey = [];
while ($setting = mysqli_fetch_assoc($settings)) {
    $setting['levels'] = $setting['jenjang'] === 'TK' ? ppdb_level_quotas($conn, $setting['kode_unit'], $setting['th_ajaran']) : ($setting['jenjang'] === 'SMP' ? ppdb_gender_quotas($conn, $setting['kode_unit'], $setting['th_ajaran'], true) : []);
    $settingsRows[] = $setting;
    $settingsByKey[$setting['kode_unit'].'|'.$setting['th_ajaran']] = [
        'kuota_internal' => (int) $setting['kuota_internal'],
        'kuota_eksternal' => (int) $setting['kuota_eksternal'],
        'biaya_pendaftaran' => (string) $setting['biaya_pendaftaran'],
        'nomor_rekening' => $setting['nomor_rekening'],
        'nama_pemilik_rekening' => $setting['nama_pemilik_rekening'],
        'masa_pembayaran_hari' => (int) $setting['masa_pembayaran_hari'],
        'internal_dibuka' => (int) $setting['internal_dibuka'],
        'eksternal_dibuka' => (int) $setting['eksternal_dibuka'],
        'jadwal_buka' => isset($setting['jadwal_buka']) ? substr(str_replace(' ', 'T', $setting['jadwal_buka']), 0, 16) : '',
        'jadwal_tutup' => isset($setting['jadwal_tutup']) ? substr(str_replace(' ', 'T', $setting['jadwal_tutup']), 0, 16) : '',
        'batas_formulir' => isset($setting['batas_formulir']) ? substr(str_replace(' ', 'T', $setting['batas_formulir']), 0, 16) : '',
        'reserved_internal' => (int) $setting['internal_terisi'],
        'reserved_eksternal' => (int) $setting['eksternal_terisi'],
        'levels' => (object) $setting['levels'],
    ];
}
// Tabel token dipaginasi di server karena satu pool bisa berisi 150 kode per unit.
$tokenFilterUnit = $adminUnitCode !== '' ? $adminUnitCode : (is_string($_GET['token_unit'] ?? null) && isset($units[$_GET['token_unit']]) ? $_GET['token_unit'] : '');
$tokenFilterStatus = in_array($_GET['token_status'] ?? '', ['available', 'used', 'revoked'], true) ? $_GET['token_status'] : '';
$tokenSearch = is_string($_GET['token_q'] ?? null) ? substr(preg_replace('/[^A-Za-z0-9]/', '', $_GET['token_q']) ?? '', 0, 9) : '';
$tokenWhere = ['1=1'];
$tokenTypes = '';
$tokenParams = [];
if ($tokenFilterUnit !== '') {
    $tokenWhere[] = 't.kode_unit=?';
    $tokenTypes .= 's';
    $tokenParams[] = $tokenFilterUnit;
}
if ($tokenFilterStatus === 'available') {
    $tokenWhere[] = 't.used_at IS NULL AND t.revoked_at IS NULL';
} elseif ($tokenFilterStatus === 'used') {
    $tokenWhere[] = 't.used_at IS NOT NULL';
} elseif ($tokenFilterStatus === 'revoked') {
    $tokenWhere[] = 't.used_at IS NULL AND t.revoked_at IS NOT NULL';
}
if ($tokenSearch !== '') {
    $tokenWhere[] = 't.kode_token LIKE ?';
    $tokenTypes .= 's';
    $tokenParams[] = '%'.$tokenSearch.'%';
}
$tokenWhereSql = implode(' AND ', $tokenWhere);
$tokenCountStmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM tb_token_internal t WHERE '.$tokenWhereSql);
if ($tokenTypes !== '') {
    mysqli_stmt_bind_param($tokenCountStmt, $tokenTypes, ...$tokenParams);
}
mysqli_stmt_execute($tokenCountStmt);
$tokenTotal = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($tokenCountStmt))['total'];
mysqli_stmt_close($tokenCountStmt);
$tokenPerPage = 25;
$tokenPages = max(1, (int) ceil($tokenTotal / $tokenPerPage));
$tokenPage = min($tokenPages, max(1, (int) ($_GET['token_page'] ?? 1)));
$tokenOffset = ($tokenPage - 1) * $tokenPerPage;
$tokenListStmt = mysqli_prepare($conn, "SELECT t.id,t.kode_token,t.kode_unit,t.th_ajaran,t.created_at,t.used_at,t.revoked_at,u.nama_unit FROM tb_token_internal t JOIN tb_unit_pendidikan u ON u.kode_unit=t.kode_unit WHERE ".$tokenWhereSql." ORDER BY t.th_ajaran DESC,FIELD(u.jenjang,'KB','TPA','TK','SD','SMP'),t.kode_token LIMIT ".$tokenPerPage.' OFFSET '.$tokenOffset);
if ($tokenTypes !== '') {
    mysqli_stmt_bind_param($tokenListStmt, $tokenTypes, ...$tokenParams);
}
mysqli_stmt_execute($tokenListStmt);
$internalTokens = mysqli_stmt_get_result($tokenListStmt);
$tokenQuery = static function (array $overrides) use ($tokenFilterUnit, $tokenFilterStatus, $tokenSearch, $adminUnitCode): string {
    $query = array_merge([
        'token_unit' => $adminUnitCode === '' ? $tokenFilterUnit : '',
        'token_status' => $tokenFilterStatus,
        'token_q' => $tokenSearch,
    ], $overrides);
    return '?'.http_build_query(array_filter($query, static fn ($value) => $value !== '' && $value !== null && $value !== 1)).'#token-internal';
};
// Semua unit yang sudah dikonfigurasi selalu tampil, termasuk yang belum punya token aktif.
$internalTokensCondition = $adminUnitCode === '' ? '' : " WHERE c.kode_unit='".mysqli_real_escape_string($conn, $adminUnitCode)."'";
$internalTokenPools = mysqli_query($conn, "SELECT c.kode_unit,c.th_ajaran,u.nama_unit,COUNT(t.id) AS total_dibuat,COALESCE(SUM(t.id IS NOT NULL AND t.used_at IS NULL AND t.revoked_at IS NULL),0) AS total_token FROM tb_pengaturan_ppdb c JOIN tb_unit_pendidikan u ON u.kode_unit=c.kode_unit LEFT JOIN tb_token_internal t ON t.kode_unit=c.kode_unit AND t.th_ajaran=c.th_ajaran".$internalTokensCondition." GROUP BY c.kode_unit,c.th_ajaran,u.nama_unit,u.jenjang ORDER BY c.th_ajaran DESC,FIELD(u.jenjang,'KB','TPA','TK','SD','SMP')");
$activeAdminPage = 'quota';
$adminPageTitle = 'Kuota & pembayaran';
$adminPageDescription = 'Atur kuota, biaya, rekening, dan status buka pendaftaran per unit serta tahun ajaran.';
require 'admin_header.php';
?>
<div class="admin-content">
    <header class="page-heading">
        <div>
            <p class="eyebrow">Pengaturan penerimaan</p>
            <h1>Kuota dan pembayaran</h1>
            <p>Atur alokasi internal dan eksternal secara terpisah untuk setiap unit dan tahun ajaran.</p>
        </div>
    </header>
    <?php if ($error !== '') { ?><div class="alert-error" role="alert"><?php echo h($error); ?></div><?php } ?>
    <?php if ($notice !== '') { ?><div class="alert-success" role="status"><?php echo h($notice); ?></div><?php } ?>
    <section class="panel quota-config-panel">
        <div class="panel-header">
            <div>
                <h2>Konfigurasi unit</h2>
                <span class="panel-subtitle">Biaya dan rekening tidak diisi otomatis; masukkan nilai resmi yayasan.</span>
            </div>
        </div>
        <form class="quota-config-form" id="quota-config-form" method="post"<?php echo $_SERVER['REQUEST_METHOD'] === 'POST' ? ' data-submitted="1"' : ''; ?>>
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="save_quota">
            <div class="filter-field">
                <label for="kode_unit">Unit pendidikan</label>
                <select id="kode_unit" name="kode_unit" required>
                    <option value="">Pilih unit</option>
                    <?php foreach ($units as $code => $unit) { ?>
                        <option value="<?php echo h($code); ?>" data-jenjang="<?php echo h($unit['jenjang']); ?>"<?php echo (($_POST['kode_unit'] ?? '') === $code) ? ' selected' : ''; ?>><?php echo h(ppdb_unit_display_name($unit['nama_unit'])); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="th_ajaran">Tahun ajaran</label>
                <select id="th_ajaran" name="th_ajaran" required>
                    <option value="">Pilih tahun ajaran</option>
                    <?php foreach ($academicYears as $year) { ?>
                        <option value="<?php echo h($year); ?>"<?php echo (($_POST['th_ajaran'] ?? '') === $year) ? ' selected' : ''; ?>><?php echo h($year); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="kuota_internal">Kuota internal SDM</label>
                <input id="kuota_internal" name="kuota_internal" type="number" min="0" max="10000" value="<?php echo h($_POST['kuota_internal'] ?? '0'); ?>" required
                    data-reserved="<?php echo h((string) ($settingsByKey[($_POST['kode_unit'] ?? '').'|'.($_POST['th_ajaran'] ?? '')]['reserved_internal'] ?? '0')); ?>">
            </div>
            <div class="filter-field">
                <label for="kuota_eksternal">Kuota eksternal umum</label>
                <input id="kuota_eksternal" name="kuota_eksternal" type="number" min="0" max="10000" value="<?php echo h($_POST['kuota_eksternal'] ?? '0'); ?>" required
                    data-reserved="<?php echo h((string) ($settingsByKey[($_POST['kode_unit'] ?? '').'|'.($_POST['th_ajaran'] ?? '')]['reserved_eksternal'] ?? '0')); ?>">
            </div>
            <?php if (ppdb_table_exists($conn, 'tb_kuota_tingkat')) { $postedUnitIsTk = ($units[$_POST['kode_unit'] ?? '']['jenjang'] ?? '') === 'TK'; ?>
                <fieldset class="level-quota" data-level-quota data-level-jenjang="TK"<?php echo $postedUnitIsTk ? '' : ' hidden'; ?>>
                    <legend>Kuota per jenjang TK</legend>
                    <p class="level-quota__hint">Kuota internal dan eksternal TK di atas otomatis menjadi jumlah dari isian berikut.</p>
                    <?php foreach (PPDB_TK_LEVELS as $level) { $levelKey = strtolower(str_replace(' ', '-', $level)); ?>
                        <?php foreach (['internal' => 'internal SDM', 'eksternal' => 'eksternal umum'] as $route => $routeLabel) { ?>
                            <div class="filter-field">
                                <label for="kuota-<?php echo $levelKey.'-'.$route; ?>"><?php echo h($level.' '.$routeLabel); ?></label>
                                <input id="kuota-<?php echo $levelKey.'-'.$route; ?>" name="kuota_tingkat[<?php echo h($level); ?>][<?php echo $route; ?>]" type="number" min="0" max="10000" value="<?php echo h((string) ($_POST['kuota_tingkat'][$level][$route] ?? '0')); ?>" data-level="<?php echo h($level); ?>" data-route="<?php echo $route; ?>"<?php echo $postedUnitIsTk ? ' required' : ' disabled'; ?>>
                            </div>
                        <?php } ?>
                    <?php } ?>
                </fieldset>
            <?php } ?>
            <?php if (ppdb_table_exists($conn, 'tb_kuota_jenis_kelamin')) { $postedUnitIsSmp = ($units[$_POST['kode_unit'] ?? '']['jenjang'] ?? '') === 'SMP'; ?>
                <fieldset class="level-quota" data-level-quota data-level-jenjang="SMP"<?php echo $postedUnitIsSmp ? '' : ' hidden'; ?>>
                    <legend>Kuota SMP per jenis kelamin</legend>
                    <p class="level-quota__hint">Kuota internal dan eksternal SMP di atas otomatis menjadi jumlah dari isian berikut.</p>
                    <?php foreach (PPDB_GENDERS as $gender => $genderLabel) { ?>
                        <?php foreach (['internal' => 'internal SDM', 'eksternal' => 'eksternal umum'] as $route => $routeLabel) { ?>
                            <div class="filter-field">
                                <label for="kuota-<?php echo $gender.'-'.$route; ?>"><?php echo h($genderLabel.' '.$routeLabel); ?></label>
                                <input id="kuota-<?php echo $gender.'-'.$route; ?>" name="kuota_tingkat[<?php echo $gender; ?>][<?php echo $route; ?>]" type="number" min="0" max="10000" value="<?php echo h((string) ($_POST['kuota_tingkat'][$gender][$route] ?? '0')); ?>" data-level="<?php echo $gender; ?>" data-route="<?php echo $route; ?>"<?php echo $postedUnitIsSmp ? ' required' : ' disabled'; ?>>
                            </div>
                        <?php } ?>
                    <?php } ?>
                </fieldset>
            <?php } ?>
            <div class="filter-field">
                <label for="masa_pembayaran_hari">Batas pembayaran (hari)</label>
                <input id="masa_pembayaran_hari" name="masa_pembayaran_hari" type="number" min="1" max="30" value="<?php echo h($_POST['masa_pembayaran_hari'] ?? '7'); ?>" required>
            </div>
            <div class="filter-field field-span-2">
                <label for="biaya_pendaftaran">Biaya pendaftaran (Rp)</label>
                <input id="biaya_pendaftaran" name="biaya_pendaftaran" inputmode="decimal" placeholder="Contoh: 250000 — kosongkan bila tidak dipungut" value="<?php echo h($_POST['biaya_pendaftaran'] ?? ''); ?>" required>
            </div>
            <div class="filter-field">
                <label for="bank_nama">Bank</label>
                <input id="bank_nama" value="<?php echo h(PPDB_BANK_NAME); ?>" readonly>
            </div>
            <div class="filter-field">
                <label for="nomor_rekening">Nomor rekening</label>
                <input id="nomor_rekening" name="nomor_rekening" maxlength="50" value="<?php echo h($_POST['nomor_rekening'] ?? ''); ?>">
            </div>
            <div class="filter-field field-span-2">
                <label for="nama_pemilik_rekening">Nama pemilik rekening</label>
                <input id="nama_pemilik_rekening" name="nama_pemilik_rekening" maxlength="120" value="<?php echo h($_POST['nama_pemilik_rekening'] ?? ''); ?>">
            </div>
            <?php if (ppdb_column_exists($conn, 'tb_pengaturan_ppdb', 'jadwal_buka')) { ?>
                <div class="filter-field">
                    <label for="jadwal_buka">Jadwal buka (WIB)</label>
                    <input id="jadwal_buka" name="jadwal_buka" type="datetime-local" value="<?php echo h(is_string($_POST['jadwal_buka'] ?? null) ? $_POST['jadwal_buka'] : ''); ?>">
                </div>
                <div class="filter-field">
                    <label for="jadwal_tutup">Jadwal tutup (WIB)</label>
                    <input id="jadwal_tutup" name="jadwal_tutup" type="datetime-local" value="<?php echo h(is_string($_POST['jadwal_tutup'] ?? null) ? $_POST['jadwal_tutup'] : ''); ?>">
                </div>
                <p class="field-span-2 level-quota__hint">Jalur eksternal hanya dapat diakses di antara jadwal buka dan tutup, selama jalurnya dicentang buka. Jalur internal dapat diakses sebelum jadwal buka bila dicentang buka, dan berhenti pada jadwal tutup. Jalur eksternal belum terbuka selama jadwal buka kosong; jadwal tutup boleh dikosongkan bila tanpa batas waktu. Kuota tidak berubah.</p>
            <?php } ?>
            <?php if (ppdb_column_exists($conn, 'tb_pengaturan_ppdb', 'batas_formulir')) { ?>
                <div class="form-deadline-row field-span-2">
                    <span class="form-deadline-row__icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16Zm.5-13H11v6l5.2 3.1.8-1.2-4.5-2.7V7Z"/></svg></span>
                    <div class="form-deadline-row__copy">
                        <strong>Waktu pengisian formulir</strong>
                        <span data-deadline-summary>Pilih unit dan tahun ajaran terlebih dahulu.</span>
                    </div>
                    <button class="button-secondary" type="button" data-deadline-open disabled>Atur waktu pengisian</button>
                </div>
                <dialog class="deadline-dialog" data-deadline-dialog aria-labelledby="deadline-dialog-title">
                    <div class="deadline-dialog__head">
                        <p class="deadline-dialog__eyebrow" data-deadline-unit>Konfigurasi unit</p>
                        <h2 id="deadline-dialog-title">Waktu pengisian formulir</h2>
                        <p>Pendaftar dapat mengisi formulir lengkap sejak jadwal buka sampai batas ini, meskipun jadwal tutup pendaftaran sudah lewat.</p>
                    </div>
                    <dl class="deadline-dialog__range">
                        <div><dt>Mulai</dt><dd data-deadline-start>Jadwal buka belum diatur</dd></div>
                        <div><dt>Jadwal tutup pendaftaran</dt><dd data-deadline-close>Tanpa batas</dd></div>
                    </dl>
                    <div class="filter-field">
                        <label for="batas_formulir">Batas pengisian formulir (WIB)</label>
                        <input id="batas_formulir" name="batas_formulir" type="datetime-local" value="<?php echo h(is_string($_POST['batas_formulir'] ?? null) ? $_POST['batas_formulir'] : ''); ?>">
                        <small class="deadline-dialog__hint" data-deadline-hint>Kosongkan bila formulir boleh diisi tanpa batas waktu.</small>
                    </div>
                    <div class="deadline-dialog__actions">
                        <button class="button-secondary" type="button" data-deadline-clear>Tanpa batas</button>
                        <span></span>
                        <button class="button-secondary" type="button" data-deadline-cancel>Batal</button>
                        <button class="button-primary" type="submit">Simpan batas</button>
                    </div>
                </dialog>
            <?php } ?>
            <label class="quota-toggle">
                <input type="checkbox" name="internal_dibuka" value="1" <?php echo isset($_POST['internal_dibuka']) ? 'checked' : ''; ?>>
                <span>Buka pendaftaran internal</span>
            </label>
            <label class="quota-toggle">
                <input type="checkbox" name="eksternal_dibuka" value="1" <?php echo isset($_POST['eksternal_dibuka']) ? 'checked' : ''; ?>>
                <span>Buka pendaftaran eksternal</span>
            </label>
            <div class="quota-form-footer">
                <span id="quota-form-mode">Pilih unit dan tahun ajaran untuk melihat atau mengatur kuota.</span>
                <button class="button-primary" id="quota-save-button" type="submit">Simpan pengaturan</button>
            </div>
        </form>
    </section>

    <?php if (ppdb_column_exists($conn, 'tb_pengaturan_ppdb', 'google_spreadsheet_id') && $settingsRows) { $sheetsServiceAccount = google_sheets_service_account_email(); ?>
    <section class="panel quota-current-panel" id="spreadsheet-unit">
        <div class="panel-header">
            <div>
                <h2>Spreadsheet Google per unit</h2>
                <span class="panel-subtitle">Tempel ID atau tautan spreadsheet tiap unit. Data peserta ditulis ke tab <?php echo h(GOOGLE_SHEETS_TAB); ?><?php if ($sheetsServiceAccount !== '') { ?>; bagikan spreadsheet sebagai Editor ke <code><?php echo h($sheetsServiceAccount); ?></code><?php } ?>.</span>
            </div>
        </div>
        <form class="quota-config-form" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="save_spreadsheets">
            <?php foreach ($settingsRows as $row) { $sheetKey = $row['kode_unit'].'|'.$row['th_ajaran']; $sheetFieldId = 'sheet-'.preg_replace('/[^a-z0-9]+/i', '-', $sheetKey); ?>
                <div class="filter-field field-span-2">
                    <label for="<?php echo h($sheetFieldId); ?>"><?php echo h(ppdb_unit_display_name($row['nama_unit']).' · '.$row['th_ajaran']); ?></label>
                    <input id="<?php echo h($sheetFieldId); ?>" name="spreadsheet[<?php echo h($sheetKey); ?>]" maxlength="300" autocomplete="off" spellcheck="false" placeholder="ID atau tautan Google Spreadsheet" value="<?php echo h($row['google_spreadsheet_id']); ?>">
                </div>
            <?php } ?>
            <div class="quota-form-footer">
                <span>Kosongkan bila unit tersebut belum memakai spreadsheet.</span>
                <button class="button-primary" type="submit">Simpan spreadsheet</button>
            </div>
        </form>
    </section>
    <?php } ?>

    <section class="panel quota-current-panel" id="token-internal">
        <div class="panel-header">
            <div><h2>Token internal SDM</h2><span class="panel-subtitle">Satu tautan bersama; setiap SDM memasukkan token 9 karakter dengan huruf besar, huruf kecil, dan angka. Pool dibuat maksimal 150 token per unit dan tahun ajaran. Admin dapat mengaktifkan kembali token yang dicabut setelah menyetujui penggunaan ulangnya.</span></div>
        </div>
        <form class="quota-config-form" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="generate_internal_tokens">
            <div class="filter-field">
                <label for="token-unit">Unit pendidikan</label>
                <select id="token-unit" name="token_unit" required>
                    <option value="">Pilih unit</option>
                    <?php foreach ($units as $code => $unit) { ?><option value="<?php echo h($code); ?>"><?php echo h(ppdb_unit_display_name($unit['nama_unit'])); ?></option><?php } ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="token-year">Tahun ajaran</label>
                <select id="token-year" name="token_year" required>
                    <option value="">Pilih tahun ajaran</option>
                    <?php foreach ($academicYears as $year) { ?><option value="<?php echo h($year); ?>"><?php echo h($year); ?></option><?php } ?>
                </select>
            </div>
            <div class="share-link-box field-span-2">
                <div class="share-link-box__head">
                    <span class="share-link-box__icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M10.6 13.4a1 1 0 0 1 0-1.4l3.5-3.5a1 1 0 1 1 1.4 1.4L12 13.4a1 1 0 0 1-1.4 0Zm-3.5 6.4a4.5 4.5 0 0 1-3.2-7.7l2.5-2.5a1 1 0 0 1 1.4 1.4l-2.5 2.5a2.5 2.5 0 0 0 3.5 3.5l2.5-2.5a1 1 0 0 1 1.4 1.4l-2.5 2.5a4.5 4.5 0 0 1-3.1 1.4Zm9.3-6.1a1 1 0 0 1-.7-1.7l2.5-2.5a2.5 2.5 0 0 0-3.5-3.5l-2.5 2.5a1 1 0 1 1-1.4-1.4l2.5-2.5a4.5 4.5 0 0 1 6.3 6.3l-2.5 2.5a1 1 0 0 1-.7.3Z"/></svg></span>
                    <div>
                        <strong>Tautan pendaftaran SDM</strong>
                        <small>Satu tautan untuk semua unit. Bagikan bersama satu kode token untuk setiap SDM.</small>
                    </div>
                </div>
                <div class="share-link-box__field">
                    <a class="share-link-box__url" href="<?php echo h($internalEntryUrl); ?>" target="_blank" rel="noopener"><?php echo h(preg_replace('#^https?://#', '', $internalEntryUrl)); ?></a>
                    <button class="share-link-box__button" type="button" data-copy-link="<?php echo h($internalEntryUrl); ?>"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3h10a2 2 0 0 1 2 2v12h-2V5H9V3ZM5 7h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Zm0 2v10h10V9H5Z"/></svg><span>Salin tautan</span></button>
                </div>
            </div>
            <div class="quota-form-footer">
                <span>Berikan satu kode kepada satu SDM.</span>
                <button class="button-primary" type="submit">Buat token hingga 150</button>
            </div>
        </form>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th scope="col">Unit</th><th scope="col">Tahun ajaran</th><th scope="col">Token aktif</th><th scope="col">PDF</th></tr></thead>
                <tbody>
                <?php if (mysqli_num_rows($internalTokenPools) === 0) { ?><tr><td colspan="4" class="empty-state">Belum ada unit yang dikonfigurasi.</td></tr><?php } ?>
                <?php while ($pool = mysqli_fetch_assoc($internalTokenPools)) { ?>
                    <tr>
                        <td data-label="Unit" class="primary-cell"><?php echo h(ppdb_unit_display_name($pool['nama_unit'])); ?></td>
                        <td data-label="Tahun ajaran"><?php echo h($pool['th_ajaran']); ?></td>
                        <td data-label="Token aktif"><?php echo (int) $pool['total_token']; ?></td>
                        <td data-label="PDF">
                            <?php if ((int) $pool['total_token'] > 0) { ?>
                                <a class="button-secondary" href="export-token-internal.php?unit=<?php echo rawurlencode($pool['kode_unit']); ?>&amp;tahun=<?php echo rawurlencode($pool['th_ajaran']); ?>">Unduh PDF token unit</a>
                            <?php } elseif ((int) $pool['total_dibuat'] < 150) { ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="generate_internal_tokens">
                                    <input type="hidden" name="token_unit" value="<?php echo h($pool['kode_unit']); ?>">
                                    <input type="hidden" name="token_year" value="<?php echo h($pool['th_ajaran']); ?>">
                                    <button class="button-primary" type="submit">Buat token</button>
                                </form>
                            <?php } else { ?>
                                <span class="cell-sub">Semua token sudah terpakai atau dicabut</span>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <form class="filter-bar" method="get" action="pengaturan-ppdb.php#token-internal" aria-label="Filter token internal">
            <div class="filter-field grow">
                <label for="token-q">Cari kode token</label>
                <input id="token-q" name="token_q" type="search" value="<?php echo h($tokenSearch); ?>" maxlength="9" autocomplete="off" spellcheck="false">
            </div>
            <?php if ($adminUnitCode === '') { ?>
                <div class="filter-field">
                    <label for="token-filter-unit">Unit</label>
                    <select id="token-filter-unit" name="token_unit">
                        <option value="">Semua unit</option>
                        <?php foreach ($units as $code => $unit) { ?><option value="<?php echo h($code); ?>"<?php echo $tokenFilterUnit === $code ? ' selected' : ''; ?>><?php echo h(ppdb_unit_display_name($unit['nama_unit'])); ?></option><?php } ?>
                    </select>
                </div>
            <?php } ?>
            <div class="filter-field">
                <label for="token-filter-status">Status</label>
                <select id="token-filter-status" name="token_status">
                    <option value="">Semua status</option>
                    <option value="available"<?php echo $tokenFilterStatus === 'available' ? ' selected' : ''; ?>>Belum digunakan</option>
                    <option value="used"<?php echo $tokenFilterStatus === 'used' ? ' selected' : ''; ?>>Sudah digunakan</option>
                    <option value="revoked"<?php echo $tokenFilterStatus === 'revoked' ? ' selected' : ''; ?>>Dicabut</option>
                </select>
            </div>
            <div class="filter-actions">
                <button class="button-primary" type="submit">Terapkan</button>
                <?php if ($tokenFilterStatus !== '' || $tokenSearch !== '' || ($adminUnitCode === '' && $tokenFilterUnit !== '')) { ?><a class="button-secondary" href="pengaturan-ppdb.php#token-internal">Reset</a><?php } ?>
            </div>
        </form>
        <div class="table-meta">
            <span><?php if ($tokenTotal > 0) { ?>Menampilkan <strong><?php echo $tokenOffset + 1; ?>&ndash;<?php echo min($tokenTotal, $tokenOffset + $tokenPerPage); ?></strong> dari <strong><?php echo $tokenTotal; ?></strong> token<?php } else { ?>Tidak ada token yang cocok<?php } ?></span>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th scope="col">Kode token</th><th scope="col">Unit / tahun</th><th scope="col">Dibuat</th><th scope="col">Status</th><th scope="col">Aksi</th></tr></thead>
                <tbody>
                <?php if (mysqli_num_rows($internalTokens) === 0) { ?><tr><td colspan="5" class="empty-state">Belum ada token internal. Pilih unit dan tahun ajaran untuk membuat pool.</td></tr><?php } ?>
                <?php while ($tokenRow = mysqli_fetch_assoc($internalTokens)) {
                    $tokenUnused = $tokenRow['used_at'] === null && $tokenRow['revoked_at'] === null;
                    $tokenStatus = $tokenRow['used_at'] !== null ? 'Sudah digunakan' : ($tokenRow['revoked_at'] !== null ? 'Dicabut' : 'Belum digunakan');
                ?>
                    <tr>
                        <td data-label="Kode token" class="primary-cell"><code><?php echo h($tokenRow['kode_token']); ?></code></td>
                        <td data-label="Unit / tahun"><span><?php echo h(ppdb_unit_display_name($tokenRow['nama_unit'])); ?> <small class="cell-sub"><?php echo h($tokenRow['th_ajaran']); ?></small></span></td>
                        <td data-label="Dibuat"><?php echo h($tokenRow['created_at']); ?></td>
                        <td data-label="Status">
                            <?php if ($tokenRow['used_at'] !== null) { ?>
                                <span class="button-secondary token-status-used" title="Kode ini sudah dipakai untuk pendaftaran pada <?php echo h($tokenRow['used_at']); ?>">
                                    <span class="token-status-check" aria-hidden="true"></span>
                                    Sudah digunakan
                                </span>
                            <?php } else { ?>
                                <span class="token-status <?php echo $tokenRow['revoked_at'] !== null ? 'token-status--revoked' : 'token-status--available'; ?>">
                                    <span class="token-status__dot" aria-hidden="true"></span>
                                    <span><?php echo h($tokenStatus); ?></span>
                                </span>
                            <?php } ?>
                        </td>
                        <td data-label="Aksi">
                            <?php if ($tokenUnused) { ?>
                                <form method="post" onsubmit="return confirm('Cabut token ini? Kode tidak dapat digunakan lagi.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="revoke_internal_token">
                                    <input type="hidden" name="token_id" value="<?php echo (int) $tokenRow['id']; ?>">
                                    <button class="button-secondary" type="submit">Cabut</button>
                                </form>
                            <?php } elseif ($tokenRow['used_at'] === null && $tokenRow['revoked_at'] !== null) { ?>
                                <form method="post" onsubmit="return confirm('Anda menyetujui penggunaan kembali kode <?php echo h($tokenRow['kode_token']); ?> untuk unit <?php echo h($tokenRow['nama_unit']); ?> tahun <?php echo h($tokenRow['th_ajaran']); ?>?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="reactivate_internal_token">
                                    <input type="hidden" name="token_id" value="<?php echo (int) $tokenRow['id']; ?>">
                                    <input type="hidden" name="admin_approval" value="1">
                                    <button class="button-primary" type="submit">Setujui &amp; aktifkan</button>
                                </form>
                            <?php } elseif ($tokenRow['used_at'] !== null && $tokenRow['revoked_at'] === null) { ?>
                                <form method="post" onsubmit="return confirm('Anda menyetujui penggunaan kembali token ini? Persetujuan admin akan dicatat.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="reuse_internal_token">
                                    <input type="hidden" name="token_id" value="<?php echo (int) $tokenRow['id']; ?>">
                                    <input type="hidden" name="admin_approval" value="1">
                                    <button class="button-primary" type="submit">Setujui &amp; gunakan lagi</button>
                                </form>
                            <?php } else { echo '&mdash;'; } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php if ($tokenPages > 1) { ?>
            <nav class="pagination" aria-label="Halaman token internal">
                <?php if ($tokenPage > 1) { ?><a class="page-step" href="<?php echo h($tokenQuery(['token_page' => $tokenPage - 1])); ?>" rel="prev">&larr; Sebelumnya</a><?php } else { ?><span class="page-step is-disabled">&larr; Sebelumnya</span><?php } ?>
                <div class="page-numbers">
                    <?php for ($page = 1; $page <= $tokenPages; $page++) {
                        if ($page === 1 || $page === $tokenPages || abs($page - $tokenPage) <= 1) {
                            echo '<a class="page-number'.($page === $tokenPage ? ' is-current' : '').'" href="'.h($tokenQuery(['token_page' => $page])).'"'.($page === $tokenPage ? ' aria-current="page"' : '').'>'.$page.'</a>';
                        } elseif (abs($page - $tokenPage) === 2) {
                            echo '<span class="page-gap">&hellip;</span>';
                        }
                    } ?>
                </div>
                <?php if ($tokenPage < $tokenPages) { ?><a class="page-step" href="<?php echo h($tokenQuery(['token_page' => $tokenPage + 1])); ?>" rel="next">Berikutnya</a><?php } else { ?><span class="page-step is-disabled">Berikutnya</span><?php } ?>
            </nav>
        <?php } ?>
    </section>

    <section class="panel quota-current-panel">
        <div class="panel-header">
            <div>
                <h2>Kuota yang sudah diatur</h2>
                <span class="panel-subtitle">Realisasi pengisian dan penerimaan per unit</span>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Unit</th><th scope="col">Tahun</th><th scope="col">Kuota internal</th>
                        <th scope="col">Kuota eksternal</th><th scope="col">Diterima</th><th scope="col">Biaya</th>
                        <th scope="col">Status</th><th scope="col">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$settingsRows) { ?>
                    <tr><td class="empty-state" colspan="8">Belum ada pengaturan. Pilih unit dan tahun ajaran di atas untuk membuat konfigurasi pertama.</td></tr>
                <?php } ?>
                <?php foreach ($settingsRows as $row) {
                ?>
                    <tr>
                        <td data-label="Unit" class="primary-cell"><?php echo h(ppdb_unit_display_name($row['nama_unit'])); ?></td>
                        <td data-label="Tahun"><?php echo h($row['th_ajaran']); ?></td>
                        <td data-label="Kuota internal"><span class="quota-cell-text"><?php echo (int) $row['internal_terisi']; ?> / <?php echo (int) $row['kuota_internal']; ?> terisi<small class="cell-sub"><?php echo (int) $row['internal_diterima']; ?> diterima · <?php echo (int) $row['internal_token_belum_digunakan']; ?> token belum digunakan · <?php echo $row['internal_dibuka'] ? 'dibuka' : 'ditutup'; ?></small><?php foreach ($row["levels"] as $levelName => $levelRoutes) { ?><small class="level-breakdown"><?php echo h($levelName); ?>: <?php echo (int) $levelRoutes["internal"][1]; ?> / <?php echo (int) $levelRoutes["internal"][0]; ?></small><?php } ?></span></td>
                        <td data-label="Kuota eksternal"><span class="quota-cell-text"><?php echo (int) $row['eksternal_terisi']; ?> / <?php echo (int) $row['kuota_eksternal']; ?> terisi<small class="cell-sub"><?php echo (int) $row['eksternal_diterima']; ?> diterima · <?php echo $row['eksternal_dibuka'] ? 'dibuka' : 'ditutup'; ?></small><?php foreach ($row["levels"] as $levelName => $levelRoutes) { ?><small class="level-breakdown"><?php echo h($levelName); ?>: <?php echo (int) $levelRoutes["eksternal"][1]; ?> / <?php echo (int) $levelRoutes["eksternal"][0]; ?></small><?php } ?></span></td>
                        <td data-label="Diterima"><?php echo (int) $row['internal_diterima'] + (int) $row['eksternal_diterima']; ?></td>
                        <td data-label="Biaya"><?php echo (float) $row['biaya_pendaftaran'] > 0 ? 'Rp '.h(number_format((float) $row['biaya_pendaftaran'], 0, ',', '.')) : 'Gratis'; ?></td>
                        <?php
                        $rowPhase = ppdb_schedule_phase($row['jadwal_buka'] ?? null, $row['jadwal_tutup'] ?? null);
                        $rowSchedule = ppdb_format_schedule_range($row['jadwal_buka'] ?? null, $row['jadwal_tutup'] ?? null);
                        $rowOpen = $row['pendaftaran_dibuka'] && $rowPhase === 'berjalan';
                        $rowStatus = !$row['pendaftaran_dibuka'] ? 'Ditutup' : ($rowPhase === 'belum' ? 'Terjadwal' : ($rowPhase === 'selesai' ? 'Jadwal berakhir' : 'Dibuka'));
                        // Tanpa jadwal buka, jalur eksternal belum terbuka; hanya internal yang mungkin berjalan.
                        if ($rowOpen && empty($row['jadwal_buka'])) {
                            $rowOpen = (bool) $row['internal_dibuka'];
                            $rowStatus = $rowOpen ? 'Internal dibuka' : 'Belum dijadwalkan';
                        }
                        ?>
                        <td data-label="Status"><span class="quota-cell-text"><span class="status-pill <?php echo $rowOpen ? 'is-open' : 'is-closed'; ?>"><?php echo $rowStatus; ?></span><?php if ($rowSchedule !== '') { ?><small class="cell-sub"><?php echo h($rowSchedule); ?></small><?php } ?><?php if (!empty($row['batas_formulir'])) { ?><small class="cell-sub">Formulir s.d. <?php echo h(ppdb_format_datetime_short($row['batas_formulir'])); ?> WIB</small><?php } ?></span></td>
                        <td data-label="Aksi"><button class="button-secondary quota-edit-button" type="button" data-unit="<?php echo h($row['kode_unit']); ?>" data-year="<?php echo h($row['th_ajaran']); ?>">Ubah</button></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<script>window.PPDB_QUOTA_SETTINGS = <?php echo json_encode($settingsByKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="js/pengaturan.js?v=<?php echo (int) @filemtime(__DIR__.'/js/pengaturan.js'); ?>"></script>
<?php require 'admin_footer.php'; ?>