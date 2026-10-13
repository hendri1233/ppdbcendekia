<?php
require_once __DIR__ . '/public_layout.php';

$sections = cms_sections($conn, 'kurikulum');
$intro = cms_setting($conn, 'curriculum_intro', 'Kurikulum sekolah Islam terpadu memadukan kurikulum nasional dengan pembinaan karakter dan nilai keislaman. Berikut program dan pendekatan pembelajaran yang dijalankan di unit-unit Yayasan Wakaf Cendekia Takengon.');

public_page_start($conn, [
    'title' => 'Kurikulum JSIT',
    'description' => 'Program Penggerak, Kurikulum 2013, Merdeka Belajar, dan pembelajaran mendalam (deep learning) di unit pendidikan Yayasan Wakaf Cendekia Takengon.',
    'active' => 'kurikulum',
    'path' => 'kurikulum.php',
]);
?>
<section class="page-hero">
    <div class="shell">
        <ol class="breadcrumbs" aria-label="Breadcrumb"><li><a href="index.php">Beranda</a></li><li aria-current="page">Kurikulum JSIT</li></ol>
        <p class="kicker">Kurikulum JSIT</p>
        <h1>Kurikulum dan pendekatan pembelajaran</h1>
        <p><?= h($intro) ?></p>
    </div>
</section>

<section class="section">
    <div class="shell curriculum">
        <nav class="curriculum__toc" aria-label="Daftar isi kurikulum" data-spy>
            <?php foreach ($sections as $key => $section) { ?>
                <a href="#<?= h($key) ?>"><?= h($section['title']) ?></a>
            <?php } ?>
        </nav>
        <div>
            <?php foreach ($sections as $key => $section) { ?>
                <article class="curriculum__section" id="<?= h($key) ?>" aria-labelledby="<?= h($key) ?>-title">
                    <h2 id="<?= h($key) ?>-title"><?= h($section['title']) ?></h2>
                    <?php if ($section['subtitle']) { ?><p class="curriculum__subtitle"><?= h($section['subtitle']) ?></p><?php } ?>
                    <?php if ($section['image_path']) { ?>
                        <figure class="curriculum__image"><img src="<?= h($section['image_path']) ?>" alt="" width="1200" height="600" loading="lazy"></figure>
                    <?php } ?>
                    <?php if (trim((string) $section['body_html']) !== '') { ?>
                        <div class="prose"><?= $section['body_html'] ?></div>
                    <?php } else { ?>
                        <div class="empty-note"><strong>Uraian sedang disiapkan.</strong><span>Penjelasan program ini akan tampil setelah diisi oleh pengelola situs.</span></div>
                    <?php } ?>
                </article>
            <?php } ?>
        </div>
    </div>
</section>
<?php public_page_end($conn); ?>
