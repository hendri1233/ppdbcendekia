<?php
require_once __DIR__ . '/public_layout.php';

$units = cms_units($conn);
$unitCodes = array_column($units, 'kode_unit');
$slug = is_string($_GET['baca'] ?? null) ? trim($_GET['baca']) : '';

// ---------- Halaman baca ----------
if ($slug !== '') {
    $stmt = mysqli_prepare($conn, "SELECT n.*,u.nama_unit,u.jenjang FROM tb_cms_news n LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=n.kode_unit WHERE n.slug=? AND n.status='published' AND n.published_at<=NOW() LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $slug);
    mysqli_stmt_execute($stmt);
    $article = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$article) {
        http_response_code(404);
        public_page_start($conn, ['title' => 'Berita tidak ditemukan', 'active' => 'berita', 'path' => 'berita']);
        ?>
        <section class="page-hero">
            <div class="shell">
                <p class="kicker">404</p>
                <h1>Berita tidak ditemukan.</h1>
                <p>Berita mungkin sudah dipindahkan atau belum diterbitkan.</p>
                <div class="hero-slider__actions"><a class="button button--primary" href="berita">Lihat semua berita</a></div>
            </div>
        </section>
        <?php
        public_page_end($conn);
        exit;
    }

    $articlePath = 'berita/'.rawurlencode((string) $article['slug']);
    $siteBase = rtrim(ppdb_public_base_url(), '/');
    $basePath = (string) (parse_url($siteBase, PHP_URL_PATH) ?: '');
    $canonicalPath = rtrim($basePath, '/').'/'.$articlePath;
    $requestPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    if (rtrim($requestPath, '/') !== $canonicalPath) {
        header('Location: '.$siteBase.'/'.$articlePath, true, 301);
        exit;
    }

    // Kolom kanan: berita unit yang sama lebih dulu, lalu dilengkapi berita terbaru lainnya.
    [$sameUnit] = $article['kode_unit'] ? cms_published_news($conn, 6, 0, (string) $article['kode_unit']) : [[]];
    [$latest] = cms_published_news($conn, 6);
    $related = [];
    foreach (array_merge($sameUnit, $latest) as $row) {
        if ((int) $row['id'] !== (int) $article['id']) {
            $related[(int) $row['id']] = $row;
        }
    }
    $related = array_slice($related, 0, 5);
    $articleUrl = $siteBase.'/'.$articlePath;

    public_page_start($conn, [
        'title' => $article['title'],
        'description' => cms_news_excerpt($article, 160),
        'active' => 'berita',
        'path' => $articlePath,
        'image' => $article['cover_path'] ?: 'img/og-cendekia.jpg',
        'og_type' => 'article',
        'schema' => [[
            '@context' => 'https://schema.org',
            '@type' => 'NewsArticle',
            'headline' => $article['title'],
            'datePublished' => date(DATE_ATOM, strtotime((string) $article['published_at'])),
            'dateModified' => date(DATE_ATOM, strtotime((string) $article['updated_at'])),
            'image' => $article['cover_path'] ? [ppdb_public_base_url().'/'.$article['cover_path']] : [],
            'publisher' => ['@type' => 'Organization', 'name' => PUBLIC_SITE_NAME],
        ]],
    ]);
    ?>
    <div class="section">
    <div class="shell article-layout<?= $related ? '' : ' article-layout--single' ?>">
    <article class="article">
        <header class="article__head">
            <ol class="breadcrumbs" aria-label="Breadcrumb"><li><a href="./">Beranda</a></li><li><a href="berita">Berita</a></li><li aria-current="page"><?= h(cms_plain_excerpt($article['title'], 40)) ?></li></ol>
            <p class="news-meta">
                <span class="tag"><?= h($article['jenjang'] ?: $article['category']) ?></span>
                <time datetime="<?= h(substr((string) $article['published_at'], 0, 10)) ?>"><?= h(cms_format_datetime_id($article['published_at'])) ?></time>
                <span><?= cms_reading_minutes((string) $article['body_html']) ?> menit baca</span>
                <?php if ($article['nama_unit']) { ?><a class="text-link" href="unit/<?= h(ppdb_unit_public_slug(['kode_unit' => $article['kode_unit'], 'nama_unit' => $article['nama_unit']])) ?>"><?= h(ppdb_unit_display_name($article['nama_unit'])) ?></a><?php } ?>
            </p>
            <h1><?= h($article['title']) ?></h1>
            <?php if (trim((string) $article['excerpt']) !== '') { ?><p class="article__lead"><?= h($article['excerpt']) ?></p><?php } ?>
        </header>
        <?php if ($article['cover_path']) { ?>
            <figure class="article__cover"><?= cms_image_backdrop($article['cover_path']) ?><img src="<?= h($article['cover_path']) ?>" alt="<?= h($article['title']) ?>"<?= cms_image_size_attrs($article['cover_path']) ?>></figure>
        <?php } ?>
        <div class="article__body"><div class="prose"><?= cms_format_news_html((string) $article['body_html']) ?></div></div>
        <div class="article__share">
            <span>Bagikan:</span>
            <a class="button button--quiet button--sm" href="https://wa.me/?text=<?= rawurlencode($article['title'].' '.$articleUrl) ?>" target="_blank" rel="noopener">WhatsApp</a>
            <a class="button button--quiet button--sm" href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode($articleUrl) ?>" target="_blank" rel="noopener">Facebook</a>
            <button class="button button--quiet button--sm" type="button" data-copy-link="<?= h($articleUrl) ?>" aria-live="polite">Salin link</button>
        </div>
    </article>
    <?php if ($related) { ?>
        <aside class="article-aside" aria-labelledby="related-title">
            <h2 id="related-title">Berita lainnya</h2>
            <ul class="side-news">
                <?php foreach ($related as $news) { $newsUrl = 'berita/'.rawurlencode($news['slug']); ?>
                    <li>
                        <a class="side-news__item" href="<?= h($newsUrl) ?>">
                            <span class="side-news__thumb">
                                <?php if ($news['cover_path']) { ?><img src="<?= h($news['cover_path']) ?>" alt="" width="96" height="96" loading="lazy"><?php } else { ?><img class="side-news__logo" src="img/logo-display.png" alt="" width="48" height="48" loading="lazy"><?php } ?>
                            </span>
                            <span class="side-news__text">
                                <strong><?= h($news['title']) ?></strong>
                                <small><?= h($news['jenjang'] ?: $news['category']) ?> · <?= h(cms_format_datetime_id($news['published_at'])) ?></small>
                            </span>
                        </a>
                    </li>
                <?php } ?>
            </ul>
            <a class="text-link article-aside__all" href="berita">Semua berita</a>
        </aside>
    <?php } ?>
    </div>
    </div>
    <?php
    public_page_end($conn);
    exit;
}

// ---------- Daftar berita ----------
$selectedUnit = is_string($_GET['unit'] ?? null) && in_array($_GET['unit'], $unitCodes, true) ? $_GET['unit'] : '';
$perPage = 9;
$page = max(1, (int) ($_GET['halaman'] ?? 1));
[$items, $total] = cms_published_news($conn, $perPage, ($page - 1) * $perPage, $selectedUnit);
$pages = max(1, (int) ceil($total / $perPage));
$siteBase = rtrim(ppdb_public_base_url(), '/');
$basePath = (string) (parse_url($siteBase, PHP_URL_PATH) ?: '');
$canonicalListingPath = rtrim($basePath, '/').'/berita';
$requestPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
if (rtrim($requestPath, '/') !== $canonicalListingPath) {
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: '.$siteBase.'/berita'.($query !== '' ? '?'.$query : ''), true, 301);
    exit;
}
$link = static fn (array $query) => 'berita'.(($query = array_filter($query, static fn ($v) => $v !== '' && $v !== 1)) ? '?'.http_build_query($query) : '');

public_page_start($conn, [
    'title' => 'Berita',
    'description' => 'Berita dan kegiatan terbaru Yayasan Wakaf Cendekia Takengon serta unit KB, TPA, TK, SD, dan SMP.',
    'active' => 'berita',
    'path' => $link(['unit' => $selectedUnit, 'halaman' => $page]),
]);
?>
<section class="page-hero">
    <div class="shell">
        <ol class="breadcrumbs" aria-label="Breadcrumb"><li><a href="./">Beranda</a></li><li aria-current="page">Berita</li></ol>
        <p class="kicker">Berita dan kegiatan</p>
        <h1>Kabar dari yayasan dan setiap unit</h1>
    </div>
</section>

<section class="section section--tight">
    <div class="shell">
        <nav class="filter-chips" aria-label="Saring berita per unit">
            <a href="berita"<?= $selectedUnit === '' ? ' aria-current="true"' : '' ?>>Semua</a>
            <?php foreach ($units as $unit) { ?>
                <a href="<?= h($link(['unit' => $unit['kode_unit']])) ?>"<?= $selectedUnit === $unit['kode_unit'] ? ' aria-current="true"' : '' ?>><?= h($unit['jenjang']) ?></a>
            <?php } ?>
        </nav>

        <?php if (!$items) { ?>
            <div class="empty-note"><strong>Belum ada berita<?= $selectedUnit !== '' ? ' untuk unit ini' : '' ?>.</strong><span>Berita akan tampil di sini setelah diterbitkan oleh pengelola.</span></div>
        <?php } else { ?>
            <div class="news-grid">
                <?php $noLead = $page > 1; foreach ($items as $index => $news) { include __DIR__.'/partials/news-card.php'; } ?>
            </div>
        <?php } ?>

        <?php if ($pages > 1) { ?>
            <nav class="pager" aria-label="Halaman berita">
                <?php if ($page > 1) { ?><a href="<?= h($link(['unit' => $selectedUnit, 'halaman' => $page - 1])) ?>" rel="prev"><?= public_icon('prev') ?><span class="visually-hidden">Sebelumnya</span></a><?php } ?>
                <?php for ($i = 1; $i <= $pages; $i++) {
                    if ($i === 1 || $i === $pages || abs($i - $page) <= 1) { ?>
                        <a href="<?= h($link(['unit' => $selectedUnit, 'halaman' => $i])) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
                    <?php } elseif (abs($i - $page) === 2) { ?><span aria-hidden="true">&hellip;</span><?php }
                } ?>
                <?php if ($page < $pages) { ?><a href="<?= h($link(['unit' => $selectedUnit, 'halaman' => $page + 1])) ?>" rel="next"><?= public_icon('next') ?><span class="visually-hidden">Berikutnya</span></a><?php } ?>
            </nav>
        <?php } ?>
    </div>
</section>
<?php public_page_end($conn); ?>
