<?php
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/app_helpers.php';

// Data unit diambil dari database agar halaman beranda selalu sesuai
// dengan konfigurasi resmi, termasuk status buka dan kuotanya.
$unitQuery = mysqli_query($conn, "SELECT u.kode_unit,u.nama_unit,u.jenjang,u.npsn,u.email,u.alamat,
    q.th_ajaran,q.kuota_internal,q.kuota_eksternal,q.biaya_pendaftaran,q.internal_dibuka,q.eksternal_dibuka,
    (SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=u.kode_unit AND p.th_ajaran=q.th_ajaran AND p.jalur_pendaftaran='eksternal' AND p.status_ppdb NOT IN ('ditolak','data_lama')) AS terisi
    ,(SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=u.kode_unit AND p.th_ajaran=q.th_ajaran AND p.jalur_pendaftaran='internal' AND p.status_ppdb NOT IN ('ditolak','data_lama')) AS internal_terisi
    FROM tb_unit_pendidikan u
    LEFT JOIN tb_pengaturan_ppdb q ON q.kode_unit=u.kode_unit AND q.th_ajaran='2027/2028'
    ORDER BY FIELD(u.jenjang,'KB','TPA','TK','SD','SMP')");

$units = [];
$openUnits = [];
$totalOpenQuota = 0;
while ($unit = mysqli_fetch_assoc($unitQuery)) {
    $unit['kuota_eksternal'] = (int) ($unit['kuota_eksternal'] ?? 0);
    $unit['kuota_internal'] = (int) ($unit['kuota_internal'] ?? 0);
    $unit['terisi'] = (int) $unit['terisi'];
    $unit['internal_terisi'] = (int) $unit['internal_terisi'];
    $unit['sisa'] = max(0, $unit['kuota_eksternal'] - $unit['terisi']);
    $unit['internal_sisa'] = max(0, $unit['kuota_internal'] - $unit['internal_terisi']);
    $unit['internal_terbuka'] = (int) ($unit['internal_dibuka'] ?? 0) === 1 && $unit['kuota_internal'] > 0 && $unit['internal_sisa'] > 0;
    $unit['terbuka'] = (int) ($unit['eksternal_dibuka'] ?? 0) === 1 && $unit['kuota_eksternal'] > 0 && $unit['sisa'] > 0;
    $units[] = $unit;
    if ($unit['terbuka']) {
        $openUnits[] = $unit;
        $totalOpenQuota += $unit['sisa'];
    }
}

$siteName = 'Yayasan Wakaf Cendekia Takengon';
$siteShort = 'PPDB Cendekia Takengon';
$activeYear = '2027/2028';
$siteUrl = ppdb_public_base_url();

$faqItems = [
    [
        'q' => 'Siapa saja yang bisa mendaftar?',
        'a' => 'Ananda untuk layanan kelompok bermain, penitipan anak, taman kanak-kanak, sekolah dasar, dan sekolah menengah pertama. Pendaftaran dibuka per unit dan per tahun ajaran sesuai kuota yang ditetapkan yayasan.',
    ],
    [
        'q' => 'Bagaimana cara mendaftar?',
        'a' => 'Pendaftaran berlangsung tiga tahap. Pertama, mengisi registrasi awal berisi data dasar ananda. Kedua, panitia menghubungi pendaftar melalui WhatsApp untuk pembayaran. Ketiga, pendaftar mengisi formulir lengkap dan mengunggah dokumen pendukung.',
    ],
    [
        'q' => 'Dokumen apa saja yang harus disiapkan?',
        'a' => 'Scan Kartu Keluarga, Akta Kelahiran, KTP ayah, dan KTP ibu. Foto peserta didik wajib, sedangkan foto atau scan NISN bersifat opsional bila ananda belum memiliki NISN.',
    ],
    [
        'q' => 'Berapa lama proses pendaftaran berlangsung?',
        'a' => 'Panitia menghubungi pendaftar setelah registrasi awal masuk. Batas pembayaran ditetapkan otomatis, dan formulir lengkap diperiksa setelah seluruh dokumen diterima.',
    ],
    [
        'q' => 'Apakah data anak saya aman?',
        'a' => 'Data identitas lengkap hanya dapat diakses pengelola yang telah masuk ke dashboard. Dokumen disimpan di luar folder publik situs dan tidak ditampilkan kembali kepada pendaftar.',
    ],
    [
        'q' => 'Bagaimana jika kuota sudah terpenuhi?',
        'a' => 'Pendaftar yang tidak lolos berada pada daftar tunggu sesuai urutan antrean. Panitia akan menghubungi begitu tersedia kursi.',
    ],
];

$organizationSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'EducationalOrganization',
    'name' => $siteName,
    'url' => $siteUrl,
    'logo' => $siteUrl . '/img/logo-display.png',
    'description' => 'Yayasan yang menaungi satuan pendidikan Islam terpadu jenjang KB, TPA, TK, SD, dan SMP di Kecamatan Bebesen, Kabupaten Aceh Tengah.',
    'email' => 'wakafcendekiatakengon@gmail.com',
    'telephone' => '+6282181649543',
    'address' => [
        '@type' => 'PostalAddress',
        'streetAddress' => 'Jalan Pertamina-Kebet, Kampung Lemah Burbana',
        'addressLocality' => 'Bebesen',
        'addressRegion' => 'Aceh Tengah',
        'addressCountry' => 'ID',
    ],
    'areaServed' => 'Kabupaten Aceh Tengah, Aceh',
];

$faqSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(static fn (array $item) => [
        '@type' => 'Question',
        'name' => $item['q'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
    ], $faqItems),
];

$breadcrumbSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Beranda', 'item' => $siteUrl],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Unit Pendidikan', 'item' => $siteUrl . '/#unit'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Tentang Yayasan', 'item' => $siteUrl . '/#yayasan'],
    ],
];

$enDash = "\u{2014}";
$arrow = "\u{2192}";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f3a2e">

    <title>PPDB <?= h($activeYear) ?> &middot; <?= h($siteName) ?></title>
    <meta name="description" content="Penerimaan peserta didik baru <?= h($activeYear) ?> untuk jenjang KB, TPA, TK, SD, dan SMP di <?= h($siteName) ?> Takengon. Pendaftaran online tiga tahap, mudah, dan dipandu lewat WhatsApp.">
    <meta name="keywords" content="PPDB Cendekia, PPDB Takengon, wakaf cendekia, sekolah Islam terpadu Aceh Tengah, PPDB TK, PPDB SD, PPDB SMP, penerimaan peserta didik baru Bebesen">
    <meta name="author" content="<?= h($siteName) ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="<?= h($siteUrl) ?>/">

    <meta property="og:type" content="website">
    <meta property="og:locale" content="id_ID">
    <meta property="og:site_name" content="<?= h($siteShort) ?>">
    <meta property="og:title" content="PPDB <?= h($activeYear) ?> &middot; <?= h($siteName) ?>">
    <meta property="og:description" content="Penerimaan peserta didik baru jenjang KB, TPA, TK, SD, dan SMP. Pendaftaran online tiga tahap, dipandu langsung melalui WhatsApp.">
    <meta property="og:url" content="<?= h($siteUrl) ?>/">
    <meta property="og:image" content="<?= h($siteUrl) ?>/img/wisuda-1200.jpg">
    <meta property="og:image:alt" content="Wisuda alumni Islam Terpadu Cendekia Takengon">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="PPDB <?= h($activeYear) ?> &middot; <?= h($siteName) ?>">
    <meta name="twitter:description" content="Penerimaan peserta didik baru jenjang KB, TPA, TK, SD, dan SMP di Takengon.">
    <meta name="twitter:image" content="<?= h($siteUrl) ?>/img/wisuda-1200.jpg">

    <link rel="icon" href="img/logo-display.png" type="image/png">
    <link rel="apple-touch-icon" href="img/logo-display.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<a class="skip-link" href="#konten">Lompat ke konten utama</a>

<header class="site-header" id="atas">
    <div class="header-inner">
        <a class="brand" href="index.php" aria-label="<?= h($siteName) ?> beranda">
            <img class="brand-logo" src="img/logo-display.png" alt="Logo <?= h($siteName) ?>" width="46" height="45">
            <span class="brand-copy">
                <strong>Wakaf Cendekia</strong>
                <small>Takengon &middot; <?= h($activeYear) ?></small>
            </span>
        </a>

        <button class="menu-toggle" type="button" aria-label="Buka navigasi" aria-expanded="false" aria-controls="navigasi-utama" data-menu-toggle>
            <span></span><span></span><span></span>
        </button>

        <nav class="primary-navigation" id="navigasi-utama" aria-label="Navigasi utama" data-navigation>
            <a href="#unit">Unit pendidikan</a>
            <a href="#alur">Alur pendaftaran</a>
            <a href="#yayasan">Tentang yayasan</a>
            <a href="#faq">Pertanyaan</a>
            <a class="nav-admin" href="login.php">Admin</a>
            <a class="nav-apply" href="daftar.php">Pendaftaran <span aria-hidden="true"><?= $arrow ?></span></a>
        </nav>
    </div>
</header>

<main id="konten">
    <section class="hero">
        <div class="hero__media">
            <img src="img/wisuda-1200.jpg" alt="Wisuda alumni Islam Terpadu Cendekia Takengon" width="1200" height="1200" fetchpriority="high" decoding="async">
            <div class="hero__scrim" aria-hidden="true"></div>
        </div>

        <div class="hero__inner">
            <div class="hero__content">
                <p class="hero__eyebrow">
                    <span class="pulse-dot" aria-hidden="true"></span>
                    Pendaftaran peserta didik baru <?= h($activeYear) ?>
                </p>
                <h1>
                    Pendidikan Islam terpadu<br>
                    <span>untuk tiga jenjang.</span>
                </h1>
                <p class="hero__lead">
                    KB, TPA, TK, SD, dan SMP di Kecamatan Bebesen, Kabupaten Aceh Tengah.
                    Pendaftaran online tiga tahap yang dipandu langsung melalui WhatsApp,
                    dari data dasar hingga dokumen lengkap.
                </p>

                <div class="hero__actions">
                    <a class="btn btn--primary" href="daftar.php">Mulai pendaftaran <span aria-hidden="true"><?= $arrow ?></span></a>
                    <a class="btn btn--ghost" href="#alur">Lihat alurnya</a>
                </div>

                <dl class="hero__facts">
                    <div>
                        <dt>Unit pendidikan</dt>
                        <dd><?= count($units) ?> jenjang</dd>
                    </div>
                    <div>
                        <dt>Tahun ajaran</dt>
                        <dd><?= h($activeYear) ?></dd>
                    </div>
                    <div>
                        <dt>Kuota tersedia</dt>
                        <dd><?= $totalOpenQuota > 0 ? $totalOpenQuota . ' kursi' : 'Belum dibuka' ?></dd>
                    </div>
                </dl>
            </div>
        </div>

        <p class="hero__caption" aria-hidden="true">
            <span>Bebesen &middot; Aceh Tengah</span>
            <span><?= h($activeYear) ?></span>
        </p>
    </section>

    <section class="unit-section" id="unit">
        <div class="section-head">
            <p class="section-index">01 <?= $enDash ?> Unit pendidikan</p>
            <h2>Lima layanan pendidikan,<br><span>satu lingkungan belajar.</span></h2>
            <p class="section-lead">Pendaftaran umum dibuka per unit dan tahun ajaran. SDM yayasan menggunakan <a href="daftar.php?internal=1">tautan internal bersama</a> dan token khusus 9 karakter.</p>
        </div>

        <div class="unit-grid">
            <?php foreach ($units as $index => $unit) { ?>
                <?php $persentase = $unit['kuota_eksternal'] > 0 ? min(100, (int) round($unit['terisi'] / $unit['kuota_eksternal'] * 100)) : 0; ?>
                <article class="unit-card<?= $unit['terbuka'] ? '' : ' is-closed' ?>" data-reveal style="--delay: <?= $index * 70 ?>ms">
                    <header class="unit-card__top">
                        <span class="unit-card__level"><?= h($unit['jenjang']) ?></span>
                        <?php if ($unit['terbuka']) { ?>
                            <span class="unit-card__badge is-open">Pendaftaran dibuka</span>
                        <?php } else { ?>
                            <span class="unit-card__badge">Belum dibuka</span>
                        <?php } ?>
                    </header>

                    <h3><?= h($unit['nama_unit']) ?></h3>
                    <p class="unit-card__npsn">NPSN <?= h($unit['npsn']) ?></p>
                    <?php if ($unit['alamat'] !== '') { ?><p class="unit-card__address"><?= h($unit['alamat']) ?></p><?php } else { ?><p class="unit-card__address">Lokasi belum dikonfirmasi.</p><?php } ?>

                    <dl class="unit-card__meta">
                        <div>
                            <dt>Eksternal</dt>
                            <dd><?= $unit['kuota_eksternal'] > 0 ? $unit['kuota_eksternal'] : '&mdash;' ?></dd>
                        </div>
                        <div>
                            <dt>Sisa eksternal</dt>
                            <dd><?= $unit['terbuka'] ? $unit['sisa'] : '&mdash;' ?></dd>
                        </div>
                        <div>
                            <dt>Biaya</dt>
                            <dd><?= (float) $unit['biaya_pendaftaran'] > 0 ? 'Rp ' . number_format((float) $unit['biaya_pendaftaran'], 0, ',', '.') : 'Gratis' ?></dd>
                        </div>
                    </dl>
                    <p class="unit-card__quota-note">
                        Internal SDM yayasan: <?= $unit['kuota_internal'] > 0 ? $unit['kuota_internal'].' kursi' : 'belum diatur' ?>
                        <?= $unit['internal_terbuka'] ? ' · '.$unit['internal_sisa'].' tersisa' : '' ?>
                    </p>

                    <?php if ($unit['kuota_eksternal'] > 0) { ?>
                        <div class="unit-card__bar" role="img" aria-label="Kuota eksternal terisi <?= $unit['terisi'] ?> dari <?= $unit['kuota_eksternal'] ?> kursi">
                            <span style="--w: <?= $persentase ?>%"></span>
                        </div>
                    <?php } ?>

                    <a class="unit-card__cta" href="<?= $unit['th_ajaran'] ? 'daftar.php?unit='.rawurlencode($unit['kode_unit']).'&amp;tahun='.rawurlencode((string) $unit['th_ajaran']) : 'daftar.php' ?>">
                        <?= $unit['terbuka'] ? 'Pilih unit ini' : 'Lihat pendaftaran' ?>
                        <span aria-hidden="true"><?= $arrow ?></span>
                    </a>
                </article>
            <?php } ?>
        </div>
    </section>

    <section class="alur-section" id="alur">
        <div class="section-head">
            <p class="section-index">02 <?= $enDash ?> Alur pendaftaran</p>
            <h2>Tiga tahap,<br><span>tanpa langkah yang rumit.</span></h2>
            <p class="section-lead">Tidak ada formulir panjang di awal. Data dasar dulu, lalu formulir lengkap diisi setelah pembayaran disetujui.</p>
        </div>

        <ol class="alur-steps">
            <li class="alur-step" data-reveal style="--delay: 0ms">
                <span class="alur-step__number">01</span>
                <div class="alur-step__body">
                    <h3>Registrasi awal</h3>
                    <p>Tujuh isian dasar: nama lengkap ananda, tanggal lahir, jenis kelamin, siapa yang mendaftarkan, dan tiga nomor WhatsApp.</p>
                    <span class="alur-step__meta">&plusmn; 3 menit &middot; tanpa NIK</span>
                </div>
            </li>
            <li class="alur-step" data-reveal style="--delay: 80ms">
                <span class="alur-step__number">02</span>
                <div class="alur-step__body">
                    <h3>Verifikasi &amp; pembayaran</h3>
                    <p>Panitia memeriksa data lalu mengirim tautan pembayaran. Anda mengunggah bukti transfer untuk diperiksa.</p>
                    <span class="alur-step__meta"> Lewat WhatsApp</span>
                </div>
            </li>
            <li class="alur-step" data-reveal style="--delay: 160ms">
                <span class="alur-step__number">03</span>
                <div class="alur-step__body">
                    <h3>Formulir lengkap</h3>
                    <p>Isi data identitas, alamat, dan keluarga. Unggah foto, NISN, KK, akta kelahiran, serta KTP orang tua.</p>
                    <span class="alur-step__meta">Kode pendaftaran dipakai sekali</span>
                </div>
            </li>
        </ol>

        <div class="alur-cta">
            <p>Siap memulai? Registrasi awal hanya butuh satu formulir singkat.</p>
            <a class="btn btn--primary" href="daftar.php">Mulai pendaftaran <span aria-hidden="true"><?= $arrow ?></span></a>
        </div>
    </section>

    <section class="foundation-section" id="yayasan">
        <div class="foundation-copy" data-reveal>
            <p class="section-index">03 <?= $enDash ?> Tentang yayasan</p>
            <h2>Yayasan Wakaf<br>Cendekia Takengon</h2>
            <p>Menaungi satuan pendidikan Islam terpadu jenjang KB, TPA, TK, SD, dan SMP di Kecamatan Bebesen, Kabupaten Aceh Tengah. Yayasan didirikan pada 5 Januari 2022 dengan komitmen pada pendidikan yang inklusif, bertanggung jawab, dan dapat diakses semua orang.</p>
        </div>

        <dl class="foundation-facts" data-reveal style="--delay: 90ms">
            <div>
                <dt>Pimpinan yayasan</dt>
                <dd>Ilawarni</dd>
            </div>
            <div>
                <dt>Alamat</dt>
                <dd>Kampung Lemah Burbana<br>Bebesen, Aceh Tengah 24552</dd>
            </div>
            <div>
                <dt>Kontak</dt>
                <dd>
                    <a href="tel:+6282181649543">0821-8164-9543</a>
                    <a href="mailto:wakafcendekiatakengon@gmail.com">wakafcendekiatakengon@gmail.com</a>
                </dd>
            </div>
        </dl>
    </section>

    <section class="faq-section" id="faq">
        <div class="section-head">
            <p class="section-index">04 <?= $enDash ?> Pertanyaan umum</p>
            <h2>Yang paling sering<br><span>ditanyakan.</span></h2>
        </div>

        <div class="faq-list" data-accordion>
            <?php foreach ($faqItems as $index => $item) { ?>
                <details class="faq-item"<?= $index === 0 ? ' open' : '' ?> data-reveal style="--delay: <?= $index * 50 ?>ms">
                    <summary>
                        <span><?= h($item['q']) ?></span>
                        <svg class="faq-item__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                            <path d="M12 5v14M5 12h14"/>
                        </svg>
                    </summary>
                    <div class="faq-item__answer">
                        <p><?= h($item['a']) ?></p>
                    </div>
                </details>
            <?php } ?>
        </div>
    </section>

    <section class="cta-band">
        <div>
            <p class="section-index">Penerimaan peserta didik baru</p>
            <h2>Siap menjadi bagian<br>dari keluarga Cendekia.</h2>
        </div>
        <a class="btn btn--light" href="daftar.php">Isi registrasi awal <span aria-hidden="true"><?= $arrow ?></span></a>
    </section>
</main>

<footer class="site-footer">
    <div class="footer-inner">
        <a class="footer-brand" href="index.php">
            <img src="img/logo-display.png" alt="" width="34" height="34">
            <span>Wakaf Cendekia <small>Takengon</small></span>
        </a>
        <nav class="footer-nav" aria-label="Navigasi footer">
            <a href="#unit">Unit pendidikan</a>
            <a href="#alur">Alur pendaftaran</a>
            <a href="#faq">Pertanyaan</a>
            <a href="login.php">Admin</a>
        </nav>
        <p class="footer-contact">
            Jalan Pertamina&ndash;Kebet, Kecamatan Bebesen, Aceh Tengah<br>
            <a href="mailto:wakafcendekiatakengon@gmail.com">wakafcendekiatakengon@gmail.com</a>
        </p>
    </div>
    <p class="footer-legal">&copy; <?= date('Y') ?> <?= h($siteName) ?>. Seluruh hak cipta dilindungi.</p>
</footer>

<script type="application/ld+json"><?= json_encode($organizationSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script type="application/ld+json"><?= json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script type="application/ld+json"><?= json_encode($breadcrumbSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script src="js/index.js" defer></script>
</body>
</html>
