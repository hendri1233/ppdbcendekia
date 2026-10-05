<?php
require_once 'koneksi.php';
require_once 'app_helpers.php';
require_admin();
require_once __DIR__ . '/admin-partials.php';

$search = trim($_GET['q'] ?? '');
$selectedYear = trim($_GET['year'] ?? '');
$selectedUnit = trim($_GET['unit'] ?? '');
$selectedStatus = trim($_GET['status'] ?? '');

$statusOptions = [
    'menunggu_kontak' => 'Menunggu dihubungi',
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
if ($selectedStatus !== '') {
    $conditions[] = 'p.status_ppdb = ?';
    $parameters[] = $selectedStatus;
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

$query = 'SELECT p.id_pendaftaran,p.NISN,p.nm_peserta,p.th_ajaran,p.tgl_daftar,p.waktu_daftar,p.nomor_antrian,p.status_ppdb,p.status_pembayaran,p.pendaftar,u.nama_unit FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON p.kode_unit=u.kode_unit'
    .$whereSql
    .' ORDER BY FIELD(p.status_ppdb,\'menunggu_kontak\',\'pembayaran_diperiksa\',\'pembayaran_terverifikasi\',\'formulir_terisi\',\'menunggu_pembayaran\',\'menunggu_formulir\',\'daftar_tunggu\',\'diterima\',\'ditolak\',\'data_lama\'), p.waktu_daftar DESC, p.id_pendaftaran DESC'
    .' LIMIT '.intdiv($perPage * ($currentPage - 1) + $perPage, $perPage).' OFFSET '.$offset;

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

$yearOptions = mysqli_query($conn, "SELECT DISTINCT th_ajaran FROM tb_pendaftaran WHERE th_ajaran <> '' ORDER BY th_ajaran DESC");
$unitOptions = mysqli_query($conn, "SELECT kode_unit, nama_unit FROM tb_unit_pendidikan ORDER BY FIELD(jenjang, 'TK', 'SD', 'SMP')");

/** Menyusun ulang query string sambil mengganti satu parameter. */
function with_query(array $overrides): string
{
    $query = array_merge($_GET, $overrides);
    $query = array_filter($query, static fn ($value) => $value !== '' && $value !== null);
    return '?' . http_build_query($query);
}

$hasFilter = $search !== '' || $selectedYear !== '' || $selectedUnit !== '' || $selectedStatus !== '';

$activeAdminPage = 'registrations';
$adminPageTitle = 'Data peserta';
$adminPageDescription = 'Kelola seluruh data pendaftar PPDB, filter per status, unit, dan tahun ajaran.';
require 'admin_header.php';
?>
<div class="admin-content">
    <header class="page-heading">
        <div>
            <p class="eyebrow">ARSIP PENERIMAAN</p>
            <h1>Data peserta</h1>
            <p>Daftar seluruh pendaftar. Gunakan filter untuk memisahkan peserta yang menunggu tindakan.</p>
        </div>
        <div class="table-actions">
            <?php if ($hasFilter) { ?><a class="button-secondary" href="daftar_peserta.php">Reset filter</a><?php } ?>
        </div>
    </header>

    <section class="panel">
        <form class="filter-bar" action="daftar_peserta.php" method="get">
            <div class="filter-field grow">
                <label for="q">Cari peserta</label>
                <div class="search-field">
                    <?php echo ppdb_admin_icon('search'); ?>
                    <input id="q" type="search" name="q" value="<?php echo h($search); ?>" placeholder="Nama, kode pendaftaran, atau NISN">
                </div>
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
            <div class="filter-field">
                <label for="unit">Unit pendidikan</label>
                <select id="unit" name="unit">
                    <option value="">Semua unit</option>
                    <?php while ($unit = mysqli_fetch_assoc($unitOptions)) { ?>
                        <option value="<?php echo h($unit['kode_unit']); ?>"<?php echo $selectedUnit === $unit['kode_unit'] ? ' selected' : ''; ?>><?php echo h($unit['nama_unit']); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="filter-actions">
                <button class="button-primary" type="submit">Terapkan</button>
                <?php if ($hasFilter) { ?><a class="button-secondary" href="daftar_peserta.php">Reset</a><?php } ?>
            </div>
        </form>

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
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">KODE</th>
                            <th scope="col">ANTREAN</th>
                            <th scope="col">NAMA PESERTA</th>
                            <th scope="col">DAFTAR OLEH</th>
                            <th scope="col">UNIT</th>
                            <th scope="col">TAHUN</th>
                            <th scope="col">STATUS</th>
                            <th scope="col">TANGGAL</th>
                            <th scope="col">AKSI</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($row = mysqli_fetch_assoc($registrations)) {
                        $meta = ppdb_admin_status_meta($row['status_ppdb']); ?>
                        <tr>
                            <td data-label="Kode"><code class="code-chip"><?php echo h($row['id_pendaftaran']); ?></code></td>
                            <td data-label="Antrean"><?php echo $row['nomor_antrian'] ? '#'.(int) $row['nomor_antrian'] : '<span class="muted-dash">&mdash;</span>'; ?></td>
                            <td data-label="Nama" class="primary-cell">
                                <a href="detail-peserta.php?id=<?php echo rawurlencode($row['id_pendaftaran']); ?>"><?php echo h($row['nm_peserta']); ?></a>
                                <?php if ($row['NISN'] !== '') { ?><small class="cell-sub">NISN <?php echo h($row['NISN']); ?></small><?php } ?>
                            </td>
                            <td data-label="Daftar oleh"><?php echo h(ppdb_pendaftar_label($row['pendaftar'])); ?></td>
                            <td data-label="Unit"><?php echo h($row['nama_unit'] ?? 'Data lama'); ?></td>
                            <td data-label="Tahun"><?php echo h($row['th_ajaran']); ?></td>
                            <td data-label="Status"><span class="status-pill tone-<?php echo h($meta['tone']); ?>"><?php echo h($meta['label']); ?></span></td>
                            <td data-label="Tanggal"><?php echo h(date('d-m-Y', strtotime($row['tgl_daftar']))); ?></td>
                            <td data-label="Aksi">
                                <div class="table-actions">
                                    <a class="table-action" href="detail-peserta.php?id=<?php echo rawurlencode($row['id_pendaftaran']); ?>">Detail</a>
                                    <form action="hapus-peserta.php" method="post" data-confirm="Hapus pendaftaran ini? Data Dapodik, dokumen, dan bukti pembayaran terkait juga akan terhapus.">
                                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                        <input type="hidden" name="id" value="<?php echo h($row['id_pendaftaran']); ?>">
                                        <button class="table-action danger" type="submit">Hapus</button>
                                    </form>
                                </div>
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
                    <a class="page-step" href="<?php echo h(with_query(['page' => $currentPage + 1])); ?>" rel="next">Berikutnya &rarr;</a>
                <?php } else { ?>
                    <span class="page-step is-disabled">Berikutnya &rarr;</span>
                <?php } ?>
            </nav>
        <?php } ?>
    </section>
</div>
<script src="js/admin.js"></script>
<?php require 'admin_footer.php'; ?>
