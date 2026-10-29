<?php
require_once __DIR__ . '/public_layout.php';

$code = is_string($_GET['kode'] ?? null) ? $_GET['kode'] : '';
$requestedSlug = is_string($_GET['slug'] ?? null) ? strtolower(trim($_GET['slug'])) : '';
$unit = null;
foreach (cms_units($conn) as $row) {
    if ($row['kode_unit'] === $code || ($requestedSlug !== '' && ppdb_unit_public_slug($row) === $requestedSlug)) {
        $unit = $row;
        $code = (string) $row['kode_unit'];
        break;
    }
}

if (!$unit) {
    http_response_code(404);
    public_page_start($conn, ['title' => 'Unit tidak ditemukan', 'active' => 'unit', 'path' => 'unit']);
    ?>
    <section class="page-hero">
        <div class="shell">
            <p class="kicker">404</p>
            <h1>Unit pendidikan tidak ditemukan.</h1>
            <div class="hero-slider__actions"><a class="button button--primary" href="./">Kembali ke beranda</a></div>
        </div>
    </section>
    <?php
    public_page_end($conn);
    exit;
}

$unitPath = ppdb_unit_public_path($unit);
$publicBase = rtrim(ppdb_public_base_url(), '/');
$basePath = (string) (parse_url($publicBase, PHP_URL_PATH) ?: '');
$canonicalPath = rtrim($basePath, '/').'/'.$unitPath;
$requestPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
if (rtrim($requestPath, '/') !== $canonicalPath) {
    header('Location: '.$publicBase.'/'.$unitPath, true, 301);
    exit;
}

// Status SPMB jalur umum unit ini untuk tahun ajaran aktif.
$quotaStmt = mysqli_prepare($conn, "SELECT q.kuota_eksternal,q.eksternal_dibuka,
    (SELECT COUNT(*) FROM tb_pendaftaran p WHERE p.kode_unit=q.kode_unit AND p.th_ajaran=q.th_ajaran AND p.jalur_pendaftaran='eksternal' AND p.status_ppdb NOT IN ('ditolak','data_lama')) AS terisi
    FROM tb_pengaturan_ppdb q WHERE q.kode_unit=? AND q.th_ajaran=? LIMIT 1");
$year = PUBLIC_PPDB_YEAR;
mysqli_stmt_bind_param($quotaStmt, 'ss', $code, $year);
mysqli_stmt_execute($quotaStmt);
$quota = mysqli_fetch_assoc(mysqli_stmt_get_result($quotaStmt)) ?: ['kuota_eksternal' => 0, 'eksternal_dibuka' => 0, 'terisi' => 0];
mysqli_stmt_close($quotaStmt);
$remaining = max(0, (int) $quota['kuota_eksternal'] - (int) $quota['terisi']);
$isOpen = (int) $quota['eksternal_dibuka'] === 1 && $remaining > 0;

[$unitNews] = cms_published_news($conn, 3, 0, $code);
$name = ppdb_unit_display_name($unit['nama_unit']);

public_page_start($conn, [
    'title' => $name,
    'active' => 'unit',
    'description' => $unit['tagline'] ?: $name.' — '.ppdb_jenjang_label($unit['jenjang']).' di bawah Yayasan Wakaf Cendekia Takengon.',
    'path' => $unitPath,
    'image' => $unit['photo_path'] ?: 'img/wisuda-1200.jpg',
]);
?>
<section class="page-hero">
    <div class="shell">
        <ol class="breadcrumbs" aria-label="Breadcrumb"><li><a href="./">Beranda</a></li><li aria-current="page"><?= h($unit['jenjang']) ?></li></ol>
        <p class="kicker"><?= h(ppdb_jenjang_label($unit['jenjang'])) ?></p>
        <h1 class="unit-name"><?= h($name) ?></h1>
        <?php if ($unit['tagline']) { ?><p><?= h($unit['tagline']) ?></p><?php } ?>
    </div>
</section>

<section class="section">
    <div class="shell unit-profile">
        <div>
            <?php if ($unit['photo_path']) { ?>
                <figure class="unit-profile__photo"><img src="<?= h($unit['photo_path']) ?>" alt="<?= h($name) ?>" width="1200" height="675"></figure>
            <?php } ?>
            <?php if (trim((string) $unit['description_html']) !== '') { ?>
                <div class="prose"><?= $unit['description_html'] ?></div>
            <?php } else { ?>
                <div class="empty-note"><strong>Profil unit sedang disiapkan.</strong><span>Informasi lengkap <?= h($name) ?> akan tampil setelah diisi oleh pengelola situs.</span></div>
            <?php } ?>
        </div>
        <aside>
            <div class="side-card">
                <h2>Identitas unit</h2>
                <dl>
                    <div><dt>Jenjang</dt><dd><?= h(ppdb_jenjang_label($unit['jenjang'])) ?></dd></div>
                    <?php if ($unit['npsn']) { ?><div><dt>NPSN</dt><dd><?= h($unit['npsn']) ?></dd></div><?php } ?>
                    <?php if ($unit['alamat']) { ?><div><dt>Alamat</dt><dd><?= h($unit['alamat']) ?></dd></div><?php } ?>
                    <?php if ($unit['email']) { ?><div><dt>Email</dt><dd><a href="mailto:<?= h($unit['email']) ?>"><?= h($unit['email']) ?></a></dd></div><?php } ?>
                    <?php if ($unit['phone']) { ?><div><dt>Telepon</dt><dd><a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $unit['phone'])) ?>"><?= h($unit['phone']) ?></a></dd></div><?php } ?>
                </dl>
            </div>
            <div class="side-card">
                <h2>SPMB <?= h(PUBLIC_PPDB_YEAR) ?></h2>
                <?php if ($isOpen) { ?>
                    <span class="badge badge--success">Dibuka &middot; <?= $remaining ?> kursi tersisa</span>
                    <a class="button button--primary" href="daftar.php?unit=<?= rawurlencode($code) ?>&amp;tahun=<?= rawurlencode(PUBLIC_PPDB_YEAR) ?>">Daftar ke <?= h($unit['jenjang']) ?></a>
                <?php } else { ?>
                    <span class="badge badge--neutral"><?= (int) $quota['eksternal_dibuka'] === 1 ? 'Kuota terpenuhi' : 'Belum dibuka' ?></span>
                    <a class="button button--secondary" href="spmb">Info SPMB</a>
                <?php } ?>
            </div>
        </aside>
    </div>
</section>

<?php if ($unitVideos = cms_unit_socials($conn, $code, true)) { ?>
    <section class="section unit-videos" aria-labelledby="unit-videos-title">
        <div class="shell">
            <header class="section-head">
                <h2 id="unit-videos-title" class="unit-videos__title">Media Sosial <span><?= h($name) ?></span></h2>
            </header>
            <ul class="unit-videos__row">
                <?php foreach ($unitVideos as $video) { ?>
                    <li class="unit-video unit-video--<?= h($video['network']) ?>">
                        <div class="unit-video__frame">
                            <?php if ($video['video']) { ?>
                                <video src="<?= h($video['video']['src']) ?>"<?= $video['video']['poster'] ? ' poster="'.h($video['video']['poster']).'"' : '' ?> autoplay muted loop playsinline controls preload="metadata" title="<?= h($video['label'].' '.$name) ?>"></video>
                            <?php } elseif ($video['embed']) { ?>
                                <iframe src="<?= h($video['embed']) ?>" title="<?= h($video['label'].' '.$name) ?>" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen scrolling="no"></iframe>
                            <?php } else { ?>
                                <a class="unit-video__fallback" href="<?= h($video['url']) ?>" target="_blank" rel="noopener"><?= public_icon($video['icon']) ?><span>Tonton di <?= h($video['label']) ?></span></a>
                            <?php } ?>
                        </div>
                        <a class="unit-video__label" href="<?= h($video['url']) ?>" target="_blank" rel="noopener"><?= public_icon($video['icon']) ?><span><?= h($video['label']) ?></span></a>
                    </li>
                <?php } ?>
            </ul>
        </div>
    </section>
<?php } ?>

<?php if ($unitNews) { ?>
    <section class="section section--tinted" aria-labelledby="unit-news-title">
        <div class="shell">
            <header class="section-head section-head--split">
                <h2 id="unit-news-title">Berita <?= h($unit['jenjang']) ?></h2>
                <a class="text-link" href="berita?unit=<?= rawurlencode($code) ?>">Semua berita unit</a>
            </header>
            <div class="news-grid">
                <?php $noLead = true; foreach ($unitNews as $index => $news) { include __DIR__.'/partials/news-card.php'; } ?>
            </div>
        </div>
    </section>
<?php } ?>
<?php public_page_end($conn); ?>
