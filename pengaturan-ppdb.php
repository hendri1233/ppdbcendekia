<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_super_admin();

$error = '';
$notice = '';
$internalEntryUrl = ppdb_public_base_url().'/daftar.php?internal=1';
$academicYears = [];
for ($year = 2027; $year <= 2040; $year++) {
    $academicYears[] = $year.'/'.($year + 1);
}

$adminUnitCode = ppdb_is_super_admin() ? '' : (string) $_SESSION['admin_unit_code'];
$unitCondition = $adminUnitCode === '' ? '' : " WHERE kode_unit='".mysqli_real_escape_string($conn, $adminUnitCode)."'";
$settingsUnitCondition = $adminUnitCode === '' ? '' : " WHERE c.kode_unit='".mysqli_real_escape_string($conn, $adminUnitCode)."'";
$tokensUnitCondition = $adminUnitCode === '' ? '' : " WHERE t.kode_unit='".mysqli_real_escape_string($conn, $adminUnitCode)."'";
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
                    error_log('PPDB internal token generation failed: '.$exception->getMessage());
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
                    error_log('PPDB internal token reuse approval failed: '.$exception->getMessage());
                    $error = 'Persetujuan penggunaan ulang token belum dapat disimpan.';
                }
            }
        }
    } else {
    $unitCode = $_POST['kode_unit'] ?? '';
    $academicYear = $_POST['th_ajaran'] ?? '';
    $quotaInternal = filter_var($_POST['kuota_internal'] ?? null, FILTER_VALIDATE_INT);
    $quotaExternal = filter_var($_POST['kuota_eksternal'] ?? null, FILTER_VALIDATE_INT);
    $feeInput = trim($_POST['biaya_pendaftaran'] ?? '');
    $bankName = trim($_POST['bank_nama'] ?? '');
    $accountNumber = trim($_POST['nomor_rekening'] ?? '');
    $accountName = trim($_POST['nama_pemilik_rekening'] ?? '');
    $paymentDays = filter_var($_POST['masa_pembayaran_hari'] ?? null, FILTER_VALIDATE_INT);
    $internalOpen = isset($_POST['internal_dibuka']) ? 1 : 0;
    $externalOpen = isset($_POST['eksternal_dibuka']) ? 1 : 0;
    $isOpen = $internalOpen || $externalOpen ? 1 : 0;

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
    } elseif ((float) $feeInput > 0 && ($bankName === '' || $accountNumber === '' || $accountName === '')) {
        $error = 'Lengkapi bank, nomor rekening, dan nama pemilik sebelum menetapkan biaya.';
    } elseif (($internalOpen && $quotaInternal < 1) || ($externalOpen && $quotaExternal < 1)) {
        $error = 'Kuota jalur harus lebih dari nol untuk membuka pendaftaran jalur tersebut.';
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

            if ($quotaInternal < $reserved['internal'] || $quotaExternal < $reserved['eksternal']) {
                mysqli_rollback($conn);
                $error = 'Kuota tidak dapat lebih kecil dari jumlah pendaftar aktif (internal '.$reserved['internal'].', eksternal '.$reserved['eksternal'].').';
            } else {
                $adminId = (int) $_SESSION['admin_id'];
                $totalQuota = $quotaInternal + $quotaExternal;
                $update = mysqli_prepare($conn, 'INSERT INTO tb_pengaturan_ppdb (kode_unit,th_ajaran,kuota,kuota_internal,kuota_eksternal,biaya_pendaftaran,bank_nama,nomor_rekening,nama_pemilik_rekening,masa_pembayaran_hari,pendaftaran_dibuka,internal_dibuka,eksternal_dibuka,diperbarui_oleh) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE kuota=VALUES(kuota), kuota_internal=VALUES(kuota_internal), kuota_eksternal=VALUES(kuota_eksternal), biaya_pendaftaran=VALUES(biaya_pendaftaran), bank_nama=VALUES(bank_nama), nomor_rekening=VALUES(nomor_rekening), nama_pemilik_rekening=VALUES(nama_pemilik_rekening), masa_pembayaran_hari=VALUES(masa_pembayaran_hari), pendaftaran_dibuka=VALUES(pendaftaran_dibuka), internal_dibuka=VALUES(internal_dibuka), eksternal_dibuka=VALUES(eksternal_dibuka), diperbarui_oleh=VALUES(diperbarui_oleh)');
                mysqli_stmt_bind_param($update, 'ssiiidsssiiiii', $unitCode, $academicYear, $totalQuota, $quotaInternal, $quotaExternal, $feeInput, $bankName, $accountNumber, $accountName, $paymentDays, $isOpen, $internalOpen, $externalOpen, $adminId);
                if (mysqli_stmt_execute($update)) {
                    mysqli_commit($conn);
                    $notice = 'Pengaturan PPDB berhasil disimpan.';
                } else {
                    mysqli_rollback($conn);
                    $error = 'Pengaturan belum dapat disimpan.';
                }
                mysqli_stmt_close($update);
            }
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            error_log('PPDB quota update failed: '.$exception->getMessage());
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
    $settingsRows[] = $setting;
    $settingsByKey[$setting['kode_unit'].'|'.$setting['th_ajaran']] = [
        'kuota_internal' => (int) $setting['kuota_internal'],
        'kuota_eksternal' => (int) $setting['kuota_eksternal'],
        'biaya_pendaftaran' => (string) $setting['biaya_pendaftaran'],
        'bank_nama' => $setting['bank_nama'],
        'nomor_rekening' => $setting['nomor_rekening'],
        'nama_pemilik_rekening' => $setting['nama_pemilik_rekening'],
        'masa_pembayaran_hari' => (int) $setting['masa_pembayaran_hari'],
        'internal_dibuka' => (int) $setting['internal_dibuka'],
        'eksternal_dibuka' => (int) $setting['eksternal_dibuka'],
        'reserved_internal' => (int) $setting['internal_terisi'],
        'reserved_eksternal' => (int) $setting['eksternal_terisi'],
    ];
}
$internalTokens = mysqli_query($conn, "SELECT t.id,t.kode_token,t.kode_unit,t.th_ajaran,t.created_at,t.used_at,t.revoked_at,u.nama_unit FROM tb_token_internal t JOIN tb_unit_pendidikan u ON u.kode_unit=t.kode_unit".$tokensUnitCondition." ORDER BY t.th_ajaran DESC,FIELD(u.jenjang,'KB','TPA','TK','SD','SMP'),t.kode_token");
$internalTokensCondition = $adminUnitCode === '' ? '' : " AND t.kode_unit='".mysqli_real_escape_string($conn, $adminUnitCode)."'";
$internalTokenPools = mysqli_query($conn, "SELECT t.kode_unit,t.th_ajaran,u.nama_unit,COUNT(*) AS total_token FROM tb_token_internal t JOIN tb_unit_pendidikan u ON u.kode_unit=t.kode_unit WHERE t.used_at IS NULL AND t.revoked_at IS NULL".$internalTokensCondition." GROUP BY t.kode_unit,t.th_ajaran,u.nama_unit ORDER BY t.th_ajaran DESC,FIELD(u.jenjang,'KB','TPA','TK','SD','SMP')");
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
                        <option value="<?php echo h($code); ?>"<?php echo (($_POST['kode_unit'] ?? '') === $code) ? ' selected' : ''; ?>><?php echo h($unit['nama_unit']); ?></option>
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
            <div class="filter-field">
                <label for="masa_pembayaran_hari">Batas pembayaran (hari)</label>
                <input id="masa_pembayaran_hari" name="masa_pembayaran_hari" type="number" min="1" max="30" value="<?php echo h($_POST['masa_pembayaran_hari'] ?? '7'); ?>" required>
            </div>
            <div class="filter-field field-span-2">
                <label for="biaya_pendaftaran">Biaya pendaftaran (Rp)</label>
                <input id="biaya_pendaftaran" name="biaya_pendaftaran" inputmode="decimal" placeholder="Contoh: 250000 — kosongkan bila tidak dipungut" value="<?php echo h($_POST['biaya_pendaftaran'] ?? ''); ?>" required>
            </div>
            <div class="filter-field">
                <label for="bank_nama">Bank atau e-wallet</label>
                <input id="bank_nama" name="bank_nama" maxlength="80" placeholder="Contoh: BPI" value="<?php echo h($_POST['bank_nama'] ?? ''); ?>">
            </div>
            <div class="filter-field">
                <label for="nomor_rekening">Nomor rekening</label>
                <input id="nomor_rekening" name="nomor_rekening" maxlength="50" value="<?php echo h($_POST['nomor_rekening'] ?? ''); ?>">
            </div>
            <div class="filter-field field-span-2">
                <label for="nama_pemilik_rekening">Nama pemilik rekening</label>
                <input id="nama_pemilik_rekening" name="nama_pemilik_rekening" maxlength="120" value="<?php echo h($_POST['nama_pemilik_rekening'] ?? ''); ?>">
            </div>
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

    <section class="panel quota-current-panel">
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
                    <?php foreach ($units as $code => $unit) { ?><option value="<?php echo h($code); ?>"><?php echo h($unit['nama_unit']); ?></option><?php } ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="token-year">Tahun ajaran</label>
                <select id="token-year" name="token_year" required>
                    <option value="">Pilih tahun ajaran</option>
                    <?php foreach ($academicYears as $year) { ?><option value="<?php echo h($year); ?>"><?php echo h($year); ?></option><?php } ?>
                </select>
            </div>
            <div class="quota-form-footer">
                <span>Tautan bersama: <a href="<?php echo h($internalEntryUrl); ?>" target="_blank" rel="noopener"><?php echo h($internalEntryUrl); ?></a>. Berikan satu kode kepada satu SDM.</span>
                <button class="button-primary" type="submit">Buat token hingga 150</button>
            </div>
        </form>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>UNIT</th><th>TAHUN AJARAN</th><th>TOKEN AKTIF</th><th>PDF</th></tr></thead>
                <tbody>
                <?php if (mysqli_num_rows($internalTokenPools) === 0) { ?><tr><td colspan="4" class="empty-state">Belum ada token aktif yang dapat diekspor.</td></tr><?php } ?>
                <?php while ($pool = mysqli_fetch_assoc($internalTokenPools)) { ?>
                    <tr>
                        <td data-label="Unit" class="primary-cell"><?php echo h($pool['nama_unit']); ?></td>
                        <td data-label="Tahun ajaran"><?php echo h($pool['th_ajaran']); ?></td>
                        <td data-label="Token aktif"><?php echo (int) $pool['total_token']; ?></td>
                        <td data-label="PDF"><a class="button-secondary" href="export-token-internal.php?unit=<?php echo rawurlencode($pool['kode_unit']); ?>&amp;tahun=<?php echo rawurlencode($pool['th_ajaran']); ?>">Unduh PDF token unit</a></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>KODE TOKEN</th><th>UNIT / TAHUN</th><th>DIBUAT</th><th>STATUS</th><th>AKSI</th></tr></thead>
                <tbody>
                <?php if (mysqli_num_rows($internalTokens) === 0) { ?><tr><td colspan="5" class="empty-state">Belum ada token internal. Pilih unit dan tahun ajaran untuk membuat pool.</td></tr><?php } ?>
                <?php while ($tokenRow = mysqli_fetch_assoc($internalTokens)) {
                    $tokenUnused = $tokenRow['used_at'] === null && $tokenRow['revoked_at'] === null;
                    $tokenStatus = $tokenRow['used_at'] !== null ? 'Sudah digunakan' : ($tokenRow['revoked_at'] !== null ? 'Dicabut' : 'Belum digunakan');
                ?>
                    <tr>
                        <td data-label="Kode token" class="primary-cell"><code><?php echo h($tokenRow['kode_token']); ?></code></td>
                        <td data-label="Unit / tahun"><?php echo h($tokenRow['nama_unit']); ?><br><?php echo h($tokenRow['th_ajaran']); ?></td>
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
                        <th scope="col">UNIT</th><th scope="col">TAHUN</th><th scope="col">KUOTA INTERNAL</th>
                        <th scope="col">KUOTA EKSTERNAL</th><th scope="col">DITERIMA</th><th scope="col">BIAYA</th>
                        <th scope="col">STATUS</th><th scope="col">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$settingsRows) { ?>
                    <tr><td class="empty-state" colspan="8">Belum ada pengaturan. Pilih unit dan tahun ajaran di atas untuk membuat konfigurasi pertama.</td></tr>
                <?php } ?>
                <?php foreach ($settingsRows as $row) {
                ?>
                    <tr>
                        <td data-label="Unit" class="primary-cell"><?php echo h($row['nama_unit']); ?></td>
                        <td data-label="Tahun"><?php echo h($row['th_ajaran']); ?></td>
                        <td data-label="Kuota internal"><?php echo (int) $row['internal_terisi']; ?> / <?php echo (int) $row['kuota_internal']; ?> terisi<br><small><?php echo (int) $row['internal_diterima']; ?> diterima · <?php echo (int) $row['internal_token_belum_digunakan']; ?> token belum digunakan · <?php echo $row['internal_dibuka'] ? 'dibuka' : 'ditutup'; ?></small></td>
                        <td data-label="Kuota eksternal"><?php echo (int) $row['eksternal_terisi']; ?> / <?php echo (int) $row['kuota_eksternal']; ?> terisi<br><small><?php echo (int) $row['eksternal_diterima']; ?> diterima · <?php echo $row['eksternal_dibuka'] ? 'dibuka' : 'ditutup'; ?></small></td>
                        <td data-label="Diterima"><?php echo (int) $row['internal_diterima'] + (int) $row['eksternal_diterima']; ?></td>
                        <td data-label="Biaya"><?php echo (float) $row['biaya_pendaftaran'] > 0 ? 'Rp '.h(number_format((float) $row['biaya_pendaftaran'], 0, ',', '.')) : 'Gratis'; ?></td>
                        <td data-label="Status"><span class="status-pill <?php echo $row['pendaftaran_dibuka'] ? 'is-open' : 'is-closed'; ?>"><?php echo $row['pendaftaran_dibuka'] ? 'Dibuka' : 'Ditutup'; ?></span></td>
                        <td data-label="Aksi"><button class="button-secondary quota-edit-button" type="button" data-unit="<?php echo h($row['kode_unit']); ?>" data-year="<?php echo h($row['th_ajaran']); ?>">Ubah</button></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<script>window.PPDB_QUOTA_SETTINGS = <?php echo json_encode($settingsByKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="js/pengaturan.js"></script>
<?php require 'admin_footer.php'; ?>