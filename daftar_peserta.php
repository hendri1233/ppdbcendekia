<?php
require_once 'koneksi.php';
require_once 'app_helpers.php';
require_once 'wablas.php';
require_admin();
require_once __DIR__ . '/admin-partials.php';

$waNotice = $_SESSION['wa_notice'] ?? null;
unset($_SESSION['wa_notice']);

$search = trim($_GET['q'] ?? '');
$selectedYear = trim($_GET['year'] ?? '');
$adminUnitCode = ppdb_is_super_admin() ? '' : (string) $_SESSION['admin_unit_code'];
$selectedUnit = ppdb_is_super_admin() ? trim($_GET['unit'] ?? '') : $adminUnitCode;
$selectedStatus = trim($_GET['status'] ?? '');
$selectedTrack = trim($_GET['jalur'] ?? '');
// Jalur pendaftaran (internal/eksternal) atau jalur seleksi SMP (prestasi/umum, migrasi 030).
$hasSelectionRoute = ppdb_column_exists($conn, 'tb_pendaftaran', 'jalur_seleksi');
if (!in_array($selectedTrack, array_merge(['', 'internal', 'eksternal'], $hasSelectionRoute ? ['prestasi', 'umum'] : []), true)) {
    $selectedTrack = '';
}
$onlyDuplicates = ($_GET['ganda'] ?? '') === '1';
$sortOrder = ($_GET['urut'] ?? '') === 'antrean' ? 'antrean' : 'terbaru';
// Data ganda: pendaftaran lain pada tahun ajaran yang sama dengan nama dan tanggal lahir ananda
// yang sama, atau NIK ananda yang sama. Arsip lama tidak dihitung.
$duplicateSql = "(EXISTS (SELECT 1 FROM tb_pendaftaran d WHERE d.id_pendaftaran<>p.id_pendaftaran AND d.th_ajaran=p.th_ajaran AND d.status_ppdb<>'data_lama' AND d.tgl_lahir=p.tgl_lahir AND UPPER(TRIM(d.nm_peserta))=UPPER(TRIM(p.nm_peserta))) OR EXISTS (SELECT 1 FROM tb_detail_dapodik a JOIN tb_detail_dapodik b ON b.nik=a.nik AND b.id_pendaftaran<>a.id_pendaftaran JOIN tb_pendaftaran d ON d.id_pendaftaran=b.id_pendaftaran WHERE a.id_pendaftaran=p.id_pendaftaran AND a.nik<>'' AND d.th_ajaran=p.th_ajaran AND d.status_ppdb<>'data_lama'))";

$statusOptions = [
    'menunggu_kontak' => 'Menunggu persetujuan',
    'menunggu_pembayaran' => 'Menunggu bayar',
    'pembayaran_diperiksa' => 'Bukti bayar masuk',
    'pembayaran_terverifikasi' => 'Bayar disetujui',
    'menunggu_formulir' => 'Menunggu formulir',
    'formulir_terisi' => 'Formulir masuk',
    'diterima' => 'Diterima',
    'daftar_tunggu' => 'Daftar tunggu',
    'ditolak' => 'Perlu tindakan',
    'data_lama' => 'Arsip lama',
];
if ($selectedStatus !== '' && !isset($statusOptions[$selectedStatus])) {
    $selectedStatus = '';
}

$conditions = [];
$parameters = [];
if ($search !== '') {
    $conditions[] = '(p.id_pendaftaran LIKE ? OR p.nm_peserta LIKE ? OR p.NISN LIKE ?)';
    $like = '%'.$search.'%';
    $parameters[] = $like;
    $parameters[] = $like;
    $parameters[] = $like;
}
if ($selectedYear !== '') {
    $conditions[] = 'p.th_ajaran = ?';
    $parameters[] = $selectedYear;
}
if ($selectedUnit !== '') {
    $conditions[] = 'p.kode_unit = ?';
    $parameters[] = $selectedUnit;
}
if ($selectedStatus === 'pembayaran_diperiksa') {
    // Bukti tetap perlu diperiksa walau pendaftar sudah otomatis diterima.
    $conditions[] = "p.status_pembayaran = 'menunggu_verifikasi'";
} elseif ($selectedStatus !== '') {
    $conditions[] = 'p.status_ppdb = ?';
    $parameters[] = $selectedStatus;
}
if (in_array($selectedTrack, ['prestasi', 'umum'], true)) {
    $conditions[] = 'p.jalur_seleksi = ?';
    $parameters[] = $selectedTrack;
} elseif ($selectedTrack !== '') {
    $conditions[] = 'p.jalur_pendaftaran = ?';
    $parameters[] = $selectedTrack;
}
if ($onlyDuplicates) {
    $conditions[] = "p.status_ppdb<>'data_lama'";
    $conditions[] = $duplicateSql;
}
$whereSql = $conditions ? ' WHERE '.implode(' AND ', $conditions) : '';

// Jumlah total untuk pagination, dihitung terpisah dari halaman saat ini.
$countQuery = 'SELECT COUNT(*) AS total FROM tb_pendaftaran p'.$whereSql;
$countStatement = mysqli_prepare($conn, $countQuery);
if ($parameters) {
    $countRefs = [];
    foreach ($parameters as $key => &$parameter) {
        $countRefs[$key] = &$parameter;
    }
    mysqli_stmt_bind_param($countStatement, str_repeat('s', count($parameters)), ...$countRefs);
}
mysqli_stmt_execute($countStatement);
$totalRows = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($countStatement))['total'];
mysqli_stmt_close($countStatement);

$perPage = 25;
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$currentPage = (int) ($_GET['page'] ?? 1);
$currentPage = min(max(1, $currentPage), $totalPages);
$offset = ($currentPage - 1) * $perPage;

$query = 'SELECT p.id_pendaftaran,p.NISN,p.nm_peserta,p.th_ajaran,p.tgl_daftar,p.waktu_daftar,p.nomor_antrian,p.jalur_pendaftaran,p.status_ppdb,p.status_pembayaran,p.status_data,p.alamat,p.data_diperiksa_pada,p.pendaftar,p.no_hp,p.no_hp_pendaftar,p.no_hp_ayah,p.no_hp_ibu,p.tgl_lahir,p.token_bukti,p.revisi_dibuka,'.$duplicateSql.' AS data_ganda,'.(ppdb_column_exists($conn, 'tb_pendaftaran', 'tingkat_kelas') ? 'p.tingkat_kelas,' : '').'u.nama_unit FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON p.kode_unit=u.kode_unit'
    .$whereSql
    // Bawaan: pendaftar terbaru di atas agar tautan cepat dikirim. Pilihan antrean: #1 per tahun ajaran dan unit.
    // Pada filter data ganda, pendaftaran yang sama dikelompokkan berdampingan.
    .($onlyDuplicates
        ? ' ORDER BY p.th_ajaran DESC, UPPER(TRIM(p.nm_peserta)), p.tgl_lahir, p.waktu_daftar ASC, p.id_pendaftaran ASC'
        : ($sortOrder === 'antrean'
            ? " ORDER BY p.th_ajaran DESC, FIELD(u.jenjang,'KB','TPA','TK','SD','SMP'), p.kode_unit, p.nomor_antrian IS NULL, p.nomor_antrian ASC, p.waktu_daftar ASC, p.id_pendaftaran ASC"
            : ' ORDER BY p.waktu_daftar DESC, p.id_pendaftaran DESC'))
    .' LIMIT '.$perPage.' OFFSET '.$offset;

$registrationsStatement = mysqli_prepare($conn, $query);
if ($parameters) {
    $references = [];
    foreach ($parameters as $key => &$parameter) {
        $references[$key] = &$parameter;
    }
    mysqli_stmt_bind_param($registrationsStatement, str_repeat('s', count($parameters)), ...$references);
}
mysqli_stmt_execute($registrationsStatement);
$registrations = mysqli_stmt_get_result($registrationsStatement);
mysqli_stmt_close($registrationsStatement);

if ($adminUnitCode === '') {
    $yearOptions = mysqli_query($conn, "SELECT DISTINCT th_ajaran FROM tb_pendaftaran WHERE th_ajaran <> '' ORDER BY th_ajaran DESC");
    $unitOptions = mysqli_query($conn, "SELECT kode_unit,nama_unit FROM tb_unit_pendidikan ORDER BY FIELD(jenjang,'KB','TPA','TK','SD','SMP')");
} else {
    $yearStatement = mysqli_prepare($conn, "SELECT DISTINCT th_ajaran FROM tb_pendaftaran WHERE kode_unit=? AND th_ajaran<>'' ORDER BY th_ajaran DESC");
    mysqli_stmt_bind_param($yearStatement, 's', $adminUnitCode);
    mysqli_stmt_execute($yearStatement);
    $yearOptions = mysqli_stmt_get_result($yearStatement);
    $unitStatement = mysqli_prepare($conn, 'SELECT kode_unit,nama_unit FROM tb_unit_pendidikan WHERE kode_unit=?');
    mysqli_stmt_bind_param($unitStatement, 's', $adminUnitCode);
    mysqli_stmt_execute($unitStatement);
    $unitOptions = mysqli_stmt_get_result($unitStatement);
}

/** Menyusun ulang query string sambil mengganti satu parameter. */
function with_query(array $overrides): string
{
    $query = array_merge($_GET, $overrides);
    $query = array_filter($query, static fn ($value) => $value !== '' && $value !== null);
    return '?' . http_build_query($query);
}

$hasFilter = $search !== '' || $selectedYear !== '' || $selectedUnit !== '' || $selectedStatus !== '' || $selectedTrack !== '' || $onlyDuplicates || $sortOrder !== 'terbaru';

// Jumlah pendaftar yang menunggu tindakan admin, mengikuti filter unit dan tahun ajaran.
$actionCounts = ['menunggu_kontak' => 0, 'pembayaran_diperiksa' => 0];
$countConditions = [];
$countParameters = [];
if ($selectedUnit !== '') {
    $countConditions[] = 'kode_unit = ?';
    $countParameters[] = $selectedUnit;
}
if ($selectedYear !== '') {
    $countConditions[] = 'th_ajaran = ?';
    $countParameters[] = $selectedYear;
}
$countStatement = mysqli_prepare($conn, "SELECT SUM(status_ppdb='menunggu_kontak') AS menunggu_kontak, SUM(status_pembayaran='menunggu_verifikasi') AS pembayaran_diperiksa, SUM(status_ppdb<>'data_lama') AS total_aktif, SUM(status_ppdb='formulir_terisi') AS formulir_masuk, SUM(status_ppdb='diterima') AS diterima, SUM(status_ppdb IN ('formulir_terisi','diterima','daftar_tunggu') AND status_data<>'terverifikasi' AND COALESCE(revisi_dibuka,0)=0) AS belum_cek_kk FROM tb_pendaftaran".($countConditions ? ' WHERE '.implode(' AND ', $countConditions) : ''));
if ($countParameters) {
    mysqli_stmt_bind_param($countStatement, str_repeat('s', count($countParameters)), ...$countParameters);
}
mysqli_stmt_execute($countStatement);
$countRow = mysqli_fetch_assoc(mysqli_stmt_get_result($countStatement)) ?: [];
mysqli_stmt_close($countStatement);
foreach ($actionCounts as $key => $_count) {
    $actionCounts[$key] = (int) ($countRow[$key] ?? 0);
}
$progressStages = ['Registrasi', 'Pembayaran', 'Formulir', 'Verifikasi', 'Hasil'];

$activeAdminPage = 'registrations';
$adminPageTitle = 'Data peserta';
$adminPageDescription = 'Kelola seluruh data pendaftar SPMB, filter per status, unit, dan tahun ajaran.';
require 'admin_header.php';
?>
<div class="admin-content">
    <header class="page-heading">
        <div>
            <p class="eyebrow"><?php echo $adminUnitCode === '' ? 'Arsip penerimaan' : 'Arsip unit · '.h(ppdb_unit_display_name($_SESSION['admin_unit_name'] ?? '')); ?></p>
            <h1>Data peserta</h1>
            <p><?php echo $adminUnitCode === '' ? 'Daftar seluruh pendaftar. Gunakan filter untuk memisahkan peserta yang menunggu tindakan.' : 'Daftar pendaftar untuk unit pendidikan Anda. Data unit lain tidak ditampilkan.'; ?></p>
        </div>
        <?php $headingStats = [
            ['Total pendaftar', (int) ($countRow['total_aktif'] ?? 0), with_query(['status' => null, 'ganda' => null, 'q' => null, 'jalur' => null, 'page' => null]), 'tone-total'],
            ['Formulir masuk', (int) ($countRow['formulir_masuk'] ?? 0), with_query(['status' => 'formulir_terisi', 'page' => null]), 'tone-form'],
            ['Diterima', (int) ($countRow['diterima'] ?? 0), with_query(['status' => 'diterima', 'page' => null]), 'tone-ok'],
            ['Belum cek KK', (int) ($countRow['belum_cek_kk'] ?? 0), 'verifikasi-data.php'.(ppdb_is_super_admin() && $selectedUnit !== '' ? '?unit='.rawurlencode($selectedUnit) : ''), 'tone-kk'],
        ]; ?>
        <div class="heading-stats">
            <?php foreach ($headingStats as [$statLabel, $statValue, $statHref, $statTone]) { ?>
                <a class="heading-stat <?php echo $statTone; ?>" href="<?php echo h($statHref); ?>"><strong><?php echo number_format($statValue, 0, ',', '.'); ?></strong><span><?php echo h($statLabel); ?></span></a>
            <?php } ?>
            <?php if ($hasFilter) { ?><a class="button-secondary heading-stats__reset" href="daftar_peserta.php">Reset filter</a><?php } ?>
        </div>
    </header>

    <?php if ($waNotice) { ?>
        <div class="<?php echo $waNotice['ok'] ? 'alert-success' : 'alert-error'; ?>" role="status">
            <div>
                <?php echo h($waNotice['text']); ?>
                <?php if (!empty($waNotice['links'])) { ?>
                    <div class="table-actions" style="margin-top: 8px;">
                        <?php foreach ($waNotice['links'] as $link) { ?><a class="table-action stage-wa" href="<?php echo h($link['url']); ?>" target="_blank" rel="noopener">WA <?php echo h($link['role']); ?> · <?php echo h($link['display']); ?></a><?php } ?>
                    </div>
                <?php } ?>
            </div>
        </div>
    <?php } ?>

    <section class="panel">
        <form class="filter-bar filter-bar--compact" action="daftar_peserta.php" method="get">
            <div class="filter-field grow">
                <label for="q">Cari peserta</label>
                <div class="search-field">
                    <?php echo ppdb_admin_icon('search'); ?>
                    <input id="q" type="search" name="q" value="<?php echo h($search); ?>" placeholder="Nama, kode, atau NISN · tekan Enter" enterkeyhint="search">
                </div>
            </div>
            <div class="filter-field">
                <label for="jalur">Jalur</label>
                <select id="jalur" name="jalur">
                    <option value="">Semua jalur</option>
                    <option value="internal"<?php echo $selectedTrack === 'internal' ? ' selected' : ''; ?>>Internal · SDM</option>
                    <option value="eksternal"<?php echo $selectedTrack === 'eksternal' ? ' selected' : ''; ?>>Eksternal · umum</option>
                    <?php if ($hasSelectionRoute) { ?>
                        <optgroup label="Jalur seleksi SMP">
                            <option value="prestasi"<?php echo $selectedTrack === 'prestasi' ? ' selected' : ''; ?>>Prestasi</option>
                            <option value="umum"<?php echo $selectedTrack === 'umum' ? ' selected' : ''; ?>>Umum</option>
                        </optgroup>
                    <?php } ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">Semua status</option>
                    <?php foreach ($statusOptions as $value => $label) { ?>
                        <option value="<?php echo h($value); ?>"<?php echo $selectedStatus === $value ? ' selected' : ''; ?>><?php echo h($label); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="year">Tahun ajaran</label>
                <select id="year" name="year">
                    <option value="">Semua tahun</option>
                    <?php while ($year = mysqli_fetch_assoc($yearOptions)) { ?>
                        <option value="<?php echo h($year['th_ajaran']); ?>"<?php echo $selectedYear === $year['th_ajaran'] ? ' selected' : ''; ?>><?php echo h($year['th_ajaran']); ?></option>
                    <?php } ?>
                </select>
            </div>
            <?php if ($adminUnitCode === '') { ?><div class="filter-field">
                <label for="unit">Unit pendidikan</label>
                <select id="unit" name="unit">
                    <option value="">Semua unit</option>
                    <?php while ($unit = mysqli_fetch_assoc($unitOptions)) { ?>
                        <option value="<?php echo h($unit['kode_unit']); ?>"<?php echo $selectedUnit === $unit['kode_unit'] ? ' selected' : ''; ?>><?php echo h($unit['nama_unit']); ?></option>
                    <?php } ?>
                </select>
            </div><?php } ?>
            <div class="filter-field">
                <label for="ganda">Data ganda</label>
                <select id="ganda" name="ganda">
                    <option value="">Semua data</option>
                    <option value="1"<?php echo $onlyDuplicates ? ' selected' : ''; ?>>Hanya data ganda</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="urut">Urutkan</label>
                <select id="urut" name="urut">
                    <option value="">Terbaru mendaftar</option>
                    <option value="antrean"<?php echo $sortOrder === 'antrean' ? ' selected' : ''; ?>>Nomor antrean</option>
                </select>
            </div>
            <div class="filter-actions">
                <button class="button-primary" type="submit">Terapkan</button>
                <?php if ($hasFilter) { ?><a class="button-secondary" href="daftar_peserta.php">Reset</a><?php } ?>
            </div>
        </form>

        <?php if ($actionCounts['menunggu_kontak'] > 0 || $actionCounts['pembayaran_diperiksa'] > 0 || in_array($selectedStatus, ['menunggu_kontak', 'pembayaran_diperiksa'], true)) { ?>
            <nav class="action-shortcuts" aria-label="Perlu tindakan">
                <span class="action-shortcuts__label">Perlu tindakan</span>
                <?php foreach (['menunggu_kontak' => 'Menunggu persetujuan', 'pembayaran_diperiksa' => 'Bukti bayar masuk'] as $statusKey => $statusText) {
                    $active = $selectedStatus === $statusKey; ?>
                    <a class="action-shortcut<?php echo $active ? ' is-active' : ''; ?><?php echo $actionCounts[$statusKey] > 0 ? ' has-items' : ''; ?>" href="<?php echo h(with_query(['status' => $active ? null : $statusKey, 'page' => null])); ?>"<?php echo $active ? ' aria-current="true"' : ''; ?>>
                        <?php echo h($statusText); ?><strong><?php echo (int) $actionCounts[$statusKey]; ?></strong>
                    </a>
                <?php } ?>
            </nav>
        <?php } ?>

        <div class="table-meta">
            <span>Menampilkan <strong><?php echo $totalRows > 0 ? $offset + 1 : 0; ?>&ndash;<?php echo min($offset + $perPage, $totalRows); ?></strong> dari <strong><?php echo $totalRows; ?></strong> peserta</span>
            <?php if ($totalPages > 1) { ?><span>Halaman <?php echo $currentPage; ?> dari <?php echo $totalPages; ?></span><?php } ?>
        </div>

        <?php if (mysqli_num_rows($registrations) === 0) { ?>
            <div class="empty-state">
                <span class="empty-state__icon"><?php echo ppdb_admin_icon('inbox'); ?></span>
                <strong><?php echo $hasFilter ? 'Tidak ada hasil untuk filter ini' : 'Belum ada pendaftar'; ?></strong>
                <p><?php echo $hasFilter ? 'Coba ubah kata kunci atau atur ulang filter.' : 'Pendaftaran yang masuk akan tampil di sini secara otomatis.'; ?></p>
            </div>
        <?php } else { ?>
            <div class="table-wrap table-wrap--sticky-head">
                <table class="data-table data-table--single-line data-table--freeze">
                    <thead>
                        <tr>
                            <th scope="col">Kode</th>
                            <th scope="col">Antrean</th>
                            <th scope="col">Nama peserta</th>
                            <th scope="col">Daftar oleh</th>
                            <th scope="col">Unit</th>
                            <th scope="col">Jalur</th>
                            <th scope="col">Tahun</th>
                            <th scope="col">Status</th>
                            <th scope="col">Tanggal</th>
                            <th scope="col">Aksi</th>
                            <?php foreach ($progressStages as $stage) { ?>
                                <th scope="col" class="stage-col"><?php echo h($stage); ?></th>
                            <?php } ?>
                            <th scope="col" class="stage-col" title="Data formulir sudah dicocokkan dengan scan KK, akta, dan KTP">Cek KK</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($row = mysqli_fetch_assoc($registrations)) {
                        $meta = ppdb_admin_status_meta($row['status_ppdb']);
                        $statusInfo = ppdb_status_info($row['status_ppdb']);
                        $isRejected = $row['status_ppdb'] === 'ditolak';
                        $progressStep = $isRejected ? ppdb_rejected_completed_steps($row) + 1 : (int) $statusInfo['step'];
                        $isArchived = $progressStep === 0;
                        $isAccepted = $row['status_ppdb'] === 'diterima';
                        ?>
                        <tr data-row-id="<?php echo h($row['id_pendaftaran']); ?>">
                            <td data-label="Kode"><code class="code-chip"><?php echo h($row['id_pendaftaran']); ?></code></td>
                            <td data-label="Antrean"><?php echo $row['nomor_antrian'] ? '#'.(int) $row['nomor_antrian'] : '<span class="muted-dash">&mdash;</span>'; ?></td>
                            <td data-label="Nama" class="primary-cell">
                                <a class="participant-link" href="detail-peserta.php?id=<?php echo rawurlencode($row['id_pendaftaran']); ?>&amp;kembali=<?php echo rawurlencode(with_query([])); ?>" data-participant="<?php echo h($row['id_pendaftaran']); ?>" data-reminder="<?php echo h(ppdb_reminder_stage($row)); ?>" data-reminder-note="<?php echo h(ppdb_reminder_stage($row) === '' ? ppdb_reminder_unavailable_note($row) : ''); ?>" data-verify="<?php echo h((string) $row['status_data']); ?>" aria-haspopup="menu"><?php echo h($row['nm_peserta']); ?></a>
                                <?php if (!empty($row['data_ganda'])) { ?><a class="duplicate-badge" href="<?php echo h(with_query(['ganda' => '1', 'q' => $row['nm_peserta'], 'page' => null])); ?>" title="Ada pendaftaran lain dengan nama dan tanggal lahir atau NIK yang sama pada tahun ajaran ini">Ganda</a><?php } ?>
                                <?php if ($row['NISN'] !== '') { ?><small class="cell-sub">NISN <?php echo h($row['NISN']); ?></small><?php } ?>
                            </td>
                            <td data-label="Daftar oleh"><?php echo h(ppdb_pendaftar_label($row['pendaftar'])); ?></td>
                            <td data-label="Unit"><?php echo h(ppdb_unit_display_name($row['nama_unit'] ?? 'Data lama')); ?><?php if (!empty($row['tingkat_kelas'])) { ?> <span class="unit-level">· <?php echo h($row['tingkat_kelas']); ?></span><?php } ?></td>
                            <td data-label="Jalur"><?php echo $row['jalur_pendaftaran'] === 'internal' ? 'Internal · SDM' : 'Eksternal · umum'; ?></td>
                            <td data-label="Tahun"><?php echo h($row['th_ajaran']); ?></td>
                            <td data-label="Status"><span class="status-pill tone-<?php echo h($meta['tone']); ?>"><?php echo h($meta['label']); ?></span><?php if ($row['status_pembayaran'] === 'menunggu_verifikasi') { ?><small class="cell-sub">Bukti transfer belum diperiksa</small><?php } elseif ($row['status_pembayaran'] === 'ditolak' && $row['status_ppdb'] === 'formulir_terisi') { ?><small class="cell-sub">Menunggu bukti transfer baru</small><?php } ?></td>
                            <td data-label="Tanggal"><?php echo h(date('d-m-Y', strtotime($row['tgl_daftar']))); ?></td>
                            <td data-label="Aksi">
                                <div class="table-actions">
                                    <a class="table-action" href="detail-peserta.php?id=<?php echo rawurlencode($row['id_pendaftaran']); ?>&amp;kembali=<?php echo rawurlencode(with_query([])); ?>">Detail</a>
                                    <?php if (in_array($row['status_pembayaran'], ['terverifikasi', 'menunggu_verifikasi'], true) && in_array($row['status_ppdb'], ['menunggu_formulir', 'formulir_terisi', 'ditolak', 'diterima', 'daftar_tunggu'], true)) { ?>
                                        <form action="revisi-formulir.php" method="post" class="revision-action" data-revisi>
                                            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="id" value="<?php echo h($row['id_pendaftaran']); ?>">
                                            <input type="hidden" name="return" value="<?php echo h(with_query([])); ?>">
                                            <button class="table-action revision-action__button" type="submit" title="Buka kembali formulir dan kirim tautannya ke WhatsApp wali murid">Revisi</button>
                                            <span class="revision-action__status" data-revisi-status aria-live="polite"></span>
                                            <?php
                                            // Formulir sedang dibuka untuk revisi: tautan dapat dikirim ulang manual tanpa membuka revisi lagi.
                                            $revisionOpen = $row['status_data'] === 'perlu_perbaikan' && ($row['status_ppdb'] === 'menunggu_formulir' || !empty($row['revisi_dibuka']));
                                            $manualRecipients = $revisionOpen && !empty($row['token_bukti']) ? ppdb_registrant_recipients($row) : [];
                                            if ($manualRecipients) {
                                                $manualText = implode("\n", ppdb_revision_message_lines($row['id_pendaftaran'], (string) $row['nm_peserta'], ppdb_continue_url($row)));
                                                ?>
                                                <details class="manual-wa-menu">
                                                    <summary class="table-action stage-wa" title="Kirim ulang tautan revisi lewat WhatsApp secara manual">WA revisi</summary>
                                                    <div class="manual-wa-menu__list">
                                                        <?php foreach ($manualRecipients as $number => $role) { ?><a href="<?php echo h(ppdb_whatsapp_link($number, $manualText)); ?>" target="_blank" rel="noopener noreferrer"><?php echo h($role); ?> · <?php echo h(ppdb_display_phone($number)); ?></a><?php } ?>
                                                    </div>
                                                </details>
                                            <?php } ?>
                                        </form>
                                    <?php } ?>
                                    <form action="hapus-peserta.php" method="post" data-confirm="Hapus pendaftaran ini? Data Dapodik, dokumen, dan bukti pembayaran terkait juga akan terhapus.">
                                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="id" value="<?php echo h($row['id_pendaftaran']); ?>">
                                        <button class="table-action danger" type="submit">Hapus</button>
                                    </form>
                                </div>
                            </td>
                            <?php if ($isArchived) { ?>
                                <td data-label="Proses" class="stage-cell stage-cell--archived" colspan="<?php echo count($progressStages); ?>">
                                    <span class="stage-state is-upcoming">Arsip lama · tidak menggunakan alur SPMB aktif</span>
                                </td>
                            <?php } else {
                                foreach ($progressStages as $index => $stage) {
                                    $stageNumber = $index + 1;
                                    $isDone = $stageNumber < $progressStep || $isAccepted;
                                    $isCurrent = !$isDone && $stageNumber === $progressStep; ?>
                                <td data-label="<?php echo h($stage); ?>" class="stage-cell">
                                    <?php if ($isDone) { ?>
                                        <span class="stage-state is-complete">&#10003; Selesai</span>
                                        <?php if ($stage === 'Registrasi' || $stage === 'Hasil') {
                                            $isCompletePrint = $stage === 'Hasil'; ?>
                                            <a class="stage-print" href="cetak-registrasi.php?id=<?php echo rawurlencode($row['id_pendaftaran']).($isCompletePrint ? '&amp;berkas=lengkap' : ''); ?>" target="_blank" rel="noopener" title="<?php echo $isCompletePrint ? 'Cetak data lengkap peserta, orang tua, wali, dan berkas (A4)' : 'Cetak registrasi, bukti transfer, dan berkas jalur prestasi (A4)'; ?>" aria-label="<?php echo $isCompletePrint ? 'Cetak data lengkap' : 'Cetak registrasi'; ?> <?php echo h($row['nm_peserta']); ?>"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 9V3h12v6"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v7H6z"/></svg></a>
                                        <?php } ?>
                                    <?php } elseif ($isCurrent) { ?>
                                        <div class="stage-current">
                                            <span class="status-pill tone-<?php echo h($meta['tone']); ?>"><?php echo h($meta['label']); ?></span>
                                        </div>
                                    <?php } else { ?>
                                        <span class="stage-state <?php echo $isRejected ? 'is-stopped' : 'is-upcoming'; ?>"><?php echo $isRejected ? 'Terhenti' : 'Belum'; ?></span>
                                    <?php } ?>
                                </td>
                            <?php }
                            } ?>
                            <td data-label="Cek KK" class="stage-cell kk-cell">
                                <?php $kkVerified = $row['status_data'] === 'terverifikasi';
                                $kkFormIn = $kkVerified || in_array($row['status_ppdb'], ['formulir_terisi', 'diterima', 'daftar_tunggu'], true) || !empty($row['revisi_dibuka']);
                                if ($kkFormIn) { ?>
                                    <a class="kk-mark <?php echo $kkVerified ? 'is-ok' : 'is-no'; ?>" href="verifikasi-data.php?id=<?php echo rawurlencode($row['id_pendaftaran']); ?>" title="<?php echo $kkVerified ? 'Sudah diverifikasi · sesuai KK' : 'Belum diverifikasi · klik untuk mencocokkan'; ?>"><?php echo $kkVerified ? '&#10003; Sesuai' : '&#10007; Belum'; ?></a>
                                <?php } else { ?>
                                    <span class="stage-state is-upcoming" title="Formulir lengkap belum masuk">&ndash;</span>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

        <?php if ($totalPages > 1) { ?>
            <nav class="pagination" aria-label="Navigasi halaman">
                <?php if ($currentPage > 1) { ?>
                    <a class="page-step" href="<?php echo h(with_query(['page' => $currentPage - 1])); ?>" rel="prev">&larr; Sebelumnya</a>
                <?php } else { ?>
                    <span class="page-step is-disabled">&larr; Sebelumnya</span>
                <?php } ?>

                <div class="page-numbers">
                    <?php for ($page = 1; $page <= $totalPages; $page++) {
                        if ($page === 1 || $page === $totalPages || abs($page - $currentPage) <= 1) {
                            echo '<a class="page-number'.($page === $currentPage ? ' is-current' : '').'" href="'.h(with_query(['page' => $page])).'">'.$page.'</a>';
                        } elseif (abs($page - $currentPage) === 2) {
                            echo '<span class="page-gap">&hellip;</span>';
                        }
                    } ?>
                </div>

                <?php if ($currentPage < $totalPages) { ?>
                    <a class="page-step" href="<?php echo h(with_query(['page' => $currentPage + 1])); ?>" rel="next">Berikutnya</a>
                <?php } else { ?>
                    <span class="page-step is-disabled">Berikutnya</span>
                <?php } ?>
            </nav>
        <?php } ?>
    </section>
</div>
<div class="name-menu" data-name-menu data-csrf="<?php echo h(csrf_token()); ?>" role="menu" hidden>
    <button type="button" role="menuitem" data-name-preview><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5C6.5 5 2.7 9.2 1.5 12c1.2 2.8 5 7 10.5 7s9.3-4.2 10.5-7C21.3 9.2 17.5 5 12 5Zm0 11.5a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9Zm0-2.2a2.3 2.3 0 1 0 0-4.6 2.3 2.3 0 0 0 0 4.6Z"/></svg><span>Pratinjau<small>Lihat data lengkap di panel samping</small></span></button>
    <a role="menuitem" href="#" data-name-detail><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M14 3h7v7h-2V6.4l-8.3 8.3-1.4-1.4L17.6 5H14V3ZM5 5h6v2H5v12h12v-6h2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z"/></svg><span>Lihat detail<small>Buka halaman detail dan tindakan</small></span></a>
    <a role="menuitem" href="#" class="name-menu__verify" data-name-verify><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 3h11l5 5v6h-2V9h-4V5H6v14h7v2H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm4 8h8v2H8v-2Zm0 4h5v2H8v-2Zm12.3 1.3 1.4 1.4-4.7 4.7-2.7-2.7 1.4-1.4 1.3 1.3 3.3-3.3Z"/></svg><span>Verifikasi data vs KK<small data-verify-hint>Cocokkan isian dengan scan KK, akta, KTP</small></span><b class="name-menu__badge" data-verify-badge aria-hidden="true"></b></a>
    <button type="button" role="menuitem" class="name-menu__reminder" data-name-reminder><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22Zm7-6V11a7 7 0 0 0-5.5-6.84V3.5a1.5 1.5 0 0 0-3 0v.66A7 7 0 0 0 5 11v5l-2 2v1h18v-1l-2-2Z"/></svg><span>Kirim pengingat WA<small data-reminder-hint>Ingatkan orang tua untuk melanjutkan proses</small></span></button>
</div>

<dialog class="reminder-dialog" data-reminder-dialog aria-labelledby="reminder-title">
    <form method="dialog" class="reminder-dialog__close-form"><button type="submit" class="pv-close" aria-label="Tutup"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6z"/></svg></button></form>
    <header class="reminder-dialog__head">
        <span class="reminder-dialog__icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22Zm7-6V11a7 7 0 0 0-5.5-6.84V3.5a1.5 1.5 0 0 0-3 0v.66A7 7 0 0 0 5 11v5l-2 2v1h18v-1l-2-2Z"/></svg></span>
        <div>
            <p class="reminder-dialog__eyebrow">Pengingat WhatsApp</p>
            <h2 id="reminder-title" data-reminder-name>Memuat…</h2>
            <p class="reminder-dialog__meta" data-reminder-meta></p>
        </div>
    </header>
    <div class="reminder-dialog__body" data-reminder-body>
        <div class="pv-loading" aria-label="Memuat data"><span></span><span></span><span></span></div>
    </div>
    <footer class="reminder-dialog__foot" data-reminder-foot hidden>
        <div class="reminder-dialog__manual" data-reminder-manual></div>
        <div class="reminder-dialog__buttons">
            <button type="button" class="button-secondary" data-reminder-cancel>Batal</button>
            <button type="button" class="reminder-dialog__send" data-reminder-send>Kirim otomatis</button>
        </div>
    </footer>
</dialog>

<dialog class="pv-drawer" data-preview-drawer aria-labelledby="pv-title">
    <div class="pv-drawer__bar">
        <div class="pv-drawer__nav">
            <button type="button" data-preview-prev aria-label="Peserta sebelumnya" title="Peserta sebelumnya (←)">&lsaquo;</button>
            <span data-preview-position aria-live="polite"></span>
            <button type="button" data-preview-next aria-label="Peserta berikutnya" title="Peserta berikutnya (→)">&rsaquo;</button>
        </div>
        <div class="pv-drawer__actions">
            <a class="button-secondary" href="#" target="_blank" rel="noopener" data-preview-print>Cetak</a>
            <a class="button-primary" href="#" data-preview-detail>Lihat detail</a>
            <button type="button" class="pv-close" data-preview-close aria-label="Tutup pratinjau"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6z"/></svg></button>
        </div>
    </div>
    <div class="pv-drawer__body" data-preview-body tabindex="-1"></div>
</dialog>
<?php require 'admin_footer.php'; ?>
