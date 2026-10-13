<?php

/**
 * Kerangka halaman publik situs yayasan: <head>, header bernavigasi, loader,
 * footer, dan widget asisten AI. Dipakai semua halaman publik agar konsisten.
 */

require_once __DIR__.'/koneksi.php';
require_once __DIR__.'/cms_helpers.php';
require_once __DIR__.'/web_stats.php';

const PUBLIC_SITE_NAME = 'Yayasan Wakaf Cendekia Takengon';
const PUBLIC_PPDB_YEAR = '2027/2028';

function public_asset(string $path): string
{
    $full = __DIR__.'/'.$path;
    return h($path.(is_file($full) ? '?v='.filemtime($full) : ''));
}

function public_icon(string $name): string
{
    $paths = [
        'arrow' => '<path d="M4 10h11M11 5.5 15.5 10 11 14.5"/>',
        'chevron' => '<path d="m6 8 4 4 4-4"/>',
        'sun' => '<circle cx="10" cy="10" r="3.4"/><path d="M10 2.5v1.8M10 15.7v1.8M2.5 10h1.8M15.7 10h1.8M4.7 4.7l1.3 1.3M14 14l1.3 1.3M4.7 15.3 6 14M14 6l1.3-1.3"/>',
        'moon' => '<path d="M16.2 12.4A6.6 6.6 0 0 1 7.6 3.8a6.6 6.6 0 1 0 8.6 8.6Z"/>',
        'chat' => '<path d="M4 4.5h12a1.5 1.5 0 0 1 1.5 1.5v7a1.5 1.5 0 0 1-1.5 1.5H9l-4 3v-3H4A1.5 1.5 0 0 1 2.5 13V6A1.5 1.5 0 0 1 4 4.5Z"/><path d="M6.5 8.5h7M6.5 11h4.5"/>',
        'close' => '<path d="m5 5 10 10M15 5 5 15"/>',
        'send' => '<path d="M3 10 17 3l-4 14-3.2-5.8L3 10Z"/><path d="m9.8 11.2 3-3"/>',
        'phone' => '<path d="M5.5 3h2.2l1.2 3.4-1.6 1.1a8.6 8.6 0 0 0 5.2 5.2l1.1-1.6 3.4 1.2v2.2A1.5 1.5 0 0 1 15.5 16 12.5 12.5 0 0 1 4 4.5 1.5 1.5 0 0 1 5.5 3Z"/>',
        'mail' => '<rect x="2.5" y="4.5" width="15" height="11" rx="1.5"/><path d="m3 5.5 7 5.5 7-5.5"/>',
        'pin' => '<path d="M10 17.5s5.5-5 5.5-9.5a5.5 5.5 0 0 0-11 0c0 4.5 5.5 9.5 5.5 9.5Z"/><circle cx="10" cy="8" r="2"/>',
        'globe' => '<circle cx="10" cy="10" r="7.5"/><path d="M2.5 10h15M10 2.5c2.2 2.3 2.2 12.7 0 15M10 2.5c-2.2 2.3-2.2 12.7 0 15"/>',
        'play' => '<path d="M7 5.5v9l7.5-4.5L7 5.5Z"/>',
        'prev' => '<path d="m12 5-5 5 5 5"/>',
        'next' => '<path d="m8 5 5 5-5 5"/>',
        'check' => '<path d="m5 10.5 3.2 3L15 6.5"/>',
        'calendar' => '<rect x="3" y="4.5" width="14" height="12.5" rx="1.5"/><path d="M3 8.5h14M7 2.5v4M13 2.5v4"/>',
    ];
    return '<svg class="icon icon--'.$name.'" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.($paths[$name] ?? '').'</svg>';
}

function public_navigation(): array
{
    return [
        ['key' => 'beranda', 'href' => 'index.php', 'label' => 'Beranda'],
        ['key' => 'tentang', 'href' => 'tentang.php', 'label' => 'Tentang', 'children' => [
            ['href' => 'tentang.php#prakata', 'label' => 'Prakata pimpinan'],
            ['href' => 'tentang.php#manajemen', 'label' => 'Profil manajemen'],
            ['href' => 'tentang.php#visi-misi', 'label' => 'Visi dan misi'],
            ['href' => 'tentang.php#fasilitas', 'label' => 'Fasilitas'],
        ]],
        ['key' => 'kurikulum', 'href' => 'kurikulum.php', 'label' => 'Kurikulum JSIT', 'children' => [
            ['href' => 'kurikulum.php#penggerak', 'label' => 'Program Penggerak'],
            ['href' => 'kurikulum.php#k13', 'label' => 'Kurikulum 2013'],
            ['href' => 'kurikulum.php#merdeka', 'label' => 'Merdeka Belajar'],
            ['href' => 'kurikulum.php#deep_learning', 'label' => 'Deep Learning'],
        ]],
        ['key' => 'berita', 'href' => 'berita.php', 'label' => 'Berita'],
        ['key' => 'ppdb', 'href' => 'ppdb.php', 'label' => 'PPDB'],
        ['key' => 'kontak', 'href' => 'kontak.php', 'label' => 'Kontak'],
    ];
}

/**
 * Membuka halaman publik.
 *
 * @param array{title:string,description?:string,active?:string,path?:string,image?:string,schema?:array} $page
 */
function public_page_start(mysqli $conn, array $page): void
{
    ppdb_track_visit($conn);
    $siteUrl = ppdb_public_base_url();
    $title = $page['title'] === PUBLIC_SITE_NAME ? PUBLIC_SITE_NAME : $page['title'].' · '.PUBLIC_SITE_NAME;
    $description = $page['description'] ?? 'Yayasan Wakaf Cendekia Takengon menaungi KB, TPA, TK, SD, dan SMP Islam terpadu di Bebesen, Aceh Tengah.';
    $canonical = $siteUrl.'/'.ltrim($page['path'] ?? '', '/');
    $image = $page['image'] ?? 'img/wisuda-1200.jpg';
    $imageUrl = preg_match('#^https?://#', $image) ? $image : $siteUrl.'/'.ltrim($image, '/');
    $active = $page['active'] ?? '';
    ?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
        // Tema dipasang sebelum halaman digambar agar tidak berkedip.
        (function () {
            var root = document.documentElement, theme = 'light';
            try { theme = localStorage.getItem('cendekia-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); } catch (e) {}
            root.setAttribute('data-theme', theme === 'dark' ? 'dark' : 'light');
            root.classList.add('js', 'is-loading');
        })();
    </script>
    <meta name="theme-color" content="#14563f" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0f1714" media="(prefers-color-scheme: dark)">
    <title><?= h($title) ?></title>
    <meta name="description" content="<?= h($description) ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="<?= h($canonical) ?>">
    <meta property="og:type" content="<?= h($page['og_type'] ?? 'website') ?>">
    <meta property="og:locale" content="id_ID">
    <meta property="og:site_name" content="<?= h(PUBLIC_SITE_NAME) ?>">
    <meta property="og:title" content="<?= h($title) ?>">
    <meta property="og:description" content="<?= h($description) ?>">
    <meta property="og:url" content="<?= h($canonical) ?>">
    <meta property="og:image" content="<?= h($imageUrl) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="icon" href="img/logo-display.png" type="image/png">
    <link rel="apple-touch-icon" href="img/logo-display.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Source+Serif+4:opsz,wght@8..60,600;8..60,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= public_asset('css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= public_asset('style.css') ?>">
    <?php if (!empty($page['schema'])) { foreach ($page['schema'] as $schema) { ?>
        <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    <?php } } ?>
</head>
<body class="page-<?= h($active ?: 'umum') ?>">
<div class="page-loader" role="status" aria-live="polite" data-page-loader>
    <span class="page-loader__ring" aria-hidden="true"><img src="img/logo-display.png" alt="" width="40" height="40"></span>
    <span class="visually-hidden">Memuat halaman…</span>
</div>
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

        <nav class="primary-navigation" id="navigasi-utama" aria-label="Navigasi utama" data-navigation>
            <ul class="nav-list">
                <?php foreach (public_navigation() as $item) {
                    $isActive = $active === $item['key']; ?>
                    <li class="nav-item<?= !empty($item['children']) ? ' has-children' : '' ?>">
                        <a class="nav-link<?= $isActive ? ' is-current' : '' ?>" href="<?= h($item['href']) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><?= h($item['label']) ?><?php if (!empty($item['children'])) { echo public_icon('chevron'); } ?></a>
                        <?php if (!empty($item['children'])) { ?>
                            <ul class="subnav">
                                <?php foreach ($item['children'] as $child) { ?>
                                    <li><a href="<?= h($child['href']) ?>"><?= h($child['label']) ?></a></li>
                                <?php } ?>
                            </ul>
                        <?php } ?>
                    </li>
                <?php } ?>
            </ul>
            <a class="button button--primary nav-apply" href="daftar.php">Daftar PPDB</a>
        </nav>

        <div class="header-tools">
            <button class="theme-toggle" type="button" data-theme-toggle aria-label="Ganti ke mode gelap" title="Ganti tema">
                <?= public_icon('moon') ?><?= public_icon('sun') ?>
            </button>
            <button class="menu-toggle" type="button" aria-label="Buka navigasi" aria-expanded="false" aria-controls="navigasi-utama" data-menu-toggle>
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>
</header>

<main id="konten">
    <?php
}

function public_page_end(mysqli $conn): void
{
    $units = cms_units($conn);
    $phone = cms_setting($conn, 'contact_phone', '082181649543');
    $email = cms_setting($conn, 'contact_email', 'wakafcendekiatakengon@gmail.com');
    $address = cms_setting($conn, 'contact_address', 'Jalan Pertamina–Kebet, Kampung Lemah Burbana, Bebesen, Aceh Tengah 24552');
    $socials = array_filter([
        'Instagram' => cms_setting($conn, 'social_instagram'),
        'Facebook' => cms_setting($conn, 'social_facebook'),
        'YouTube' => cms_setting($conn, 'social_youtube'),
        'TikTok' => cms_setting($conn, 'social_tiktok'),
    ], static fn ($url) => preg_match('#^https://#', $url));
    $aiReady = cms_ai_available($conn);
    ?>
</main>

<footer class="site-footer">
    <div class="shell footer-grid">
        <div class="footer-identity">
            <a class="footer-brand" href="index.php">
                <img src="img/logo-display.png" alt="" width="44" height="44" loading="lazy">
                <span>Yayasan Wakaf Cendekia Takengon</span>
            </a>
            <p><?= h(cms_setting($conn, 'site_tagline', 'Shaleh, cerdas, dan terampil.')) ?></p>
            <ul class="footer-contact">
                <li><?= public_icon('pin') ?><span><?= h($address) ?></span></li>
                <li><?= public_icon('phone') ?><a href="https://wa.me/<?= h(ppdb_normalize_whatsapp_number($phone) ?: '6282181649543') ?>" rel="noopener"><?= h($phone) ?></a></li>
                <li><?= public_icon('mail') ?><a href="mailto:<?= h($email) ?>"><?= h($email) ?></a></li>
            </ul>
        </div>

        <div>
            <h2 class="footer-heading">Unit pendidikan</h2>
            <ul class="footer-list">
                <?php foreach ($units as $unit) { ?>
                    <li><a href="unit.php?kode=<?= rawurlencode($unit['kode_unit']) ?>"><?= h(ppdb_unit_display_name($unit['nama_unit'])) ?></a></li>
                <?php } ?>
            </ul>
        </div>

        <div>
            <h2 class="footer-heading">Jelajahi</h2>
            <ul class="footer-list">
                <li><a href="tentang.php">Tentang yayasan</a></li>
                <li><a href="kurikulum.php">Kurikulum JSIT</a></li>
                <li><a href="berita.php">Berita</a></li>
                <li><a href="ppdb.php">PPDB <?= h(PUBLIC_PPDB_YEAR) ?></a></li>
                <li><a href="kontak.php">Kontak</a></li>
            </ul>
            <?php if ($socials) { ?>
                <h2 class="footer-heading footer-heading--spaced">Media sosial</h2>
                <ul class="footer-list">
                    <?php foreach ($socials as $label => $url) { ?><li><a href="<?= h($url) ?>" rel="noopener" target="_blank"><?= h($label) ?></a></li><?php } ?>
                </ul>
            <?php } ?>
        </div>
    </div>
    <p class="shell footer-legal">&copy; <?= date('Y') ?> <?= h(PUBLIC_SITE_NAME) ?></p>
</footer>

<?php if ($aiReady) { ?>
    <div class="assistant" data-assistant>
        <button class="assistant__launcher" type="button" aria-expanded="false" aria-controls="assistant-panel" data-assistant-toggle>
            <?= public_icon('chat') ?><span>Tanya asisten</span>
        </button>
        <section class="assistant__panel" id="assistant-panel" aria-labelledby="assistant-title" hidden data-assistant-panel>
            <header class="assistant__head">
                <div>
                    <h2 id="assistant-title">Asisten Cendekia</h2>
                    <p>Menjawab dari informasi resmi di situs ini.</p>
                </div>
                <button class="assistant__close" type="button" aria-label="Tutup asisten" data-assistant-close><?= public_icon('close') ?></button>
            </header>
            <div class="assistant__log" data-assistant-log aria-live="polite">
                <div class="assistant__msg assistant__msg--bot"><p>Assalamu’alaikum. Silakan bertanya tentang unit pendidikan, kurikulum, atau pendaftaran PPDB.</p></div>
                <div class="assistant__suggest" data-assistant-suggest>
                    <button type="button">Unit apa saja yang menerima pendaftar?</button>
                    <button type="button">Dokumen apa yang perlu disiapkan?</button>
                    <button type="button">Bagaimana alur pendaftaran PPDB?</button>
                </div>
            </div>
            <form class="assistant__form" data-assistant-form>
                <label class="visually-hidden" for="assistant-input">Tulis pertanyaan</label>
                <textarea id="assistant-input" name="question" rows="1" maxlength="800" placeholder="Tulis pertanyaan…" required></textarea>
                <button class="assistant__send" type="submit" aria-label="Kirim pertanyaan"><?= public_icon('send') ?></button>
            </form>
            <p class="assistant__note">Jawaban dibuat AI dan bisa keliru. Untuk kepastian, hubungi panitia.</p>
        </section>
    </div>
<?php } ?>

<script src="<?= public_asset('js/site.js') ?>" defer></script>
</body>
</html>
    <?php
}
