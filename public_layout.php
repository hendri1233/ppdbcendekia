<?php

/**
 * Kerangka halaman publik situs yayasan: <head>, header bernavigasi,
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
        'school' => '<path d="M2.5 8 10 4l7.5 4L10 12 2.5 8Z"/><path d="M5.5 9.6v3.6c1.3 1.2 2.8 1.8 4.5 1.8s3.2-.6 4.5-1.8V9.6M17.5 8v4.5"/>',
        'scale' => '<path d="M10 3v14M6 17h8M4 6h12M4 6l-2 5a2.5 2.5 0 0 0 4 0L4 6Zm12 0-2 5a2.5 2.5 0 0 0 4 0l-2-5Z"/>',
        'heart' => '<path d="M10 16.5s-6.5-3.8-6.5-8.3A3.5 3.5 0 0 1 10 6.3a3.5 3.5 0 0 1 6.5 1.9c0 4.5-6.5 8.3-6.5 8.3Z"/>',
        'instagram' => '<rect x="3" y="3" width="14" height="14" rx="4"/><circle cx="10" cy="10" r="3.2"/><path d="M14 6h.01"/>',
        'facebook' => '<path d="M11.5 17.5v-6.5h2.3l.4-2.7h-2.7V6.7c0-.8.3-1.3 1.4-1.3h1.4V3a18 18 0 0 0-2.1-.1c-2 0-3.4 1.2-3.4 3.5v1.9H6.5V11h2.3v6.5"/>',
        'youtube' => '<rect x="2.5" y="5" width="15" height="10" rx="3"/><path d="m8.5 8 3.5 2-3.5 2V8Z"/>',
        'tiktok' => '<path d="M11 3v9.5a2.8 2.8 0 1 1-2.8-2.8M11 3c.4 2 1.9 3.5 4 3.8"/>',
    ];
    return '<svg class="icon icon--'.$name.'" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.($paths[$name] ?? '').'</svg>';
}

function public_navigation(mysqli $conn): array
{
    $unitChildren = [
        ['href' => './#unit-title', 'label' => 'Semua unit pendidikan'],
        ['href' => 'unit', 'label' => 'Direktori unit'],
    ];
    foreach (cms_units($conn) as $unit) {
        $unitChildren[] = [
            'href' => ppdb_unit_public_path($unit),
            'label' => ppdb_unit_display_name((string) $unit['nama_unit']),
        ];
    }

    return [
        ['key' => 'beranda', 'href' => './', 'label' => 'Beranda'],
        ['key' => 'unit', 'href' => './#unit-title', 'label' => 'Unit Pendidikan', 'children' => $unitChildren],
        ['key' => 'kurikulum', 'href' => 'kurikulum', 'label' => 'Kurikulum', 'children' => [
            ['href' => 'kurikulum#penggerak', 'label' => 'Program Penggerak'],
            ['href' => 'kurikulum#k13', 'label' => 'Kurikulum 2013'],
            ['href' => 'kurikulum#merdeka', 'label' => 'Merdeka Belajar'],
            ['href' => 'kurikulum#deep_learning', 'label' => 'Deep Learning'],
        ]],
        ['key' => 'inspirasi', 'href' => 'program', 'label' => 'Program', 'children' => [
            ['href' => 'program', 'label' => 'Program yayasan'],
            ['href' => 'galeri', 'label' => 'Galeri kegiatan'],
            ['href' => 'cerita#prestasi', 'label' => 'Prestasi'],
            ['href' => 'cerita#testimoni', 'label' => 'Testimoni'],
            ['href' => 'cerita#alumni', 'label' => 'Cerita alumni'],
        ]],
        ['key' => 'sosial', 'href' => 'sosial', 'label' => 'Sosial', 'children' => [
            ['href' => 'sosial#lembaga-wakaf', 'label' => 'Lembaga Wakaf Insan Cendekia Takengon'],
            ['href' => 'sosial#program-wakaf', 'label' => 'Program wakaf'],
            ['href' => 'sosial#kegiatan-wakaf', 'label' => 'Kegiatan wakaf'],
            ['href' => 'sosial#legalitas', 'label' => 'Legalitas'],
            ['href' => 'sosial#media-sosial', 'label' => 'Media sosial'],
            ['href' => 'sosial#kontak-lembaga', 'label' => 'Kontak lembaga'],
        ]],
        ['key' => 'usaha', 'href' => 'usaha', 'label' => 'Usaha', 'children' => [
            ['href' => 'usaha#koperasi-kenara', 'label' => 'Koperasi Kenara Cendekia Takengon'],
            ['href' => 'usaha#kegiatan-koperasi', 'label' => 'Kegiatan koperasi'],
            ['href' => 'usaha#legalitas', 'label' => 'Legalitas'],
        ]],
        ['key' => 'berita', 'href' => 'berita', 'label' => 'Berita'],
        ['key' => 'tentang', 'href' => 'tentang', 'label' => 'Tentang', 'children' => [
            ['href' => 'tentang#prakata', 'label' => 'Prakata pimpinan'],
            ['href' => 'tentang#manajemen', 'label' => 'Profil manajemen'],
            ['href' => 'tentang#visi-misi', 'label' => 'Visi dan misi'],
            ['href' => 'tentang#fasilitas', 'label' => 'Fasilitas'],
        ]],
        ['key' => 'kontak', 'href' => 'kontak', 'label' => 'Kontak'],
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
    $title = !empty($page['title_is_complete'])
        ? $page['title']
        : ($page['title'] === PUBLIC_SITE_NAME ? PUBLIC_SITE_NAME : $page['title'].' · '.PUBLIC_SITE_NAME);
    $description = $page['description'] ?? 'Yayasan Wakaf Cendekia Takengon menaungi KB, TPA, TK, SD, dan SMP Islam terpadu di Bebesen, Aceh Tengah.';
    $canonical = $siteUrl.'/'.ltrim($page['path'] ?? '', '/');
    $image = $page['image'] ?? 'img/og-cendekia.jpg';
    $imageUrl = preg_match('#^https?://#', $image) ? $image : $siteUrl.'/'.ltrim($image, '/');
    $requestPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: './');
    $active = $page['active'] ?? '';
    ?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <base href="<?= h(rtrim($siteUrl, '/').'/') ?>">
    <script>
        // Tema dipasang sebelum halaman digambar agar tidak berkedip.
        (function () {
            var root = document.documentElement, theme = 'light';
            // Bawaan terang; mode gelap hanya bila pengunjung pernah memilihnya.
            try { theme = localStorage.getItem('cendekia-theme') || 'light'; } catch (e) {}
            root.setAttribute('data-theme', theme === 'dark' ? 'dark' : 'light');
            root.classList.add('js');
        })();
    </script>
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
    <?php if ($image === 'img/og-cendekia.jpg') { ?>
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <?php } ?>
    <meta property="og:image:alt" content="<?= h(PUBLIC_SITE_NAME) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300;0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= public_asset('css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= public_asset('style.css') ?>">
    <?php if (!empty($page['schema'])) { foreach ($page['schema'] as $schema) { ?>
        <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    <?php } } ?>
    <meta name="theme-color" content="#ffffff">
    <link rel="icon" type="image/x-icon" href="img/favicons/favicon.ico" sizes="16x16 32x32 48x48">
    <link rel="icon" type="image/png" sizes="48x48" href="img/favicons/favicon-48x48.png">
    <link rel="icon" type="image/png" sizes="16x16" href="img/favicons/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicons/favicon-32x32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="img/favicons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="img/favicons/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="img/favicons/android-chrome-512x512.png">
</head>
<body class="page-<?= h($active ?: 'umum') ?>">
<?php if (defined('PPDB_MAINTENANCE_PREVIEW')) { ?>
<div role="status" style="padding:8px 16px;color:#5a3500;background:#fde7c4;font-size:14px;font-weight:600;text-align:center">Mode maintenance aktif. Pengunjung melihat pesan maintenance; halaman ini hanya terlihat oleh admin.</div>
<?php } ?>
<a class="skip-link" href="<?= h($requestPath) ?>#konten">Lompat ke konten utama</a>

<header class="site-header">
    <div class="shell header-inner">
        <a class="brand" href="./">
            <img class="brand-logo" src="img/logo-display.png" alt="" width="44" height="44">
            <span class="brand-copy">
                <strong><span>Yayasan Wakaf</span> <span>Cendekia Takengon</span></strong>
                <small>Aceh Tengah</small>
            </span>
        </a>

        <nav class="primary-navigation" id="navigasi-utama" aria-label="Navigasi utama" data-navigation>
            <ul class="nav-list">
                <?php foreach (public_navigation($conn) as $item) {
                    $isActive = $active === $item['key']; ?>
                    <li class="nav-item nav-item--<?= h($item['key']) ?><?= !empty($item['children']) ? ' has-children' : '' ?>">
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
            <div class="nav-apply-menu" data-apply-menu>
                <button class="button button--primary nav-apply" type="button" aria-expanded="false" aria-controls="menu-spmb" data-apply-toggle>SPMB<?= public_icon('chevron') ?></button>
                <ul class="subnav subnav--apply" id="menu-spmb">
                    <li><a href="spmb#unit">Informasi SPMB</a></li>
                    <li><a href="daftar">Daftar</a></li>
                </ul>
            </div>
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
    $phone = ppdb_admin_whatsapp_display();
    $email = cms_setting($conn, 'contact_email', 'wakafcendekiatakengon@gmail.com');
    $address = cms_setting($conn, 'contact_address', 'Jalan Pertamina–Kebet, Kampung Lemah Burbana, Bebesen, Aceh Tengah 24552');
    $socials = [
        'Instagram' => 'https://www.instagram.com/cendekia_tkn?vrfl=MzhqbWgyeHN5bjV2',
        'Facebook' => 'https://www.facebook.com/share/1CPiWGAMH6/',
        'YouTube' => 'https://youtube.com/@wakafcendekiatakengon',
        'TikTok' => 'https://vm.tiktok.com/ZS9yDTdj4jU5a-Jsya9/',
    ];
    $aiReady = cms_ai_available($conn);
    ?>
</main>

<footer class="site-footer">
    <div class="shell footer-grid">
        <div class="footer-identity">
            <a class="footer-brand" href="./">
                <img src="img/logo-display.png" alt="" width="44" height="44" loading="lazy">
                <span>Yayasan Wakaf Cendekia Takengon</span>
            </a>
            <p><?= h(cms_setting($conn, 'site_tagline', 'Shaleh, cerdas, dan terampil.')) ?></p>
            <ul class="footer-contact">
                <li><?= public_icon('pin') ?><span><?= h($address) ?></span></li>
                <li><?= public_icon('phone') ?><a href="<?= h(ppdb_admin_whatsapp_url()) ?>" rel="noopener"><?= h($phone) ?></a></li>
                <li><?= public_icon('mail') ?><a href="mailto:<?= h($email) ?>"><?= h($email) ?></a></li>
            </ul>
        </div>

        <div>
            <h2 class="footer-heading">Unit pendidikan</h2>
            <ul class="footer-list footer-list--units">
                <?php foreach ($units as $unit) { ?>
                    <li><a href="<?= h(ppdb_unit_public_path($unit)) ?>"><?= h(ppdb_unit_display_name($unit['nama_unit'])) ?></a></li>
                <?php } ?>
            </ul>
        </div>

        <div>
            <h2 class="footer-heading">Jelajahi</h2>
            <ul class="footer-list">
                <li><a href="tentang">Tentang yayasan</a></li>
                <li><a href="program">Program yayasan</a></li>
                <li><a href="galeri">Galeri kegiatan</a></li>
                <li><a href="cerita">Prestasi &amp; cerita</a></li>
                <li><a href="unit">Direktori unit</a></li>
                <li><a href="kurikulum">Kurikulum</a></li>
                <li><a href="berita">Berita</a></li>
                <li><a href="spmb">SPMB <?= h(PUBLIC_PPDB_YEAR) ?></a></li>
                <li><a href="kontak">Kontak</a></li>
            </ul>
        </div>

        <div>
            <div class="footer-ppdb">
                <h2 class="footer-heading">SPMB <?= h(PUBLIC_PPDB_YEAR) ?></h2>
                <p class="footer-note">Pendaftaran dibuka per unit. Registrasi awal hanya butuh beberapa menit.</p>
                <a class="button button--light button--sm footer-cta" href="daftar">Daftar sekarang</a>
            </div>
            <?php if ($socials) { ?>
                <h2 class="footer-heading footer-heading--spaced">Ikuti kami</h2>
                <ul class="footer-social">
                    <?php foreach ($socials as $label => $url) { ?><li><a href="<?= h($url) ?>" rel="noopener" target="_blank" title="<?= h($label) ?>"><?= public_icon(strtolower($label)) ?><span class="visually-hidden"><?= h($label) ?></span></a></li><?php } ?>
                </ul>
            <?php } ?>
        </div>
    </div>
    <div class="shell footer-legal">
        <p>&copy; <?= date('Y') ?> <?= h(PUBLIC_SITE_NAME) ?>. Seluruh hak dilindungi.</p>
        <p><a href="kebijakan-privasi">Kebijakan Privasi</a> &middot; Takengon, Aceh Tengah</p>
    </div>
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
                <div class="assistant__msg assistant__msg--bot"><p>Assalamu’alaikum. Silakan bertanya tentang unit pendidikan, kurikulum, atau pendaftaran SPMB.</p></div>
                <div class="assistant__suggest" data-assistant-suggest>
                    <button type="button">Unit apa saja yang menerima pendaftar?</button>
                    <button type="button">Dokumen apa yang perlu disiapkan?</button>
                    <button type="button">Bagaimana alur pendaftaran SPMB?</button>
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
