<?php
require_once __DIR__.'/admin_cms.php';

$self = 'cms-beranda.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cms_require_post_token($self);
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_text') {
            cms_save_setting($conn, 'home_headline', cms_post_text('home_headline', 120));
            cms_save_setting($conn, 'home_lead', cms_post_text('home_lead', 300));
            cms_save_setting($conn, 'home_intro_html', ppdb_sanitize_rich_text((string) ($_POST['home_intro_html'] ?? '')));
            cms_flash('success', 'Teks beranda disimpan.');
            cms_redirect($self.'#teks');
        }

        if ($action === 'save_interval') {
            $seconds = (int) ($_POST['slider_interval'] ?? 5);
            if ($seconds < 3 || $seconds > 30) {
                throw new RuntimeException('Durasi per slide harus antara 3 dan 30 detik.');
            }
            cms_save_setting($conn, 'slider_interval', (string) $seconds);
            cms_flash('success', 'Slide di beranda kini berganti setiap '.$seconds.' detik.');
            cms_redirect($self.'#slider');
        }

        if ($action === 'save_video') {
            $url = cms_post_text('home_video_url', 255);
            if ($url !== '' && cms_youtube_id($url) === '') {
                throw new RuntimeException('Tautan YouTube tidak dikenali. Salin tautan dari tombol Bagikan di YouTube, misalnya https://youtu.be/xxxxxxxxxxx.');
            }
            cms_save_setting($conn, 'home_video_url', $url);
            cms_save_setting($conn, 'home_video_title', cms_post_text('home_video_title', 120));
            cms_save_setting($conn, 'home_video_text', cms_post_text('home_video_text', 400));
            cms_flash('success', $url === '' ? 'Video dihapus dari beranda.' : 'Video beranda disimpan dan langsung tampil di situs.');
            cms_redirect($self.'#video');
        }

        if ($action === 'add_slide') {
            $upload = cms_uploaded_image('slide_image');
            if (!$upload) {
                throw new RuntimeException('Pilih gambar slide terlebih dahulu.');
            }
            $link = cms_post_text('link_url', 255);
            if (!cms_valid_url($link, false)) {
                throw new RuntimeException('Tautan slide harus diawali https:// atau berupa halaman situs, misalnya ppdb.php.');
            }
            $path = cms_store_image($upload, 'slider', 2400);
            $title = cms_post_text('title', 160);
            $caption = cms_post_text('caption', 300);
            $order = (int) ($_POST['sort_order'] ?? 0);
            $stmt = mysqli_prepare($conn, 'INSERT INTO tb_cms_slides (image_path,title,caption,link_url,sort_order,is_active) VALUES (?,?,?,?,?,1)');
            mysqli_stmt_bind_param($stmt, 'ssssi', $path, $title, $caption, $link, $order);
            mysqli_stmt_execute($stmt);
            cms_flash('success', 'Slide ditambahkan dan tampil di beranda.');
            cms_redirect($self.'#slider');
        }

        if ($action === 'update_slide' || $action === 'delete_slide') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = mysqli_prepare($conn, 'SELECT image_path FROM tb_cms_slides WHERE id=?');
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            $slide = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            if (!$slide) {
                throw new RuntimeException('Slide tidak ditemukan.');
            }
            if ($action === 'delete_slide') {
                $delete = mysqli_prepare($conn, 'DELETE FROM tb_cms_slides WHERE id=?');
                mysqli_stmt_bind_param($delete, 'i', $id);
                mysqli_stmt_execute($delete);
                cms_delete_image($slide['image_path']);
                cms_flash('success', 'Slide dihapus.');
                cms_redirect($self.'#slider');
            }
            $link = cms_post_text('link_url', 255);
            if (!cms_valid_url($link, false)) {
                throw new RuntimeException('Tautan slide harus diawali https:// atau berupa halaman situs, misalnya ppdb.php.');
            }
            $upload = cms_uploaded_image('slide_image_'.$id);
            $path = $slide['image_path'];
            if ($upload) {
                $path = cms_store_image($upload, 'slider', 2400);
                cms_delete_image($slide['image_path']);
            }
            $title = cms_post_text('title', 160);
            $caption = cms_post_text('caption', 300);
            $order = (int) ($_POST['sort_order'] ?? 0);
            $active = !empty($_POST['is_active']) ? 1 : 0;
            $update = mysqli_prepare($conn, 'UPDATE tb_cms_slides SET image_path=?,title=?,caption=?,link_url=?,sort_order=?,is_active=? WHERE id=?');
            mysqli_stmt_bind_param($update, 'ssssiii', $path, $title, $caption, $link, $order, $active, $id);
            mysqli_stmt_execute($update);
            cms_flash('success', 'Slide diperbarui.');
            cms_redirect($self.'#slide-'.$id);
        }
    } catch (RuntimeException $exception) {
        cms_flash('error', $exception->getMessage());
        cms_redirect($self);
    }
}

$slides = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM tb_cms_slides ORDER BY sort_order,id'), MYSQLI_ASSOC);
$videoUrl = cms_setting($conn, 'home_video_url');
$videoId = cms_youtube_id($videoUrl);

$activeAdminPage = 'cms-home';
$adminPageTitle = 'Beranda & slider';
$adminPageDescription = 'Atur slider, teks pembuka, dan video YouTube di beranda situs yayasan.';
require 'admin_header.php';
?>
<div class="admin-content cms-page">
    <?php cms_render_flash(); ?>
    <header class="page-heading">
        <div>
            <p class="eyebrow">Situs web</p>
            <h1>Beranda &amp; slider</h1>
            <p>Perubahan langsung tampil di beranda situs. Gambar slide disimpan sebagai WebP dan diperkecil otomatis.</p>
        </div>
        <a class="button-secondary" href="index.php" target="_blank" rel="noopener">Lihat beranda <span aria-hidden="true">↗</span></a>
    </header>

    <section class="panel" id="slider">
        <div class="panel-header">
            <div><h2>Slider beranda</h2><span class="panel-subtitle"><?php echo count($slides); ?> slide · urutan kecil tampil lebih dulu. Tanpa slide, beranda memakai foto wisuda bawaan.</span></div>
            <form class="cms-interval" method="post" data-loading-form>
                <?php echo cms_csrf_field(); ?>
                <input type="hidden" name="action" value="save_interval">
                <label class="cms-label" for="slider-interval">Durasi per slide</label>
                <span class="cms-interval__field"><input id="slider-interval" name="slider_interval" type="number" min="3" max="30" step="1" value="<?php echo (int) cms_setting($conn, 'slider_interval', '5'); ?>" required><span>detik</span></span>
                <button class="button-secondary" type="submit">Simpan</button>
            </form>
        </div>
        <form class="cms-form cms-form--inline" method="post" enctype="multipart/form-data" data-loading-form>
            <?php echo cms_csrf_field(); ?>
            <input type="hidden" name="action" value="add_slide">
            <?php echo cms_image_field('slide_image', null, 'Gambar slide baru', 'Rasio lebar 16:9 disarankan, minimal 1600 px. JPG, PNG, atau WebP, maks. 6 MB.', false); ?>
            <div class="cms-grid">
                <div class="cms-field"><label class="cms-label" for="new-title">Judul (opsional)</label><input id="new-title" name="title" maxlength="160"></div>
                <div class="cms-field"><label class="cms-label" for="new-order">Urutan</label><input id="new-order" name="sort_order" type="number" value="<?php echo count($slides) + 1; ?>" min="0" max="999"></div>
                <div class="cms-field cms-field--wide"><label class="cms-label" for="new-caption">Keterangan foto</label><input id="new-caption" name="caption" maxlength="300" placeholder="Contoh: Kegiatan manasik haji siswa TK, Mei 2026"><span class="cms-hint">Juga dipakai sebagai teks alternatif gambar untuk pembaca layar.</span></div>
                <div class="cms-field cms-field--wide"><label class="cms-label" for="new-link">Tautan (opsional)</label><input id="new-link" name="link_url" maxlength="255" placeholder="https://… atau ppdb.php"></div>
            </div>
            <div class="cms-actions"><button class="button-primary" type="submit">Tambah slide</button></div>
        </form>

        <?php if ($slides) { ?>
            <ul class="cms-list">
                <?php foreach ($slides as $slide) { $id = (int) $slide['id']; ?>
                    <li class="cms-list__item<?php echo $slide['is_active'] ? '' : ' is-inactive'; ?>" id="slide-<?php echo $id; ?>">
                        <form class="cms-form cms-row" method="post" enctype="multipart/form-data" data-loading-form>
                            <?php echo cms_csrf_field(); ?>
                            <input type="hidden" name="action" value="update_slide">
                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                            <?php echo cms_image_field('slide_image_'.$id, $slide['image_path'], 'Gambar', 'Pilih berkas untuk mengganti.', false); ?>
                            <div class="cms-grid">
                                <div class="cms-field"><label class="cms-label" for="title-<?php echo $id; ?>">Judul</label><input id="title-<?php echo $id; ?>" name="title" value="<?php echo h($slide['title']); ?>" maxlength="160"></div>
                                <div class="cms-field"><label class="cms-label" for="order-<?php echo $id; ?>">Urutan</label><input id="order-<?php echo $id; ?>" name="sort_order" type="number" value="<?php echo (int) $slide['sort_order']; ?>" min="0" max="999"></div>
                                <div class="cms-field cms-field--wide"><label class="cms-label" for="caption-<?php echo $id; ?>">Keterangan foto</label><input id="caption-<?php echo $id; ?>" name="caption" value="<?php echo h($slide['caption']); ?>" maxlength="300"></div>
                                <div class="cms-field cms-field--wide"><label class="cms-label" for="link-<?php echo $id; ?>">Tautan</label><input id="link-<?php echo $id; ?>" name="link_url" value="<?php echo h($slide['link_url']); ?>" maxlength="255"></div>
                                <label class="cms-check cms-field--wide"><input type="checkbox" name="is_active" value="1"<?php echo $slide['is_active'] ? ' checked' : ''; ?>> Tampilkan di beranda</label>
                            </div>
                            <div class="cms-actions">
                                <button class="button-primary" type="submit">Simpan slide</button>
                            </div>
                        </form>
                        <form class="cms-delete" method="post" data-confirm="Hapus slide ini? Gambar akan dihapus permanen.">
                            <?php echo cms_csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_slide">
                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                            <button class="button-danger" type="submit">Hapus</button>
                        </form>
                    </li>
                <?php } ?>
            </ul>
        <?php } ?>
    </section>

    <section class="panel" id="video">
        <div class="panel-header"><div><h2>Video YouTube</h2><span class="panel-subtitle">Tempel tautan video; pemutar langsung tampil di beranda. Kosongkan untuk menyembunyikan.</span></div></div>
        <form class="cms-form" method="post" data-loading-form>
            <?php echo cms_csrf_field(); ?>
            <input type="hidden" name="action" value="save_video">
            <div class="cms-grid">
                <div class="cms-field cms-field--wide">
                    <label class="cms-label" for="video-url">Tautan YouTube</label>
                    <input id="video-url" name="home_video_url" value="<?php echo h($videoUrl); ?>" maxlength="255" placeholder="https://www.youtube.com/watch?v=… atau https://youtu.be/…" data-youtube-input>
                    <span class="cms-hint">Mendukung tautan watch, youtu.be, shorts, live, dan embed.</span>
                </div>
                <div class="cms-field"><label class="cms-label" for="video-title">Judul video</label><input id="video-title" name="home_video_title" value="<?php echo h(cms_setting($conn, 'home_video_title')); ?>" maxlength="120" placeholder="Mengenal Cendekia lebih dekat"></div>
                <div class="cms-field"><label class="cms-label" for="video-text">Keterangan singkat</label><input id="video-text" name="home_video_text" value="<?php echo h(cms_setting($conn, 'home_video_text')); ?>" maxlength="400"></div>
            </div>
            <div class="cms-video-preview" data-youtube-preview<?php echo $videoId === '' ? ' hidden' : ''; ?>>
                <?php if ($videoId !== '') { ?><iframe src="https://www.youtube-nocookie.com/embed/<?php echo h($videoId); ?>" title="Pratinjau video" loading="lazy" allowfullscreen></iframe><?php } ?>
            </div>
            <div class="cms-actions"><button class="button-primary" type="submit">Simpan video</button></div>
        </form>
    </section>

    <section class="panel" id="teks">
        <div class="panel-header"><div><h2>Teks beranda</h2><span class="panel-subtitle">Judul besar di atas slider dan paragraf profil singkat.</span></div></div>
        <form class="cms-form" method="post" data-loading-form>
            <?php echo cms_csrf_field(); ?>
            <input type="hidden" name="action" value="save_text">
            <div class="cms-grid">
                <div class="cms-field cms-field--wide"><label class="cms-label" for="headline">Judul utama</label><input id="headline" name="home_headline" value="<?php echo h(cms_setting($conn, 'home_headline')); ?>" maxlength="120" placeholder="Shaleh, cerdas, dan terampil."></div>
                <div class="cms-field cms-field--wide"><label class="cms-label" for="lead">Kalimat pembuka</label><textarea id="lead" name="home_lead" rows="2" maxlength="300" placeholder="Kosongkan untuk memakai teks bawaan."><?php echo h(cms_setting($conn, 'home_lead')); ?></textarea></div>
                <?php echo cms_rich_editor('home_intro_html', cms_setting($conn, 'home_intro_html'), 'Profil singkat', 'Tampil di bagian "Profil singkat". Kosongkan untuk memakai teks bawaan.'); ?>
            </div>
            <div class="cms-actions"><button class="button-primary" type="submit">Simpan teks</button></div>
        </form>
    </section>
</div>
<?php echo cms_admin_scripts(); ?>
<?php require 'admin_footer.php'; ?>
