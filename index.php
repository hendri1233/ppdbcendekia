<?php
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/app_helpers.php';
require_once __DIR__ . '/web_stats.php';

ppdb_track_visit($conn);

$activeYear = '2027/2028';

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
    $unit['penuh'] = (int) ($unit['eksternal_dibuka'] ?? 0) === 1 && $unit['kuota_eksternal'] > 0 && $unit['sisa'] === 0;
    $units[] = $unit;
    if ($unit['terbuka']) {
        $openUnits[] = $unit;
        $totalOpenQuota += $unit['sisa'];
    }
}

// Statistik publik: pendaftar per unit dan kunjungan web 30 hari terakhir.
$totalRegistrants = 0;
foreach ($units as $unit) {
    $totalRegistrants += $unit['terisi'] + $unit['internal_terisi'];
}
$dailyVisits = ppdb_daily_visits($conn, 30);
$visitorsToday = end($dailyVisits)['visitors'];
$viewsLast30 = array_sum(array_column($dailyVisits, 'views'));
$visitorsLast30 = ppdb_unique_visitors($conn, 30);

// Petunjuk teknis panitia: acuan usia, dokumen, dan program yayasan.
$guide = ppdb_technical_guide();
$agesByJenjang = [];
foreach ($guide['ages'] ?? [] as $age) {
    $label = (string) ($age['unit'] ?? '');
    $key = strtok($label, ' ');
    $agesByJenjang[$key][] = $age;
}
$ageSummary = static function (string $jenjang) use ($agesByJenjang): string {
    $rows = $agesByJenjang[$jenjang] ?? [];
    if (!$rows) {
        return '';
    }
    if (count($rows) === 1) {
        return 'Usia '.(int) $rows[0]['minimumYears'].'–'.(int) $rows[0]['maximumYears'].' tahun';
    }
    return implode(' · ', array_map(static fn (array $row): string => preg_replace('/\s*\(.*\)$/', '', (string) $row['unit']).' '.(int) $row['minimumYears'].'–'.(int) $row['maximumYears'].' th', $rows));
};

$siteName = 'Yayasan Wakaf Cendekia Takengon';
$siteShort = 'PPDB Cendekia Takengon';
$siteUrl = ppdb_public_base_url();
$unitCount = count($units);

$faqItems = [
    [
        'q' => 'Siapa saja yang bisa mendaftar?',
        'a' => 'Ananda usia dini hingga lulusan SD untuk Kelompok Bermain, Taman Penitipan Anak, TK, SD, dan SMP di bawah Yayasan Wakaf Cendekia Takengon. Pendaftaran dibuka per unit dan per tahun ajaran sesuai kuota yang ditetapkan yayasan.',
    ],
    [
        'q' => 'Bagaimana cara mendaftar?',
        'a' => 'Pendaftaran berlangsung tiga tahap. Pertama, mengisi registrasi awal berisi data dasar ananda. Kedua, panitia menghubungi pendaftar melalui WhatsApp untuk pembayaran. Ketiga, pendaftar mengisi formulir lengkap dan mengunggah dokumen pendukung.',
    ],
    [
        'q' => 'Dokumen apa saja yang harus disiapkan?',
        'a' => 'Kartu Keluarga, akta kelahiran, KTP ayah dan ibu (atau KTP wali bila ananda diasuh pihak lain), foto ananda, serta NISN bila ananda sudah memiliki dari sekolah sebelumnya. Dokumen diunggah pada tahap formulir lengkap.',
    ],
    [
        'q' => 'Bagaimana saya memantau status pendaftaran?',
        'a' => 'Setelah registrasi awal terkirim, Anda menerima kode pendaftaran dan tautan halaman status pribadi. Tautan berikutnya untuk pembayaran dan formulir lengkap dikirim panitia melalui WhatsApp. Simpan tautan tersebut dan jangan dibagikan kepada orang lain.',
    ],
    [
        'q' => 'Apakah data anak saya aman?',
        'a' => 'Data identitas lengkap hanya dapat diakses pengelola yang telah masuk ke dashboard. Dokumen disimpan di luar folder publik situs dan tidak ditampilkan kembali kepada pendaftar.',
    ],
    [
        'q' => 'Bagaimana jika kuota sudah terpenuhi?',
        'a' => 'Pendaftar dapat ditempatkan pada daftar tunggu. Panitia akan menghubungi melalui WhatsApp apabila tersedia kursi.',
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
        'postalCode' => '24552',
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

$arrow = '<svg class="i-arrow" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M4 10h11M11 5.5 15.5 10 11 14.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$check = '<svg class="i-check" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="m5 10.5 3.2 3L15 6.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#14563f">

    <title>PPDB <?= h($activeYear) ?> &middot; <?= h($siteName) ?></title>
    <meta name="description" content="Penerimaan peserta didik baru <?= h($activeYear) ?> untuk KB, TPA, TK, SD, dan SMP Islam terpadu di bawah <?= h($siteName) ?>, Bebesen, Aceh Tengah. Pendaftaran online tiga tahap yang dipandu panitia melalui WhatsApp.">
    <meta name="author" content="<?= h($siteName) ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="<?= h($siteUrl) ?>/">

    <meta property="og:type" content="website">
    <meta property="og:locale" content="id_ID">
    <meta property="og:site_name" content="<?= h($siteShort) ?>">
    <meta property="og:title" content="PPDB <?= h($activeYear) ?> &middot; <?= h($siteName) ?>">
    <meta property="og:description" content="Penerimaan peserta didik baru KB, TPA, TK, SD, dan SMP. Pendaftaran online tiga tahap, dipandu langsung melalui WhatsApp.">
    <meta property="og:url" content="<?= h($siteUrl) ?>/">
    <meta property="og:image" content="<?= h($siteUrl) ?>/img/wisuda-1200.jpg">
    <meta property="og:image:width" content="1600">
    <meta property="og:image:height" content="900">
    <meta property="og:image:alt" content="Wisuda tahfidz dan pelepasan alumni SMP IT Cendekia Takengon">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="PPDB <?= h($activeYear) ?> &middot; <?= h($siteName) ?>">
    <meta name="twitter:description" content="Penerimaan peserta didik baru KB, TPA, TK, SD, dan SMP di Takengon.">
    <meta name="twitter:image" content="<?= h($siteUrl) ?>/img/wisuda-1200.jpg">

    <link rel="icon" href="img/logo-display.png" type="image/png">
    <link rel="apple-touch-icon" href="img/logo-display.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Source+Serif+4:opsz,wght@8..60,600;8..60,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/tokens.css?v=<?= (int) filemtime(__DIR__.'/css/tokens.css') ?>">
    <link rel="stylesheet" href="style.css?v=<?= (int) filemtime(__DIR__.'/style.css') ?>">
</head>
<body>
<a class="skip-link" href="#konten">Lompat ke konten utama</a>

<header class="site-header">
    <div class="shell header-inner">
        <a class="brand" href="index.php">
            <img class="brand-logo" src="img/logo-display.png" alt="" width="44" height="44">
            <span class="brand-copy">
                <strong>Yayasan Wakaf Cendekia</strong>
                <small>Takengon, Aceh Tengah</small>
            </span>
        </a>

        <button class="menu-toggle" type="button" aria-label="Buka navigasi" aria-expanded="false" aria-controls="navigasi-utama" data-menu-toggle>
            <span></span><span></span><span></span>
        </button>

        <nav class="primary-navigation" id="navigasi-utama" aria-label="Navigasi utama" data-navigation>
            <a href="#unit">Unit &amp; kuota</a>
            <a href="#alur">Alur</a>
            <a href="#persiapan">Persiapan</a>
            <a href="#yayasan">Yayasan</a>
            <a href="#faq">Tanya jawab</a>
            <a class="nav-admin" href="login.php">Masuk pengelola</a>
            <a class="button button--primary nav-apply" href="daftar.php">Daftar sekarang</a>
        </nav>
    </div>
</header>

<main id="konten">
    <section class="hero" aria-labelledby="hero-title">
        <div class="shell hero__grid">
            <div class="hero__copy">
                <p class="hero__kicker">Penerimaan peserta didik baru &middot; Tahun ajaran <?= h($activeYear) ?></p>
                <h1 id="hero-title">Shaleh, cerdas, dan terampil&mdash;sejak bangku bermain hingga SMP.</h1>
                <p class="hero__lead">
                    Yayasan Wakaf Cendekia Takengon menaungi <?= $unitCount ?> satuan pendidikan Islam terpadu di Bebesen, Aceh Tengah.
                    Pendaftaran dilakukan daring dalam tiga tahap dan dipandu panitia melalui WhatsApp.
                </p>
                <div class="hero__actions">
                    <a class="button button--primary button--lg" href="daftar.php">Mulai registrasi awal <?= $arrow ?></a>
                    <a class="button button--quiet button--lg" href="#persiapan">Lihat persyaratan</a>
                </div>
                <p class="hero__status">
                    <span class="status-dot<?= $openUnits ? ' is-open' : '' ?>" aria-hidden="true"></span>
                    <?php if ($openUnits) { ?>
                        <span><strong><?= count($openUnits) ?> dari <?= $unitCount ?> unit</strong> menerima pendaftar jalur umum &middot; <?= $totalOpenQuota ?> kursi tersisa</span>
                    <?php } else { ?>
                        <span>Pendaftaran jalur umum <?= h($activeYear) ?> <strong>belum dibuka</strong>. Pantau halaman ini untuk pengumuman berikutnya.</span>
                    <?php } ?>
                </p>
            </div>

            <figure class="hero__figure">
                <img src="img/wisuda-1200.jpg" srcset="img/foto1.jpg 1200w, img/wisuda-1200.jpg 1600w" sizes="(max-width: 900px) 100vw, 46vw" alt="Siswa melempar toga pada wisuda tahfidz dan pelepasan alumni SMP IT Cendekia Takengon" width="1600" height="900" fetchpriority="high">
                <figcaption>Wisuda tahfidz dan pelepasan alumni SMP IT Cendekia Takengon.</figcaption>
            </figure>
        </div>
    </section>

    <section class="section" id="unit" aria-labelledby="unit-title">
        <div class="shell">
            <header class="section-head section-head--split">
                <div>
                    <h2 id="unit-title">Unit pendidikan dan kuota</h2>
                    <p>Status jalur umum tahun ajaran <?= h($activeYear) ?>. Angka kuota dan pendaftar diambil langsung dari sistem pendaftaran.</p>
                </div>
                <p class="section-head__meta">Diperbarui <?= h(ppdb_format_date_id(ppdb_stats_today()->format('Y-m-d'))) ?></p>
            </header>

            <ol class="unit-board">
                <?php foreach ($units as $unit) {
                    $ageText = $ageSummary((string) $unit['jenjang']);
                    $unitTotal = $unit['terisi'] + $unit['internal_terisi'];
                ?>
                    <li class="unit-row<?= $unit['terbuka'] ? ' is-open' : '' ?>">
                        <span class="unit-row__code" aria-hidden="true"><?= h($unit['jenjang']) ?></span>
                        <div class="unit-row__identity">
                            <h3><?= h(ppdb_unit_display_name($unit['nama_unit'])) ?></h3>
                            <p><?= h(implode(' · ', array_filter([ppdb_jenjang_label($unit['jenjang']), $unit['npsn'] ? 'NPSN '.$unit['npsn'] : '', $ageText]))) ?></p>
                        </div>
                        <dl class="unit-row__numbers">
                            <div>
                                <dt>Kuota umum</dt>
                                <dd><?= $unit['kuota_eksternal'] > 0 ? $unit['kuota_eksternal'] : '&ndash;' ?></dd>
                            </div>
                            <div>
                                <dt>Pendaftar</dt>
                                <dd><?= $unitTotal ?></dd>
                            </div>
                        </dl>
                        <div class="unit-row__status">
                            <?php if ($unit['terbuka']) { ?>
                                <span class="badge badge--success">Dibuka &middot; <?= $unit['sisa'] ?> kursi tersisa</span>
                                <a class="button button--secondary button--sm" href="daftar.php?unit=<?= rawurlencode($unit['kode_unit']) ?>&amp;tahun=<?= rawurlencode($activeYear) ?>">Daftar ke <?= h($unit['jenjang']) ?><span class="visually-hidden"> <?= h(ppdb_unit_display_name($unit['nama_unit'])) ?></span></a>
                            <?php } elseif ($unit['penuh']) { ?>
                                <span class="badge badge--warning">Kuota terpenuhi</span>
                            <?php } else { ?>
                                <span class="badge badge--neutral">Belum dibuka</span>
                            <?php } ?>
                        </div>
                    </li>
                <?php } ?>
            </ol>
            <p class="unit-board__note">Jalur internal SDM yayasan memakai kode undangan terpisah dan tidak mengurangi kuota umum. Pendaftar dihitung dari kedua jalur, tidak termasuk yang ditolak.</p>
        </div>
    </section>

    <section class="section section--tinted" id="alur" aria-labelledby="alur-title">
        <div class="shell">
            <header class="section-head">
                <h2 id="alur-title">Tiga tahap pendaftaran</h2>
                <p>Data dasar lebih dulu. Formulir lengkap dan dokumen baru diminta setelah pembayaran disetujui panitia.</p>
            </header>

            <ol class="steps">
                <li class="step">
                    <span class="step__number">1</span>
                    <h3>Registrasi awal</h3>
                    <p>Tujuh isian: nama lengkap ananda, tanggal lahir, jenis kelamin, siapa yang mendaftarkan, serta nomor WhatsApp pendaftar, ayah, dan ibu.</p>
                    <p class="step__meta">Sekitar 3 menit &middot; tanpa NIK</p>
                </li>
                <li class="step">
                    <span class="step__number">2</span>
                    <h3>Verifikasi dan pembayaran</h3>
                    <p>Panitia memeriksa data, lalu mengirim tautan pembayaran melalui WhatsApp. Anda mengunggah bukti transfer di halaman status.</p>
                    <p class="step__meta">Dipandu melalui WhatsApp</p>
                </li>
                <li class="step">
                    <span class="step__number">3</span>
                    <h3>Formulir lengkap</h3>
                    <p>Isi data identitas, alamat, dan keluarga, baca materi PPDB unit tujuan, lalu unggah dokumen pendukung.</p>
                    <p class="step__meta">Sediakan waktu sekitar 1 jam</p>
                </li>
            </ol>
        </div>
    </section>

    <section class="section" id="persiapan" aria-labelledby="persiapan-title">
        <div class="shell">
            <header class="section-head">
                <h2 id="persiapan-title">Yang perlu disiapkan</h2>
                <p>Ringkasan dari petunjuk teknis panitia. Libatkan pasangan atau pengasuh ananda saat mengisi, karena sebagian data saling terkait.</p>
            </header>

            <div class="prep-grid">
                <article class="prep-block">
                    <h3>Dokumen</h3>
                    <?php if (!empty($guide['documents'])) { ?>
                        <ul class="checklist">
                            <?php foreach ($guide['documents'] as $document) { ?>
                                <li><?= $check ?><span><?= h($document) ?></span></li>
                            <?php } ?>
                        </ul>
                    <?php } else { ?>
                        <ul class="checklist">
                            <li><?= $check ?><span>Kartu Keluarga (KK)</span></li>
                            <li><?= $check ?><span>Akta kelahiran ananda</span></li>
                            <li><?= $check ?><span>KTP ayah dan ibu, atau KTP wali</span></li>
                            <li><?= $check ?><span>NISN dari sekolah sebelumnya, bila ada</span></li>
                        </ul>
                    <?php } ?>
                    <p class="prep-block__note">Foto ananda juga diminta saat mengunggah dokumen pada tahap formulir lengkap.</p>
                </article>

                <?php if (!empty($guide['ages'])) { ?>
                    <article class="prep-block">
                        <h3>Acuan usia</h3>
                        <table class="age-table">
                            <caption class="visually-hidden">Acuan usia pendaftaran per jenjang</caption>
                            <thead><tr><th scope="col">Jenjang</th><th scope="col">Usia</th></tr></thead>
                            <tbody>
                                <?php foreach ($guide['ages'] as $age) { ?>
                                    <tr>
                                        <th scope="row"><?= h($age['unit'] ?? '') ?></th>
                                        <td>
                                            <?= (int) ($age['minimumYears'] ?? 0) ?>&ndash;<?= (int) ($age['maximumYears'] ?? 0) ?> tahun
                                            <?php if (!empty($age['note'])) { ?><small><?= h($age['note']) ?></small><?php } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                        <p class="prep-block__note">Mengikuti ketentuan Dinas Pendidikan. Umur ananda dihitung otomatis saat tanggal lahir diisi pada registrasi awal.</p>
                    </article>
                <?php } ?>

                <?php if (!empty($guide['programs'])) { ?>
                    <article class="prep-block prep-block--accent">
                        <h3>Program bagi calon wali siswa</h3>
                        <ul class="program-list">
                            <?php foreach ($guide['programs'] as $program) { ?>
                                <li><?= h($program) ?></li>
                            <?php } ?>
                        </ul>
                        <p class="prep-block__note">Diseleksi berdasarkan syarat dan ketentuan yayasan. Tanyakan kepada panitia untuk keterangan lebih lanjut.</p>
                    </article>
                <?php } ?>
            </div>
        </div>
    </section>

    <section class="section section--tinted" id="angka" aria-labelledby="angka-title">
        <div class="shell">
            <header class="section-head">
                <h2 id="angka-title">Angka pendaftaran</h2>
                <p>Jumlah pendaftar tahun ajaran <?= h($activeYear) ?> dan kunjungan ke situs ini selama 30 hari terakhir, diperbarui otomatis.</p>
            </header>

            <div class="metrics">
                <dl class="metric-list">
                    <div class="metric">
                        <dt>Total pendaftar</dt>
                        <dd><?= number_format($totalRegistrants, 0, ',', '.') ?></dd>
                        <dd class="metric__note">Tahun ajaran <?= h($activeYear) ?></dd>
                    </div>
                    <div class="metric">
                        <dt>Pengunjung 30 hari</dt>
                        <dd><?= number_format($visitorsLast30, 0, ',', '.') ?></dd>
                        <dd class="metric__note">Pengunjung berbeda</dd>
                    </div>
                    <div class="metric">
                        <dt>Pengunjung hari ini</dt>
                        <dd><?= number_format($visitorsToday, 0, ',', '.') ?></dd>
                        <dd class="metric__note"><?= h(ppdb_format_date_id(ppdb_stats_today()->format('Y-m-d'))) ?></dd>
                    </div>
                    <div class="metric">
                        <dt>Halaman dilihat</dt>
                        <dd><?= number_format($viewsLast30, 0, ',', '.') ?></dd>
                        <dd class="metric__note">30 hari terakhir</dd>
                    </div>
                </dl>

                <article class="chart-card">
                    <header>
                        <h3>Pengunjung harian</h3>
                        <p>Pengunjung berbeda per hari, 30 hari terakhir.</p>
                    </header>
                    <div class="visit-chart" data-visit-chart role="img" aria-label="Grafik pengunjung harian 30 hari terakhir, total <?= $visitorsLast30 ?> pengunjung berbeda"></div>
                    <details class="stats-table">
                        <summary>Lihat data dalam tabel</summary>
                        <div class="stats-table__scroll">
                            <table>
                                <thead><tr><th scope="col">Tanggal</th><th scope="col">Pengunjung</th><th scope="col">Halaman dilihat</th></tr></thead>
                                <tbody>
                                    <?php foreach (array_reverse($dailyVisits) as $day) { ?>
                                        <tr><td><?= h(ppdb_format_date_id($day['date'])) ?></td><td><?= $day['visitors'] ?></td><td><?= $day['views'] ?></td></tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </article>
            </div>
            <div class="stats-tooltip" data-stats-tooltip role="tooltip" hidden></div>
            <script type="application/json" id="visit-data"><?= json_encode($dailyVisits, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        </div>
    </section>

    <section class="section" id="yayasan" aria-labelledby="yayasan-title">
        <div class="shell about">
            <div class="about__copy">
                <img class="about__logo" src="img/logo-display.png" alt="Logo Yayasan Wakaf Cendekia Takengon dengan semboyan Shaleh, Cerdas &amp; Terampil" width="120" height="118" loading="lazy">
                <h2 id="yayasan-title">Yayasan Wakaf Cendekia Takengon</h2>
                <p>Yayasan berbadan hukum yang menaungi satuan pendidikan Islam terpadu jenjang KB, TPA, TK, SD, dan SMP di Kecamatan Bebesen, Kabupaten Aceh Tengah. Yayasan juga terdaftar sebagai nazhir wakaf uang pada Badan Wakaf Indonesia.</p>
            </div>

            <dl class="fact-list">
                <div>
                    <dt>Berdiri</dt>
                    <dd>5 Januari 2022</dd>
                </div>
                <div>
                    <dt>Badan hukum</dt>
                    <dd>SK Kemenkumham AHU-0000410.AH.01.12 Tahun 2022</dd>
                </div>
                <div>
                    <dt>Nazhir wakaf uang</dt>
                    <dd>Terdaftar di Badan Wakaf Indonesia, kode 3.3.00369</dd>
                </div>
                <div>
                    <dt>Pimpinan yayasan</dt>
                    <dd>Ilawarni</dd>
                </div>
                <div>
                    <dt>Alamat</dt>
                    <dd>Jalan Pertamina&ndash;Kebet, Kampung Lemah Burbana, Kecamatan Bebesen, Kabupaten Aceh Tengah, Aceh 24552</dd>
                </div>
                <div>
                    <dt>Kontak panitia</dt>
                    <dd>
                        <a href="https://wa.me/6282181649543" rel="noopener">WhatsApp 0821-8164-9543</a>
                        <a href="mailto:wakafcendekiatakengon@gmail.com">wakafcendekiatakengon@gmail.com</a>
                    </dd>
                </div>
            </dl>
        </div>
    </section>

    <section class="section section--tinted" id="faq" aria-labelledby="faq-title">
        <div class="shell faq">
            <header class="section-head">
                <h2 id="faq-title">Tanya jawab</h2>
                <p>Belum menemukan jawabannya? Hubungi panitia melalui WhatsApp <a href="https://wa.me/6282181649543" rel="noopener">0821-8164-9543</a>.</p>
            </header>

            <div class="faq-list" data-accordion>
                <?php foreach ($faqItems as $index => $item) { ?>
                    <details class="faq-item"<?= $index === 0 ? ' open' : '' ?>>
                        <summary>
                            <span><?= h($item['q']) ?></span>
                            <svg class="faq-item__icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="m5 7.5 5 5 5-5"/></svg>
                        </summary>
                        <div class="faq-item__answer">
                            <p><?= h($item['a']) ?></p>
                        </div>
                    </details>
                <?php } ?>
            </div>
        </div>
    </section>

    <section class="closing" aria-labelledby="closing-title">
        <div class="shell closing__inner">
            <div>
                <h2 id="closing-title">Registrasi awal hanya butuh beberapa menit.</h2>
                <p>Siapkan nomor WhatsApp pendaftar, ayah, dan ibu. Panitia akan memandu langkah berikutnya.</p>
            </div>
            <a class="button button--light button--lg" href="daftar.php">Mulai registrasi awal <?= $arrow ?></a>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="shell footer-grid">
        <div class="footer-identity">
            <a class="footer-brand" href="index.php">
                <img src="img/logo-display.png" alt="" width="40" height="40" loading="lazy">
                <span>Yayasan Wakaf Cendekia Takengon</span>
            </a>
            <p>Jalan Pertamina&ndash;Kebet, Kampung Lemah Burbana, Bebesen, Aceh Tengah 24552</p>
        </div>

        <div>
            <h2 class="footer-heading">Kontak unit</h2>
            <ul class="footer-list">
                <li><span>Yayasan</span><a href="mailto:wakafcendekiatakengon@gmail.com">wakafcendekiatakengon@gmail.com</a></li>
                <?php foreach ($units as $unit) { if (!$unit['email']) { continue; } ?>
                    <li><span><?= h($unit['jenjang']) ?></span><a href="mailto:<?= h($unit['email']) ?>"><?= h($unit['email']) ?></a></li>
                <?php } ?>
            </ul>
        </div>

        <div>
            <h2 class="footer-heading">Tautan</h2>
            <ul class="footer-list footer-list--plain">
                <li><a href="daftar.php">Registrasi awal</a></li>
                <li><a href="#unit">Unit dan kuota</a></li>
                <li><a href="#persiapan">Persyaratan</a></li>
                <li><a href="https://cendekiabisa.com" rel="noopener">cendekiabisa.com</a></li>
                <li><a href="login.php">Masuk pengelola</a></li>
            </ul>
        </div>
    </div>
    <p class="shell footer-legal">&copy; <?= date('Y') ?> <?= h($siteName) ?></p>
</footer>

<script type="application/ld+json"><?= json_encode($organizationSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script type="application/ld+json"><?= json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script src="js/index.js?v=<?= (int) filemtime(__DIR__.'/js/index.js') ?>" defer></script>
</body>
</html>
