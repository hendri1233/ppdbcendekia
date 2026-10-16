<?php
require_once __DIR__.'/admin_cms.php';

$contentTypes = [
    'program' => [
        'label' => 'Program yayasan',
        'categories' => ['pendidikan' => 'Pendidikan', 'dakwah' => 'Dakwah', 'sosial' => 'Sosial'],
        'people' => false,
        'imageRequired' => false,
    ],
    'gallery' => [
        'label' => 'Galeri kegiatan',
        'categories' => ['kegiatan' => 'Kegiatan', 'pembelajaran' => 'Pembelajaran', 'fasilitas' => 'Fasilitas'],
        'people' => false,
        'imageRequired' => true,
    ],
    'prestasi' => [
        'label' => 'Prestasi',
        'categories' => ['akademik' => 'Akademik', 'non-akademik' => 'Nonakademik', 'keagamaan' => 'Keagamaan'],
        'people' => true,
        'imageRequired' => false,
    ],
    'testimoni' => [
        'label' => 'Testimoni',
        'categories' => ['wali-murid' => 'Wali murid', 'guru' => 'Guru', 'mitra' => 'Mitra'],
        'people' => true,
        'imageRequired' => false,
    ],
    'alumni' => [
        'label' => 'Cerita alumni',
        'categories' => ['perjalanan' => 'Perjalanan alumni', 'karya' => 'Karya dan kontribusi'],
        'people' => true,
        'imageRequired' => false,
    ],
];
$type = is_string($_GET['jenis'] ?? null) && isset($contentTypes[$_GET['jenis']]) ? $_GET['jenis'] : 'program';
$typeConfig = $contentTypes[$type];
$self = 'cms-konten.php?jenis='.rawurlencode($type);
$units = cms_units($conn);
$unitCodes = array_column($units, 'kode_unit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cms_require_post_token($self);
    try {
        $action = $_POST['action'] ?? '';
        $id = (int) ($_POST['id'] ?? 0);
        $current = null;
        if ($id > 0) {
            $lookup = mysqli_prepare($conn, 'SELECT id,image_path FROM tb_cms_showcase WHERE id=? AND content_type=? LIMIT 1');
            mysqli_stmt_bind_param($lookup, 'is', $id, $type);
            mysqli_stmt_execute($lookup);
            $current = mysqli_fetch_assoc(mysqli_stmt_get_result($lookup));
            mysqli_stmt_close($lookup);
            if (!$current) {
                throw new RuntimeException('Konten tidak ditemukan. Muat ulang halaman lalu coba kembali.');
            }
        }

        if ($action === 'delete') {
            $delete = mysqli_prepare($conn, 'DELETE FROM tb_cms_showcase WHERE id=? AND content_type=?');
            mysqli_stmt_bind_param($delete, 'is', $id, $type);
            mysqli_stmt_execute($delete);
            mysqli_stmt_close($delete);
            cms_delete_image($current['image_path']);
            cms_flash('success', $typeConfig['label'].' dihapus.');
            cms_redirect($self);
        }
        if ($action !== 'save') {
            throw new RuntimeException('Perintah konten tidak dikenali.');
        }

        $title = cms_post_text('title', 160);
        if ($title === '') {
            throw new RuntimeException('Judul wajib diisi.');
        }
        $category = is_string($_POST['category'] ?? null) ? $_POST['category'] : '';
        if (!isset($typeConfig['categories'][$category])) {
            throw new RuntimeException('Pilih kategori konten yang tersedia.');
        }
        $unitCode = is_string($_POST['kode_unit'] ?? null) ? $_POST['kode_unit'] : '';
        if ($unitCode !== '' && !in_array($unitCode, $unitCodes, true)) {
            throw new RuntimeException('Unit pendidikan tidak ditemukan.');
        }
        $unitCode = $unitCode !== '' ? $unitCode : null;
        $subtitle = cms_post_text('subtitle', 200);
        $body = ppdb_sanitize_rich_text((string) ($_POST['body_html'] ?? ''));
        $personName = $typeConfig['people'] ? cms_post_text('person_name', 160) : '';
        $personRole = $typeConfig['people'] ? cms_post_text('person_role', 160) : '';
        $sortOrder = max(0, min(9999, (int) ($_POST['sort_order'] ?? 0)));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $image = cms_process_image_field('cover', $current['image_path'] ?? null, 'showcase', 1800);
        if ($typeConfig['imageRequired'] && !$image) {
            throw new RuntimeException('Galeri kegiatan wajib memiliki foto.');
        }

        if ($current) {
            $save = mysqli_prepare($conn, 'UPDATE tb_cms_showcase SET category=?,title=?,subtitle=?,body_html=?,image_path=?,person_name=?,person_role=?,kode_unit=?,sort_order=?,is_active=? WHERE id=? AND content_type=?');
            mysqli_stmt_bind_param($save, 'ssssssssiiis', $category, $title, $subtitle, $body, $image, $personName, $personRole, $unitCode, $sortOrder, $isActive, $id, $type);
            mysqli_stmt_execute($save);
            mysqli_stmt_close($save);
            cms_flash('success', $typeConfig['label'].' diperbarui.');
        } else {
            $save = mysqli_prepare($conn, 'INSERT INTO tb_cms_showcase (content_type,category,title,subtitle,body_html,image_path,person_name,person_role,kode_unit,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            mysqli_stmt_bind_param($save, 'sssssssssii', $type, $category, $title, $subtitle, $body, $image, $personName, $personRole, $unitCode, $sortOrder, $isActive);
            mysqli_stmt_execute($save);
            mysqli_stmt_close($save);
            cms_flash('success', $typeConfig['label'].' ditambahkan.');
        }
        cms_redirect($self);
    } catch (RuntimeException $exception) {
        cms_flash('error', $exception->getMessage());
        cms_redirect($self);
    }
}

$editId = max(0, (int) ($_GET['edit'] ?? 0));
$editing = null;
if ($editId > 0) {
    $editQuery = mysqli_prepare($conn, 'SELECT * FROM tb_cms_showcase WHERE id=? AND content_type=? LIMIT 1');
    mysqli_stmt_bind_param($editQuery, 'is', $editId, $type);
    mysqli_stmt_execute($editQuery);
    $editing = mysqli_fetch_assoc(mysqli_stmt_get_result($editQuery)) ?: null;
    mysqli_stmt_close($editQuery);
}
$list = mysqli_prepare($conn, 'SELECT id,category,title,subtitle,image_path,person_name,person_role,kode_unit,sort_order,is_active FROM tb_cms_showcase WHERE content_type=? ORDER BY sort_order,id DESC');
mysqli_stmt_bind_param($list, 's', $type);
mysqli_stmt_execute($list);
$items = mysqli_fetch_all(mysqli_stmt_get_result($list), MYSQLI_ASSOC);
mysqli_stmt_close($list);

$activeAdminPage = 'cms-showcase';
$adminPageTitle = $typeConfig['label'];
$adminPageDescription = 'Kelola konten program, galeri, prestasi, testimoni, dan cerita alumni situs Cendekia.';
require __DIR__.'/admin_header.php';
?>
<div class="admin-content cms-page">
    <?php cms_render_flash(); ?>
    <header class="page-heading">
        <div>
            <p class="eyebrow">Situs web</p>
            <h1>Program, galeri &amp; cerita</h1>
            <p>Terbitkan informasi yang sudah diverifikasi. Konten aktif langsung tampil di halaman publik.</p>
        </div>
    </header>

    <nav class="cms-tabs" aria-label="Jenis konten situs">
        <?php foreach ($contentTypes as $key => $config) { ?>
            <a href="cms-konten.php?jenis=<?php echo rawurlencode($key); ?>"<?php echo $key === $type ? ' aria-current="page"' : ''; ?>><?php echo h($config['label']); ?></a>
        <?php } ?>
    </nav>

    <section class="panel" id="editor">
        <div class="panel-header"><div><h2><?php echo $editing ? 'Edit konten' : 'Tambah '.$typeConfig['label']; ?></h2><span class="panel-subtitle">Urutan lebih kecil tampil lebih dahulu.</span></div></div>
        <form class="cms-form" method="post" enctype="multipart/form-data" data-loading-form>
            <?php echo cms_csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="jenis" value="<?php echo h($type); ?>">
            <?php if ($editing) { ?><input type="hidden" name="id" value="<?php echo (int) $editing['id']; ?>"><?php } ?>
            <div class="cms-split">
                <div class="cms-grid">
                    <div class="cms-field"><label class="cms-label" for="showcase-title">Judul</label><input id="showcase-title" name="title" maxlength="160" required value="<?php echo h($editing['title'] ?? ''); ?>"></div>
                    <div class="cms-field"><label class="cms-label" for="showcase-category">Kategori</label><select id="showcase-category" name="category" required><?php foreach ($typeConfig['categories'] as $key => $label) { ?><option value="<?php echo h($key); ?>"<?php echo ($editing['category'] ?? '') === $key ? ' selected' : ''; ?>><?php echo h($label); ?></option><?php } ?></select></div>
                    <div class="cms-field cms-field--wide"><label class="cms-label" for="showcase-subtitle">Ringkasan / keterangan singkat</label><input id="showcase-subtitle" name="subtitle" maxlength="200" value="<?php echo h($editing['subtitle'] ?? ''); ?>"></div>
                    <?php if ($typeConfig['people']) { ?>
                        <div class="cms-field"><label class="cms-label" for="person-name">Nama narasumber</label><input id="person-name" name="person_name" maxlength="160" value="<?php echo h($editing['person_name'] ?? ''); ?>"></div>
                        <div class="cms-field"><label class="cms-label" for="person-role">Peran / angkatan</label><input id="person-role" name="person_role" maxlength="160" value="<?php echo h($editing['person_role'] ?? ''); ?>"></div>
                    <?php } ?>
                    <div class="cms-field"><label class="cms-label" for="showcase-unit">Unit terkait</label><select id="showcase-unit" name="kode_unit"><option value="">Yayasan / semua unit</option><?php foreach ($units as $unit) { ?><option value="<?php echo h($unit['kode_unit']); ?>"<?php echo ($editing['kode_unit'] ?? '') === $unit['kode_unit'] ? ' selected' : ''; ?>><?php echo h(ppdb_unit_display_name($unit['nama_unit'])); ?></option><?php } ?></select></div>
                    <div class="cms-field"><label class="cms-label" for="showcase-order">Urutan tampil</label><input id="showcase-order" name="sort_order" type="number" min="0" max="9999" value="<?php echo (int) ($editing['sort_order'] ?? count($items) + 1); ?>"></div>
                    <?php echo cms_rich_editor('body_html', $editing['body_html'] ?? '', 'Uraian'); ?>
                    <label class="cms-check cms-field--wide"><input type="checkbox" name="is_active" value="1"<?php echo !$editing || !empty($editing['is_active']) ? ' checked' : ''; ?>> Tampilkan di situs publik</label>
                </div>
                <?php echo cms_image_field('cover', $editing['image_path'] ?? null, $typeConfig['imageRequired'] ? 'Foto galeri (wajib)' : 'Foto pendukung (opsional)', 'JPG, PNG, atau WebP, maks. 6 MB. Disimpan otomatis sebagai WebP.', !$typeConfig['imageRequired']); ?>
            </div>
            <div class="cms-actions"><button class="button-primary" type="submit"><?php echo $editing ? 'Simpan perubahan' : 'Tambah konten'; ?></button><?php if ($editing) { ?><a class="button-secondary" href="<?php echo h($self); ?>">Batal edit</a><?php } ?></div>
        </form>
    </section>

    <section class="panel" id="daftar-konten">
        <div class="panel-header"><div><h2>Konten <?php echo h(strtolower($typeConfig['label'])); ?></h2><span class="panel-subtitle"><?php echo count($items); ?> entri, termasuk draf tersembunyi.</span></div></div>
        <?php if (!$items) { ?><div class="empty-note"><strong>Belum ada konten.</strong><span>Tambahkan entri di formulir atas untuk mulai mengisi halaman publik.</span></div><?php } else { ?>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Konten</th><th>Kategori</th><th>Urutan</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
                <?php foreach ($items as $item) { ?>
                    <tr>
                        <td><strong><?php echo h($item['title']); ?></strong><?php if ($item['person_name']) { ?><br><small><?php echo h($item['person_name']); ?><?php echo $item['person_role'] ? ' · '.h($item['person_role']) : ''; ?></small><?php } ?></td>
                        <td><?php echo h($typeConfig['categories'][$item['category']] ?? $item['category']); ?></td>
                        <td><?php echo (int) $item['sort_order']; ?></td>
                        <td><?php echo $item['is_active'] ? 'Tampil' : 'Draf'; ?></td>
                        <td><div class="table-actions"><a class="button-secondary button-sm" href="<?php echo h($self.'&edit='.(int) $item['id'].'#editor'); ?>">Edit</a><form method="post" data-confirm="Hapus konten ini? Tindakan ini tidak dapat dibatalkan."><?php echo cms_csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>"><button class="button-danger button-sm" type="submit">Hapus</button></form></div></td>
                    </tr>
                <?php } ?>
            </tbody></table></div>
        <?php } ?>
    </section>
</div>
<?php echo cms_admin_scripts(); ?>
<?php require __DIR__.'/admin_footer.php'; ?>
