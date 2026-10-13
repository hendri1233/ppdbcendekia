<?php
require_once __DIR__.'/admin_cms.php';

$pages = [
    'tentang' => ['label' => 'Tentang', 'public' => 'tentang.php'],
    'kurikulum' => ['label' => 'Kurikulum JSIT', 'public' => 'kurikulum.php'],
];
$pageKey = isset($pages[$_GET['halaman'] ?? '']) ? $_GET['halaman'] : 'tentang';
$self = 'cms-halaman.php?halaman='.$pageKey;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cms_require_post_token($self);
    try {
        if (($_POST['action'] ?? '') === 'save_meta') {
            if ($pageKey === 'tentang') {
                cms_save_setting($conn, 'leader_name', cms_post_text('leader_name', 120));
                cms_save_setting($conn, 'leader_role', cms_post_text('leader_role', 160));
            } else {
                cms_save_setting($conn, 'curriculum_intro', cms_post_text('curriculum_intro', 600));
            }
            cms_flash('success', 'Pengantar halaman disimpan.');
            cms_redirect($self.'#pengantar');
        }

        if (($_POST['action'] ?? '') === 'save_section') {
            $key = is_string($_POST['section_key'] ?? null) ? $_POST['section_key'] : '';
            $stmt = mysqli_prepare($conn, 'SELECT image_path FROM tb_cms_sections WHERE page=? AND section_key=?');
            mysqli_stmt_bind_param($stmt, 'ss', $pageKey, $key);
            mysqli_stmt_execute($stmt);
            $section = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            if (!$section) {
                throw new RuntimeException('Bagian halaman tidak ditemukan.');
            }
            $title = cms_post_text('title', 160);
            if ($title === '') {
                throw new RuntimeException('Judul bagian wajib diisi.');
            }
            $subtitle = cms_post_text('subtitle', 255);
            $body = ppdb_sanitize_rich_text((string) ($_POST['body_html'] ?? ''));
            $image = cms_process_image_field('image', $section['image_path'], 'halaman', 1600);
            $update = mysqli_prepare($conn, 'UPDATE tb_cms_sections SET title=?,subtitle=?,body_html=?,image_path=? WHERE page=? AND section_key=?');
            mysqli_stmt_bind_param($update, 'ssssss', $title, $subtitle, $body, $image, $pageKey, $key);
            mysqli_stmt_execute($update);
            cms_flash('success', '"'.$title.'" disimpan.');
            cms_redirect($self.'#bagian-'.$key);
        }
    } catch (RuntimeException $exception) {
        cms_flash('error', $exception->getMessage());
        cms_redirect($self);
    }
}

$stmt = mysqli_prepare($conn, 'SELECT * FROM tb_cms_sections WHERE page=? ORDER BY sort_order,id');
mysqli_stmt_bind_param($stmt, 's', $pageKey);
mysqli_stmt_execute($stmt);
$sections = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

$imageLabels = ['prakata' => 'Foto pimpinan (potret 4:5)'];

$activeAdminPage = 'cms-pages';
$adminPageTitle = 'Halaman '.$pages[$pageKey]['label'];
$adminPageDescription = 'Isi halaman Tentang dan Kurikulum JSIT di situs yayasan.';
require 'admin_header.php';
?>
<div class="admin-content cms-page">
    <?php cms_render_flash(); ?>
    <header class="page-heading">
        <div>
            <p class="eyebrow">Situs web</p>
            <h1>Halaman <?php echo h($pages[$pageKey]['label']); ?></h1>
            <p>Bagian yang belum diisi tampil sebagai "sedang disiapkan" di situs, bukan teks contoh.</p>
        </div>
        <a class="button-secondary" href="<?php echo h($pages[$pageKey]['public']); ?>" target="_blank" rel="noopener">Lihat halaman <span aria-hidden="true">↗</span></a>
    </header>

    <nav class="cms-tabs" aria-label="Pilih halaman">
        <?php foreach ($pages as $key => $page) { ?>
            <a href="cms-halaman.php?halaman=<?php echo h($key); ?>"<?php echo $key === $pageKey ? ' aria-current="page"' : ''; ?>><?php echo h($page['label']); ?></a>
        <?php } ?>
        <?php if ($pageKey === 'tentang') { ?>
            <a href="cms-koleksi.php?jenis=manajemen">Profil manajemen</a>
            <a href="cms-koleksi.php?jenis=fasilitas">Fasilitas</a>
        <?php } ?>
    </nav>

    <section class="panel" id="pengantar">
        <div class="panel-header"><div><h2><?php echo $pageKey === 'tentang' ? 'Identitas pimpinan' : 'Pengantar kurikulum'; ?></h2><span class="panel-subtitle"><?php echo $pageKey === 'tentang' ? 'Nama dan jabatan yang tampil di bawah foto prakata.' : 'Paragraf pembuka di bagian atas halaman Kurikulum JSIT.'; ?></span></div></div>
        <form class="cms-form" method="post" data-loading-form>
            <?php echo cms_csrf_field(); ?>
            <input type="hidden" name="action" value="save_meta">
            <div class="cms-grid">
                <?php if ($pageKey === 'tentang') { ?>
                    <div class="cms-field"><label class="cms-label" for="leader-name">Nama pimpinan</label><input id="leader-name" name="leader_name" value="<?php echo h(cms_setting($conn, 'leader_name', 'Ilawarni')); ?>" maxlength="120"></div>
                    <div class="cms-field"><label class="cms-label" for="leader-role">Jabatan</label><input id="leader-role" name="leader_role" value="<?php echo h(cms_setting($conn, 'leader_role', 'Pimpinan Yayasan Wakaf Cendekia Takengon')); ?>" maxlength="160"></div>
                <?php } else { ?>
                    <div class="cms-field cms-field--wide"><label class="cms-label" for="curriculum-intro">Pengantar</label><textarea id="curriculum-intro" name="curriculum_intro" rows="3" maxlength="600"><?php echo h(cms_setting($conn, 'curriculum_intro')); ?></textarea><span class="cms-hint">Kosongkan untuk memakai teks bawaan.</span></div>
                <?php } ?>
            </div>
            <div class="cms-actions"><button class="button-primary" type="submit">Simpan</button></div>
        </form>
    </section>

    <?php foreach ($sections as $section) { $key = $section['section_key']; $filled = trim((string) $section['body_html']) !== ''; ?>
        <section class="panel" id="bagian-<?php echo h($key); ?>">
            <div class="panel-header">
                <div><h2><?php echo h($section['title']); ?></h2><span class="panel-subtitle">Tampil di <?php echo h($pages[$pageKey]['public']); ?>#<?php echo h($key === 'visi_misi' ? 'visi-misi' : $key); ?></span></div>
                <span class="status-pill <?php echo $filled ? 'is-open' : 'is-closed'; ?>"><?php echo $filled ? 'Terisi' : 'Belum diisi'; ?></span>
            </div>
            <form class="cms-form" method="post" enctype="multipart/form-data" data-loading-form>
                <?php echo cms_csrf_field(); ?>
                <input type="hidden" name="action" value="save_section">
                <input type="hidden" name="section_key" value="<?php echo h($key); ?>">
                <div class="cms-split">
                    <div class="cms-grid">
                        <div class="cms-field"><label class="cms-label" for="title-<?php echo h($key); ?>">Judul</label><input id="title-<?php echo h($key); ?>" name="title" value="<?php echo h($section['title']); ?>" maxlength="160" required></div>
                        <div class="cms-field"><label class="cms-label" for="subtitle-<?php echo h($key); ?>">Subjudul</label><input id="subtitle-<?php echo h($key); ?>" name="subtitle" value="<?php echo h($section['subtitle']); ?>" maxlength="255"></div>
                        <?php echo cms_rich_editor('body_html', $section['body_html'], 'Isi'); ?>
                    </div>
                    <?php echo cms_image_field('image', $section['image_path'], $imageLabels[$key] ?? 'Gambar pendukung (opsional)'); ?>
                </div>
                <div class="cms-actions"><button class="button-primary" type="submit">Simpan bagian</button></div>
            </form>
        </section>
    <?php } ?>
</div>
<?php echo cms_admin_scripts(); ?>
<?php require 'admin_footer.php'; ?>
