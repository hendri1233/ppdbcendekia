<?php
require_once 'app_helpers.php';
require_admin();
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
require_once 'web_stats.php';

$adminUnitCode = ppdb_is_super_admin() ? '' : (string) $_SESSION['admin_unit_code'];
$escapedUnitCode = mysqli_real_escape_string($conn, $adminUnitCode);
$registrationWhere = $adminUnitCode === '' ? '' : " WHERE kode_unit='".$escapedUnitCode."'";
$registrationScope = $adminUnitCode === '' ? '' : " AND p.kode_unit='".$escapedUnitCode."'";

// Angka(Method, 0/1)
$totalRegistrations = (int) mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS total FROM tb_pendaftaran'.$registrationWhere))['total'];

/**
 * Sebaran pendaftar per tahap alur. Dipakai untuk statistik, antrean kerja,
 * dan diagram batang tanpa pustaka grafik.
 */
$funnelQuery = mysqli_query($conn, 'SELECT status_ppdb,COUNT(*) AS total FROM tb_pendaftaran'.$registrationWhere.' GROUP BY status_ppdb');
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
$unmappedRegistrations = $adminUnitCode === '' ? (int) mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS total FROM tb_pendaftaran WHERE kode_unit IS NULL'))['total'] : 0;
$unitCount = $adminUnitCode === '' ? (int) mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS total FROM tb_unit_pendidikan'))['total'] : 1;
$activeYears = (int) mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(DISTINCT th_ajaran) AS total FROM tb_pendaftaran'.$registrationWhere))['total'];
$documentQuery = 'SELECT COUNT(*) AS total FROM tb_dokumen_peserta d JOIN tb_pendaftaran p ON p.id_pendaftaran=d.id_pendaftaran'.($adminUnitCode === '' ? '' : " WHERE p.kode_unit='".$escapedUnitCode."'");
$documentCount = (int) mysqli_fetch_assoc(mysqli_query($conn, $documentQuery))['total'];

// Pengisian kuota per unit agar admin tahu realisasi penerimaan.
$quotaQuery = mysqli_query($conn, "SELECT u.kode_unit,u.nama_unit,u.jenjang,q.kuota_internal,q.kuota_eksternal,q.internal_dibuka,q.eksternal_dibuka,q.th_ajaran,
    (SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=u.kode_unit AND p.th_ajaran=q.th_ajaran AND p.jalur_pendaftaran='internal' AND p.status_ppdb NOT IN ('ditolak','data_lama')) AS internal_terisi,
    (SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=u.kode_unit AND p.th_ajaran=q.th_ajaran AND p.jalur_pendaftaran='eksternal' AND p.status_ppdb NOT IN ('ditolak','data_lama')) AS eksternal_terisi,
    (SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=u.kode_unit AND p.th_ajaran=q.th_ajaran AND p.status_ppdb='diterima') AS diterima
    FROM tb_unit_pendidikan u LEFT JOIN tb_pengaturan_ppdb q ON q.kode_unit=u.kode_unit".($adminUnitCode === '' ? '' : " WHERE u.kode_unit='".$escapedUnitCode."'")." ORDER BY FIELD(u.jenjang,'KB','TPA','TK','SD','SMP'),q.th_ajaran DESC");
$quotaRows = [];
while ($row = mysqli_fetch_assoc($quotaQuery)) {
    $row['kuota_internal'] = (int) $row['kuota_internal'];
    $row['kuota_eksternal'] = (int) $row['kuota_eksternal'];
    $row['internal_terisi'] = (int) $row['internal_terisi'];
    $row['eksternal_terisi'] = (int) $row['eksternal_terisi'];
    $row['diterima'] = (int) $row['diterima'];
    $quotaSum = $row['kuota_internal'] + $row['kuota_eksternal'];
    $row['persentase'] = $quotaSum > 0 ? min(100, (int) round($row['diterima'] / $quotaSum * 100)) : 0;
    $quotaRows[] = $row;
}

// Pencapaian kuota unit yang sedang dibuka, per jalur yang dibuka, untuk panel capaian harian.
$quotaProgress = [];
foreach ($quotaRows as $row) {
    $routes = [];
    foreach (['internal' => 'Internal', 'eksternal' => 'Eksternal'] as $route => $label) {
        if ($row[$route.'_dibuka'] && $row['kuota_'.$route] > 0) {
            $routes[] = ['key' => $route, 'label' => $label, 'filled' => $row[$route.'_terisi'], 'quota' => $row['kuota_'.$route]];
        }
    }
    if (!$routes) {
        continue;
    }
    $filled = array_sum(array_column($routes, 'filled'));
    $quota = array_sum(array_column($routes, 'quota'));
    $quotaProgress[] = [
        'code' => $row['kode_unit'],
        'jenjang' => $row['jenjang'],
        'name' => ppdb_unit_display_name($row['nama_unit']),
        'year' => $row['th_ajaran'],
        'routes' => $routes,
        'filled' => $filled,
        'quota' => $quota,
        'accepted' => $row['diterima'],
        'percent' => min(100, (int) round($filled / $quota * 100)),
    ];
}

// Akses tautan pendaftaran dan sebaran tahap per unit untuk grafik di bagian bawah.
$unitStageKeys = array_merge(array_column($funnelStages, 'key'), ['diterima', 'daftar_tunggu', 'ditolak']);
$unitLinkVisitors = ppdb_unit_link_visitors($conn);
$unitStageRows = [];
$unitListQuery = mysqli_query($conn, 'SELECT kode_unit,nama_unit,jenjang FROM tb_unit_pendidikan'.($adminUnitCode === '' ? '' : " WHERE kode_unit='".$escapedUnitCode."'")." ORDER BY FIELD(jenjang,'KB','TPA','TK','SD','SMP')");
while ($row = mysqli_fetch_assoc($unitListQuery)) {
    $unitStageRows[$row['kode_unit']] = $row + [
        'visitors' => $unitLinkVisitors[$row['kode_unit']] ?? 0,
        'registrants' => 0,
        'stages' => array_fill_keys($unitStageKeys, 0),
    ];
}
$unitStageQuery = mysqli_query($conn, "SELECT kode_unit,status_ppdb,COUNT(*) AS total FROM tb_pendaftaran WHERE kode_unit IS NOT NULL AND status_ppdb<>'data_lama'".($adminUnitCode === '' ? '' : " AND kode_unit='".$escapedUnitCode."'").' GROUP BY kode_unit,status_ppdb');
while ($row = mysqli_fetch_assoc($unitStageQuery)) {
    if (!isset($unitStageRows[$row['kode_unit']])) {
        continue;
    }
    $unitStageRows[$row['kode_unit']]['registrants'] += (int) $row['total'];
    if (isset($unitStageRows[$row['kode_unit']]['stages'][$row['status_ppdb']])) {
        $unitStageRows[$row['kode_unit']]['stages'][$row['status_ppdb']] = (int) $row['total'];
    }
}
$dailyProgress = ppdb_unit_daily_progress($conn, $adminUnitCode, 30);

// Grafik rantai: kursi terisi kumulatif per hari untuk setiap jalur unit yang dibuka.
// Pendaftar sebelum rentang menjadi angka awal sehingga titik terakhir = kursi terisi sekarang.
$chainStart = $dailyProgress[0]['date'];
$chainUnits = [];
foreach ($quotaProgress as $unit) {
    $perDay = ['internal' => [], 'eksternal' => []];
    $before = ['internal' => 0, 'eksternal' => 0];
    $chainQuery = mysqli_prepare($conn, "SELECT jalur_pendaftaran,tgl_daftar,COUNT(*) AS total FROM tb_pendaftaran WHERE kode_unit=? AND th_ajaran=? AND status_ppdb NOT IN ('ditolak','data_lama') GROUP BY jalur_pendaftaran,tgl_daftar");
    mysqli_stmt_bind_param($chainQuery, 'ss', $unit['code'], $unit['year']);
    mysqli_stmt_execute($chainQuery);
    $chainResult = mysqli_stmt_get_result($chainQuery);
    while ($row = mysqli_fetch_assoc($chainResult)) {
        $route = $row['jalur_pendaftaran'] === 'internal' ? 'internal' : 'eksternal';
        if ($row['tgl_daftar'] === null || $row['tgl_daftar'] < $chainStart) {
            $before[$route] += (int) $row['total'];
        } else {
            $perDay[$route][$row['tgl_daftar']] = ($perDay[$route][$row['tgl_daftar']] ?? 0) + (int) $row['total'];
        }
    }
    mysqli_stmt_close($chainQuery);

    $routes = [];
    foreach ($unit['routes'] as $route) {
        $running = $before[$route['key']];
        $points = [];
        foreach ($dailyProgress as $day) {
            $added = $perDay[$route['key']][$day['date']] ?? 0;
            $running += $added;
            $points[] = ['filled' => $running, 'added' => $added];
        }
        $routes[] = $route + ['points' => $points];
    }
    $chainUnits[] = ['name' => $unit['name'], 'routes' => $routes];
}

// Gabungan semua unit (KB sampai SMP) untuk super admin: kuota dan kursi terisi dijumlahkan per jalur.
if ($adminUnitCode === '' && $quotaProgress) {
    $combinedRoutes = [];
    foreach (['internal' => 'Internal', 'eksternal' => 'Eksternal'] as $routeKey => $routeLabel) {
        $quota = 0;
        $filled = 0;
        $points = array_fill(0, count($dailyProgress), ['filled' => 0, 'added' => 0]);
        foreach ($chainUnits as $chainUnit) {
            foreach ($chainUnit['routes'] as $route) {
                if ($route['key'] !== $routeKey) {
                    continue;
                }
                $quota += $route['quota'];
                $filled += $route['filled'];
                foreach ($route['points'] as $index => $point) {
                    $points[$index]['filled'] += $point['filled'];
                    $points[$index]['added'] += $point['added'];
                }
            }
        }
        if ($quota > 0) {
            $combinedRoutes[] = ['key' => $routeKey, 'label' => $routeLabel, 'filled' => $filled, 'quota' => $quota, 'points' => $points];
        }
    }
    $combinedFilled = array_sum(array_column($combinedRoutes, 'filled'));
    $combinedQuota = array_sum(array_column($combinedRoutes, 'quota'));
    $quotaProgress[] = [
        'code' => '',
        'combined' => true,
        'name' => 'Gabungan semua unit (KB–SMP)',
        'year' => implode(', ', array_unique(array_column($quotaProgress, 'year'))),
        'units' => array_column($quotaProgress, 'name'),
        'routes' => array_map(static fn (array $route): array => array_diff_key($route, ['points' => true]), $combinedRoutes),
        'filled' => $combinedFilled,
        'quota' => $combinedQuota,
        'accepted' => array_sum(array_column($quotaProgress, 'accepted')),
        'percent' => min(100, (int) round($combinedFilled / max(1, $combinedQuota) * 100)),
    ];
    // Grafik gabungan: satu kelompok batang per unit (urut KB sampai SMP), tanpa tanggal.
    $combinedBars = [];
    foreach ($quotaProgress as $unit) {
        if (!empty($unit['combined'])) {
            continue;
        }
        $bar = ['label' => $unit['jenjang'], 'name' => $unit['name'], 'filled' => $unit['filled'], 'quota' => $unit['quota']];
        foreach (['internal', 'eksternal'] as $routeKey) {
            $bar[$routeKey] = ['filled' => 0, 'quota' => 0];
            foreach ($unit['routes'] as $route) {
                if ($route['key'] === $routeKey) {
                    $bar[$routeKey] = ['filled' => $route['filled'], 'quota' => $route['quota']];
                }
            }
        }
        $combinedBars[] = $bar;
    }
    $chainUnits[] = ['name' => 'Gabungan semua unit (KB–SMP)', 'routes' => $combinedRoutes, 'bars' => $combinedBars];
}
$linkMapDays = 30;
$linkMap = ppdb_unit_link_locations($conn, $adminUnitCode, $linkMapDays);
$geoipReady = ppdb_geoip_ready();
$unitVisitorMax = 1;
$unitStageMax = 1;
foreach ($unitStageRows as $row) {
    $unitVisitorMax = max($unitVisitorMax, $row['visitors']);
    $unitStageMax = max($unitStageMax, ...array_values($row['stages']));
}

$syncCounts = ['pending' => 0, 'failed' => 0, 'synced' => 0];
$lastSyncFailure = '';
$sheetsCredentialsReady = false;
$syncFlash = null;
if ($adminUnitCode === '') {
    $syncStatusResult = mysqli_query($conn, 'SELECT status,COUNT(*) AS total FROM tb_google_sync_outbox GROUP BY status');
    while ($syncStatus = mysqli_fetch_assoc($syncStatusResult)) {
        $syncCounts[$syncStatus['status']] = (int) $syncStatus['total'];
    }
    $lastSyncFailure = mysqli_fetch_assoc(mysqli_query($conn, "SELECT last_error FROM tb_google_sync_outbox WHERE status='failed' AND last_error IS NOT NULL ORDER BY last_attempt_at DESC LIMIT 1"))['last_error'] ?? '';
    $credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
    $sheetsCredentialsReady = $credentialsPath && is_file($credentialsPath);
    $syncFlash = $_SESSION['google_sheets_sync_result'] ?? null;
    unset($_SESSION['google_sheets_sync_result']);
}

$latestSql = 'SELECT p.id_pendaftaran,p.nm_peserta,p.th_ajaran,p.tgl_daftar,p.status_ppdb,u.nama_unit FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON p.kode_unit=u.kode_unit'.($adminUnitCode === '' ? '' : " WHERE p.kode_unit='".$escapedUnitCode."'").' ORDER BY p.tgl_daftar DESC,p.id_pendaftaran DESC LIMIT 8';
$latestRegistrations = mysqli_query($conn, $latestSql);
$attentionSql = "SELECT p.id_pendaftaran,p.nm_peserta,p.status_ppdb,p.tgl_daftar,p.waktu_daftar,u.nama_unit FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON p.kode_unit=u.kode_unit WHERE p.status_ppdb IN ('menunggu_kontak','pembayaran_diperiksa','pembayaran_terverifikasi','formulir_terisi')".$registrationScope." ORDER BY FIELD(p.status_ppdb,'menunggu_kontak','pembayaran_diperiksa','pembayaran_terverifikasi','formulir_terisi'),p.waktu_daftar ASC LIMIT 8";
$attentionQuery = mysqli_query($conn, $attentionSql);

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
            <p class="eyebrow"><?php echo $adminUnitCode === '' ? 'Penerimaan peserta didik baru' : 'Penerimaan · '.h(ppdb_unit_display_name($_SESSION['admin_unit_name'] ?? 'Unit')); ?></p>
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
                                    <small><?php echo h(ppdb_unit_display_name($row['nama_unit'] ?? 'Data lama')); ?> · <?php echo h($row['id_pendaftaran']); ?></small>
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

    <?php if ($adminUnitCode === '') { ?><section class="panel">
        <div class="panel-header">
            <div><h2>Kapasitas per unit pendidikan</h2><span class="panel-subtitle">Realisasi penerimaan terhadap kuota yang ditetapkan</span></div>
            <a class="panel-link" href="pengaturan-ppdb.php">Atur kuota &rarr;</a>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th scope="col">Unit</th><th scope="col">Jenjang</th><th scope="col">Tahun</th><th scope="col">Realisasi</th><th scope="col">Internal</th><th scope="col">Eksternal</th><th scope="col">Diterima</th><th scope="col">Status</th></tr></thead>
                <tbody>
                <?php if (!$quotaRows) { ?>
                    <tr><td class="empty-state" colspan="8">Belum ada unit pendidikan.</td></tr>
                <?php } ?>
                <?php foreach ($quotaRows as $row) { ?>
                    <tr>
                        <td class="primary-cell" data-label="Unit"><?php echo h(ppdb_unit_display_name($row['nama_unit'])); ?></td>
                        <td data-label="Jenjang"><span class="jenjang-chip"><?php echo h($row['jenjang']); ?></span></td>
                        <td data-label="Tahun"><?php echo h($row['th_ajaran'] ?? '—'); ?></td>
                        <td data-label="Realisasi">
                            <div class="quota-cell">
                                <div class="quota-track"><span class="quota-bar" style="--w: <?php echo $row['persentase']; ?>%"></span></div>
                                <small><?php echo $row['persentase']; ?>%</small>
                            </div>
                        </td>
                        <td data-label="Internal"><span><?php echo $row['internal_terisi']; ?> / <?php echo $row['kuota_internal']; ?> <small class="cell-sub"><?php echo $row['internal_dibuka'] ? 'Dibuka' : 'Ditutup'; ?></small></span></td>
                        <td data-label="Eksternal"><span><?php echo $row['eksternal_terisi']; ?> / <?php echo $row['kuota_eksternal']; ?> <small class="cell-sub"><?php echo $row['eksternal_dibuka'] ? 'Dibuka' : 'Ditutup'; ?></small></span></td>
                        <td data-label="Diterima"><?php echo $row['diterima']; ?></td>
                        <td data-label="Status"><span class="status-pill <?php echo $row['internal_dibuka'] || $row['eksternal_dibuka'] ? 'is-open' : 'is-closed'; ?>"><?php echo $row['internal_dibuka'] || $row['eksternal_dibuka'] ? 'Ada jalur dibuka' : 'Ditutup'; ?></span></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section><?php } ?>

    <div class="dashboard-grid">
        <section class="panel">
            <div class="panel-header">
                <div><h2>Pendaftaran terbaru</h2><span class="panel-subtitle">8 pendaftar terakhir</span></div>
                <a class="panel-link" href="daftar_peserta.php">Semua peserta &rarr;</a>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th scope="col">Kode</th><th scope="col">Nama</th><th scope="col">Unit</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Aksi</span></th></tr></thead>
                    <tbody>
                    <?php if (mysqli_num_rows($latestRegistrations) === 0) { ?>
                        <tr><td class="empty-state" colspan="5">Belum ada pendaftaran.</td></tr>
                    <?php } else { while ($row = mysqli_fetch_assoc($latestRegistrations)) {
                        $meta = ppdb_admin_status_meta($row['status_ppdb']); ?>
                        <tr>
                            <td data-label="Kode"><code class="code-chip"><?php echo h($row['id_pendaftaran']); ?></code></td>
                            <td class="primary-cell" data-label="Nama"><?php echo h($row['nm_peserta']); ?></td>
                            <td data-label="Unit"><?php echo h(ppdb_unit_display_name($row['nama_unit'] ?? 'Data lama')); ?></td>
                            <td data-label="Status"><span class="status-pill tone-<?php echo h($meta['tone']); ?>"><?php echo h($meta['label']); ?></span></td>
                            <td data-label="Aksi"><a class="table-action" href="detail-peserta.php?id=<?php echo rawurlencode($row['id_pendaftaran']); ?>">Detail<span class="visually-hidden"> <?php echo h($row['nm_peserta']); ?></span></a></td>
                        </tr>
                    <?php }} ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($adminUnitCode === '') { ?><section class="panel spreadsheet-panel">
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
        </section><?php } ?>
    </div>

    <section class="panel panel-muted">
        <div class="panel-body admin-facts">
            <div><span>Dokumen peserta</span><strong><?php echo $documentCount; ?></strong><small>berkas tersimpan</small></div>
            <div><span>Unit pendidikan</span><strong><?php echo $unitCount; ?></strong><small>KB, TPA, TK, SD, SMP</small></div>
            <div><span>Tahun ajaran</span><strong><?php echo $activeYears; ?></strong><small>tercatat di arsip</small></div>
            <div><span>Data belum dipetakan</span><strong><?php echo $unmappedRegistrations; ?></strong><small>tanpa unit</small></div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div><h2>Akses tautan &amp; tahapan per unit</h2><span class="panel-subtitle">Pengunjung berbeda yang membuka tautan pendaftaran unit, dan posisi pendaftar di setiap tahap</span></div>
        </div>
        <div class="table-wrap">
            <table class="data-table unit-stage-table">
                <thead>
                    <tr>
                        <th scope="col">Unit</th>
                        <th scope="col">Akses tautan</th>
                        <th scope="col" class="num-col">Pendaftar</th>
                        <?php foreach ($unitStageKeys as $key) { ?>
                            <th scope="col" class="num-col"><?php echo h(ppdb_admin_status_meta($key)['label']); ?></th>
                        <?php } ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$unitStageRows) { ?>
                    <tr><td class="empty-state" colspan="<?php echo 3 + count($unitStageKeys); ?>">Belum ada unit pendidikan.</td></tr>
                <?php } ?>
                <?php foreach ($unitStageRows as $row) {
                    $unitName = ppdb_unit_display_name($row['nama_unit']);
                    $conversion = $row['visitors'] > 0 ? min(100, (int) round($row['registrants'] / $row['visitors'] * 100)) : null; ?>
                    <tr>
                        <td class="primary-cell" data-label="Unit"><?php echo h($unitName); ?></td>
                        <td data-label="Akses tautan">
                            <div class="visit-bar" title="<?php echo h($unitName.': '.$row['visitors'].' pengunjung membuka tautan pendaftaran'); ?>">
                                <div class="visit-bar__track"><span class="visit-bar__fill" style="--w: <?php echo $row['visitors'] > 0 ? max(3, (int) round($row['visitors'] / $unitVisitorMax * 100)) : 0; ?>%"></span></div>
                                <strong><?php echo $row['visitors']; ?></strong>
                            </div>
                        </td>
                        <td data-label="Pendaftar" class="num-col">
                            <strong><?php echo $row['registrants']; ?></strong>
                            <?php if ($conversion !== null) { ?><small class="cell-sub" title="Pendaftar dibanding pengakses tautan"><?php echo $conversion; ?>% dari akses</small><?php } ?>
                        </td>
                        <?php foreach ($unitStageKeys as $key) {
                            $count = $row['stages'][$key];
                            $label = ppdb_admin_status_meta($key)['label'];
                            $intensity = $count > 0 ? 15 + (int) round($count / $unitStageMax * 70) : 0; ?>
                            <td data-label="<?php echo h($label); ?>" class="num-col">
                                <span class="stage-heat<?php echo $count === 0 ? ' is-zero' : ($intensity >= 50 ? ' is-strong' : ''); ?>" style="--i: <?php echo $intensity; ?>%" title="<?php echo h($unitName.' · '.$label.': '.$count.' peserta'); ?>"><?php echo $count > 0 ? $count : '&ndash;'; ?></span>
                            </td>
                        <?php } ?>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <p class="panel-note unit-stage-note">Akses tautan dihitung sejak pencatatan dimulai; satu pengunjung dihitung sekali per unit. Warna sel makin pekat bila pesertanya makin banyak.</p>
    </section>

    <section class="panel progress-panel" data-progress-chart>
        <div class="panel-header">
            <div><h2>Capaian harian</h2><span class="panel-subtitle">Kursi terisi per hari di setiap jalur yang dibuka; puncak grafik sama dengan kuota</span></div>
            <div class="range-toggle" role="group" aria-label="Rentang hari">
                <button type="button" data-range="7">7 hari</button>
                <button type="button" data-range="14" aria-pressed="true">14 hari</button>
                <button type="button" data-range="30">30 hari</button>
            </div>
            <button class="progress-fullscreen" type="button" data-progress-fullscreen aria-pressed="false" aria-label="Tampilan penuh" title="Tampilan penuh">⛶</button>
        </div>
        <dl class="progress-stats">
            <div><dt>Akses tautan</dt><dd data-stat="visits">0</dd></div>
            <div><dt>Pendaftar baru</dt><dd data-stat="registrants">0</dd></div>
            <div><dt>Konversi</dt><dd data-stat="conversion">&ndash;</dd></div>
            <div><dt>Hari teramai</dt><dd data-stat="busiest">&ndash;</dd></div>
        </dl>
        <ul class="progress-legend" aria-label="Keterangan grafik">
            <li><span class="progress-key is-eksternal" aria-hidden="true"></span>Jalur eksternal</li>
            <li><span class="progress-key is-internal" aria-hidden="true"></span>Jalur internal</li>
            <li><span class="progress-key is-quota" aria-hidden="true"></span>Batas kuota</li>
        </ul>
        <?php if (!$quotaProgress) { ?>
            <p class="panel-note chain-empty">Belum ada unit yang membuka pendaftaran.</p>
        <?php } else { ?>
            <div class="chain-carousel" data-chain-carousel>
                <div class="chain-carousel__bar">
                    <button class="chain-carousel__nav" type="button" data-carousel-prev aria-label="Unit sebelumnya">&lsaquo;</button>
                    <p class="chain-carousel__status" aria-live="polite"><b data-carousel-index>1</b> / <?php echo count($quotaProgress); ?> · <span data-carousel-name><?php echo h($quotaProgress[0]['name']); ?></span></p>
                    <button class="chain-carousel__nav" type="button" data-carousel-next aria-label="Unit berikutnya">&rsaquo;</button>
                    <button class="chain-carousel__full" type="button" data-carousel-fullscreen aria-pressed="false" aria-label="Tampilan penuh unit" title="Tampilan penuh unit">⛶</button>
                </div>
            <ul class="chain-list" data-carousel-track tabindex="0" aria-label="Capaian per unit, geser ke kanan atau kiri">
                <?php foreach ($quotaProgress as $unitIndex => $unit) { ?>
                    <li class="chain-unit<?php echo $unit['filled'] >= $unit['quota'] ? ' is-full' : ''; ?><?php echo !empty($unit['combined']) ? ' is-combined' : ''; ?>">
                        <div class="quota-progress__head">
                            <strong><?php echo h($unit['name']); ?></strong>
                            <span class="quota-progress__value"><b><?php echo $unit['filled']; ?></b> / <?php echo $unit['quota']; ?> kursi · <?php echo $unit['percent']; ?>%</span>
                        </div>
                        <small class="quota-progress__meta">
                            <?php if (!empty($unit['combined'])) { ?>Unit yang dibuka: <?php echo h(implode(', ', $unit['units'])); ?> · <?php } ?>
                            Tahun ajaran <?php echo h($unit['year']); ?> · Diterima <?php echo $unit['accepted']; ?>
                            <?php if ($unit['filled'] >= $unit['quota']) { ?> · <span class="quota-progress__full">Kuota terpenuhi</span><?php } ?>
                        </small>
                        <?php if (!empty($unit['combined'])) { ?>
                            <div class="combined-head">
                                <?php foreach ($unit['routes'] as $route) { ?>
                                    <span><span class="progress-key is-<?php echo h($route['key']); ?>" aria-hidden="true"></span><?php echo h($route['label']); ?> <b><?php echo $route['filled']; ?></b> / <?php echo $route['quota']; ?></span>
                                <?php } ?>
                                <span><span class="progress-key is-chain" aria-hidden="true"></span>Rantai total terisi per unit</span>
                            </div>
                            <div class="combined-chart" data-combined-canvas data-unit="<?php echo $unitIndex; ?>" role="img" aria-label="<?php echo h('Kursi terisi per unit dari KB sampai SMP, total '.$unit['filled'].' dari '.$unit['quota'].' kursi'); ?>"></div>
                        <?php } else { ?>
                        <div class="chain-routes">
                            <?php foreach ($unit['routes'] as $routeIndex => $route) { ?>
                                <div class="chain-route is-<?php echo h($route['key']); ?>">
                                    <div class="chain-route__head">
                                        <span><span class="progress-key is-<?php echo h($route['key']); ?>" aria-hidden="true"></span><?php echo h($route['label']); ?></span>
                                        <span><b><?php echo $route['filled']; ?></b> / <?php echo $route['quota']; ?><?php if ($route['filled'] >= $route['quota']) { ?> <span class="quota-progress__full">penuh</span><?php } ?></span>
                                    </div>
                                    <div class="chain-chart" data-chain-canvas data-unit="<?php echo $unitIndex; ?>" data-route="<?php echo $routeIndex; ?>" role="img" aria-label="<?php echo h($unit['name'].' jalur '.strtolower($route['label']).': '.$route['filled'].' dari '.$route['quota'].' kursi terisi'); ?>"></div>
                                </div>
                            <?php } ?>
                        </div>
                        <?php } ?>
                    </li>
                <?php } ?>
            </ul>
                <div class="chain-carousel__dots" role="tablist" aria-label="Pilih unit">
                    <?php foreach ($quotaProgress as $unitIndex => $unit) { ?>
                        <button type="button" role="tab" data-carousel-dot="<?php echo $unitIndex; ?>" aria-label="<?php echo h($unit['name']); ?>" aria-selected="<?php echo $unitIndex === 0 ? 'true' : 'false'; ?>"><?php echo h(!empty($unit['combined']) ? 'Gabungan' : $unit['jenjang']); ?></button>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
        <div class="progress-tooltip" data-progress-tooltip hidden></div>
        <details class="progress-table">
            <summary>Lihat tabel harian</summary>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th scope="col">Hari</th><th scope="col">Akses tautan</th><th scope="col">Pendaftar baru</th></tr></thead>
                    <tbody>
                    <?php foreach (array_reverse($dailyProgress) as $day) { ?>
                        <tr><td data-label="Hari"><?php echo h($day['day'].', '.$day['full']); ?></td><td data-label="Akses tautan"><?php echo $day['visits']; ?></td><td data-label="Pendaftar baru"><?php echo $day['registrants']; ?></td></tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </details>
        <script type="application/json" id="progress-data"><?php echo json_encode(['days' => $dailyProgress, 'units' => $chainUnits], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div><h2>Peta pengakses tautan</h2><span class="panel-subtitle">Perkiraan kota orang yang membuka tautan pendaftaran, <?php echo $linkMapDays; ?> hari terakhir</span></div>
            <ul class="link-map-legend" aria-label="Keterangan titik">
                <li><span class="link-map-dot is-today" aria-hidden="true"></span>Dibuka hari ini</li>
                <li><span class="link-map-dot" aria-hidden="true"></span>Sebelumnya</li>
            </ul>
        </div>
        <?php if (!$geoipReady) { ?>
            <p class="link-map-alert" role="status">Database lokasi belum dipasang, jadi kunjungan baru belum diberi titik. Simpan berkas <code>GeoLite2-City.mmdb</code> di <code><?php echo h(ppdb_geoip_database_path()); ?></code> atau atur <code>GEOIP_DB_PATH</code> di <code>.env</code>.</p>
        <?php } ?>
        <div class="link-map" data-link-map role="img" aria-label="Peta <?php echo count($linkMap['points']); ?> lokasi pengakses tautan pendaftaran"></div>
        <script type="application/json" id="link-map-data"><?php echo json_encode($linkMap['points'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th scope="col">Lokasi</th><th scope="col">Pengunjung</th><th scope="col">Unit yang dibuka</th></tr></thead>
                <tbody>
                <?php if (!$linkMap['points']) { ?>
                    <tr><td class="empty-state" colspan="3">Belum ada pengakses tautan dengan lokasi yang terbaca.</td></tr>
                <?php } ?>
                <?php foreach (array_slice($linkMap['points'], 0, 8) as $point) { ?>
                    <tr>
                        <td class="primary-cell" data-label="Lokasi"><?php echo h($point['place']); ?><?php if ($point['today']) { ?> <span class="status-pill tone-red">Hari ini</span><?php } ?></td>
                        <td data-label="Pengunjung"><?php echo $point['visitors']; ?></td>
                        <td data-label="Unit"><?php echo h(implode(', ', array_map(static fn (array $unit): string => $unit['name'].' ('.$unit['visitors'].')', $point['units']))); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <p class="panel-note unit-stage-note">Lokasi ditebak dari alamat IP dan hanya sampai tingkat kota. Pengguna data seluler sering tampil di kota pusat jaringan operator (misalnya Medan atau Jakarta), bukan lokasi sebenarnya.<?php if ($linkMap['unlocated'] > 0) { ?> <?php echo $linkMap['unlocated']; ?> pengunjung tidak terbaca lokasinya.<?php } ?></p>
    </section>
</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" integrity="sha512-h9FcoyWjHcOcmEVkxOfTLnmZFWIH0iZhZT1H2TbOq55xssQGEJHEaIm+PgoUaZbRvQTNTluNOEfb1ZRy6D3BOw==" crossorigin="anonymous" referrerpolicy="no-referrer">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js" integrity="sha512-puJW3E/qXDqYp9IfhAI54BJEaWIfloJ7JWs7OeD5i6ruC9JZL1gERT1wjtwXFlh7CjE7ZJ+/vcRZRkIYIb6p4g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="js/admin-progress.js?v=<?php echo (int) @filemtime(__DIR__.'/js/admin-progress.js'); ?>" defer></script>
<script src="js/admin-map.js?v=<?php echo (int) @filemtime(__DIR__.'/js/admin-map.js'); ?>" defer></script>
<?php require 'admin_footer.php'; ?>
