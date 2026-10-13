<?php
require_once __DIR__.'/admin_cms.php';

$units = cms_units($conn);
$codes = array_column($units, 'kode_unit');
$selected = in_array($_GET['kode'] ?? '', $codes, true) ? $_GET['kode'] : ($codes[0] ?? '');
$self = 'cms-unit.php?kode='.rawurlencode($selected);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cms_require_post_token($self);
    try {
        $code = in_array($_POST['kode_unit'] ?? '', $codes, true) ? $_POST['kode_unit'] : '';
        if ($code === '') {
            throw new RuntimeException('Unit tidak ditemukan.');
        }
        $current = null;
        foreach ($units as $unit) {
            if ($unit['kode_unit'] === $code) {
                $current = $unit;
            }
        }
        $tagline = cms_post_text('tagline', 200);
        $phone = cms_post_text('phone', 30);
        if ($phone !== '' && !preg_match('/^[0-9 +().-]{6,30}$/', $phone)) {
            throw new RuntimeException('Nomor telepon hanya boleh berisi angka, spasi, +, (, ), titik, atau tanda hubung.');
        }
        $description = ppdb_sanitize_rich_text((string) ($_POST['description_html'] ?? ''));
        $photo = cms_process_image_field('photo', $current['photo_path'] ?? null, 'unit', 1600);
        $stmt = mysqli_prepare($conn, 'INSERT INTO tb_cms_unit_profiles (kode_unit,tagline,description_html,photo_path,phone) VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE tagline=VALUES(tagline),description_html=VALUES(description_html),photo_path=VALUES(photo_path),phone=VALUES(phone)');
        mysqli_stmt_bind_param($stmt, 'sssss', $code, $tagline, $description, $photo, $phone);
        mysqli_stmt_execute($stmt);
        cms_flash('success', 'Profil '.ppdb_unit_display_name($current['nama_unit']).' disimpan.');
        cms_redirect('cms-unit.php?kode='.rawurlencode($code));
    } catch (RuntimeException $exception) {
        cms_flash('error', $exception->getMessage());
        cms_redirect($self);
    }
}

$unit = null;
foreach ($units as $row) {
    if ($row['kode_unit'] === $selected) {
        $unit = $row;
    }
}

$activeAdminPage = 'cms-units';
$adminPageTitle = 'Profil unit';
$adminPageDescription = 'Foto, ringkasan, dan uraian setiap unit pendidikan di situs yayasan.';
require 'admin_header.php';
?>
<div class="admin-content cms-page">
    <?php cms_render_flash(); ?>
    <header class="page-heading">
        <div>
            <p class="eyebrow">Situs web</p>
            <h1>Profil unit</h1>
            <p>Nama, jenjang, NPSN, alamat, dan email unit diambil dari data PPDB dan tidak diubah di sini.</p>
        </div>
        <?php if ($unit) { ?><a class="button-secondary" href="unit.php?kode=<?php echo rawurlencode($unit['kode_unit']); ?>" target="_blank" rel="noopener">Lihat halaman unit <span aria-hidden="true">↗</span></a><?php } ?>
    </header>

    <nav class="cms-tabs" aria-label="Pilih unit">
        <?php foreach ($units as $row) { $filled = trim((string) $row['description_html']) !== ''; ?>
            <a href="cms-unit.php?kode=<?php echo rawurlencode($row['kode_unit']); ?>"<?php echo $row['kode_unit'] === $selected ? ' aria-current="page"' : ''; ?>><?php echo h($row['jenjang']); ?><span class="cms-tabs__dot<?php echo $filled ? ' is-filled' : ''; ?>" title="<?php echo $filled ? 'Profil terisi' : 'Profil belum diisi'; ?>"></span></a>
        <?php } ?>
    </nav>

    <?php if ($unit) { ?>
        <section class="panel">
            <div class="panel-header"><div><h2><?php echo h(ppdb_unit_display_name($unit['nama_unit'])); ?></h2><span class="panel-subtitle"><?php echo h(ppdb_jenjang_label($unit['jenjang'])); ?><?php echo $unit['npsn'] ? ' · NPSN '.h($unit['npsn']) : ''; ?></span></div></div>
            <form class="cms-form" method="post" enctype="multipart/form-data" data-loading-form>
                <?php echo cms_csrf_field(); ?>
                <input type="hidden" name="kode_unit" value="<?php echo h($unit['kode_unit']); ?>">
                <div class="cms-split">
                    <div class="cms-grid">
                        <div class="cms-field cms-field--wide"><label class="cms-label" for="unit-tagline">Ringkasan satu kalimat</label><input id="unit-tagline" name="tagline" value="<?php echo h($unit['tagline']); ?>" maxlength="200"><span class="cms-hint">Tampil di kartu unit pada beranda dan di bawah judul halaman unit.</span></div>
                        <div class="cms-field"><label class="cms-label" for="unit-phone">Telepon unit</label><input id="unit-phone" name="phone" value="<?php echo h($unit['phone']); ?>" maxlength="30" inputmode="tel"></div>
                        <?php echo cms_rich_editor('description_html', $unit['description_html'], 'Uraian profil unit', 'Program unggulan, kegiatan, dan informasi yang diverifikasi sekolah.'); ?>
                    </div>
                    <?php echo cms_image_field('photo', $unit['photo_path'], 'Foto unit (16:9)'); ?>
                </div>
                <div class="cms-actions"><button class="button-primary" type="submit">Simpan profil</button></div>
            </form>
        </section>
    <?php } ?>
</div>
<?php echo cms_admin_scripts(); ?>
<?php require 'admin_footer.php'; ?>
