<?php

/**
 * Komponen bersama halaman admin "Situs web" (khusus super admin):
 * pesan kilat setelah redirect, penjaga CSRF, editor teks, dan field gambar.
 */

require_once __DIR__.'/app_helpers.php';
require_super_admin();
require_once __DIR__.'/koneksi.php';
require_once __DIR__.'/cms_helpers.php';

function cms_flash(string $type, string $message): void
{
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
}

function cms_render_flash(): void
{
    $flash = $_SESSION['cms_flash'] ?? null;
    unset($_SESSION['cms_flash']);
    if ($flash) {
        $class = $flash['type'] === 'error' ? 'alert-error' : 'alert-success';
        echo '<div class="'.$class.'" role="status">'.h($flash['message']).'</div>';
    }
}

/** Redirect setelah POST agar muat ulang tidak mengirim ulang formulir. */
function cms_redirect(string $url): never
{
    header('Location: '.$url);
    exit;
}

/** Menolak POST tanpa token CSRF yang sah. */
function cms_require_post_token(string $backUrl): void
{
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        cms_flash('error', 'Sesi formulir kedaluwarsa. Muat ulang halaman dan coba kembali.');
        cms_redirect($backUrl);
    }
}

function cms_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'.h(csrf_token()).'">';
}

function cms_post_text(string $key, int $maxLength): string
{
    $value = is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    return mb_substr($value, 0, $maxLength);
}

function cms_valid_url(string $url, bool $httpsOnly = true): bool
{
    if ($url === '') {
        return true;
    }
    $pattern = $httpsOnly ? '#^https://[^\s<>"]+$#i' : '#^(https?://[^\s<>"]+|[a-z0-9-]+\.php(\?[^\s<>"]*)?(\#[\w-]*)?)$#i';
    return (bool) preg_match($pattern, $url);
}

/** Editor teks kaya; isi disanitasi lagi di server sebelum disimpan. */
function cms_rich_editor(string $name, ?string $html, string $label, string $hint = ''): string
{
    static $count = 0;
    $id = 'rich-'.preg_replace('/[^a-z0-9]+/i', '-', $name).'-'.(++$count);
    $buttons = [
        ['bold', 'Tebal', '<strong>B</strong>'],
        ['italic', 'Miring', '<em>I</em>'],
        ['formatBlock', 'Subjudul', 'H2', '<h2>'],
        ['formatBlock', 'Sub-subjudul', 'H3', '<h3>'],
        ['formatBlock', 'Paragraf', '¶', '<p>'],
        ['insertUnorderedList', 'Daftar berpoin', '• Daftar'],
        ['insertOrderedList', 'Daftar bernomor', '1. Daftar'],
        ['formatBlock', 'Kutipan', '“ Kutipan', '<blockquote>'],
        ['createLink', 'Tautan', 'Tautan'],
        ['removeFormat', 'Hapus format', 'Bersihkan'],
    ];
    $toolbar = '';
    foreach ($buttons as $button) {
        $toolbar .= '<button type="button" data-format="'.h($button[0]).'"'.(isset($button[3]) ? ' data-value="'.h($button[3]).'"' : '').' title="'.h($button[1]).'" aria-label="'.h($button[1]).'">'.$button[2].'</button>';
    }
    return '<div class="cms-field cms-field--wide" data-rich-field>'
        .'<span class="cms-label" id="'.$id.'-label">'.h($label).'</span>'
        .($hint !== '' ? '<span class="cms-hint">'.h($hint).'</span>' : '')
        .'<div class="rich-toolbar" role="toolbar" aria-label="Format '.h($label).'">'.$toolbar.'</div>'
        .'<div class="rich-editable" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="'.$id.'-label" data-rich-editor>'.ppdb_sanitize_rich_text((string) $html).'</div>'
        .'<textarea name="'.h($name).'" data-rich-source hidden>'.h(ppdb_sanitize_rich_text((string) $html)).'</textarea>'
        .'</div>';
}

/** Field gambar dengan pratinjau langsung dan opsi hapus gambar lama. */
function cms_image_field(string $name, ?string $current, string $label, string $hint = 'JPG, PNG, atau WebP, maks. 6 MB. Disimpan otomatis sebagai WebP.', bool $removable = true): string
{
    static $count = 0;
    $id = 'img-'.preg_replace('/[^a-z0-9]+/i', '-', $name).'-'.(++$count);
    $preview = $current ? '<img src="'.h($current).'" alt="" data-image-preview>' : '<span class="cms-image__empty" data-image-empty>Belum ada gambar</span><img alt="" data-image-preview hidden>';
    return '<div class="cms-field cms-image">'
        .'<label class="cms-label" for="'.$id.'">'.h($label).'</label>'
        .'<div class="cms-image__frame">'.$preview.'</div>'
        .'<input id="'.$id.'" type="file" name="'.h($name).'" accept="image/jpeg,image/png,image/webp" data-image-input>'
        .'<span class="cms-hint">'.h($hint).'</span>'
        .($current && $removable ? '<label class="cms-check"><input type="checkbox" name="'.h($name).'_remove" value="1"> Hapus gambar ini</label>' : '')
        .'</div>';
}

/**
 * Memproses field gambar: unggahan baru menggantikan yang lama, centang hapus
 * mengosongkan. Mengembalikan path akhir.
 */
function cms_process_image_field(string $name, ?string $current, string $folder, int $maxWidth = 1920): ?string
{
    $upload = cms_uploaded_image($name);
    if ($upload) {
        $stored = cms_store_image($upload, $folder, $maxWidth);
        cms_delete_image($current);
        return $stored;
    }
    if (!empty($_POST[$name.'_remove'])) {
        cms_delete_image($current);
        return null;
    }
    return $current ?: null;
}

/** Tag skrip editor untuk halaman CMS. */
function cms_admin_scripts(): string
{
    return '<script src="js/cms-admin.js?v='.(int) filemtime(__DIR__.'/js/cms-admin.js').'" defer></script>';
}
