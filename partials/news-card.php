<?php
/**
 * Kartu berita. Membutuhkan $news (baris dari cms_published_news) dan $index;
 * kartu pertama tampil lebar kecuali $noLead bernilai true.
 */
$isLead = $index === 0 && empty($noLead);
$newsUrl = 'berita.php?baca='.rawurlencode($news['slug']);
?>
<article class="news-card<?= $isLead ? ' news-card--lead' : '' ?>">
    <a class="news-card__media" href="<?= h($newsUrl) ?>" tabindex="-1" aria-hidden="true">
        <?php if ($news['cover_path']) { ?>
            <img src="<?= h($news['cover_path']) ?>" alt="" width="800" height="500" loading="lazy">
        <?php } else { ?>
            <span class="news-card__placeholder"><img src="img/logo-display.png" alt="" width="96" height="96" loading="lazy"></span>
        <?php } ?>
    </a>
    <div>
        <p class="news-meta">
            <span class="tag"><?= h($news['jenjang'] ?: $news['category']) ?></span>
            <time datetime="<?= h(substr((string) $news['published_at'], 0, 10)) ?>"><?= h(cms_format_datetime_id($news['published_at'])) ?></time>
        </p>
        <h3><a href="<?= h($newsUrl) ?>"><?= h($news['title']) ?></a></h3>
        <p><?= h(cms_news_excerpt($news, $isLead ? 240 : 140)) ?></p>
    </div>
</article>
