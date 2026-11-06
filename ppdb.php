<?php
require_once __DIR__ . '/public_layout.php';

$activeYear = '2027/2028';

// Data unit diambil dari database agar halaman beranda selalu sesuai
// dengan konfigurasi resmi, termasuk status buka dan kuotanya.
$unitQuery = mysqli_query($conn, "SELECT u.kode_unit,u.nama_unit,u.jenjang,u.npsn,u.email,u.alamat,
    q.th_ajaran,q.kuota_internal,q.kuota_eksternal,q.biaya_pendaftaran,".ppdb_internal_open_condition_sql($conn)." AS internal_dibuka,".ppdb_route_open_sql($conn, 'eksternal_dibuka').",".ppdb_schedule_columns_sql($conn).",
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
    // Tampilan publik menggabungkan jalur internal dan eksternal.
    $unit['kuota_total'] = $unit['kuota_internal'] + $unit['kuota_eksternal'];
    $unit['pendaftar_total'] = $unit['terisi'] + $unit['internal_terisi'];
    $unit['sisa_total'] = max(0, $unit['kuota_total'] - $unit['pendaftar_total']);
    // Halaman publik hanya mengikuti jalur eksternal; jalur internal SDM tidak membuat unit tampil dibuka.
    $unit['dibuka_total'] = (int) ($unit['eksternal_dibuka'] ?? 0) === 1;
    // Angka kuota baru ditampilkan setelah unit dibuka; penuh dan ditutup tetap tampil.
    $unit['kuota_tampil'] = $unit['kuota_total'] > 0
        && ($unit['dibuka_total'] || $unit['sisa_total'] === 0 || ppdb_schedule_phase($unit['jadwal_buka'] ?? null, $unit['jadwal_tutup'] ?? null) === 'selesai');
    $unit['levels'] = $unit['jenjang'] === 'TK' && $unit['th_ajaran'] ? ppdb_level_quotas($conn, $unit['kode_unit'], $unit['th_ajaran']) : [];
    if ($unit['jenjang'] === 'SMP' && $unit['th_ajaran']) {
        foreach (ppdb_gender_quotas($conn, $unit['kode_unit'], $unit['th_ajaran']) as $gender => $routes) {
            $unit['levels'][PPDB_GENDERS[$gender]] = $routes;
        }
    }
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

$unitCount = count($units);

$faqItems = [
    [
        'q' => 'Siapa saja yang bisa mendaftar?',
        'a' => 'Ananda usia dini hingga lulusan SD untuk Kelompok Bermain, Taman Penitipan Anak, TK, SD, dan SMP di bawah Yayasan Wakaf Cendekia Takengon. Pendaftaran dibuka per unit dan per tahun ajaran sesuai kuota yang ditetapkan yayasan.',
    ],
    [
        'q' => 'Bagaimana cara mendaftar?',
        'a' => 'Pendaftaran berlangsung tiga tahap. Pertama, mengisi registrasi awal berisi data dasar ananda. Kedua, pendaftar melakukan pembayaran dan mengunggah bukti transfer. Ketiga, pendaftar mengisi formulir lengkap dan mengunggah dokumen pendukung. Setelah formulir terkirim, ananda langsung diterima selama kursi tersedia. Seluruhnya dilakukan di situs ini.',
    ],
    [
        'q' => 'Dokumen apa saja yang harus disiapkan?',
        'a' => 'Kartu Keluarga, akta kelahiran, KTP ayah dan ibu (atau KTP wali bila ananda diasuh pihak lain), foto ananda, serta NISN bila ananda sudah memiliki dari sekolah sebelumnya. Dokumen diunggah pada tahap formulir lengkap.',
    ],
    [
        'q' => 'Bagaimana saya memantau status pendaftaran?',
        'a' => 'Setelah registrasi awal terkirim, Anda menerima kode pendaftaran dan langsung melanjutkan ke pembayaran. Simpan kode tersebut. Untuk kembali atau melihat hasil, buka halaman Lanjutkan pendaftaran dengan kode, tanggal lahir ananda, dan nomor WhatsApp.',
    ],
    [
        'q' => 'Apakah data anak saya aman?',
        'a' => 'Data identitas lengkap hanya dapat diakses pengelola yang telah masuk ke dashboard. Dokumen disimpan di luar folder publik situs dan tidak ditampilkan kembali kepada pendaftar.',
    ],
    [
        'q' => 'Bagaimana jika kuota sudah terpenuhi?',
        'a' => 'Pendaftar dapat ditempatkan pada daftar tunggu. Perkembangannya dapat dilihat di halaman Lanjutkan pendaftaran.',
    ],
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
$check = '<svg class="i-check" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="m5 10.5 3.2 3L15 6.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

public_page_start($conn, [
    'title' => 'SPMB '.$activeYear,
    'description' => 'Pendaftaran SPMB '.$activeYear.' untuk KB, TPA, TK, SD, dan SMP Islam terpadu di Takengon. Cek kuota, persyaratan, alur pendaftaran, dan jadwal.',
    'active' => 'ppdb',
    'path' => 'spmb',
    'schema' => [$faqSchema],
]);
?>
<?php
// Ringkasan hero: dijumlahkan dari data unit di atas, tanpa query tambahan.
$visibleUnits = array_filter($units, static fn (array $unit): bool => $unit['kuota_tampil']);
$summaryQuota = array_sum(array_column($visibleUnits, 'kuota_total'));
$summarySeats = array_sum(array_column($visibleUnits, 'sisa_total'));
$summaryOpen = count(array_filter($units, static fn (array $unit): bool => $unit['dibuka_total'] && $unit['sisa_total'] > 0));
?>
<section class="page-hero page-hero--ppdb">
    <div class="shell ppdb-hero">
        <div>
            <ol class="breadcrumbs" aria-label="Breadcrumb"><li><a href="./">Beranda</a></li><li aria-current="page">SPMB</li></ol>
            <p class="kicker">Penerimaan peserta didik baru &middot; Tahun ajaran <?= h($activeYear) ?></p>
            <h1>Pendaftaran daring, selesai langsung di situs ini.</h1>
            <p>Lihat kuota setiap unit, ikuti tiga tahap pendaftaran, dan siapkan dokumen yang diperlukan sebelum mengisi formulir lengkap.</p>
            <div class="hero-slider__actions">
                <a class="button button--primary button--lg" href="daftar">Mulai registrasi awal</a>
                <a class="button button--quiet button--lg" href="spmb#persiapan">Lihat persyaratan</a>
            </div>
        </div>
        <aside class="ppdb-summary" aria-label="Ringkasan SPMB <?= h($activeYear) ?>">
            <p class="ppdb-summary__eyebrow">SPMB <?= h($activeYear) ?></p>
            <dl>
                <div><dt>Unit dibuka</dt><dd><?= $summaryOpen ?><small> / <?= count($units) ?></small></dd></div>
                <div><dt>Total kuota</dt><dd><?= number_format($summaryQuota, 0, ',', '.') ?></dd></div>
                <div><dt>Pendaftar</dt><dd><?= number_format($totalRegistrants, 0, ',', '.') ?></dd></div>
                <div><dt>Sisa kursi</dt><dd><?= number_format($summarySeats, 0, ',', '.') ?></dd></div>
            </dl>
            <a class="ppdb-summary__link" href="spmb#unit">Lihat kuota per unit</a>
        </aside>
    </div>
</section>

<nav class="subpage-nav" aria-label="Bagian halaman SPMB" data-spy>
    <ul class="shell">
        <li><a href="spmb#unit">Unit &amp; kuota</a></li>
        <li><a href="spmb#alur">Alur</a></li>
        <li><a href="spmb#persiapan">Persiapan</a></li>
        <li><a href="spmb#angka">Angka pendaftaran</a></li>
        <li><a href="spmb#faq">Tanya jawab</a></li>
    </ul>
</nav>

    <section class="section" id="unit" aria-labelledby="unit-title">
        <div class="shell">
            <header class="section-head section-head--split">
                <div>
                    <p class="kicker">Daya tampung</p>
                    <h2 id="unit-title">Unit pendidikan dan kuota</h2>
                    <p>Status pendaftaran tahun ajaran <?= h($activeYear) ?>. Angka kuota dan sisa kursi diambil langsung dari sistem pendaftaran.</p>
                </div>
                <p class="section-head__meta">Diperbarui <?= h(ppdb_format_date_id(ppdb_stats_today()->format('Y-m-d'))) ?></p>
            </header>

            <ol class="unit-board">
                <?php foreach ($units as $unit) {
                    $ageText = $ageSummary((string) $unit['jenjang']);
                    // Kuota eksternal (umum) penuh: tampil penuh bagi publik walau kuota internal masih tersisa.
                    $externalFull = $unit['penuh'];
                    $fillPercent = $externalFull ? 100 : ($unit['kuota_total'] > 0 ? min(100, (int) round($unit['pendaftar_total'] / $unit['kuota_total'] * 100)) : 0);
                    $isOpen = !$externalFull && $unit['dibuka_total'] && $unit['kuota_total'] > 0 && $unit['sisa_total'] > 0;
                    $schedulePhase = ppdb_schedule_phase($unit['jadwal_buka'] ?? null, $unit['jadwal_tutup'] ?? null);
                    $scheduleText = ppdb_format_schedule_range($unit['jadwal_buka'] ?? null, $unit['jadwal_tutup'] ?? null);
                ?>
                    <li class="unit-row<?= $isOpen ? ' is-open' : '' ?>">
                        <span class="unit-row__code" aria-hidden="true"><?= h($unit['jenjang']) ?></span>
                        <div class="unit-row__identity">
                            <h3><a href="<?= h(ppdb_unit_public_path($unit)) ?>"><?= h(ppdb_unit_display_name($unit['nama_unit'])) ?></a></h3>
                            <p><?= h(implode(' · ', array_filter([ppdb_jenjang_label($unit['jenjang']), $unit['npsn'] ? 'NPSN '.$unit['npsn'] : '', $ageText]))) ?></p>
                            <?php if ($scheduleText !== '') { ?><p class="unit-row__schedule">Jadwal pendaftaran: <?= h($scheduleText) ?></p><?php } ?>
                        </div>
                        <?php if (!$unit['kuota_tampil']) { ?>
                        <div class="unit-row__capacity"></div>
                        <?php } else { ?>
                        <div class="unit-row__capacity">
                            <dl class="unit-row__numbers">
                                <div>
                                    <dt>Kuota</dt>
                                    <dd><?= $unit['kuota_total'] > 0 ? $unit['kuota_total'] : '&ndash;' ?></dd>
                                </div>
                                <div>
                                    <dt>Sisa kursi</dt>
                                    <dd<?= $externalFull ? ' class="is-full"' : '' ?>><?= $externalFull ? 'Kuota sudah penuh' : ($unit['kuota_total'] > 0 ? $unit['sisa_total'] : '&ndash;') ?></dd>
                                </div>
                            </dl>
                            <?php if ($unit['kuota_total'] > 0) { ?>
                                <span class="unit-row__meter" role="img" aria-label="<?= $fillPercent ?> persen kuota terisi"><span style="width: <?= $fillPercent ?>%"></span></span>
                            <?php } ?>
                            <?php if ($unit['levels']) { ?>
                                <ul class="unit-row__levels">
                                    <?php foreach ($unit['levels'] as $levelName => $levelRoutes) {
                                        $levelQuota = $levelRoutes['internal'][0] + $levelRoutes['eksternal'][0];
                                        $levelLeft = max(0, $levelQuota - $levelRoutes['internal'][1] - $levelRoutes['eksternal'][1]);
                                        // Sama dengan aturan unit: kuota umum jenjang habis berarti penuh bagi publik.
                                        $levelFull = $levelRoutes['eksternal'][1] >= $levelRoutes['eksternal'][0];
                                    ?>
                                        <li><strong><?= h($levelName) ?></strong> <span>Kuota <?= $levelQuota ?></span> <span<?= $levelFull ? ' class="is-full"' : '' ?>><?= $levelFull ? 'Kuota sudah penuh' : 'Sisa '.$levelLeft ?></span></li>
                                    <?php } ?>
                                </ul>
                            <?php } ?>
                        </div>
                        <?php } ?>
                        <div class="unit-row__status">
                            <?php if ($isOpen) { ?>
                                <span class="badge badge--success">Dibuka</span>
                                <?php if ($unit['terbuka']) { ?>
                                    <a class="button button--secondary button--sm" href="<?= h(ppdb_unit_register_link($unit['kode_unit'], $activeYear)) ?>">Daftar ke <?= h($unit['jenjang']) ?><span class="visually-hidden"> <?= h(ppdb_unit_display_name($unit['nama_unit'])) ?></span></a>
                                <?php } ?>
                            <?php } elseif ($externalFull || ($unit['kuota_total'] > 0 && $unit['sisa_total'] === 0)) { ?>
                                <span class="badge badge--warning">Kuota terpenuhi</span>
                            <?php } elseif ($schedulePhase === 'selesai') { ?>
                                <span class="badge badge--neutral">Pendaftaran ditutup</span>
                            <?php } elseif ($schedulePhase === 'belum') { ?>
                                <span class="badge badge--neutral">Dibuka <?= h(ppdb_format_datetime_short($unit['jadwal_buka'])) ?> WIB</span>
                            <?php } else { ?>
                                <span class="badge badge--neutral">Belum dibuka</span>
                            <?php } ?>
                        </div>
                    </li>
                <?php } ?>
            </ol>
            <p class="unit-board__note">Kuota dan sisa kursi mencakup seluruh jalur pendaftaran, tidak termasuk pendaftar yang ditolak.</p>
        </div>
    </section>

    <section class="section section--tinted" id="alur" aria-labelledby="alur-title">
        <div class="shell">
            <header class="section-head">
                <p class="kicker">Alur pendaftaran</p>
                <h2 id="alur-title">Tiga tahap pendaftaran</h2>
                <p>Data dasar lebih dulu, lalu pembayaran. Formulir lengkap dan dokumen diisi langsung setelah bukti transfer diunggah.</p>
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
                    <h3>Pembayaran</h3>
                    <p>Lanjut langsung setelah registrasi awal: transfer sesuai petunjuk, lalu unggah buktinya. Anda tidak perlu menunggu dihubungi.</p>
                    <p class="step__meta">Langsung di situs ini</p>
                </li>
                <li class="step">
                    <span class="step__number">3</span>
                    <h3>Formulir lengkap</h3>
                    <p>Lanjut pada halaman yang sama: isi data identitas, alamat, dan keluarga, baca materi SPMB unit tujuan, lalu unggah dokumen pendukung.</p>
                    <p class="step__meta">Setelah terkirim, hasil langsung tampil</p>
                </li>
            </ol>
        </div>
    </section>

    <section class="section" id="persiapan" aria-labelledby="persiapan-title">
        <div class="shell">
            <header class="section-head">
                <p class="kicker">Persiapan</p>
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
            </div>
        </div>
    </section>

    <section class="section section--tinted" id="angka" aria-labelledby="angka-title">
        <div class="shell">
            <header class="section-head">
                <p class="kicker">Statistik</p>
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

    <section class="section section--tinted" id="faq" aria-labelledby="faq-title">
        <div class="shell faq">
            <header class="section-head">
                <p class="kicker">Bantuan</p>
                <h2 id="faq-title">Tanya jawab</h2>
                <p>Belum menemukan jawabannya? Hubungi panitia melalui WhatsApp <a href="<?= h(ppdb_admin_whatsapp_url()) ?>" rel="noopener"><?= h(ppdb_admin_whatsapp_display()) ?></a>.</p>
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

    <section class="closing closing--card" aria-labelledby="closing-title">
        <div class="shell closing__inner">
            <div>
                <h2 id="closing-title">Registrasi awal hanya butuh beberapa menit.</h2>
                <p>Siapkan nomor WhatsApp pendaftar, ayah, dan ibu. Sudah pernah mendaftar? <a href="lanjutkan-pendaftaran">Lanjutkan pendaftaran</a>.</p>
            </div>
            <a class="button button--light button--lg" href="daftar">Mulai registrasi awal</a>
        </div>
    </section>

<script src="<?= public_asset('js/ppdb.js') ?>" defer></script>
<?php public_page_end($conn); ?>
