<?php
require_once 'app_helpers.php';
require_admin();
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';

// Angka(Method, 0/1)
$totalRegistrations = (int) mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS total FROM tb_pendaftaran'))['total'];

/**
 * Sebaran pendaftar per tahap alur. Dipakai untuk statistik, antrean kerja,
 * dan diagram batang tanpa pustaka grafik.
 */
$funnelQuery = mysqli_query($conn, 'SELECT status_ppdb, COUNT(*) AS total FROM tb_pendaftaran GROUP BY status_ppdb');
$funnelCounts = [];
$totalFunnel = 0;
while ($row = mysqli_fetch_assoc($funnelQuery)) {
    $funnelCounts[$row['status_ppdb']] = (int) $row['total'];
    $totalFunnel += (int) $row['total'];
}

$funnelStages = [
    ['key' => 'menunggu_kontak', 'label' => 'Menunggu dihubungi', 'hint' => 'Kirim tautan pembayaran'],
    ['key' => 'menunggu_pembayaran', 'label' => 'Menunggu bayar', 'hint' => 'Menunggu bukti transfer'],
    ['key' => 'pembayaran_diperiksa', 'label' => 'Bukti masuk', 'hint' => 'Periksa bukti transfer'],
    ['key' => 'pembayaran_terverifikasi', 'label' => 'Bayar disetujui', 'hint' => 'Siapkan tautan formulir'],
    ['key' => 'menunggu_formulir', 'label' => 'Menunggu formulir', 'hint' => 'Menunggu isian lengkap'],
    ['key' => 'formulir_terisi', 'label' => 'Formulir masuk', 'hint' => 'Periksa dan putuskan'],
];
$funnelBars = [];
$funnelMax = 1;
foreach ($funnelStages as $stage) {
    $count = $funnelCounts[$stage['key']] ?? 0;
    $funnelMax = max($funnelMax, $count);
    $funnelBars[] = $stage + ['count' => $count];
}
$actionableTotal = 0;
foreach (['menunggu_kontak', 'pembayaran_diperiksa', 'pembayaran_terverifikasi', 'formulir_terisi'] as $key) {
    $actionableTotal += $funnelCounts[$key] ?? 0;
}

$acceptedCount = (int) ($funnelCounts['diterima'] ?? 0);
$waitlistCount = (int) ($funnelCounts['daftar_tunggu'] ?? 0);
$unmappedRegistrations = (int) mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS total FROM tb_pendaftaran WHERE kode_unit IS NULL'))['total'];
$unitCount = (int) mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS total FROM tb_unit_pendidikan'))['total'];
$activeYears = (int) mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(DISTINCT th_ajaran) AS total FROM tb_pendaftaran'))['total'];
$documentCount = (int) mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS total FROM tb_dokumen_peserta'))['total'];

// Pengisian kuota per unit agar admin tahu realisasi penerimaan.
$quotaQuery = mysqli_query($conn, 'SELECT u.kode_unit,u.nama_unit,u.jenjang,q.kuota,q.pendaftaran_dibuka,q.th_ajaran,(SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=u.kode_unit AND p.th_ajaran=q.th_ajaran AND p.status_ppdb NOT IN (\'ditolak\',\'data_lama\')) AS terisi,(SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=u.kode_unit AND p.th_ajaran=q.th_ajaran AND p.status_ppdb=\'diterima\') AS diterima FROM tb_unit_pendidikan u LEFT JOIN tb_pengaturan_ppdb q ON q.kode_unit=u.kode_unit ORDER BY FIELD(u.jenjang,\'TK\',\'SD\',\'SMP\'),q.th_ajaran DESC');
$quotaRows = [];
while ($row = mysqli_fetch_assoc($quotaQuery)) {
    $row['kuota'] = (int) $row['kuota'];
    $row['terisi'] = (int) $row['terisi'];
    $row['diterima'] = (int) $row['diterima'];
    $row['persentase'] = $row['kuota'] > 0 ? min(100, (int) round($row['diterima'] / $row['kuota'] * 100)) : 0;
    $quotaRows[] = $row;
}

$syncCounts = ['pending' => 0, 'failed' => 0, 'synced' => 0];
$syncStatusResult = mysqli_query($conn, 'SELECT status, COUNT(*) AS total FROM tb_google_sync_outbox GROUP BY status');
while ($syncStatus = mysqli_fetch_assoc($syncStatusResult)) {
    $syncCounts[$syncStatus['status']] = (int) $syncStatus['total'];
}
$lastSyncFailure = mysqli_fetch_assoc(mysqli_query($conn, "SELECT last_error FROM tb_google_sync_outbox WHERE status = 'failed' AND last_error IS NOT NULL ORDER BY last_attempt_at DESC LIMIT 1"))['last_error'] ?? '';
$credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
$sheetsCredentialsReady = $credentialsPath && is_file($credentialsPath);
$syncFlash = $_SESSION['google_sheets_sync_result'] ?? null;
unset($_SESSION['google_sheets_sync_result']);

$latestRegistrations = mysqli_query($conn, 'SELECT p.id_pendaftaran,p.nm_peserta,p.th_ajaran,p.tgl_daftar,p.status_ppdb,u.nama_unit FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON p.kode_unit=u.kode_unit ORDER BY p.tgl_daftar DESC, p.id_pendaftaran DESC LIMIT 8');
$attentionQuery = mysqli_query($conn, "SELECT p.id_pendaftaran,p.nm_peserta,p.status_ppdb,p.tgl_daftar,p.waktu_daftar,u.nama_unit FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON p.kode_unit=u.kode_unit WHERE p.status_ppdb IN ('menunggu_kontak','pembayaran_diperiksa','pembayaran_terverifikasi','formulir_terisi') ORDER BY FIELD(p.status_ppdb,'menunggu_kontak','pembayaran_diperiksa','pembayaran_terverifikasi','formulir_terisi'), p.waktu_daftar ASC LIMIT 8");

$activeAdminPage = 'dashboard';
$adminPageTitle = 'Dashboard';
$adminPageDescription = 'Ringkasan antrean, kapasitas unit, dan performa penerimaan peserta didik baru.';
require 'admin_header.php';
?>
<div class="admin-content">
    <?php if (isset($_GET['admin_created'])) { ?><div class="alert-success" role="status">Akun admin baru berhasil dibuat.</div><?php } ?>
    <?php if ($syncFlash) { ?><div class="<?php echo $syncFlash['failed'] > 0 ? 'alert-error' : 'alert-success'; ?>" role="status">Sinkronisasi selesai: <?php echo (int) $syncFlash['synced']; ?> baris berhasil, <?php echo (int) $syncFlash['failed']; ?> baris perlu dicoba ulang.</div><?php } ?>

    <header class="page-hero">
        <div class="page-hero__text">
            <p class="eyebrow">Penerimaan peserta didik baru</p>
            <h1>Selamat datang kembali, <?php echo h(explode(' ', (string) ($_SESSION['admin_name'] ?? 'Administrator'))[0]); ?>.</h1>
            <p><?php echo $actionableTotal > 0
                ? $actionableTotal.' peserta menunggu tindakan Anda. Mulai dari data yang paling lama menunggu.'
                : 'Tidak ada peserta yang menunggu tindakan saat ini. Semua alur berjalan lancar.'; ?></p>
        </div>
        <div class="page-hero__actions">
            <?php if ($actionableTotal > 0) { ?>
                <a class="button-primary" href="daftar_peserta.php?status=menunggu_kontak">Proses antrean<span aria-hidden="true">&rarr;</span></a>
            <?php } else { ?>
                <a class="button-secondary" href="daftar_peserta.php">Lihat semua peserta</a>
            <?php } ?>
        </div>
    </header>

    <section class="stats-grid" aria-label="Statistik utama">
        <article class="stat-card is-primary">
            <span class="stat-label">Total pendaftar</span>
            <strong class="stat-value"><?php echo $totalRegistrations; ?></strong>
            <span class="stat-note">Seluruh tahun ajaran</span>
        </article>
        <article class="stat-card<?php echo $actionableTotal > 0 ? ' is-alert' : ''; ?>">
            <span class="stat-label">Menunggu tindakan</span>
            <strong class="stat-value"><?php echo $actionableTotal; ?></strong>
            <span class="stat-note">Perlu diproses admin</span>
        </article>
        <article class="stat-card is-success">
            <span class="stat-label">Diterima</span>
            <strong class="stat-value"><?php echo $acceptedCount; ?></strong>
            <span class="stat-note">Peserta lolos penerimaan</span>
        </article>
        <article class="stat-card">
            <span class="stat-label">Daftar tunggu</span>
            <strong class="stat-value"><?php echo $waitlistCount; ?></strong>
            <span class="stat-note">Menunggu kursi kosong</span>
        </article>
    </section>

    <div class="dashboard-grid">
        <section class="panel">
            <div class="panel-header">
                <div><h2>Antrean kerja</h2><span class="panel-subtitle">Peserta yang menunggu tindakan Anda</span></div>
                <?php if ($actionableTotal > 0) { ?><span class="counter-badge"><?php echo $actionableTotal; ?> pending</span><?php } ?>
            </div>
            <div class="panel-body">
                <?php if (mysqli_num_rows($attentionQuery) === 0) { ?>
                    <div class="empty-state">
                        <span class="empty-state__icon"><?php echo ppdb_admin_icon('check'); ?></span>
                        <strong>Antrean kosong</strong>
                        <p>Semua peserta sudah diproses sampai keputusan akhir.</p>
                    </div>
                <?php } else { ?>
                    <ul class="queue-list">
                        <?php while ($row = mysqli_fetch_assoc($attentionQuery)) {
                            $meta = ppdb_admin_status_meta($row['status_ppdb']);
                        ?>
                            <li class="queue-item">
                                <span class="queue-dot tone-<?php echo h($meta['tone']); ?>" aria-hidden="true"></span>
                                <div class="queue-main">
                                    <a href="detail-peserta.php?id=<?php echo rawurlencode($row['id_pendaftaran']); ?>"><?php echo h($row['nm_peserta']); ?></a>
                                    <small><?php echo h($row['nama_unit'] ?? 'Data lama'); ?> · <?php echo h($row['id_pendaftaran']); ?></small>
                                </div>
                                <span class="status-pill tone-<?php echo h($meta['tone']); ?>"><?php echo h($meta['label']); ?></span>
                                <span class="queue-age"><?php echo h(date('d-m-Y', strtotime($row['tgl_daftar']))); ?></span>
                            </li>
                        <?php } ?>
                    </ul>
                <?php } ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div><h2>Sebaran alur pendaftaran</h2><span class="panel-subtitle">Jumlah peserta per tahap</span></div>
            </div>
            <div class="panel-body">
                <?php if ($totalFunnel === 0) { ?>
                    <p class="panel-note">Belum ada data untuk ditampilkan.</p>
                <?php } else { ?>
                    <ul class="funnel-list">
                        <?php foreach ($funnelBars as $bar) { ?>
                            <li class="funnel-row<?php echo $bar['count'] > 0 ? '' : ' is-empty'; ?>">
                                <div class="funnel-row__head">
                                    <span><?php echo h($bar['label']); ?></span>
                                    <strong><?php echo $bar['count']; ?></strong>
                                </div>
                                <div class="funnel-track">
                                    <span class="funnel-bar" style="--w: <?php echo $bar['count'] > 0 ? max(4, (int) round($bar['count'] / $funnelMax * 100)) : 0; ?>%"></span>
                                </div>
                            </li>
                        <?php } ?>
                    </ul>
                <?php } ?>
            </div>
        </section>
    </div>

    <section class="panel">
        <div class="panel-header">
            <div><h2>Kapasitas per unit pendidikan</h2><span class="panel-subtitle">Realisasi penerimaan terhadap kuota yang ditetapkan</span></div>
            <a class="panel-link" href="pengaturan-ppdb.php">Atur kuota &rarr;</a>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>UNIT</th><th>JENJANG</th><th>TAHUN</th><th>REALISASI</th><th>TERISI</th><th>DITERIMA</th><th>STATUS</th></tr></thead>
                <tbody>
                <?php if (!$quotaRows) { ?>
                    <tr><td class="empty-state" colspan="7">Belum ada unit pendidikan.</td></tr>
                <?php } ?>
                <?php foreach ($quotaRows as $row) { ?>
                    <tr>
                        <td class="primary-cell"><?php echo h($row['nama_unit']); ?></td>
                        <td><span class="jenjang-chip"><?php echo h($row['jenjang']); ?></span></td>
                        <td><?php echo h($row['th_ajaran'] ?? '—'); ?></td>
                        <td>
                            <div class="quota-cell">
                                <div class="quota-track"><span class="quota-bar" style="--w: <?php echo $row['persentase']; ?>%"></span></div>
                                <small><?php echo $row['persentase']; ?>%</small>
                            </div>
                        </td>
                        <td><?php echo $row['terisi']; ?> / <?php echo $row['kuota']; ?></td>
                        <td><?php echo $row['diterima']; ?></td>
                        <td><span class="status-pill <?php echo $row['pendaftaran_dibuka'] ? 'is-open' : 'is-closed'; ?>"><?php echo $row['pendaftaran_dibuka'] ? 'Dibuka' : 'Ditutup'; ?></span></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="dashboard-grid">
        <section class="panel">
            <div class="panel-header">
                <div><h2>Pendaftaran terbaru</h2><span class="panel-subtitle">8 pendaftar terakhir</span></div>
                <a class="panel-link" href="daftar_peserta.php">Semua peserta &rarr;</a>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>KODE</th><th>NAMA</th><th>UNIT</th><th>STATUS</th><th></th></tr></thead>
                    <tbody>
                    <?php if (mysqli_num_rows($latestRegistrations) === 0) { ?>
                        <tr><td class="empty-state" colspan="5">Belum ada pendaftaran.</td></tr>
                    <?php } else { while ($row = mysqli_fetch_assoc($latestRegistrations)) {
                        $meta = ppdb_admin_status_meta($row['status_ppdb']); ?>
                        <tr>
                            <td><code class="code-chip"><?php echo h($row['id_pendaftaran']); ?></code></td>
                            <td class="primary-cell"><?php echo h($row['nm_peserta']); ?></td>
                            <td><?php echo h($row['nama_unit'] ?? 'Data lama'); ?></td>
                            <td><span class="status-pill tone-<?php echo h($meta['tone']); ?>"><?php echo h($meta['label']); ?></span></td>
                            <td><a class="table-action" href="detail-peserta.php?id=<?php echo rawurlencode($row['id_pendaftaran']); ?>">Detail</a></td>
                        </tr>
                    <?php }} ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel spreadsheet-panel">
            <div class="panel-header">
                <div><h2>Sinkronisasi Google Sheets</h2><span class="panel-subtitle">Data Dapodik ke spreadsheet</span></div>
                <span class="status-pill <?php echo $sheetsCredentialsReady ? 'is-open' : 'is-closed'; ?>"><?php echo $sheetsCredentialsReady ? 'Aktif' : 'Nonaktif'; ?></span>
            </div>
            <div class="panel-body sheet-panel-body">
                <p>Seluruh data formulir dikirim ke tab <strong>Data Peserta Didik</strong>.</p>
                <?php if ($lastSyncFailure !== '') { ?><p class="sheet-sync-error" role="status">Status: <?php echo h($lastSyncFailure); ?></p><?php } ?>
                <dl class="sync-stats">
                    <div><dt>Tersinkron</dt><dd><?php echo $syncCounts['synced']; ?></dd></div>
                    <div><dt>Antre</dt><dd><?php echo $syncCounts['pending']; ?></dd></div>
                    <div><dt>Gagal</dt><dd><?php echo $syncCounts['failed']; ?></dd></div>
                </dl>
                <div class="sheet-actions">
                    <a class="button-secondary" href="<?php echo h(google_sheets_spreadsheet_url()); ?>" target="_blank" rel="noopener noreferrer">Buka Sheets <span aria-hidden="true">↗</span></a>
                    <form action="sinkronisasi-spreadsheet.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                        <button class="button-primary" type="submit">Sinkronkan antrean</button>
                    </form>
                </div>
            </div>
        </section>
    </div>

    <section class="panel panel-muted">
        <div class="panel-body admin-facts">
            <div><span>Dokumen peserta</span><strong><?php echo $documentCount; ?></strong><small>berkas tersimpan</small></div>
            <div><span>Unit pendidikan</span><strong><?php echo $unitCount; ?></strong><small>TK, SD, dan SMP</small></div>
            <div><span>Tahun ajaran</span><strong><?php echo $activeYears; ?></strong><small>tercatat di arsip</small></div>
            <div><span>Data belum dipetakan</span><strong><?php echo $unmappedRegistrations; ?></strong><small>tanpa unit</small></div>
        </div>
    </section>
</div>
<?php require 'admin_footer.php'; ?>
