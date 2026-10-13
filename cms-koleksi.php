<?php
require_once __DIR__.'/admin_cms.php';

$types = [
    'manajemen' => [
        'label' => 'Profil manajemen',
        'single' => 'pengurus',
        'title' => 'Nama',
        'subtitle' => 'Jabatan',
        'description' => 'Profil singkat',
        'image' => 'Foto (persegi)',
        'anchor' => 'tentang.php#manajemen',
    ],
    'fasilitas' => [
        'label' => 'Fasilitas',
        'single' => 'fasilitas',
        'title' => 'Nama fasilitas',
        'subtitle' => 'Unit atau lokasi (opsional)',
        'description' => 'Keterangan',
        'image' => 'Foto fasilitas (16:10)',
        'anchor' => 'tentang.php#fasilitas',
    ],
];
$type = isset($types[$_GET['jenis'] ?? '']) ? $_GET['jenis'] : 'manajemen';
$meta = $types[$type];
$self = 'cms-koleksi.php?jenis='.$type;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cms_require_post_token($self);
    $action = $_POST['action'] ?? '';
    try {
        $id = (int) ($_POST['id'] ?? 0);
        $current = null;
        if ($id > 0) {
            $stmt = mysqli_prepare($conn, 'SELECT id,image_path FROM tb_cms_items WHERE id=? AND item_type=?');
            mysqli_stmt_bind_param($stmt, 'is', $id, $type);
            mysqli_stmt_execute($stmt);
            $current = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            if (!$current) {
                throw new RuntimeException('Data tidak ditemukan.');
            }
        }

        if ($action === 'delete' && $current) {
            $delete = mysqli_prepare($conn, 'DELETE FROM tb_cms_items WHERE id=?');
            mysqli_stmt_bind_param($delete, 'i', $id);
            mysqli_stmt_execute($delete);
            cms_delete_image($current['image_path']);
            cms_flash('success', ucfirst($meta['single']).' dihapus.');
            cms_redirect($self);
        }

        if ($action === 'save') {
            $title = cms_post_text('title', 160);
            if ($title === '') {
                throw new RuntimeException($meta['title'].' wajib diisi.');
            }
            $subtitle = cms_post_text('subtitle', 160);
            $description = cms_post_text('description', 1000);
            $order = (int) ($_POST['sort_order'] ?? 0);
            $active = !empty($_POST['is_active']) ? 1 : 0;
            $image = cms_process_image_field('image', $current['image_path'] ?? null, $type, $type === 'manajemen' ? 800 : 1400);
            if ($current) {
                $stmt = mysqli_prepare($conn, 'UPDATE tb_cms_items SET title=?,subtitle=?,description=?,image_path=?,sort_order=?,is_active=? WHERE id=?');
                mysqli_stmt_bind_param($stmt, 'ssssiii', $title, $subtitle, $description, $image, $order, $active, $id);
            } else {
                $stmt = mysqli_prepare($conn, 'INSERT INTO tb_cms_items (item_type,title,subtitle,description,image_path,sort_order,is_active) VALUES (?,?,?,?,?,?,?)');
                mysqli_stmt_bind_param($stmt, 'sssssii', $type, $title, $subtitle, $description, $image, $order, $active);
            }
            mysqli_stmt_execute($stmt);
            $newId = $current ? $id : mysqli_insert_id($conn);
            cms_flash('success', '"'.$title.'" disimpan.');
            cms_redirect($self.'#item-'.$newId);
        }
    } catch (RuntimeException $exception) {
        cms_flash('error', $exception->getMessage());
        cms_redirect($self);
    }
}

$items = cms_items($conn, $type, false);

$renderForm = static function (?array $item) use ($meta, $items): void {
    $id = (int) ($item['id'] ?? 0);
    $suffix = $id ?: 'baru';
    ?>
    <form class="cms-form cms-row" method="post" enctype="multipart/form-data" data-loading-form>
        <?php echo cms_csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <?php if ($id) { ?><input type="hidden" name="id" value="<?php echo $id; ?>"><?php } ?>
        <?php echo cms_image_field('image', $item['image_path'] ?? null, $meta['image']); ?>
        <div class="cms-grid">
            <div class="cms-field"><label class="cms-label" for="title-<?php echo $suffix; ?>"><?php echo h($meta['title']); ?></label><input id="title-<?php echo $suffix; ?>" name="title" value="<?php echo h($item['title'] ?? ''); ?>" maxlength="160" required></div>
            <div class="cms-field"><label class="cms-label" for="subtitle-<?php echo $suffix; ?>"><?php echo h($meta['subtitle']); ?></label><input id="subtitle-<?php echo $suffix; ?>" name="subtitle" value="<?php echo h($item['subtitle'] ?? ''); ?>" maxlength="160"></div>
            <div class="cms-field cms-field--wide"><label class="cms-label" for="desc-<?php echo $suffix; ?>"><?php echo h($meta['description']); ?></label><textarea id="desc-<?php echo $suffix; ?>" name="description" rows="3" maxlength="1000"><?php echo h($item['description'] ?? ''); ?></textarea></div>
            <div class="cms-field"><label class="cms-label" for="order-<?php echo $suffix; ?>">Urutan</label><input id="order-<?php echo $suffix; ?>" name="sort_order" type="number" min="0" max="999" value="<?php echo (int) ($item['sort_order'] ?? count($items) + 1); ?>"></div>
            <label class="cms-check"><input type="checkbox" name="is_active" value="1"<?php echo ($item['is_active'] ?? 1) ? ' checked' : ''; ?>> Tampilkan di situs</label>
        </div>
        <div class="cms-actions"><button class="button-primary" type="submit"><?php echo $id ? 'Simpan perubahan' : 'Tambah '.h($meta['single']); ?></button></div>
    </form>
    <?php
};

$activeAdminPage = 'cms-pages';
$adminPageTitle = $meta['label'];
$adminPageDescription = 'Kelola '.$meta['label'].' yang tampil di halaman Tentang.';
require 'admin_header.php';
?>
<div class="admin-content cms-page">
    <?php cms_render_flash(); ?>
    <header class="page-heading">
        <div>
            <p class="eyebrow">Situs web · Halaman Tentang</p>
            <h1><?php echo h($meta['label']); ?></h1>
            <p><?php echo count($items); ?> data · urutan kecil tampil lebih dulu.</p>
        </div>
        <a class="button-secondary" href="<?php echo h($meta['anchor']); ?>" target="_blank" rel="noopener">Lihat di situs <span aria-hidden="true">↗</span></a>
    </header>

    <nav class="cms-tabs" aria-label="Pilih halaman">
        <a href="cms-halaman.php?halaman=tentang">Tentang</a>
        <a href="cms-halaman.php?halaman=kurikulum">Kurikulum JSIT</a>
        <a href="cms-koleksi.php?jenis=manajemen"<?php echo $type === 'manajemen' ? ' aria-current="page"' : ''; ?>>Profil manajemen</a>
        <a href="cms-koleksi.php?jenis=fasilitas"<?php echo $type === 'fasilitas' ? ' aria-current="page"' : ''; ?>>Fasilitas</a>
    </nav>

    <section class="panel">
        <div class="panel-header"><div><h2>Tambah <?php echo h($meta['single']); ?></h2></div></div>
        <?php $renderForm(null); ?>
    </section>

    <?php if ($items) { ?>
        <section class="panel">
            <div class="panel-header"><div><h2>Daftar <?php echo h(strtolower($meta['label'])); ?></h2></div></div>
            <ul class="cms-list">
                <?php foreach ($items as $item) { ?>
                    <li class="cms-list__item<?php echo $item['is_active'] ? '' : ' is-inactive'; ?>" id="item-<?php echo (int) $item['id']; ?>">
                        <?php $renderForm($item); ?>
                        <form class="cms-delete" method="post" data-confirm="Hapus <?php echo h($item['title']); ?>? Foto akan ikut dihapus.">
                            <?php echo cms_csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                            <button class="button-danger" type="submit">Hapus</button>
                        </form>
                    </li>
                <?php } ?>
            </ul>
        </section>
    <?php } ?>
</div>
<?php echo cms_admin_scripts(); ?>
<?php require 'admin_footer.php'; ?>
