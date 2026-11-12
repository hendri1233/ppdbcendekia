<?php
/**
 * Verifikasi data per peserta: scan KK/akta/KTP di kiri, isian formulir di kanan.
 * Admin mencentang tiap isian (cocok / salah). Bila semua cocok, status data menjadi
 * terverifikasi lalu lanjut ke peserta berikutnya; bila ada yang salah, formulir
 * dibuka untuk revisi lewat revisi-formulir.php dengan catatan isian yang keliru.
 */
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
require_once 'formulir_helpers.php';
require_admin();
require_once __DIR__.'/admin-partials.php';

const VERIFIKASI_STATUSES = ['formulir_terisi', 'diterima', 'daftar_tunggu'];

/** Unit yang boleh dilihat: admin unit hanya unitnya sendiri; super admin dapat menyaring lewat ?unit=. */
$unitFilter = ppdb_is_super_admin() ? (string) ($_GET['unit'] ?? $_POST['unit'] ?? '') : (string) $_SESSION['admin_unit_code'];
if ($unitFilter !== '' && !preg_match('/^[A-Za-z0-9_-]{1,20}$/', $unitFilter)) {
    $unitFilter = '';
}

/** Peserta berikutnya yang formulirnya sudah masuk tetapi datanya belum terverifikasi. */
function verifikasi_queue(mysqli $conn, string $unitFilter, string $excludeId = ''): array
{
    $statusList = "'".implode("','", VERIFIKASI_STATUSES)."'";
    $where = "p.status_ppdb IN ($statusList) AND p.status_data<>'terverifikasi' AND COALESCE(p.revisi_dibuka,0)=0 AND p.id_pendaftaran<>?";
    $params = [$excludeId];
    if ($unitFilter !== '') {
        $where .= ' AND p.kode_unit=?';
        $params[] = $unitFilter;
    }
    $stmt = mysqli_prepare($conn, "SELECT p.id_pendaftaran,p.nm_peserta,(SELECT COUNT(*) FROM tb_pendaftaran p WHERE $where) AS sisa FROM tb_pendaftaran p WHERE $where ORDER BY p.waktu_daftar, p.id_pendaftaran LIMIT 1");
    $all = array_merge($params, $params);
    mysqli_stmt_bind_param($stmt, str_repeat('s', count($all)), ...$all);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return ['next' => $row, 'remaining' => (int) ($row['sisa'] ?? 0)];
}

function verifikasi_url(string $registrationId, string $unitFilter): string
{
    $query = ['id' => $registrationId];
    if ($unitFilter !== '' && ppdb_is_super_admin()) {
        $query['unit'] = $unitFilter;
    }
    return 'verifikasi-data.php?'.http_build_query($query);
}

$registrationId = (string) ($_GET['id'] ?? $_POST['id'] ?? '');

// Tanpa id: langsung buka peserta pertama dalam antrean.
if ($registrationId === '') {
    $queue = verifikasi_queue($conn, $unitFilter);
    if ($queue['next']) {
        header('Location: '.verifikasi_url($queue['next']['id_pendaftaran'], $unitFilter));
        exit;
    }
}

if ($registrationId !== '') {
    if (!preg_match('/^P[0-9]{9}$/', $registrationId)) {
        http_response_code(404);
        exit('Data peserta tidak ditemukan.');
    }
    $access = mysqli_prepare($conn, 'SELECT kode_unit FROM tb_pendaftaran WHERE id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($access, 's', $registrationId);
    mysqli_stmt_execute($access);
    $accessRow = mysqli_fetch_assoc(mysqli_stmt_get_result($access));
    mysqli_stmt_close($access);
    if (!$accessRow || (!ppdb_is_super_admin() && $accessRow['kode_unit'] !== $_SESSION['admin_unit_code'])) {
        http_response_code(404);
        exit('Data peserta tidak ditemukan.');
    }
}

$flash = $_SESSION['verifikasi_flash'] ?? null;
unset($_SESSION['verifikasi_flash']);

if ($registrationId !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = '';
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif (($_POST['aksi'] ?? '') === 'verifikasi') {
        try {
            mysqli_begin_transaction($conn);
            $lock = mysqli_prepare($conn, 'SELECT p.status_ppdb,p.nm_peserta,(SELECT COUNT(*) FROM tb_detail_dapodik d WHERE d.id_pendaftaran=p.id_pendaftaran) AS ada_formulir FROM tb_pendaftaran p WHERE p.id_pendaftaran=? FOR UPDATE');
            mysqli_stmt_bind_param($lock, 's', $registrationId);
            mysqli_stmt_execute($lock);
            $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);
            if (!$locked || !(int) $locked['ada_formulir']) {
                throw new RuntimeException('Formulir lengkap peserta ini belum masuk.');
            }
            $adminId = (int) $_SESSION['admin_id'];
            $update = mysqli_prepare($conn, "UPDATE tb_pendaftaran SET status_data='terverifikasi',data_diperiksa_oleh=?,data_diperiksa_pada=NOW() WHERE id_pendaftaran=?");
            mysqli_stmt_bind_param($update, 'is', $adminId, $registrationId);
            mysqli_stmt_execute($update);
            mysqli_stmt_close($update);
            mysqli_commit($conn);
            enqueue_google_sheets_registration($conn, $registrationId);
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Status data belum dapat disimpan.';
        }
        if ($error === '') {
            $_SESSION['verifikasi_flash'] = ['success', 'Data '.$locked['nm_peserta'].' ditandai terverifikasi.'];
            $queue = verifikasi_queue($conn, $unitFilter, $registrationId);
            $target = $queue['next'] ? verifikasi_url($queue['next']['id_pendaftaran'], $unitFilter) : verifikasi_url($registrationId, $unitFilter);
            header('Location: '.$target);
            exit;
        }
    } else {
        $error = 'Aksi tidak dikenali.';
    }
    $_SESSION['verifikasi_flash'] = ['error', $error];
    header('Location: '.verifikasi_url($registrationId, $unitFilter));
    exit;
}

$participant = null;
$documents = [];
$checks = [];
if ($registrationId !== '') {
    // d.* lebih dahulu agar kolom tb_pendaftaran (p.*) tidak tertimpa NULL bila detail belum ada.
    $stmt = mysqli_prepare($conn, 'SELECT d.*,p.*,u.nama_unit,u.jenjang,(SELECT COUNT(*) FROM tb_detail_dapodik x WHERE x.id_pendaftaran=p.id_pendaftaran) AS ada_formulir FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit LEFT JOIN tb_detail_dapodik d ON d.id_pendaftaran=p.id_pendaftaran WHERE p.id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
    mysqli_stmt_execute($stmt);
    $participant = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $participant += formulir_flatten_parent_profiles(formulir_parent_profiles($conn, $registrationId));

    $documentQuery = mysqli_prepare($conn, 'SELECT id,jenis_dokumen,mime_type FROM tb_dokumen_peserta WHERE id_pendaftaran=?');
    mysqli_stmt_bind_param($documentQuery, 's', $registrationId);
    mysqli_stmt_execute($documentQuery);
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($documentQuery), MYSQLI_ASSOC) as $row) {
        $documents[$row['jenis_dokumen']] = $row;
    }
    mysqli_stmt_close($documentQuery);

    $value = static fn (string $key): string => trim((string) ($participant[$key] ?? ''));
    $joinParts = static fn (array $parts): string => implode(', ', array_filter(array_map('trim', $parts), static fn ($part) => $part !== ''));
    $rtRw = $value('rt') !== '' || $value('rw') !== '' ? 'RT '.($value('rt') ?: '-').' / RW '.($value('rw') ?: '-') : '';
    $birthDate = $value('tgl_lahir') !== '' ? date('d-m-Y', strtotime($value('tgl_lahir'))) : '';

    // [kunci, label, nilai, jenis tampilan, dokumen pembanding]
    $checks = [
        'Ananda' => [
            ['nomor_kk', 'Nomor KK', $value('nomor_kk'), 'nomor', ['kk']],
            ['nik', 'NIK ananda', $value('nik'), 'nomor', ['kk']],
            ['nm_peserta', 'Nama lengkap', $value('nm_peserta'), 'teks', ['kk', 'akta_lahir']],
            ['jenis_kelamin', 'Jenis kelamin', ppdb_jenis_kelamin_label($participant['jenis_kelamin'] ?? null), 'teks', ['kk', 'akta_lahir']],
            ['tmp_lahir', 'Tempat lahir', $value('tmp_lahir'), 'teks', ['kk', 'akta_lahir']],
            ['tgl_lahir', 'Tanggal lahir', $birthDate, 'teks', ['kk', 'akta_lahir']],
            ['agama', 'Agama', $value('agama'), 'teks', ['kk']],
            ['nomor_registrasi_akta', 'Nomor akta kelahiran', $value('nomor_registrasi_akta'), 'teks', ['akta_lahir']],
        ],
        'Alamat (sesuai KK)' => [
            ['alamat', 'Alamat', $joinParts([$value('alamat_jalan'), $rtRw, $value('dusun')]), 'teks', ['kk']],
            ['wilayah', 'Desa · kecamatan · kabupaten', $joinParts([$value('desa_kelurahan'), $value('kecamatan'), $value('kabupaten'), $value('provinsi')]), 'teks', ['kk']],
        ],
        'Ayah' => [
            ['ayah_nama', 'Nama ayah', $value('ayah_nama'), 'teks', ['kk', 'ktp_ayah', 'akta_lahir']],
            ['ayah_nik', 'NIK ayah', $value('ayah_nik'), 'nomor', ['ktp_ayah', 'kk']],
            ['ayah_tempat_lahir', 'Tempat lahir ayah', $value('ayah_tempat_lahir'), 'teks', ['ktp_ayah']],
            ['ayah_tahun_lahir', 'Tahun lahir ayah', $value('ayah_tahun_lahir'), 'teks', ['ktp_ayah']],
        ],
        'Ibu' => [
            ['ibu_nama', 'Nama ibu', $value('ibu_nama'), 'teks', ['kk', 'ktp_ibu', 'akta_lahir']],
            ['ibu_nik', 'NIK ibu', $value('ibu_nik'), 'nomor', ['ktp_ibu', 'kk']],
            ['ibu_tempat_lahir', 'Tempat lahir ibu', $value('ibu_tempat_lahir'), 'teks', ['ktp_ibu']],
            ['ibu_tahun_lahir', 'Tahun lahir ibu', $value('ibu_tahun_lahir'), 'teks', ['ktp_ibu']],
        ],
    ];
    if ($value('NISN') !== '') {
        $checks['Ananda'][] = ['NISN', 'NISN', $value('NISN'), 'nomor', ['nisn']];
    }
    if ($value('wali_nama') !== '') {
        $checks['Wali'] = [
            ['wali_nama', 'Nama wali', $value('wali_nama'), 'teks', []],
            ['wali_nik', 'NIK wali', $value('wali_nik'), 'nomor', []],
        ];
    }
    // Ibu/ayah yang sudah meninggal tidak selalu punya KTP: tempat & tahun lahir cukup dari KK.
    foreach (['Ayah' => 'ayah', 'Ibu' => 'ibu'] as $group => $role) {
        if ($value($role.'_status_hidup') === 'Meninggal dunia') {
            $checks[$group] = array_values(array_filter($checks[$group], static fn ($row) => in_array($row[0], [$role.'_nama', $role.'_nik'], true)));
        }
    }
}

$documentLabels = [
    'kk' => 'Kartu Keluarga',
    'akta_lahir' => 'Akta kelahiran',
    'ktp_ayah' => 'KTP ayah',
    'ktp_ibu' => 'KTP ibu',
    'nisn' => 'NISN',
];
$queue = verifikasi_queue($conn, $unitFilter, $registrationId);

// Antrean kosong: tampilkan formulir yang sudah terverifikasi agar dapat dicek ulang.
$verifiedList = [];
if (!$participant) {
    $statusList = "'".implode("','", VERIFIKASI_STATUSES)."'";
    $sql = "SELECT p.id_pendaftaran,p.nm_peserta,p.data_diperiksa_pada,u.nama_unit FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit WHERE p.status_ppdb IN ($statusList) AND p.status_data='terverifikasi'";
    if ($unitFilter !== '') {
        $stmt = mysqli_prepare($conn, $sql.' AND p.kode_unit=? ORDER BY p.data_diperiksa_pada DESC LIMIT 30');
        mysqli_stmt_bind_param($stmt, 's', $unitFilter);
    } else {
        $stmt = mysqli_prepare($conn, $sql.' ORDER BY p.data_diperiksa_pada DESC LIMIT 30');
    }
    mysqli_stmt_execute($stmt);
    $verifiedList = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
}

/** NIK/KK ditampilkan per 4 digit agar mudah dicocokkan dengan KK. */
function verifikasi_format_number(string $number): string
{
    return preg_match('/^[0-9]{16}$/', $number) ? trim(chunk_split($number, 4, ' ')) : $number;
}

$activeAdminPage = 'registrations';
$adminPageTitle = 'Verifikasi data';
$adminPageDescription = 'Cocokkan isian formulir peserta dengan scan KK, akta, dan KTP.';
require 'admin_header.php';
?>
<link rel="stylesheet" href="css/verifikasi-data.css?v=<?php echo (int) filemtime(__DIR__.'/css/verifikasi-data.css'); ?>">
<div class="admin-content verify">
    <div class="page-heading">
        <div>
            <p class="eyebrow">Verifikasi data · uji coba</p>
            <?php if ($participant) { ?>
                <h1><?php echo h($participant['nm_peserta']); ?></h1>
                <p><?php echo h($registrationId); ?> · <?php echo h(ppdb_unit_display_name($participant['nama_unit'] ?? '')); ?> · <?php echo h(ppdb_status_info($participant['status_ppdb'])['label']); ?> · Status data: <strong><?php echo h(['belum_diperiksa' => 'Belum diperiksa', 'terverifikasi' => 'Terverifikasi', 'perlu_perbaikan' => 'Perlu perbaikan'][$participant['status_data']] ?? $participant['status_data']); ?></strong></p>
            <?php } else { ?>
                <h1>Semua data sudah diverifikasi</h1>
                <p>Tidak ada formulir masuk yang menunggu pencocokan data.</p>
            <?php } ?>
        </div>
        <div class="table-actions">
            <?php if ($participant) { ?><a class="button-secondary" href="detail-peserta.php?id=<?php echo rawurlencode($registrationId); ?>">Buka detail peserta</a><?php } ?>
            <a class="button-secondary" href="daftar_peserta.php">&larr; Daftar peserta</a>
        </div>
    </div>

    <?php if ($flash) { ?><div class="<?php echo $flash[0] === 'error' ? 'alert-error' : 'alert-success'; ?>" role="status"><?php echo h($flash[1]); ?></div><?php } ?>

    <?php if ($participant && !(int) $participant['ada_formulir']) { ?>
        <div class="alert-warning" role="status">Formulir lengkap peserta ini belum masuk, jadi belum ada data untuk dicocokkan.</div>
    <?php } elseif ($participant) { ?>
    <div class="verify__layout" data-verify data-registration="<?php echo h($registrationId); ?>">
        <section class="verify__viewer detail-panel" aria-label="Dokumen pembanding">
            <div class="verify__tabs" role="tablist">
                <?php $firstDoc = true; foreach ($documentLabels as $slot => $label) {
                    $document = $documents[$slot] ?? null; ?>
                    <button type="button" role="tab" class="verify__tab<?php echo $document ? '' : ' is-missing'; ?>" data-doc-tab="<?php echo h($slot); ?>" aria-selected="<?php echo $firstDoc && $document ? 'true' : 'false'; ?>"<?php echo $document ? '' : ' disabled title="Belum diunggah"'; ?>><?php echo h($label); ?></button>
                <?php if ($document) { $firstDoc = false; } } ?>
            </div>
            <div class="verify__stage">
                <?php $shown = false; foreach ($documentLabels as $slot => $label) {
                    $document = $documents[$slot] ?? null;
                    if (!$document) { continue; }
                    $src = 'dokumen-admin.php?id='.(int) $document['id']; ?>
                    <div class="verify__doc" data-doc="<?php echo h($slot); ?>"<?php echo $shown ? ' hidden' : ''; ?>>
                        <?php if ($document['mime_type'] === 'application/pdf') { ?>
                            <iframe src="<?php echo h($src); ?>" title="<?php echo h($label); ?>" loading="lazy"></iframe>
                        <?php } else { ?>
                            <div class="verify__img-wrap" data-zoom-wrap><img src="<?php echo h($src); ?>" alt="Scan <?php echo h($label); ?>" data-zoom-img draggable="false"></div>
                        <?php } ?>
                        <a class="verify__open" href="<?php echo h($src); ?>" target="_blank" rel="noopener">Buka di tab baru &nearr;</a>
                    </div>
                <?php $shown = true; } ?>
                <?php if (!$documents) { ?><p class="verify__empty">Pendaftar belum mengunggah dokumen.</p><?php } ?>
            </div>
            <div class="verify__zoom" aria-label="Kontrol gambar">
                <button type="button" class="button-secondary" data-zoom="-1" aria-label="Perkecil">−</button>
                <button type="button" class="button-secondary" data-zoom="0">Pas</button>
                <button type="button" class="button-secondary" data-zoom="1" aria-label="Perbesar">+</button>
                <button type="button" class="button-secondary" data-rotate aria-label="Putar">⟳ Putar</button>
            </div>
        </section>

        <section class="verify__checks detail-panel" aria-label="Isian yang dicocokkan">
            <?php if (!empty($participant['catatan_validasi'])) { ?>
                <div class="verify__flags">
                    <strong>Ditandai pemeriksaan NIK otomatis:</strong>
                    <ul><?php foreach (explode("\n", (string) $participant['catatan_validasi']) as $flagText) { ?><li><?php echo h($flagText); ?></li><?php } ?></ul>
                </div>
            <?php } ?>
            <p class="verify__hint">Tekan <kbd>✓</kbd> bila sama dengan dokumen, <kbd>✗</kbd> bila berbeda. Klik nama dokumen di bawah isian untuk menampilkannya. NIK dan nomor KK ditampilkan per 4 digit.</p>
            <?php foreach ($checks as $group => $rows) { ?>
                <h2 class="verify__group"><?php echo h($group); ?></h2>
                <ul class="verify__list">
                    <?php foreach ($rows as [$key, $label, $fieldValue, $kind, $sources]) { ?>
                        <li class="verify__row" data-check="<?php echo h($key); ?>" data-label="<?php echo h($label); ?>">
                            <div class="verify__field">
                                <span class="verify__label"><?php echo h($label); ?></span>
                                <span class="verify__value<?php echo $kind === 'nomor' ? ' is-number' : ''; ?><?php echo $fieldValue === '' ? ' is-empty' : ''; ?>"><?php echo h($fieldValue === '' ? 'Belum diisi' : ($kind === 'nomor' ? verifikasi_format_number($fieldValue) : $fieldValue)); ?></span>
                                <?php if ($sources) { ?>
                                    <span class="verify__sources">
                                        <?php foreach ($sources as $source) { ?><button type="button" class="verify__source" data-show-doc="<?php echo h($source); ?>"<?php echo isset($documents[$source]) ? '' : ' disabled'; ?>><?php echo h($documentLabels[$source]); ?></button><?php } ?>
                                    </span>
                                <?php } ?>
                                <input class="verify__note" type="text" maxlength="80" placeholder="Seharusnya… (opsional)" data-note hidden>
                            </div>
                            <div class="verify__marks" role="group" aria-label="<?php echo h($label); ?>">
                                <button type="button" class="verify__mark is-ok" data-mark="ok" aria-pressed="false" title="Cocok">✓</button>
                                <button type="button" class="verify__mark is-bad" data-mark="bad" aria-pressed="false" title="Berbeda">✗</button>
                            </div>
                        </li>
                    <?php } ?>
                </ul>
            <?php } ?>

            <div class="verify__footer">
                <p class="verify__progress" data-progress aria-live="polite"></p>
                <button type="button" class="button-secondary" data-mark-all>Tandai semua cocok</button>
                <form method="post" data-verify-form>
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="unit" value="<?php echo h(ppdb_is_super_admin() ? $unitFilter : ''); ?>">
                    <input type="hidden" name="aksi" value="verifikasi">
                    <button class="button-primary" type="submit" data-verify-submit disabled>Data sesuai · terverifikasi<?php echo $queue['next'] ? ' &amp; lanjut' : ''; ?></button>
                </form>
                <form action="revisi-formulir.php" method="post" data-verify-revision hidden>
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="catatan" value="" data-revision-note>
                    <p class="verify__revision-preview" data-revision-preview></p>
                    <button class="button-danger" type="submit">Kirim permintaan perbaikan ke wali murid</button>
                    <div class="verify__revision-result" data-revision-result aria-live="polite"></div>
                </form>
            </div>
        </section>
    </div>
    <?php } ?>

    <?php if ($verifiedList) { ?>
        <section class="detail-panel wide">
            <h2>Sudah terverifikasi · cek ulang</h2>
            <ul class="verify__done-list">
                <?php foreach ($verifiedList as $item) { ?>
                    <li><a href="<?php echo h(verifikasi_url($item['id_pendaftaran'], $unitFilter)); ?>"><strong><?php echo h($item['nm_peserta']); ?></strong> · <?php echo h($item['id_pendaftaran']); ?> · <?php echo h(ppdb_unit_display_name($item['nama_unit'] ?? '')); ?><?php echo $item['data_diperiksa_pada'] ? ' · diperiksa '.h(date('d-m-Y H:i', strtotime($item['data_diperiksa_pada']))) : ''; ?></a></li>
                <?php } ?>
            </ul>
        </section>
    <?php } ?>

    <div class="next-participant verify__queue">
        <?php if ($queue['next']) { ?>
            <span>Masih ada <strong><?php echo $queue['remaining']; ?></strong> formulir yang belum diverifikasi<?php echo $unitFilter !== '' && ppdb_is_super_admin() ? ' di unit ini' : ''; ?>.</span>
            <a class="next-participant__button" href="<?php echo h(verifikasi_url($queue['next']['id_pendaftaran'], $unitFilter)); ?>">Lewati, ke <?php echo h($queue['next']['nm_peserta']); ?> <span aria-hidden="true">&rarr;</span></a>
        <?php } else { ?>
            <span>Tidak ada formulir lain yang menunggu verifikasi.</span>
        <?php } ?>
    </div>
</div>
<script src="js/verifikasi-data.js?v=<?php echo (int) filemtime(__DIR__.'/js/verifikasi-data.js'); ?>" defer></script>
<?php require 'admin_footer.php'; ?>
