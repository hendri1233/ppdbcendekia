<?php
require_once __DIR__ . '/public_layout.php';

$settings = cms_settings($conn);
$slides = cms_slides($conn);
if (!$slides) {
    // Cadangan sebelum admin mengunggah slide: foto dokumentasi resmi yang sudah ada.
    $slides = [[
        'image_path' => 'img/wisuda-1200.jpg',
        'title' => '',
        'caption' => 'Wisuda tahfidz dan pelepasan alumni SMP IT Cendekia Takengon.',
        'link_url' => '',
    ]];
}
$units = cms_units($conn);
[$latestNews] = cms_published_news($conn, 4);
$youtubeId = cms_youtube_id(cms_setting($conn, 'home_video_url'));
$headline = cms_setting($conn, 'home_headline', 'Shaleh, cerdas, dan terampil.');
$lead = cms_setting($conn, 'home_lead', 'Yayasan Wakaf Cendekia Takengon menaungi '.count($units).' satuan pendidikan Islam terpadu di Bebesen, Aceh Tengah, dari Kelompok Bermain hingga SMP.');
$introHtml = cms_setting($conn, 'home_intro_html');

// Status SPMB singkat untuk band ajakan di bawah halaman.
$openResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM tb_pengaturan_ppdb q WHERE q.th_ajaran='".PUBLIC_PPDB_YEAR."' AND q.eksternal_dibuka=1 AND ".ppdb_schedule_open_sql($conn)." AND q.kuota_eksternal >
    (SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=q.kode_unit AND p.th_ajaran=q.th_ajaran AND p.jalur_pendaftaran='eksternal' AND p.status_ppdb NOT IN ('ditolak','data_lama'))");
$openUnitCount = (int) (mysqli_fetch_assoc($openResult)['total'] ?? 0);

$siteUrl = ppdb_public_base_url();
public_page_start($conn, [
    'title' => 'Yayasan Wakaf Cendekia Takengon | Sekolah Islam Aceh Tengah',
    'title_is_complete' => true,
    'description' => 'Situs resmi Yayasan Wakaf Cendekia Takengon: profil yayasan, unit KB, TPA, TK, SD, dan SMP Islam terpadu, kurikulum, berita, dan SPMB '.PUBLIC_PPDB_YEAR.'.',
    'active' => 'beranda',
    'path' => '',
    'image' => 'img/og-cendekia.jpg',
    'schema' => [[
        '@context' => 'https://schema.org',
        '@type' => 'EducationalOrganization',
        'name' => PUBLIC_SITE_NAME,
        'url' => $siteUrl,
        'logo' => $siteUrl.'/img/logo-display.png',
        'image' => $siteUrl.'/img/og-cendekia.jpg',
        'telephone' => '+'.ppdb_admin_whatsapp_number(),
        'email' => cms_setting($conn, 'contact_email', 'wakafcendekiatakengon@gmail.com'),
        'sameAs' => [
            'https://www.instagram.com/cendekia_tkn?vrfl=MzhqbWgyeHN5bjV2',
            'https://www.facebook.com/share/1CPiWGAMH6/',
            'https://youtube.com/@wakafcendekiatakengon',
            'https://vm.tiktok.com/ZS9yDTdj4jU5a-Jsya9/',
        ],
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => 'Jalan Pertamina-Kebet, Kampung Lemah Burbana',
            'addressLocality' => 'Bebesen',
            'addressRegion' => 'Aceh Tengah',
            'postalCode' => '24552',
            'addressCountry' => 'ID',
        ],
    ]],
]);
?>
<section class="hero-slider hero-slider--split hero-slider--glass" aria-roledescription="carousel" aria-label="Sorotan kegiatan" data-slider data-interval="<?= max(3, min(30, (int) cms_setting($conn, 'slider_interval', '5'))) ?>">
    <div class="shell hero-split">
        <div class="hero-slider__content">
            <p class="kicker">Yayasan Wakaf Cendekia Takengon</p>
            <h1><?= h($headline) ?></h1>
            <p class="hero-slider__lead"><?= h($lead) ?></p>
            <div class="hero-slider__actions">
                <a class="button button--light button--lg" href="tentang">Kenali yayasan</a>
                <a class="button button--ghost-light button--lg" href="spmb">SPMB <?= h(PUBLIC_PPDB_YEAR) ?></a>
            </div>
            <p class="hero-slider__caption" aria-live="polite">
                <span data-slide-caption><?= h(trim(($slides[0]['title'] ? $slides[0]['title'].' — ' : '').($slides[0]['caption'] ?? ''), ' —')) ?></span>
                <a class="hero-slider__more" href="<?= h($slides[0]['link_url'] ?: '#') ?>" data-slide-link<?= empty($slides[0]['link_url']) ? ' hidden' : '' ?>>Selengkapnya</a>
            </p>

            <?php if (count($slides) > 1) { ?>
                <div class="hero-slider__controls">
                    <div class="slider-dots" role="group" aria-label="Pilih slide">
                        <?php foreach ($slides as $index => $slide) { ?>
                            <button type="button" data-slide-dot aria-label="Tampilkan slide <?= $index + 1 ?>" aria-current="<?= $index === 0 ? 'true' : 'false' ?>"></button>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
        </div>

        <div class="hero-split__media">
            <div class="hero-slider__track">
                <?php foreach ($slides as $index => $slide) { ?>
                    <figure class="hero-slide<?= $index === 0 ? ' is-active' : '' ?>" data-slide data-caption="<?= h(trim(($slide['title'] ? $slide['title'].' — ' : '').($slide['caption'] ?? ''), ' —')) ?>" data-link="<?= h($slide['link_url'] ?? '') ?>" aria-roledescription="slide" aria-label="<?= $index + 1 ?> dari <?= count($slides) ?>"<?= $index === 0 ? '' : ' aria-hidden="true"' ?>>
                        <img src="<?= h($slide['image_path']) ?>" alt="<?= h($slide['caption'] ?: ($slide['title'] ?: 'Kegiatan Yayasan Wakaf Cendekia Takengon')) ?>" width="1600" height="900"<?= $index === 0 ? ' fetchpriority="high"' : ' loading="lazy" fetchpriority="low"' ?> decoding="async">
                    </figure>
                <?php } ?>
            </div>
            <span class="hero-split__badge"><small>SPMB</small><?= h(PUBLIC_PPDB_YEAR) ?></span>
        </div>
    </div>
</section>

<section class="section section--profile" aria-labelledby="profil-title">
    <div class="shell intro">
        <div>
            <p class="kicker">Profil singkat</p>
            <h2 id="profil-title">Pendidikan Islam terpadu dalam satu yayasan wakaf.</h2>
            <div class="prose intro__body">
                <?php if ($introHtml !== '') { ?>
                    <?= $introHtml ?>
                <?php } else { ?>
                    <p>Yayasan Wakaf Cendekia Takengon menaungi Kelompok Bermain, Taman Penitipan Anak, TK, SD, dan SMP Islam terpadu di Kecamatan Bebesen, Kabupaten Aceh Tengah. Ananda dapat menempuh pendidikan sejak usia dini hingga sekolah menengah pertama dalam satu lingkungan yang berkesinambungan.</p>
                <?php } ?>
            </div>
            <a class="text-link" href="tentang">Prakata pimpinan, visi, dan misi</a>
        </div>
        <dl class="fact-stack fact-stack--icons">
            <div><dt><span class="fact-stack__icon"><?= public_icon('school') ?></span>Satuan pendidikan</dt><dd><?= count($units) ?> unit &middot; KB, TPA, TK, SD, SMP</dd></div>
            <div><dt><span class="fact-stack__icon"><?= public_icon('scale') ?></span>Badan hukum</dt><dd>SK Kemenkumham AHU-0000410.AH.01.12 Tahun 2022</dd></div>
            <div><dt><span class="fact-stack__icon"><?= public_icon('heart') ?></span>Nazhir wakaf uang</dt><dd>Terdaftar di Badan Wakaf Indonesia, kode 3.3.00369</dd></div>
            <div><dt><span class="fact-stack__icon"><?= public_icon('pin') ?></span>Lokasi</dt><dd>Bebesen, Aceh Tengah</dd></div>
        </dl>
    </div>
</section>

<section class="section section--units" aria-labelledby="unit-title">
    <div class="shell">
        <div class="unit-block">
            <header class="section-head section-head--split section-head--center">
                <div>
                    <p class="kicker">Satuan pendidikan</p>
                    <h2 id="unit-title">Unit pendidikan</h2>
                    <p>Lima jenjang di bawah satu yayasan. Pilih unit untuk melihat profil, kontak, dan beritanya.</p>
                </div>
                <a class="text-link" href="spmb#unit">Lihat kuota SPMB</a>
            </header>
            <div class="unit-grid unit-grid--list">
                <?php foreach ($units as $unit) { ?>
                    <a class="unit-card unit-card--row" href="<?= h(ppdb_unit_public_path($unit)) ?>">
                        <span class="unit-card__media<?= $unit['photo_path'] ? ' has-photo' : '' ?>">
                            <?php if ($unit['photo_path']) { ?>
                                <img src="<?= h($unit['photo_path']) ?>" alt="" width="640" height="480" loading="lazy">
                            <?php } else { ?>
                                <img class="unit-card__logo" src="img/logo-display.png" alt="" width="120" height="120" loading="lazy">
                            <?php } ?>
                            <span class="unit-card__code"><?= h($unit['jenjang']) ?></span>
                        </span>
                        <span class="unit-card__body">
                            <h3><?= h(ppdb_unit_display_name($unit['nama_unit'])) ?></h3>
                            <p><?= h(ppdb_jenjang_label($unit['jenjang']).' ('.$unit['jenjang'].')') ?></p>
                        </span>
                        <span class="unit-card__chevron" aria-hidden="true">&rsaquo;</span>
                    </a>
                <?php } ?>
            </div>
        </div>
    </div>
</section>

<?php if ($youtubeId !== '') { ?>
    <section class="section section--video" aria-labelledby="video-title">
        <div class="shell video-block video-card">
            <div>
                <p class="kicker">Video</p>
                <h2 id="video-title"><?= h(cms_setting($conn, 'home_video_title', 'Mengenal Cendekia lebih dekat')) ?></h2>
                <?php if (cms_setting($conn, 'home_video_text') !== '') { ?><p><?= h(cms_setting($conn, 'home_video_text')) ?></p><?php } ?>
            </div>
            <div class="video-frame">
                <iframe src="https://www.youtube-nocookie.com/embed/<?= h($youtubeId) ?>?rel=0" title="<?= h(cms_setting($conn, 'home_video_title', 'Video Yayasan Wakaf Cendekia Takengon')) ?>" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
            </div>
        </div>
    </section>
<?php } ?>

<section class="section section--news" aria-labelledby="berita-title">
    <div class="shell">
        <header class="section-head section-head--split">
            <div>
                <p class="kicker">Kabar terbaru</p>
                <h2 id="berita-title">Berita dan kegiatan</h2>
                <p>Kabar terbaru dari yayasan dan setiap unit.</p>
            </div>
            <?php if ($latestNews) { ?><a class="text-link" href="berita">Semua berita</a><?php } ?>
        </header>
        <?php if (!$latestNews) { ?>
            <div class="empty-note"><strong>Belum ada berita yang diterbitkan.</strong><span>Berita kegiatan akan tampil di sini setelah diterbitkan oleh pengelola.</span></div>
        <?php } else { ?>
            <div class="news-grid news-grid--home">
                <?php foreach ($latestNews as $index => $news) { include __DIR__.'/partials/news-card.php'; } ?>
            </div>
        <?php } ?>
    </div>
</section>

<section class="section section--tight" aria-labelledby="ppdb-title">
    <div class="shell">
        <div class="ppdb-band ppdb-band--glass">
            <img class="ppdb-band__photo" src="<?= public_asset('img/login-bg.webp') ?>" alt="" width="1600" height="900" loading="lazy">
            <div class="ppdb-band__glass">
            <div>
                <p class="kicker">Penerimaan peserta didik baru</p>
                <h2 id="ppdb-title">SPMB <?= h(PUBLIC_PPDB_YEAR) ?></h2>
                <p><?= $openUnitCount > 0
                    ? h($openUnitCount.' dari '.count($units).' unit sedang menerima pendaftar. Registrasi awal hanya butuh beberapa menit.')
                    : 'Pendaftaran belum dibuka. Lihat informasi kuota, alur, dan persyaratan terlebih dahulu.' ?></p>
            </div>
            <div class="ppdb-band__actions">
                <a class="button button--light button--lg" href="spmb">Info SPMB</a>
                <?php if ($openUnitCount > 0) { ?><a class="button button--ghost-light button--lg" href="daftar">Daftar sekarang</a><?php } ?>
            </div>
            </div>
        </div>
    </div>
</section>
<?php public_page_end($conn); ?>
