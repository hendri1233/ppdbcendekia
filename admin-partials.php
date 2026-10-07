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
    ];

    $path = $paths[$name] ?? $paths['dashboard'];

    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
}

/** Daftar navigasi admin, dipakai bersama oleh sidebar dan navigasi ponsel. */
function ppdb_admin_navigation(string $activePage): array
{
    return [
        ['key' => 'dashboard', 'href' => 'admin.php', 'label' => 'Dashboard', 'icon' => 'dashboard', 'caption' => 'Ringkasan'],
        ['key' => 'registrations', 'href' => 'daftar_peserta.php', 'label' => 'Data peserta', 'icon' => 'registrations', 'caption' => 'Antrean &amp; arsip'],
        ['key' => 'accounts', 'href' => 'register.php', 'label' => 'Administrator', 'icon' => 'users', 'caption' => 'Akses pengelola'],
        ['key' => 'quota', 'href' => 'pengaturan-ppdb.php', 'label' => 'Kuota &amp; pembayaran', 'icon' => 'quota', 'caption' => 'Pengaturan penerimaan'],
        ['key' => 'materials', 'href' => 'ppdb-materi.php', 'label' => 'Materi &amp; kuisioner', 'icon' => 'file', 'caption' => 'Brosur, SOP &amp; form'],
    ];
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
