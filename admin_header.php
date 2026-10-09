<?php
require_once 'app_helpers.php';
require_admin();
require_once __DIR__ . '/admin-partials.php';

$adminName = $_SESSION['admin_name'] ?? 'Administrator';
$activeAdminPage = $activeAdminPage ?? '';
$adminPageTitle = $adminPageTitle ?? 'Admin PPDB';
$adminPageDescription = $adminPageDescription ?? 'Panel pengelolaan penerimaan peserta didik baru Yayasan Wakaf Cendekia Takengon.';
$navigation = ppdb_admin_navigation($activeAdminPage);
$adminRoleLabel = ppdb_is_super_admin() ? 'Super Admin' : 'Admin Unit · '.($_SESSION['admin_unit_name'] ?? 'Unit');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f3a2e">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="<?php echo h($adminPageDescription); ?>">
    <title><?php echo h($adminPageTitle); ?> · Panel Admin PPDB Cendekia Takengon</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin.css">
</head>
<body>
<a class="skip-link" href="#admin-utama">Lompat ke konten</a>

<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="admin-brand" href="admin.php">
            <span class="admin-brand-logo">
                <img src="img/logo-display.png" alt="" width="40" height="39">
            </span>
            <span class="admin-brand-copy">
                <strong>Wakaf Cendekia</strong>
                <small>Panel PPDB · Takengon</small>
            </span>
        </a>

        <nav class="admin-nav" aria-label="Navigasi utama dashboard">
            <?php foreach ($navigation as $item) { ?>
                <a href="<?php echo h($item['href']); ?>"<?php echo $activeAdminPage === $item['key'] ? ' aria-current="page"' : ''; ?>>
                    <span class="nav-icon"><?php echo ppdb_admin_icon($item['icon']); ?></span>
                    <span class="nav-text">
                        <strong><?php echo $item['label']; ?></strong>
                        <small><?php echo $item['caption']; ?></small>
                    </span>
                </a>
            <?php } ?>
        </nav>

        <div class="sidebar-bottom">
            <a class="sidebar-link" href="index.php" target="_blank" rel="noopener">
                <?php echo ppdb_admin_icon('external'); ?>
                <span>Buka situs PPDB</span>
            </a>
            <form action="keluar.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <button class="sidebar-link" type="submit">
                    <?php echo ppdb_admin_icon('logout'); ?>
                    <span>Keluar dari dashboard</span>
                </button>
            </form>
            <p class="sidebar-meta">Yayasan Wakaf Cendekia Takengon<br>Panel Vertebral v1.0</p>
        </div>
    </aside>

    <div class="admin-frame">
        <header class="admin-topbar">
            <button class="topbar-menu" type="button" aria-label="Buka navigasi" aria-expanded="false" aria-controls="admin-mobile-nav" data-menu-toggle>
                <span></span><span></span><span></span>
            </button>

            <div class="topbar-context">
                <span class="topbar-crumb">Dashboard PPDB</span>
                <strong class="topbar-title"><?php echo h($adminPageTitle); ?></strong>
            </div>

            <div class="admin-user">
                <span class="admin-user-text">
                    <small><?php echo h($adminRoleLabel); ?></small>
                    <strong><?php echo h($adminName); ?></strong>
                </span>
                <span class="user-initial" aria-hidden="true"><?php echo h(strtoupper(substr($adminName, 0, 1))); ?></span>
            </div>
        </header>

        <nav class="admin-mobile-nav" id="admin-mobile-nav" aria-label="Navigasi dashboard ponsel" data-mobile-nav>
            <?php foreach ($navigation as $item) { ?>
                <a href="<?php echo h($item['href']); ?>"<?php echo $activeAdminPage === $item['key'] ? ' aria-current="page"' : ''; ?>>
                    <?php echo ppdb_admin_icon($item['icon']); ?>
                    <span><?php echo $item['label']; ?></span>
                </a>
            <?php } ?>
        </nav>

        <main class="admin-main" id="admin-utama">
