<?php
require_once __DIR__.'/public_layout.php';
$photos = cms_showcase_items($conn, 'gallery', 200);
$labels = ['kegiatan' => 'Kegiatan', 'pembelajaran' => 'Pembelajaran', 'fasilitas' => 'Fasilitas'];
public_page_start($conn, ['title' => 'Galeri kegiatan', 'description' => 'Dokumentasi kegiatan belajar dan kehidupan di lingkungan Cendekia.', 'active' => 'inspirasi', 'path' => 'galeri.php']);
?>
<section class="page-hero"><div class="shell"><ol class="breadcrumbs" aria-label="Breadcrumb"><li><a href="index.php">Beranda</a></li><li aria-current="page">Galeri</li></ol><p class="kicker">Momen Cendekia</p><h1>Galeri kegiatan</h1><p>Dokumentasi kegiatan, pembelajaran, dan fasilitas unit pendidikan.</p></div></section>
<section class="section"><div class="shell">
<?php if (!$photos) { ?><div class="empty-note"><strong>Galeri sedang disiapkan.</strong><span>Foto kegiatan resmi akan ditampilkan setelah diterbitkan pengelola.</span></div><?php } else { ?><div class="gallery-grid"><?php foreach ($photos as $photo) { ?><figure class="gallery-card"><img src="<?= h($photo['image_path']) ?>" alt="<?= h($photo['title']) ?>" loading="lazy"><figcaption><span><?= h($labels[$photo['category']] ?? 'Kegiatan') ?></span><strong><?= h($photo['title']) ?></strong><?php if ($photo['subtitle']) { ?><small><?= h($photo['subtitle']) ?></small><?php } ?></figcaption></figure><?php } ?></div><?php } ?>
</div></section>
<?php public_page_end($conn); ?>
