<?php
require_once 'app_helpers.php';
require_admin();
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
require_once 'web_stats.php';
require_once __DIR__.'/admin-partials.php';

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
    ['key' => 'menunggu_kontak', 'label' => 'Belum melanjutkan', 'hint' => 'Baru registrasi awal'],
    ['key' => 'menunggu_pembayaran', 'label' => 'Menunggu bayar', 'hint' => 'Menunggu bukti transfer'],
    ['key' => 'pembayaran_diperiksa', 'label' => 'Bukti masuk', 'hint' => 'Periksa bukti transfer'],
    ['key' => 'pembayaran_terverifikasi', 'label' => 'Bayar disetujui', 'hint' => 'Menunggu isian lengkap'],
    ['key' => 'menunggu_formulir', 'label' => 'Menunggu formulir', 'hint' => 'Menunggu isian lengkap'],
    ['key' => 'formulir_terisi', 'label' => 'Menunggu keputusan', 'hint' => 'Formulir sebelum penerimaan otomatis'],
];
$funnelBars = [];
$funnelMax = 1;
foreach ($funnelStages as $stage) {
    $count = $funnelCounts[$stage['key']] ?? 0;
    $funnelMax = max($funnelMax, $count);
    $funnelBars[] = $stage + ['count' => $count];
}
$actionableTotal = 0;
// Bukti transfer diperiksa setelah pendaftar otomatis diterima, jadi dihitung dari status pembayaran.
$proofPendingCount = (int) mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS total FROM tb_pendaftaran'.($registrationWhere === '' ? ' WHERE ' : $registrationWhere.' AND ')."status_pembayaran='menunggu_verifikasi' AND status_ppdb<>'ditolak'"))['total'];
$actionableTotal = $proofPendingCount + ($funnelCounts['formulir_terisi'] ?? 0);

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
$registrantMap = ppdb_registrant_locations($conn, $adminUnitCode);
$locationRows = ppdb_location_summary($linkMap['points'], $registrantMap['points']);
$geoipProblem = ppdb_geoip_problem();
$unitVisitorMax = 1;
$unitStageMax = 1;
foreach ($unitStageRows as $row) {
    $unitVisitorMax = max($unitVisitorMax, $row['visitors']);
    $unitStageMax = max($unitStageMax, ...array_values($row['stages']));
}

$syncCounts = ['pending' => 0, 'failed' => 0, 'synced' => 0];
$lastSyncFailure = '';
$sheetsCredentialsReady = false;
$sheetsServiceAccount = '';
$unitSheets = [];
$syncFlash = null;
if ($adminUnitCode === '') {
    $syncStatusResult = mysqli_query($conn, 'SELECT status,COUNT(*) AS total FROM tb_google_sync_outbox GROUP BY status');
    while ($syncStatus = mysqli_fetch_assoc($syncStatusResult)) {
        $syncCounts[$syncStatus['status']] = (int) $syncStatus['total'];
    }
    $lastSyncFailure = mysqli_fetch_assoc(mysqli_query($conn, "SELECT last_error FROM tb_google_sync_outbox WHERE status='failed' AND last_error IS NOT NULL ORDER BY last_attempt_at DESC LIMIT 1"))['last_error'] ?? '';
    // Masalah kredensial diperiksa langsung agar admin tidak membaca galat lama dari antrean.
    $sheetsCredentialsProblem = google_sheets_credentials_problem();
    $sheetsCredentialsReady = $sheetsCredentialsProblem === '';
    if (!$sheetsCredentialsReady) {
        $lastSyncFailure = $sheetsCredentialsProblem;
    }
    $sheetsServiceAccount = google_sheets_service_account_email();
    $unitSheets = [];
    if (ppdb_column_exists($conn, 'tb_pengaturan_ppdb', 'google_spreadsheet_id')) {
        $unitSheets = mysqli_fetch_all(mysqli_query($conn, "SELECT c.th_ajaran,c.google_spreadsheet_id,u.nama_unit FROM tb_pengaturan_ppdb c JOIN tb_unit_pendidikan u ON u.kode_unit=c.kode_unit WHERE c.google_spreadsheet_id<>'' ORDER BY c.th_ajaran DESC,FIELD(u.jenjang,'KB','TPA','TK','SD','SMP')"), MYSQLI_ASSOC);
    }
    $syncFlash = $_SESSION['google_sheets_sync_result'] ?? null;
    unset($_SESSION['google_sheets_sync_result']);
    // Salinan dokumen peserta di Google Drive yayasan (migrasi 029 + OAuth).
    $driveMigrated = ppdb_table_exists($conn, 'tb_google_drive_akun');
    $driveConfigured = google_drive_configured($conn);
    $driveAccount = $driveConfigured ? google_drive_account($conn) : null;
    $driveRootFolder = $driveAccount ? google_drive_cached_folder($conn, 'root') : '';
    $driveFlash = $_SESSION['google_drive_flash'] ?? null;
    unset($_SESSION['google_drive_flash']);
}

$latestSql = 'SELECT p.id_pendaftaran,p.nm_peserta,p.th_ajaran,p.tgl_daftar,p.status_ppdb,u.nama_unit FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON p.kode_unit=u.kode_unit'.($adminUnitCode === '' ? '' : " WHERE p.kode_unit='".$escapedUnitCode."'").' ORDER BY p.tgl_daftar DESC,p.id_pendaftaran DESC LIMIT 8';
$latestRegistrations = mysqli_query($conn, $latestSql);
$attentionSql = "SELECT p.id_pendaftaran,p.nm_peserta,p.status_ppdb,p.tgl_daftar,p.waktu_daftar,u.nama_unit FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON p.kode_unit=u.kode_unit WHERE (p.status_ppdb='formulir_terisi' OR (p.status_pembayaran='menunggu_verifikasi' AND p.status_ppdb<>'ditolak'))".$registrationScope." ORDER BY p.waktu_daftar ASC LIMIT 8";
$attentionQuery = mysqli_query($conn, $attentionSql);

// Data tampilan dasbor (hanya membaca angka yang sudah dihitung di atas).
$todayProgress = end($dailyProgress) ?: ['day' => '', 'full' => '', 'visits' => 0, 'registrants' => 0];
$recentRegistrants = array_column(array_slice($dailyProgress, -7), 'registrants');
$previousRegistrants = array_column(array_slice($dailyProgress, -14, 7), 'registrants');
$weekRegistrants = array_sum($recentRegistrants);
$weekDelta = $weekRegistrants - array_sum($previousRegistrants);
$activeRegistrations = $totalRegistrations - (int) ($funnelCounts['data_lama'] ?? 0);
$acceptanceRate = $activeRegistrations > 0 ? (int) round($acceptedCount / $activeRegistrations * 100) : 0;
$actionableBreakdown = [];
foreach (['pembayaran_diperiksa', 'formulir_terisi'] as $key) {
    $actionableBreakdown[] = ['key' => $key, 'label' => ppdb_admin_status_meta($key)['label'], 'count' => $key === 'pembayaran_diperiksa' ? $proofPendingCount : ($funnelCounts[$key] ?? 0)];
}
$statusMix = [];
foreach (array_merge(array_column($funnelStages, 'key'), ['diterima', 'daftar_tunggu', 'ditolak']) as $key) {
    $count = $funnelCounts[$key] ?? 0;
    if ($count > 0) {
        $meta = ppdb_admin_status_meta($key);
        $statusMix[] = ['key' => $key, 'label' => $meta['label'], 'tone' => $meta['tone'], 'count' => $count];
    }
}
$dashboardData = [
    'days' => array_map(static fn (array $day): array => [
        'label' => $day['label'], 'full' => $day['day'].', '.$day['full'], 'visits' => $day['visits'], 'registrants' => $day['registrants'],
    ], $dailyProgress),
    'mix' => $statusMix,
];

$activeAdminPage = 'dashboard';
$adminPageTitle = 'Dashboard';
$adminPageDescription = 'Ringkasan antrean, kapasitas unit, dan performa penerimaan peserta didik baru.';
require 'admin_header.php';
?>
<div class="admin-content">
    <?php if (isset($_GET['admin_created'])) { ?><div class="alert-success" role="status">Akun admin baru berhasil dibuat.</div><?php } ?>
    <?php if ($syncFlash) { ?><div class="<?php echo $syncFlash['failed'] > 0 ? 'alert-error' : 'alert-success'; ?>" role="status">Sinkronisasi selesai: <?php echo (int) $syncFlash['synced']; ?> spreadsheet unit berhasil, <?php echo (int) $syncFlash['failed']; ?> perlu dicoba ulang.</div><?php } ?>

    <header class="page-hero dash-hero">
        <div class="page-hero__text">
            <p class="eyebrow"><?php echo $adminUnitCode === '' ? 'Penerimaan peserta didik baru' : 'Penerimaan · '.h(ppdb_unit_display_name($_SESSION['admin_unit_name'] ?? 'Unit')); ?></p>
            <h1>Selamat datang kembali, <?php echo h(explode(' ', (string) ($_SESSION['admin_name'] ?? 'Administrator'))[0]); ?>.</h1>
            <p><?php echo $actionableTotal > 0
                ? $actionableTotal.' peserta menunggu tindakan Anda. Mulai dari data yang paling lama menunggu.'
                : 'Tidak ada peserta yang menunggu tindakan saat ini. Semua alur berjalan lancar.'; ?></p>
            <ul class="dash-hero__today" aria-label="Ringkasan hari ini">
                <li><?php echo ppdb_admin_icon('clock'); ?><span><?php echo h(trim($todayProgress['day'].', '.$todayProgress['full'], ', ')); ?></span></li>
                <li><?php echo ppdb_admin_icon('users'); ?><span><b><?php echo (int) $todayProgress['registrants']; ?></b> pendaftar baru hari ini</span></li>
                <li><?php echo ppdb_admin_icon('external'); ?><span><b><?php echo (int) $todayProgress['visits']; ?></b> akses tautan hari ini</span></li>
            </ul>
        </div>
        <div class="page-hero__actions">
            <?php if ($actionableTotal > 0) { ?>
                <a class="button-primary" href="daftar_peserta.php?status=pembayaran_diperiksa">Proses antrean</a>
            <?php } else { ?>
                <a class="button-primary" href="daftar_peserta.php">Lihat semua peserta</a>
            <?php } ?>
            <a class="button-secondary" href="daftar_peserta.php"><?php echo ppdb_admin_icon('registrations'); ?><span>Data peserta</span></a>
            <?php if ($adminUnitCode === '') { ?><a class="button-secondary" href="pengaturan-ppdb.php"><?php echo ppdb_admin_icon('quota'); ?><span>Kuota</span></a><?php } ?>
        </div>
    </header>

    <section class="stats-grid" aria-label="Statistik utama">
        <article class="stat-card is-primary">
            <span class="stat-icon" aria-hidden="true"><?php echo ppdb_admin_icon('users'); ?></span>
            <span class="stat-label">Total pendaftar</span>
            <strong class="stat-value" data-count="<?php echo $totalRegistrations; ?>"><?php echo $totalRegistrations; ?></strong>
            <span class="stat-note"><?php echo $weekRegistrants; ?> dalam 7 hari terakhir <span class="stat-trend <?php echo $weekDelta > 0 ? 'is-up' : ($weekDelta < 0 ? 'is-down' : 'is-flat'); ?>" title="Dibanding 7 hari sebelumnya"><?php echo ($weekDelta > 0 ? '+' : '').$weekDelta; ?> vs pekan lalu</span></span>
            <svg class="stat-spark" data-spark="<?php echo h(implode(',', array_column(array_slice($dailyProgress, -14), 'registrants'))); ?>" viewBox="0 0 120 36" preserveAspectRatio="none" aria-hidden="true"></svg>
        </article>
        <article class="stat-card<?php echo $actionableTotal > 0 ? ' is-alert' : ''; ?>">
            <span class="stat-icon" aria-hidden="true"><?php echo ppdb_admin_icon('inbox'); ?></span>
            <span class="stat-label">Menunggu tindakan</span>
            <strong class="stat-value" data-count="<?php echo $actionableTotal; ?>"><?php echo $actionableTotal; ?></strong>
            <span class="stat-note">Perlu diproses admin</span>
            <span class="stat-segments" role="img" aria-label="<?php echo h(implode(', ', array_map(static fn ($item) => $item['label'].' '.$item['count'], $actionableBreakdown))); ?>">
                <?php foreach ($actionableBreakdown as $item) { ?><span class="st-<?php echo h($item['key']); ?>" style="flex-grow: <?php echo max(0, $item['count']); ?>" title="<?php echo h($item['label'].': '.$item['count']); ?>"></span><?php } ?>
            </span>
        </article>
        <article class="stat-card is-success">
            <span class="stat-icon" aria-hidden="true"><?php echo ppdb_admin_icon('check'); ?></span>
            <span class="stat-label">Diterima</span>
            <strong class="stat-value" data-count="<?php echo $acceptedCount; ?>"><?php echo $acceptedCount; ?></strong>
            <span class="stat-note">Peserta lolos penerimaan</span>
            <span class="stat-ring" style="--p: <?php echo $acceptanceRate; ?>" role="img" aria-label="<?php echo $acceptanceRate; ?>% pendaftar aktif diterima"><b><?php echo $acceptanceRate; ?>%</b></span>
        </article>
        <article class="stat-card is-waitlist">
            <span class="stat-icon" aria-hidden="true"><?php echo ppdb_admin_icon('clock'); ?></span>
            <span class="stat-label">Daftar tunggu</span>
            <strong class="stat-value" data-count="<?php echo $waitlistCount; ?>"><?php echo $waitlistCount; ?></strong>
            <span class="stat-note">Menunggu kursi kosong</span>
        </article>
    </section>

    <div class="dashboard-grid dash-analytics">
        <section class="panel trend-panel" data-trend>
            <div class="panel-header">
                <div><h2>Tren pendaftaran</h2><span class="panel-subtitle">Pendaftar baru dan akses tautan pendaftaran per hari</span></div>
                <div class="range-toggle" role="group" aria-label="Rentang tren">
                    <button type="button" data-trend-range="7">7 hari</button>
                    <button type="button" data-trend-range="14" aria-pressed="true">14 hari</button>
                    <button type="button" data-trend-range="30">30 hari</button>
                </div>
            </div>
            <div class="panel-body">
                <dl class="trend-summary">
                    <div><dt><span class="trend-key is-registrants" aria-hidden="true"></span>Pendaftar baru</dt><dd data-trend-total="registrants">0</dd></div>
                    <div><dt><span class="trend-key is-visits" aria-hidden="true"></span>Akses tautan</dt><dd data-trend-total="visits">0</dd></div>
                    <div><dt>Konversi</dt><dd data-trend-total="conversion">&ndash;</dd></div>
                </dl>
                <div class="trend-chart" data-trend-chart role="img" aria-label="Grafik tren pendaftar baru dan akses tautan per hari"></div>
            </div>
        </section>

        <section class="panel mix-panel">
            <div class="panel-header">
                <div><h2>Komposisi status</h2><span class="panel-subtitle">Posisi seluruh pendaftar aktif saat ini</span></div>
            </div>
            <div class="panel-body">
                <?php if (!$statusMix) { ?>
                    <p class="panel-note">Belum ada data untuk ditampilkan.</p>
                <?php } else { ?>
                    <div class="mix-chart">
                        <div class="mix-donut" data-mix-donut role="img" aria-label="Diagram komposisi status pendaftar"></div>
                        <ul class="mix-legend">
                            <?php foreach ($statusMix as $index => $item) { ?>
                                <li data-mix-item="<?php echo $index; ?>" tabindex="0">
                                    <span class="mix-swatch st-<?php echo h($item['key']); ?>" aria-hidden="true"></span>
                                    <span class="mix-label"><?php echo h($item['label']); ?></span>
                                    <b><?php echo $item['count']; ?></b>
                                </li>
                            <?php } ?>
                        </ul>
                    </div>
                <?php } ?>
            </div>
        </section>
    </div>
    <script type="application/json" id="dashboard-data"><?php echo json_encode($dashboardData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>

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
            <a class="panel-link" href="pengaturan-ppdb.php">Atur kuota</a>
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
                <a class="panel-link" href="daftar_peserta.php">Semua peserta</a>
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
                <div><h2>Sinkronisasi Google Sheets</h2><span class="panel-subtitle">Satu spreadsheet per unit</span></div>
                <span class="status-pill <?php echo $sheetsCredentialsReady ? 'is-open' : 'is-closed'; ?>"><?php echo $sheetsCredentialsReady ? 'Aktif' : 'Nonaktif'; ?></span>
            </div>
            <div class="panel-body sheet-panel-body">
                <p>Seluruh data peserta dikirim ke tab <strong><?php echo h(GOOGLE_SHEETS_TAB); ?></strong> pada spreadsheet unitnya. ID spreadsheet diatur di <a href="pengaturan-ppdb.php#spreadsheet-unit">Kuota &amp; pembayaran</a>.</p>
                <?php if ($sheetsServiceAccount !== '') { ?><p>Bagikan spreadsheet sebagai Editor ke <code><?php echo h($sheetsServiceAccount); ?></code>.</p><?php } ?>
                <?php if ($unitSheets) { ?>
                    <ul class="sheet-links">
                        <?php foreach ($unitSheets as $unitSheet) { ?>
                            <li><a href="<?php echo h(google_sheets_spreadsheet_url($unitSheet['google_spreadsheet_id'])); ?>" target="_blank" rel="noopener noreferrer"><?php echo h(ppdb_unit_display_name($unitSheet['nama_unit'])); ?></a> <small><?php echo h($unitSheet['th_ajaran']); ?></small></li>
                        <?php } ?>
                    </ul>
                <?php } ?>
                <?php if ($lastSyncFailure !== '') { ?><p class="sheet-sync-error" role="status">Status: <?php echo h($lastSyncFailure); ?></p><?php } ?>
                <div class="drive-connect" id="google-drive">
                    <h3>Dokumen di Google Drive</h3>
                    <?php if ($driveFlash) { ?><p class="<?php echo $driveFlash['ok'] ? 'drive-connect__ok' : 'sheet-sync-error'; ?>" role="status"><?php echo h($driveFlash['text']); ?></p><?php } ?>
                    <?php if (!$driveMigrated) { ?>
                        <p>Jalankan migrasi <code>029_google_drive_dokumen.sql</code> untuk menyalin foto, KK, KTP, akta, NISN, dan bukti transfer ke Google Drive.</p>
                    <?php } elseif (!$driveConfigured) { ?>
                        <p>Isi <code>GOOGLE_OAUTH_CLIENT_ID</code> dan <code>GOOGLE_OAUTH_CLIENT_SECRET</code> di <code>.env</code>. Alamat callback OAuth: <code><?php echo h(google_drive_redirect_uri()); ?></code></p>
                    <?php } elseif (!$driveAccount) { ?>
                        <p>Hubungkan akun Google yayasan agar dokumen peserta disalin ke folder Drive per unit dan ditautkan di spreadsheet.</p>
                        <a class="button-primary" href="google-drive-auth.php">Hubungkan Google Drive</a>
                    <?php } else { ?>
                        <p>Terhubung sebagai <strong><?php echo h($driveAccount['email']); ?></strong>.<?php if ($driveRootFolder !== '') { ?> <a href="<?php echo h(google_drive_folder_url($driveRootFolder)); ?>" target="_blank" rel="noopener noreferrer">Buka folder <?php echo h(GOOGLE_DRIVE_ROOT_FOLDER); ?></a><?php } ?></p>
                        <?php if (!empty($driveAccount['galat_terakhir'])) { ?><p class="sheet-sync-error" role="status">Drive: <?php echo h($driveAccount['galat_terakhir']); ?></p><?php } ?>
                        <form action="google-drive-auth.php" method="post" onsubmit="return confirm('Putuskan Google Drive? Dokumen baru tidak lagi disalin ke Drive.');">
                            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="action" value="disconnect">
                            <button class="button-secondary" type="submit">Putuskan Google Drive</button>
                        </form>
                    <?php } ?>
                </div>
                <dl class="sync-stats">
                    <div><dt>Tersinkron</dt><dd><?php echo $syncCounts['synced']; ?></dd></div>
                    <div><dt>Antre</dt><dd><?php echo $syncCounts['pending']; ?></dd></div>
                    <div><dt>Gagal</dt><dd><?php echo $syncCounts['failed']; ?></dd></div>
                </dl>
                <div class="sheet-actions">
                    <form action="sinkronisasi-spreadsheet.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                        <button class="button-primary" type="submit">Sinkronkan antrean</button>
                    </form>
                    <form action="sinkronisasi-spreadsheet.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="mode" value="all">
                        <button class="button-secondary" type="submit">Tulis ulang semua unit</button>
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
            <div><h2>Capaian harian</h2><span class="panel-subtitle">Garis menunjukkan total kursi terisi, batang menunjukkan pendaftar baru per hari</span></div>
            <div class="progress-tools">
                <div class="range-toggle" role="group" aria-label="Rentang hari">
                    <button type="button" data-range="7">7 hari</button>
                    <button type="button" data-range="14" aria-pressed="true">14 hari</button>
                    <button type="button" data-range="30">30 hari</button>
                </div>
                <button class="progress-fullscreen" type="button" data-progress-fullscreen aria-pressed="false" aria-label="Tampilan penuh" title="Tampilan penuh">⛶</button>
            </div>
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
            <li><span class="progress-key is-bar" aria-hidden="true"></span>Batang: pendaftar baru per hari</li>
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
            <div><h2>Peta pengunjung &amp; pendaftar</h2><span class="panel-subtitle">Pengunjung tautan per kabupaten/kota (<?php echo $linkMapDays; ?> hari terakhir) dan lokasi pendaftar yang telah menyelesaikan formulir lengkap</span></div>
            <ul class="link-map-legend" aria-label="Keterangan titik">
                <li><span class="link-map-dot is-registrant" aria-hidden="true"></span>Pendaftar</li>
                <li><span class="link-map-dot is-today" aria-hidden="true"></span>Pengunjung hari ini</li>
                <li><span class="link-map-dot" aria-hidden="true"></span>Pengunjung sebelumnya</li>
            </ul>
        </div>
        <?php if ($geoipProblem !== null) { ?>
            <p class="link-map-alert" role="status">Database lokasi belum dapat dipakai, jadi kunjungan baru belum diberi titik. <?php echo h($geoipProblem); ?> Unggah <code>GeoLite2-City.mmdb</code> ke <code>storage/geoip/</code> di folder situs, atau isi <code>GEOIP_DB_PATH</code> di <code>.env</code> dengan lokasi lengkap berkasnya.</p>
        <?php } ?>
        <div class="link-map" data-link-map role="img" aria-label="Peta <?php echo count($linkMap['points']); ?> kabupaten pengunjung dan <?php echo count($registrantMap['points']); ?> lokasi pendaftar"></div>
        <script type="application/json" id="link-map-data"><?php echo json_encode(['visitors' => $linkMap['points'], 'registrants' => $registrantMap['points']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th scope="col">Kabupaten/kota</th><th scope="col">Pengunjung</th><th scope="col">Pendaftar</th><th scope="col">Unit</th></tr></thead>
                <tbody>
                <?php if (!$locationRows) { ?>
                    <tr><td class="empty-state" colspan="4">Belum ada pengunjung atau pendaftar dengan lokasi yang terbaca.</td></tr>
                <?php } ?>
                <?php foreach (array_slice($locationRows, 0, 10) as $row) { ?>
                    <tr>
                        <td class="primary-cell" data-label="Kabupaten/kota"><?php echo h($row['place']); ?><?php if ($row['today']) { ?> <span class="status-pill tone-red">Hari ini</span><?php } ?></td>
                        <td data-label="Pengunjung"><?php echo $row['visitors'] ?: '&ndash;'; ?></td>
                        <td data-label="Pendaftar"><?php echo $row['registrants'] ? '<strong>'.$row['registrants'].'</strong>' : '&ndash;'; ?></td>
                        <td data-label="Unit"><?php echo h(implode(', ', $row['units'])); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <p class="panel-note unit-stage-note">Pengunjung ditebak dari alamat IP dan hanya sampai tingkat kabupaten/kota; pengguna data seluler sering tercatat di kota pusat jaringan operator (misalnya Medan). Pendaftar dipetakan dari alamat formulir lengkap (desa/kecamatan), atau dari koordinat Dapodik bila sudah dicatat operator.<?php if ($linkMap['unlocated'] > 0) { ?> <?php echo $linkMap['unlocated']; ?> pengunjung tidak terbaca lokasinya.<?php } ?><?php if ($registrantMap['pending'] > 0) { ?> <?php echo $registrantMap['pending']; ?> pendaftar belum berhasil dipetakan; lokasinya dilengkapi otomatis saat dashboard dibuka kembali.<?php } ?></p>
    </section>
</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" integrity="sha512-h9FcoyWjHcOcmEVkxOfTLnmZFWIH0iZhZT1H2TbOq55xssQGEJHEaIm+PgoUaZbRvQTNTluNOEfb1ZRy6D3BOw==" crossorigin="anonymous" referrerpolicy="no-referrer">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js" integrity="sha512-puJW3E/qXDqYp9IfhAI54BJEaWIfloJ7JWs7OeD5i6ruC9JZL1gERT1wjtwXFlh7CjE7ZJ+/vcRZRkIYIb6p4g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="js/admin-progress.js?v=<?php echo (int) @filemtime(__DIR__.'/js/admin-progress.js'); ?>" defer></script>
<script src="js/admin-map.js?v=<?php echo (int) @filemtime(__DIR__.'/js/admin-map.js'); ?>" defer></script>
<script src="js/admin-dashboard.js?v=<?php echo (int) @filemtime(__DIR__.'/js/admin-dashboard.js'); ?>" defer></script>
<?php require 'admin_footer.php'; ?>
