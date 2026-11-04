<?php
require_once __DIR__.'/public_layout.php';

$koperasiName = 'Koperasi Kenara Cendekia Takengon';

// Isi nilai setelah dokumen resmi koperasi diterima; nilai kosong tampil "Sedang dilengkapi".
$legalFacts = [
    'Nomor Badan Hukum' => '',
    'Nomor Induk Koperasi (NIK)' => '',
    'Nomor Induk Berusaha (NIB)' => '',
    'Tanggal pengesahan' => '',
];

public_page_start($conn, [
    'title' => $koperasiName,
    'description' => 'Profil, kegiatan, dan legalitas '.$koperasiName.'.',
    'active' => 'usaha',
    'path' => 'usaha',
]);
?>
<section class="page-hero">
    <div class="shell">
        <ol class="breadcrumbs" aria-label="Breadcrumb"><li><a href="./">Beranda</a></li><li>Usaha</li><li aria-current="page">Koperasi</li></ol>
        <p class="kicker">Koperasi</p>
        <h1><?= h($koperasiName) ?></h1>
        <p>Unit usaha di lingkungan Yayasan Wakaf Cendekia Takengon.</p>
    </div>
</section>

<nav class="org-tabs" aria-label="Bagian halaman">
    <div class="shell">
        <a href="usaha#koperasi-kenara">Profil</a>
        <a href="usaha#kegiatan-koperasi">Kegiatan</a>
        <a href="usaha#legalitas">Legalitas</a>
    </div>
</nav>

<section class="section org-section" id="koperasi-kenara" aria-labelledby="profil-title">
    <div class="shell">
        <header class="section-head">
            <p class="kicker">Profil</p>
            <h2 id="profil-title">Profil koperasi</h2>
        </header>
        <div class="empty-note"><strong>Profil sedang disiapkan.</strong><span>Sejarah, visi, misi, dan layanan koperasi akan ditampilkan di bagian ini.</span></div>
    </div>
</section>

<section class="section section--tinted org-section" id="kegiatan-koperasi" aria-labelledby="kegiatan-title">
    <div class="shell">
        <header class="section-head">
            <p class="kicker">Kegiatan</p>
            <h2 id="kegiatan-title">Kegiatan koperasi</h2>
        </header>
        <div class="empty-note"><strong>Kegiatan sedang disiapkan.</strong><span>Dokumentasi kegiatan koperasi akan ditampilkan di bagian ini.</span></div>
    </div>
</section>

<section class="section org-section" id="legalitas" aria-labelledby="legalitas-title">
    <div class="shell">
        <header class="section-head">
            <p class="kicker">Legalitas</p>
            <h2 id="legalitas-title">Legalitas koperasi</h2>
        </header>
        <div class="org-card org-legal">
            <dl class="org-facts">
                <?php foreach ($legalFacts as $label => $value) { ?>
                    <div><dt><?= h($label) ?></dt><dd<?= $value === '' ? ' class="is-pending"' : '' ?>><?= h($value !== '' ? $value : 'Sedang dilengkapi') ?></dd></div>
                <?php } ?>
            </dl>
        </div>
    </div>
</section>
<?php public_page_end($conn); ?>
