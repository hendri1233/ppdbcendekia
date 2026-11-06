<?php
require_once __DIR__.'/public_layout.php';
require_once __DIR__.'/wakaf_sync.php';

// Sumber isi: situs resmi lembaga, cendekiabisa.com (WAKAF_SITE).
$lembagaName = 'Lembaga Wakaf Insan Cendekia Takengon';

$misi = [
    'Mewujudkan pendidikan yang berkualitas tinggi guna menciptakan generasi emas di masa yang akan datang.',
    'Menerapkan manajemen pendidikan untuk merespons perubahan daya saing global.',
    'Menjamin seluruh proses kegiatan lembaga untuk selalu berorientasi pada mutu dan profesionalisme.',
    'Mewujudkan dan mengembangkan usaha dalam bidang ekonomi produktif untuk menunjang fasilitas pendidikan yang lebih baik.',
];

// Program wakaf diambil otomatis dari cendekiabisa.com; ringkasan di bawah menggantikan
// cuplikan deskripsi situs sumber untuk program yang sudah dikenal.
$programSummaries = [
    10 => 'Gerakan menyediakan lahan wakaf untuk SMPIT Cendekia Takengon demi generasi Islami.',
    8 => 'Membangun dan membina usaha berbasis wakaf melalui pelatihan dan pendampingan usaha.',
];
// Cadangan bila cendekiabisa.com belum pernah berhasil dibaca.
$programFallback = [
    ['id' => 10, 'title' => 'Wakaf Pembebasan Lahan', 'summary' => 'Gerakan menyediakan lahan wakaf untuk SMPIT Cendekia Takengon demi generasi Islami.', 'image' => WAKAF_SITE.'uploads/programs/1777876670_WhatsApp%20Image%202026-05-02%20at%2011.37.46.jpeg', 'url' => WAKAF_SITE.'program.php?id=10'],
    ['id' => 8, 'title' => 'Inkubasi Wakaf', 'summary' => 'Membangun dan membina usaha berbasis wakaf melalui pelatihan dan pendampingan usaha.', 'image' => WAKAF_SITE.'uploads/programs/1770868648_inkubasiwakaf.jpeg', 'url' => WAKAF_SITE.'program.php?id=8'],
    ['id' => 5, 'title' => 'Wakaf Melalui Uang', 'summary' => '', 'image' => WAKAF_SITE.'uploads/programs/1770868511_wakafmelaluiuang.jpeg', 'url' => WAKAF_SITE.'program.php?id=5'],
    ['id' => 4, 'title' => 'Wakaf Uang', 'summary' => '', 'image' => WAKAF_SITE.'uploads/programs/1770868461_wakafuang.jpeg', 'url' => WAKAF_SITE.'program.php?id=4'],
];
$programs = wakaf_programs($conn, $programSummaries, $programFallback);

$activities = [
    ['label' => '14 Oktober 2026', 'title' => 'Wakaf Amalan yang Terus Mengalir', 'summary' => 'Artikel ajakan berwakaf dari lembaga.', 'path' => 'berita-detail.php?id=15'],
    ['label' => '12 Maret 2026', 'title' => 'Cerdas Cermat Sirah Nabawiyah antar Guru', 'summary' => 'Kegiatan yang diselenggarakan Yayasan Cendekia Takengon.', 'path' => 'berita-detail.php?id=13'],
    ['label' => 'Dokumentasi', 'title' => 'Kick Off Inkubasi Wakaf Produktif', 'summary' => 'Pelaksanaan oleh Kementerian Agama Republik Indonesia.', 'path' => 'galeri.php'],
    ['label' => 'Dokumentasi', 'title' => 'Rapat program kerja', 'summary' => 'Rapat Lembaga Wakaf Insan Cendekia terkait program kerja.', 'path' => 'galeri.php'],
];

$socials = [
    ['icon' => 'instagram', 'label' => 'Instagram', 'handle' => '@wictakengon', 'href' => 'https://www.instagram.com/wictakengon'],
    ['icon' => 'facebook', 'label' => 'Facebook', 'handle' => 'Halaman Facebook lembaga', 'href' => 'https://www.facebook.com/profile.php?id=100068771984905'],
    ['icon' => 'youtube', 'label' => 'YouTube', 'handle' => '@wakafcendekiatakengon7778', 'href' => 'https://www.youtube.com/@wakafcendekiatakengon7778'],
    ['icon' => 'tiktok', 'label' => 'TikTok', 'handle' => '@wic.takengon', 'href' => 'https://www.tiktok.com/@wic.takengon'],
];

$external = 'target="_blank" rel="noopener"';

public_page_start($conn, [
    'title' => $lembagaName,
    'description' => $lembagaName.' mengelola wakaf, infaq, dan sedekah secara amanah, profesional, dan transparan.',
    'active' => 'sosial',
    'path' => 'sosial',
]);
?>
<section class="page-hero">
    <div class="shell">
        <ol class="breadcrumbs" aria-label="Breadcrumb"><li><a href="./">Beranda</a></li><li>Sosial</li><li aria-current="page">Lembaga Wakaf</li></ol>
        <p class="kicker">Lembaga wakaf</p>
        <h1><?= h($lembagaName) ?></h1>
        <p>Berkhidmat mengelola wakaf, infaq, dan sedekah secara amanah, profesional, dan transparan untuk pendidikan Qur'ani, pemberdayaan umat, dan kemaslahatan jangka panjang.</p>
        <div class="org-hero__actions">
            <a class="button button--primary" href="<?= h(WAKAF_SITE.'donasi.php') ?>" <?= $external ?>>Mulai berwakaf</a>
            <a class="button button--ghost" href="<?= h(WAKAF_SITE) ?>" <?= $external ?>>Kunjungi cendekiabisa.com</a>
        </div>
    </div>
</section>

<nav class="org-tabs" aria-label="Bagian halaman">
    <div class="shell">
        <a href="sosial#lembaga-wakaf">Profil</a>
        <a href="sosial#program-wakaf">Program</a>
        <a href="sosial#kegiatan-wakaf">Kegiatan</a>
        <a href="sosial#legalitas">Legalitas</a>
        <a href="sosial#media-sosial">Media sosial</a>
        <a href="sosial#kontak-lembaga">Kontak</a>
    </div>
</nav>

<section class="section org-section" id="lembaga-wakaf" aria-labelledby="profil-title">
    <div class="shell org-profile">
        <div>
            <p class="kicker">Profil</p>
            <h2 id="profil-title">Dari yayasan pendidikan menuju pengelolaan wakaf produktif</h2>
            <div class="prose">
                <p>Yayasan ini berawal dari Yayasan Generasi Gemilang Takengon yang didirikan pada tahun 2012 dengan visi membangun masyarakat madani yang senang berpikir, gemar bekerja, dan rajin beribadah.</p>
                <p>Melihat tingginya kepedulian masyarakat Takengon terhadap perwakafan, pada tahun 2021 yayasan beralih nama menjadi Yayasan Wakaf Cendekia Takengon. Lembaga Wakaf Insan Cendekia Takengon mengelola wakaf, infaq, dan sedekah dalam lingkungan ini.</p>
            </div>
        </div>
        <aside class="org-card">
            <h3>Visi</h3>
            <p>Lembaga profesional bermutu tinggi berbasis pendidikan, ekonomi, dan sosial budaya yang dapat menjadi rujukan skala regional dan nasional dalam bingkai nilai Islam.</p>
            <h3>Misi</h3>
            <ul class="org-list">
                <?php foreach ($misi as $item) { ?><li><?= public_icon('check') ?><span><?= h($item) ?></span></li><?php } ?>
            </ul>
        </aside>
    </div>
</section>

<section class="section section--tinted org-section" id="program-wakaf" aria-labelledby="program-title">
    <div class="shell">
        <header class="section-head">
            <p class="kicker">Program</p>
            <h2 id="program-title">Program wakaf pilihan</h2>
        </header>
        <div class="org-grid org-grid--programs">
            <?php foreach ($programs as $program) { ?>
                <a class="org-program" href="<?= h($program['url']) ?>" <?= $external ?>>
                    <?php if ($program['image'] !== '') { ?><img src="<?= h($program['image']) ?>" alt="" width="480" height="300" loading="lazy"><?php } ?>
                    <span class="org-program__body">
                        <strong><?= h($program['title']) ?></strong>
                        <?php if ($program['summary'] !== '') { ?><span><?= h($program['summary']) ?></span><?php } ?>
                        <span class="org-more">Lihat program<?= public_icon('arrow') ?></span>
                    </span>
                </a>
            <?php } ?>
        </div>
    </div>
</section>

<section class="section org-section" id="kegiatan-wakaf" aria-labelledby="kegiatan-title">
    <div class="shell">
        <header class="section-head">
            <p class="kicker">Kegiatan</p>
            <h2 id="kegiatan-title">Kegiatan wakaf</h2>
        </header>
        <div class="org-grid org-grid--activities">
            <?php foreach ($activities as $activity) { ?>
                <a class="org-card org-activity" href="<?= h(WAKAF_SITE.$activity['path']) ?>" <?= $external ?>>
                    <small><?= h($activity['label']) ?></small>
                    <strong><?= h($activity['title']) ?></strong>
                    <span><?= h($activity['summary']) ?></span>
                    <span class="org-more">Selengkapnya<?= public_icon('arrow') ?></span>
                </a>
            <?php } ?>
        </div>
        <p class="org-links">
            <a class="text-link" href="<?= h(WAKAF_SITE.'berita.php') ?>" <?= $external ?>>Semua berita wakaf</a>
            <a class="text-link" href="<?= h(WAKAF_SITE.'galeri.php') ?>" <?= $external ?>>Galeri kegiatan</a>
        </p>
    </div>
</section>

<section class="section section--tinted org-section" id="legalitas" aria-labelledby="legalitas-title">
    <div class="shell">
        <header class="section-head">
            <p class="kicker">Legalitas</p>
            <h2 id="legalitas-title">Bukti resmi lembaga</h2>
        </header>
        <div class="org-card org-legal">
            <dl class="org-facts">
                <div><dt>Dokumen</dt><dd>Surat Tanda Bukti Pendaftaran Nazhir</dd></div>
                <div><dt>Nama lembaga</dt><dd><?= h($lembagaName) ?></dd></div>
                <div><dt>Instansi</dt><dd>Kementerian Agama Republik Indonesia</dd></div>
            </dl>
            <a class="button button--primary" href="<?= h(WAKAF_SITE.'assets/docs/surat-nazhir.pdf') ?>" <?= $external ?>>Lihat surat (PDF)</a>
        </div>
    </div>
</section>

<section class="section org-section" id="media-sosial" aria-labelledby="sosmed-title">
    <div class="shell">
        <header class="section-head">
            <p class="kicker">Media sosial</p>
            <h2 id="sosmed-title">Ikuti kabar lembaga</h2>
        </header>
        <div class="org-grid org-grid--socials">
            <?php foreach ($socials as $social) { ?>
                <a class="org-card org-social" href="<?= h($social['href']) ?>" <?= $external ?>>
                    <?= public_icon($social['icon']) ?>
                    <span><strong><?= h($social['label']) ?></strong><small><?= h($social['handle']) ?></small></span>
                </a>
            <?php } ?>
        </div>
    </div>
</section>

<section class="section section--tinted org-section" id="kontak-lembaga" aria-labelledby="kontak-title">
    <div class="shell">
        <header class="section-head">
            <p class="kicker">Kontak</p>
            <h2 id="kontak-title">Hubungi lembaga</h2>
        </header>
        <div class="org-grid org-grid--contacts">
            <div class="org-card org-contact"><?= public_icon('pin') ?><span><strong>Alamat</strong>Jalan Pertamina - Kebet, Bebesen, Aceh Tengah</span></div>
            <a class="org-card org-contact" href="https://wa.me/6285199811663" <?= $external ?>><?= public_icon('phone') ?><span><strong>WhatsApp</strong>+62 851-9981-1663</span></a>
            <a class="org-card org-contact" href="mailto:wakafcendekiatakengon@gmail.com"><?= public_icon('mail') ?><span><strong>Email</strong>wakafcendekiatakengon@gmail.com</span></a>
            <div class="org-card org-contact"><?= public_icon('calendar') ?><span><strong>Jam operasional</strong>Senin-Jumat 08.00-16.00 WIB<br>Sabtu 08.00-12.00 WIB</span></div>
        </div>
    </div>
</section>
<?php public_page_end($conn); ?>
