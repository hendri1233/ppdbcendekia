<?php

/**
 * Komponen bersama untuk halaman admin: meta SEO, navigasi, dan ikon.
 * Dipisah dari admin_header.php agar tidak menduplikasi markup di tiap halaman.
 */

/** Ikon garis 24x24, dibuat manual agar tidak bergantung pada pustaka ikon. */
function ppdb_admin_icon(string $name): string
{
    $paths = [
        'dashboard' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9.5 21v-6h5v6"/>',
        'registrations' => '<rect x="3.5" y="4.5" width="17" height="16" rx="1.5"/><path d="M7.5 9h9M7.5 13h9M7.5 17h5"/>',
        'users' => '<circle cx="9" cy="8.5" r="3.2"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 5.6a3.2 3.2 0 0 1 0 5.8"/><path d="M17.5 14.2A6 6 0 0 1 21 20"/>',
        'group' => '<circle cx="9" cy="8" r="3"/><path d="M3 19a6 6 0 0 1 12 0"/><circle cx="17" cy="9" r="2.3"/><path d="M17 14a5 5 0 0 1 4 4.8"/>',
        'quota' => '<path d="M4 7h10M4 12h7M4 17h4"/><circle cx="17" cy="7" r="2.2"/><circle cx="15" cy="12" r="2.2"/><circle cx="13" cy="17" r="2.2"/>',
        'admin' => '<path d="M12 3.2 4.5 6.4v5.2c0 4.3 3.1 8.2 7.5 9.2 4.4-1 7.5-4.9 7.5-9.2V6.4Z"/><path d="M9.2 12.2l2 2 3.6-3.8"/>',
        'logout' => '<path d="M14 4.5H6.5A1.5 1.5 0 0 0 5 6v12a1.5 1.5 0 0 0 1.5 1.5H14"/><path d="M17 8.5l3.5 3.5L17 15.5"/><path d="M20 12h-9"/>',
        'external' => '<path d="M14 4.5h5.5V10"/><path d="M19 5 11.5 12.5"/><path d="M18.5 14v4.5a1.5 1.5 0 0 1-1.5 1.5H6a1.5 1.5 0 0 1-1.5-1.5V7A1.5 1.5 0 0 1 6 5.5h4.5"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6"/><path d="M15 15l4.5 4.5"/>',
        'inbox' => '<path d="M3.5 13.5 6 5.5h12l2.5 8v5a1.5 1.5 0 0 1-1.5 1.5H5a1.5 1.5 0 0 1-1.5-1.5Z"/><path d="M3.5 13.5H9a3 3 0 0 0 6 0h5.5"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7v5.2l3.4 2"/>',
        'check' => '<path d="M12 3.2 4.5 6.4v5.2c0 4.3 3.1 8.2 7.5 9.2 4.4-1 7.5-4.9 7.5-9.2V6.4Z"/><path d="M9.2 12.2l2 2 3.6-3.8"/>',
        'wallet' => '<path d="M3.5 7.5A2 2 0 0 1 5.5 5.5h11a2 2 0 0 1 2 2v1"/><rect x="3.5" y="7.5" width="17" height="11.5" rx="1.8"/><circle cx="16" cy="13.2" r="1.3"/>',
        'file' => '<path d="M6 3.5h7.5L18 8v12.5H6Z"/><path d="M13.5 3.5V8H18"/>',
        'chart' => '<path d="M4 20V9.5M9.3 20V4.5M14.7 20v-8M20 20v-5.5"/>',
        'chevron' => '<path d="m9 6 6 6-6 6"/>',
        'image' => '<rect x="3.5" y="4.5" width="17" height="15" rx="1.5"/><circle cx="9" cy="9.5" r="1.8"/><path d="m4 17.5 5-5 4 4 2.5-2.5 4.5 4.5"/>',
        'page' => '<path d="M6 3.5h12v17H6Z"/><path d="M9 8h6M9 11.5h6M9 15h4"/>',
        'news' => '<path d="M4.5 5.5h12v13a1.5 1.5 0 0 1-1.5 1.5H6a1.5 1.5 0 0 1-1.5-1.5Z"/><path d="M16.5 9h3v9.5a1.5 1.5 0 0 1-3 0"/><path d="M7.5 9h6M7.5 12.5h6M7.5 16h4"/>',
        'building' => '<path d="M4 20.5h16"/><path d="M6 20.5V8l6-4 6 4v12.5"/><path d="M10 20.5v-5h4v5"/><path d="M9.5 10.5h1M13.5 10.5h1"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 3.5v2.2M12 18.3v2.2M3.5 12h2.2M18.3 12h2.2M6 6l1.6 1.6M16.4 16.4 18 18M6 18l1.6-1.6M16.4 7.6 18 6"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>',
        'moon' => '<path d="M19.5 14.5A7.8 7.8 0 0 1 9.5 4.5a7.8 7.8 0 1 0 10 10Z"/>',
    ];

    $path = $paths[$name] ?? $paths['dashboard'];

    return '<svg class="ico ico--'.$name.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
}

/** Daftar navigasi admin per kelompok fungsi, dipakai bersama oleh sidebar dan navigasi ponsel. */
function ppdb_admin_navigation(string $activePage): array
{
    $navigation = [
        ['key' => 'dashboard', 'href' => 'admin.php', 'label' => 'Dashboard', 'icon' => 'dashboard', 'group' => 'Penerimaan'],
        ['key' => 'registrations', 'href' => 'daftar_peserta.php', 'label' => 'Data peserta', 'icon' => 'registrations', 'group' => 'Penerimaan'],
        ['key' => 'quota', 'href' => 'pengaturan-ppdb.php', 'label' => 'Kuota &amp; pembayaran', 'icon' => 'quota', 'group' => 'Pengaturan'],
        ['key' => 'materials', 'href' => 'ppdb-materi.php', 'label' => 'Materi &amp; kuisioner', 'icon' => 'file', 'group' => 'Pengaturan'],
        ['key' => 'groups', 'href' => 'grup-whatsapp.php', 'label' => 'Grup WhatsApp', 'icon' => 'group', 'group' => 'Pengaturan'],
        ['key' => 'accounts', 'href' => 'register.php', 'label' => 'Administrator', 'icon' => 'users', 'group' => 'Pengaturan'],
        ['key' => 'cms-home', 'href' => 'cms-beranda.php', 'label' => 'Beranda &amp; slider', 'icon' => 'image', 'group' => 'Situs web'],
        ['key' => 'cms-pages', 'href' => 'cms-halaman.php', 'label' => 'Tentang &amp; kurikulum', 'icon' => 'page', 'group' => 'Situs web'],
        ['key' => 'cms-news', 'href' => 'cms-berita.php', 'label' => 'Berita', 'icon' => 'news', 'group' => 'Situs web'],
        ['key' => 'cms-units', 'href' => 'cms-unit.php', 'label' => 'Profil unit', 'icon' => 'building', 'group' => 'Situs web'],
        ['key' => 'cms-settings', 'href' => 'cms-pengaturan.php', 'label' => 'Kontak &amp; pengaturan', 'icon' => 'settings', 'group' => 'Situs web'],
    ];

    return ppdb_is_super_admin()
        ? $navigation
        : array_values(array_filter(
            $navigation,
            static fn (array $item): bool => in_array($item['key'], ['dashboard', 'registrations', 'groups'], true)
        ));
}

/**
 * Status PPDB dipetakan ke nada warna, label ringkas, dan ikon agar konsisten
 * di seluruh halaman admin.
 */
function ppdb_admin_status_meta(?string $status): array
{
    $map = [
        'menunggu_kontak' => ['label' => 'Menunggu kontak', 'tone' => 'slate', 'icon' => 'clock'],
        'menunggu_pembayaran' => ['label' => 'Menunggu bayar', 'tone' => 'amber', 'icon' => 'wallet'],
        'pembayaran_diperiksa' => ['label' => 'Cek bayar', 'tone' => 'amber', 'icon' => 'clock'],
        'pembayaran_terverifikasi' => ['label' => 'Bayar sah', 'tone' => 'teal', 'icon' => 'check'],
        'menunggu_formulir' => ['label' => 'Menunggu formulir', 'tone' => 'teal', 'icon' => 'inbox'],
        'formulir_terisi' => ['label' => 'Cek formulir', 'tone' => 'blue', 'icon' => 'inbox'],
        'diterima' => ['label' => 'Diterima', 'tone' => 'green', 'icon' => 'check'],
        'daftar_tunggu' => ['label' => 'Daftar tunggu', 'tone' => 'amber', 'icon' => 'clock'],
        'ditolak' => ['label' => 'Perlu tindakan', 'tone' => 'red', 'icon' => 'file'],
        'data_lama' => ['label' => 'Arsip', 'tone' => 'slate', 'icon' => 'file'],
    ];

    return $map[$status] ?? ['label' => str_replace('_', ' ', (string) $status), 'tone' => 'slate', 'icon' => 'file'];
}

/** Menentukan apakah sebuah status menuntut perhatian admin. */
function ppdb_admin_status_is_actionable(?string $status): bool
{
    return in_array($status, ['menunggu_kontak', 'pembayaran_diperiksa', 'pembayaran_terverifikasi', 'formulir_terisi'], true);
}
