<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_admin();

$error = '';
$notice = '';
$academicYears = [];
for ($year = 2026; $year <= 2040; $year++) {
    $academicYears[] = $year.'/'.($year + 1);
}

$unitResult = mysqli_query($conn, "SELECT kode_unit,nama_unit,jenjang FROM tb_unit_pendidikan ORDER BY FIELD(jenjang,'TK','SD','SMP')");
$units = [];
while ($unit = mysqli_fetch_assoc($unitResult)) {
    $units[$unit['kode_unit']] = $unit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unitCode = $_POST['kode_unit'] ?? '';
    $academicYear = $_POST['th_ajaran'] ?? '';
    $quota = filter_var($_POST['kuota'] ?? null, FILTER_VALIDATE_INT);
    $feeInput = trim($_POST['biaya_pendaftaran'] ?? '');
    $bankName = trim($_POST['bank_nama'] ?? '');
    $accountNumber = trim($_POST['nomor_rekening'] ?? '');
    $accountName = trim($_POST['nama_pemilik_rekening'] ?? '');
    $paymentDays = filter_var($_POST['masa_pembayaran_hari'] ?? null, FILTER_VALIDATE_INT);
    $isOpen = isset($_POST['pendaftaran_dibuka']) ? 1 : 0;

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi formulir kedaluwarsa. Muat ulang halaman dan coba kembali.';
    } elseif (!isset($units[$unitCode]) || !in_array($academicYear, $academicYears, true)) {
        $error = 'Pilih unit pendidikan dan tahun ajaran yang valid.';
    } elseif ($quota === false || $quota < 0 || $quota > 10000) {
        $error = 'Kuota harus berupa angka antara 0 dan 10.000.';
    } elseif ($feeInput === '' || !preg_match('/^[0-9]{1,10}(\.[0-9]{1,2})?$/', $feeInput)) {
        $error = 'Biaya pendaftaran harus berupa angka Rupiah yang valid.';
    } elseif ($paymentDays === false || $paymentDays < 1 || $paymentDays > 30) {
        $error = 'Masa pembayaran harus antara 1 dan 30 hari.';
    } elseif (strlen($bankName) > 80 || strlen($accountNumber) > 50 || strlen($accountName) > 120) {
        $error = 'Data rekening melebihi batas karakter.';
    } elseif ((float) $feeInput > 0 && ($bankName === '' || $accountNumber === '' || $accountName === '')) {
        $error = 'Lengkapi bank, nomor rekening, dan nama pemilik sebelum menetapkan biaya.';
    } elseif ($isOpen && $quota < 1) {
        $error = 'Kuota harus lebih dari nol untuk membuka pendaftaran.';
    } else {
        $reservedQuery = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM tb_pendaftaran WHERE kode_unit = ? AND th_ajaran = ?');
        mysqli_stmt_bind_param($reservedQuery, 'ss', $unitCode, $academicYear);
        mysqli_stmt_execute($reservedQuery);
        $reserved = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($reservedQuery))['total'];
        mysqli_stmt_close($reservedQuery);

        if ($quota < $reserved) {
            $error = 'Kuota tidak dapat lebih kecil dari '.$reserved.' pendaftar yang sudah tercatat.';
        } else {
            $adminId = (int) $_SESSION['admin_id'];
            $update = mysqli_prepare($conn, 'INSERT INTO tb_pengaturan_ppdb (kode_unit,th_ajaran,kuota,biaya_pendaftaran,bank_nama,nomor_rekening,nama_pemilik_rekening,masa_pembayaran_hari,pendaftaran_dibuka,diperbarui_oleh) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE kuota=VALUES(kuota), biaya_pendaftaran=VALUES(biaya_pendaftaran), bank_nama=VALUES(bank_nama), nomor_rekening=VALUES(nomor_rekening), nama_pemilik_rekening=VALUES(nama_pemilik_rekening), masa_pembayaran_hari=VALUES(masa_pembayaran_hari), pendaftaran_dibuka=VALUES(pendaftaran_dibuka), diperbarui_oleh=VALUES(diperbarui_oleh)');
            mysqli_stmt_bind_param($update, 'ssidsssiii', $unitCode, $academicYear, $quota, $feeInput, $bankName, $accountNumber, $accountName, $paymentDays, $isOpen, $adminId);
            if (mysqli_stmt_execute($update)) {
                $notice = 'Pengaturan PPDB berhasil disimpan.';
            } else {
                $error = 'Pengaturan belum dapat disimpan.';
            }
            mysqli_stmt_close($update);
        }
    }
}

$settings = mysqli_query($conn, 'SELECT c.*,u.nama_unit,u.jenjang,(SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=c.kode_unit AND p.th_ajaran=c.th_ajaran) AS total_antrian,(SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=c.kode_unit AND p.th_ajaran=c.th_ajaran AND p.status_ppdb=\'diterima\') AS total_diterima FROM tb_pengaturan_ppdb c JOIN tb_unit_pendidikan u ON u.kode_unit=c.kode_unit ORDER BY c.th_ajaran DESC,FIELD(u.jenjang,\'TK\',\'SD\',\'SMP\')');
$settingsRows = [];
$settingsByKey = [];
while ($setting = mysqli_fetch_assoc($settings)) {
    $settingsRows[] = $setting;
    $settingsByKey[$setting['kode_unit'].'|'.$setting['th_ajaran']] = [
        'kuota' => (int) $setting['kuota'],
        'biaya_pendaftaran' => (string) $setting['biaya_pendaftaran'],
        'bank_nama' => $setting['bank_nama'],
        'nomor_rekening' => $setting['nomor_rekening'],
        'nama_pemilik_rekening' => $setting['nama_pemilik_rekening'],
        'masa_pembayaran_hari' => (int) $setting['masa_pembayaran_hari'],
        'pendaftaran_dibuka' => (int) $setting['pendaftaran_dibuka'],
        'reserved' => (int) $setting['total_antrian'],
    ];
}
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
            <p>Atur penerimaan per unit dan tahun ajaran. Pendaftaran hanya terbuka setelah konfigurasi disimpan.</p>
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
                <label for="kuota">Kuota peserta</label>
                <input id="kuota" name="kuota" type="number" min="0" max="10000" value="<?php echo h($_POST['kuota'] ?? '0'); ?>" required
                    data-reserved="<?php echo h((string) ($settingsByKey[($_POST['kode_unit'] ?? '').'|'.($_POST['th_ajaran'] ?? '')]['reserved'] ?? '0')); ?>">
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
                <input type="checkbox" name="pendaftaran_dibuka" value="1" <?php echo isset($_POST['pendaftaran_dibuka']) ? 'checked' : ''; ?>>
                <span>Buka pendaftaran untuk unit dan tahun ini</span>
            </label>
            <div class="quota-form-footer">
                <span id="quota-form-mode">Pilih unit dan tahun ajaran untuk melihat atau mengatur kuota.</span>
                <button class="button-primary" id="quota-save-button" type="submit">Simpan pengaturan</button>
            </div>
        </form>
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
                        <th scope="col">UNIT</th><th scope="col">TAHUN</th><th scope="col">REALISASI</th>
                        <th scope="col">TERISI</th><th scope="col">DITERIMA</th><th scope="col">BIAYA</th>
                        <th scope="col">STATUS</th><th scope="col">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$settingsRows) { ?>
                    <tr><td class="empty-state" colspan="8">Belum ada pengaturan. Pilih unit dan tahun ajaran di atas untuk membuat konfigurasi pertama.</td></tr>
                <?php } ?>
                <?php foreach ($settingsRows as $row) {
                    $persentase = (int) $row['kuota'] > 0
                        ? min(100, (int) round((int) $row['total_diterima'] / (int) $row['kuota'] * 100))
                        : 0;
                ?>
                    <tr>
                        <td data-label="Unit" class="primary-cell"><?php echo h($row['nama_unit']); ?></td>
                        <td data-label="Tahun"><?php echo h($row['th_ajaran']); ?></td>
                        <td data-label="Realisasi">
                            <div class="quota-cell">
                                <div class="quota-track"><span class="quota-bar" style="--w: <?php echo $persentase; ?>%"></span></div>
                                <small><?php echo $persentase; ?>%</small>
                            </div>
                        </td>
                        <td data-label="Terisi"><?php echo (int) $row['total_antrian']; ?> / <?php echo (int) $row['kuota']; ?></td>
                        <td data-label="Diterima"><?php echo (int) $row['total_diterima']; ?></td>
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