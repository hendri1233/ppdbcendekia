<?php
require_once 'app_helpers.php';
require_admin();
require_once __DIR__ . '/admin-partials.php';

$adminName = $_SESSION['admin_name'] ?? 'Administrator';
$activeAdminPage = $activeAdminPage ?? '';
$adminPageTitle = $adminPageTitle ?? 'Admin PPDB';
$adminPageDescription = $adminPageDescription ?? 'Panel pengelolaan penerimaan peserta didik baru Yayasan Wakaf Cendekia Takengon.';
$navigation = ppdb_admin_navigation($activeAdminPage);
$navigationGroups = [];
foreach ($navigation as $item) {
    $navigationGroups[$item['group']][] = $item;
}
$adminRoleLabel = ppdb_is_super_admin() ? 'Super Admin' : 'Admin Unit · '.($_SESSION['admin_unit_name'] ?? 'Unit');
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
            try { if (localStorage.getItem('cendekia-sidebar') === 'collapsed') root.classList.add('sidebar-collapsed'); } catch (e) {}
        })();
    </script>
    <meta name="theme-color" content="#0d3227">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="<?php echo h($adminPageDescription); ?>">
    <title><?php echo h($adminPageTitle); ?> · Panel Admin PPDB Cendekia Takengon</title>
    <link rel="icon" href="img/logo-display.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/tokens.css?v=<?php echo (int) filemtime(__DIR__.'/css/tokens.css'); ?>">
    <link rel="stylesheet" href="css/admin.css?v=<?php echo (int) filemtime(__DIR__.'/css/admin.css'); ?>">
    <link rel="stylesheet" href="css/cms-admin.css?v=<?php echo (int) filemtime(__DIR__.'/css/cms-admin.css'); ?>">
</head>
<body>
<div class="page-loader" role="status" aria-live="polite" data-page-loader>
    <span class="page-loader__ring" aria-hidden="true"><img src="img/logo-display.png" alt="" width="40" height="40"></span>
    <span class="visually-hidden">Memuat halaman…</span>
</div>
<a class="skip-link" href="#admin-utama">Lompat ke konten</a>

<div class="admin-shell">
    <aside class="admin-sidebar" aria-label="Sidebar dashboard">
        <a class="admin-brand" href="admin.php">
            <span class="admin-brand-logo">
                <img src="img/logo-display.png" alt="" width="36" height="36">
            </span>
            <span class="admin-brand-copy">
                <strong>Wakaf Cendekia</strong>
                <small>Panel PPDB</small>
            </span>
        </a>

        <nav class="admin-nav" aria-label="Navigasi utama dashboard">
            <?php foreach ($navigationGroups as $groupLabel => $items) { ?>
                <p class="admin-nav__group"><?php echo h($groupLabel); ?></p>
                <?php foreach ($items as $item) { ?>
                    <a href="<?php echo h($item['href']); ?>" title="<?php echo strip_tags($item['label']); ?>"<?php echo $activeAdminPage === $item['key'] ? ' aria-current="page"' : ''; ?>>
                        <?php echo ppdb_admin_icon($item['icon']); ?>
                        <span><?php echo $item['label']; ?></span>
                    </a>
                <?php } ?>
            <?php } ?>
        </nav>

        <div class="sidebar-bottom">
            <a class="sidebar-link" href="index.php" target="_blank" rel="noopener" title="Buka situs PPDB">
                <?php echo ppdb_admin_icon('external'); ?>
                <span>Buka situs PPDB<span class="visually-hidden"> (tab baru)</span></span>
            </a>
            <form action="keluar.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <button class="sidebar-link" type="submit" title="Keluar">
                    <?php echo ppdb_admin_icon('logout'); ?>
                    <span>Keluar</span>
                </button>
            </form>
        </div>
    </aside>

    <div class="admin-frame">
        <header class="admin-topbar">
            <button class="topbar-menu" type="button" aria-label="Buka navigasi" aria-expanded="false" aria-controls="admin-mobile-nav" data-menu-toggle>
                <span></span><span></span><span></span>
            </button>

            <button class="sidebar-collapse" type="button" data-sidebar-toggle aria-expanded="true" aria-label="Ciutkan menu" title="Ciutkan menu">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>
        </button>
            <nav class="topbar-context" aria-label="Breadcrumb">
                <ol class="breadcrumb">
                    <li><a href="admin.php">Panel PPDB</a></li>
                    <?php if ($activeAdminPage !== 'dashboard') { ?>
                        <li aria-current="page"><?php echo h($adminPageTitle); ?></li>
                    <?php } else { ?>
                        <li aria-current="page">Dashboard</li>
                    <?php } ?>
                </ol>
            </nav>

            <button class="theme-toggle" type="button" data-theme-toggle aria-label="Ganti ke mode gelap" title="Ganti tema">
                <?php echo ppdb_admin_icon('moon'); ?><?php echo ppdb_admin_icon('sun'); ?>
            </button>

            <div class="admin-user">
                <span class="admin-user-text">
                    <strong><?php echo h($adminName); ?></strong>
                    <small><?php echo h($adminRoleLabel); ?></small>
                </span>
                <span class="user-initial" aria-hidden="true"><?php echo h(strtoupper(substr($adminName, 0, 1))); ?></span>
            </div>
        </header>

        <nav class="admin-mobile-nav" id="admin-mobile-nav" aria-label="Navigasi dashboard ponsel" data-mobile-nav>
            <?php foreach ($navigationGroups as $groupLabel => $items) { ?>
                <p class="admin-nav__group"><?php echo h($groupLabel); ?></p>
                <?php foreach ($items as $item) { ?>
                    <a href="<?php echo h($item['href']); ?>"<?php echo $activeAdminPage === $item['key'] ? ' aria-current="page"' : ''; ?>>
                        <?php echo ppdb_admin_icon($item['icon']); ?>
                        <span><?php echo $item['label']; ?></span>
                    </a>
                <?php } ?>
            <?php } ?>
            <a href="index.php" target="_blank" rel="noopener"><?php echo ppdb_admin_icon('external'); ?><span>Buka situs PPDB</span></a>
            <form action="keluar.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <button type="submit"><?php echo ppdb_admin_icon('logout'); ?><span>Keluar</span></button>
            </form>
        </nav>

        <main class="admin-main" id="admin-utama">
