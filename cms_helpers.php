<?php

/**
 * Helper konten situs yayasan (CMS): pengaturan, gambar publik, berita,
 * halaman Tentang/Kurikulum, slider, dan profil unit.
 */

require_once __DIR__.'/app_helpers.php';
require_once __DIR__.'/ppdb_material_helpers.php';

const CMS_UPLOAD_ROOT = 'uploads/cms';

/** Seluruh pengaturan situs dimuat sekali per permintaan. */
function cms_settings(mysqli $conn): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $result = @mysqli_query($conn, 'SELECT setting_key,setting_value FROM tb_cms_settings');
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $cache[$row['setting_key']] = (string) $row['setting_value'];
        }
    }
    return $cache;
}

function cms_setting(mysqli $conn, string $key, string $default = ''): string
{
    $settings = cms_settings($conn);
    return isset($settings[$key]) && $settings[$key] !== '' ? $settings[$key] : $default;
}

function cms_save_setting(mysqli $conn, string $key, string $value): void
{
    $stmt = mysqli_prepare($conn, 'INSERT INTO tb_cms_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    mysqli_stmt_bind_param($stmt, 'ss', $key, $value);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * Menyimpan gambar unggahan ke folder publik. Gambar selalu dibaca ulang dan
 * disimpan sebagai WebP oleh GD sehingga metadata dan muatan tersembunyi hilang.
 *
 * @return string path relatif dari akar situs
 */
function cms_store_image(array $file, string $folder, int $maxWidth = 1920): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('Gambar gagal diunggah. Coba pilih ulang berkasnya.');
    }
    if (($file['size'] ?? 0) > 6 * 1024 * 1024) {
        throw new RuntimeException('Ukuran gambar maksimal 6 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $loaders = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'];
    if (!isset($loaders[$mime]) || !getimagesize($file['tmp_name'])) {
        throw new RuntimeException('Format gambar harus JPG, PNG, atau WebP.');
    }
    $image = @$loaders[$mime]($file['tmp_name']);
    if (!$image) {
        throw new RuntimeException('Gambar tidak dapat dibaca. Simpan ulang berkas lalu coba lagi.');
    }
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $orientation = (int) (@exif_read_data($file['tmp_name'])['Orientation'] ?? 1);
        $rotations = [3 => 180, 6 => -90, 8 => 90];
        if (isset($rotations[$orientation])) {
            $image = imagerotate($image, $rotations[$orientation], 0);
        }
    }
    $width = imagesx($image);
    $height = imagesy($image);
    if ($width > $maxWidth) {
        $newHeight = (int) round($height * $maxWidth / $width);
        $resized = imagecreatetruecolor($maxWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $maxWidth, $newHeight, $width, $height);
        imagedestroy($image);
        $image = $resized;
    } else {
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }

    $folder = preg_replace('/[^a-z0-9-]/', '', strtolower($folder)) ?: 'umum';
    $directory = __DIR__.'/'.CMS_UPLOAD_ROOT.'/'.$folder;
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Folder unggahan tidak dapat dibuat.');
    }
    $name = date('Ymd').'-'.bin2hex(random_bytes(8)).'.webp';
    if (!imagewebp($image, $directory.'/'.$name, 82)) {
        imagedestroy($image);
        throw new RuntimeException('Gambar gagal disimpan.');
    }
    imagedestroy($image);
    return CMS_UPLOAD_ROOT.'/'.$folder.'/'.$name;
}

/** Menghapus berkas gambar CMS; path di luar folder unggahan diabaikan. */
function cms_delete_image(?string $path): void
{
    $path = (string) $path;
    if ($path === '' || !preg_match('#^uploads/cms/[a-z0-9-]+/[A-Za-z0-9-]+\.webp$#', $path)) {
        return;
    }
    $full = __DIR__.'/'.$path;
    if (is_file($full)) {
        @unlink($full);
    }
}

/** Ambil gambar dari input bila ada; null bila admin tidak memilih berkas. */
function cms_uploaded_image(string $field): ?array
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    return $file;
}

/** ID video dari tautan YouTube (watch, youtu.be, embed, shorts, live). */
function cms_youtube_id(string $url): string
{
    $url = trim($url);
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) {
        return $url;
    }
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return '';
    }
    $host = strtolower(preg_replace('/^(www\.|m\.)/', '', $parts['host']));
    $path = $parts['path'] ?? '';
    $id = '';
    if ($host === 'youtu.be') {
        $id = trim($path, '/');
    } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com', 'music.youtube.com'], true)) {
        parse_str($parts['query'] ?? '', $query);
        if (!empty($query['v'])) {
            $id = (string) $query['v'];
        } elseif (preg_match('#^/(?:embed|shorts|live|v)/([^/?]+)#', $path, $match)) {
            $id = $match[1];
        }
    }
    return preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : '';
}

function cms_slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim(substr($text, 0, 150), '-') ?: 'berita';
}

/** Ringkasan teks polos dari HTML untuk kartu berita dan meta description. */
function cms_plain_excerpt(?string $html, int $length = 180): string
{
    $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8')) ?? '');
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    $cut = mb_substr($text, 0, $length);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space ? mb_substr($cut, 0, $space) : $cut, ',.;: ').'…';
}

function cms_slides(mysqli $conn): array
{
    $result = @mysqli_query($conn, 'SELECT id,image_path,title,caption,link_url FROM tb_cms_slides WHERE is_active=1 ORDER BY sort_order,id');
    return $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
}

/** Bagian halaman (Tentang/Kurikulum) diurutkan sesuai kerangka. */
function cms_sections(mysqli $conn, string $page): array
{
    $stmt = mysqli_prepare($conn, 'SELECT section_key,title,subtitle,body_html,image_path FROM tb_cms_sections WHERE page=? ORDER BY sort_order,id');
    mysqli_stmt_bind_param($stmt, 's', $page);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    $sections = [];
    foreach ($rows as $row) {
        $sections[$row['section_key']] = $row;
    }
    return $sections;
}

function cms_items(mysqli $conn, string $type, bool $activeOnly = true): array
{
    $sql = 'SELECT id,title,subtitle,description,image_path,sort_order,is_active FROM tb_cms_items WHERE item_type=?'.($activeOnly ? ' AND is_active=1' : '').' ORDER BY sort_order,id';
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 's', $type);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

/** Konten program, galeri, prestasi, testimoni, dan alumni yang diterbitkan. */
function cms_showcase_items(mysqli $conn, string $type, int $limit = 100, string $unitCode = ''): array
{
    $allowedTypes = ['program', 'gallery', 'prestasi', 'testimoni', 'alumni'];
    if (!in_array($type, $allowedTypes, true)) {
        return [];
    }

    $sql = 'SELECT id,content_type,category,title,subtitle,body_html,image_path,person_name,person_role,kode_unit,sort_order
        FROM tb_cms_showcase WHERE content_type=? AND is_active=1';
    if ($unitCode !== '') {
        $sql .= ' AND kode_unit=?';
    }
    $sql .= ' ORDER BY sort_order,id DESC LIMIT '.max(1, min(200, $limit));
    $stmt = mysqli_prepare($conn, $sql);
    if ($unitCode !== '') {
        mysqli_stmt_bind_param($stmt, 'ss', $type, $unitCode);
    } else {
        mysqli_stmt_bind_param($stmt, 's', $type);
    }
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return $rows;
}

/** Unit pendidikan beserta profil situsnya, urut jenjang. */
function cms_units(mysqli $conn): array
{
    $result = mysqli_query($conn, "SELECT u.kode_unit,u.nama_unit,u.jenjang,u.npsn,u.email,u.alamat,p.tagline,p.description_html,p.photo_path,p.phone
        FROM tb_unit_pendidikan u LEFT JOIN tb_cms_unit_profiles p ON p.kode_unit=u.kode_unit
        ORDER BY FIELD(u.jenjang,'KB','TPA','TK','SD','SMP')");
    return $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
}

/**
 * Berita terbit, opsional per unit. Mengembalikan [rows, total].
 */
function cms_published_news(mysqli $conn, int $limit, int $offset = 0, string $unitCode = ''): array
{
    $where = "n.status='published' AND n.published_at<=NOW()";
    $types = '';
    $params = [];
    if ($unitCode !== '') {
        $where .= ' AND n.kode_unit=?';
        $types .= 's';
        $params[] = $unitCode;
    }
    $count = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM tb_cms_news n WHERE '.$where);
    if ($types !== '') {
        mysqli_stmt_bind_param($count, $types, ...$params);
    }
    mysqli_stmt_execute($count);
    $total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($count))['total'];
    mysqli_stmt_close($count);

    $stmt = mysqli_prepare($conn, 'SELECT n.id,n.slug,n.title,n.excerpt,n.body_html,n.cover_path,n.category,n.published_at,n.kode_unit,u.nama_unit,u.jenjang
        FROM tb_cms_news n LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=n.kode_unit
        WHERE '.$where.' ORDER BY n.published_at DESC,n.id DESC LIMIT '.max(1, $limit).' OFFSET '.max(0, $offset));
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return [$rows, $total];
}

function cms_news_excerpt(array $news, int $length = 160): string
{
    return trim((string) ($news['excerpt'] ?? '')) !== '' ? (string) $news['excerpt'] : cms_plain_excerpt($news['body_html'] ?? '', $length);
}

/** Tanggal & jam Indonesia ringkas untuk metadata berita. */
function cms_format_datetime_id(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    return ppdb_format_date_id(substr($datetime, 0, 10));
}

/** Asisten AI aktif bila admin mengizinkan dan kunci API tersedia di server. */
function cms_ai_available(mysqli $conn): bool
{
    return cms_setting($conn, 'ai_enabled', '0') === '1'
        && (trim((string) getenv('ANTHROPIC_API_KEY')) !== '' || trim((string) getenv('ANTHROPIC_AUTH_TOKEN')) !== '');
}
