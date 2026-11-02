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

/** Ringkasan manual yang hanya mengulang judul tidak menambah informasi. */
function cms_news_excerpt_is_redundant(array $news): bool
{
    $excerpt = trim((string) ($news['excerpt'] ?? ''));
    return $excerpt !== '' && mb_stripos((string) ($news['title'] ?? ''), $excerpt) !== false;
}

function cms_news_excerpt(array $news, int $length = 160): string
{
    // Ringkasan manual yang terlalu panjang dipotong rapi di akhir kata, sama seperti ringkasan otomatis.
    $excerpt = trim((string) ($news['excerpt'] ?? ''));
    $useExcerpt = $excerpt !== '' && !cms_news_excerpt_is_redundant($news);
    return cms_plain_excerpt($useExcerpt ? $excerpt : ($news['body_html'] ?? ''), $length);
}

/** Lapisan buram dari gambar yang sama untuk mengisi sisi kosong gambar yang ditampilkan utuh. */
function cms_image_backdrop(string $path): string
{
    return '<span class="media-backdrop" style="background-image:url(&quot;'.h($path).'&quot;)" aria-hidden="true"></span>';
}

/** Atribut width dan height asli gambar agar tata letak tidak melompat saat dimuat. */
function cms_image_size_attrs(string $path): string
{
    $size = @getimagesize(__DIR__.'/'.ltrim($path, '/'));
    return $size ? ' width="'.(int) $size[0].'" height="'.(int) $size[1].'"' : '';
}

/** Perkiraan waktu baca berita dalam menit, minimal satu menit. */
function cms_reading_minutes(string $html): int
{
    return max(1, (int) ceil(count(preg_split('/\s+/u', trim(strip_tags($html)), -1, PREG_SPLIT_NO_EMPTY)) / 200));
}

/**
 * Merapikan isi berita saat ditampilkan, tanpa mengubah data tersimpan: teks lepas dan
 * baris kosong menjadi paragraf, paragraf kosong dibuang, spasi dan tanda kutip dibakukan,
 * dan baris pendek yang seluruhnya tebal menjadi subjudul.
 */
function cms_format_news_html(string $html): string
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $previousSetting = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div id="news-root">'.$html.'</div>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
    libxml_clear_errors();
    libxml_use_internal_errors($previousSetting);
    $root = $document->getElementById('news-root');
    if (!$root) {
        return $html;
    }

    $isBlank = static fn (DOMNode $node): bool => $node instanceof DOMText && trim(str_replace("\u{A0}", ' ', $node->nodeValue)) === '';
    $isBreak = static fn (DOMNode $node): bool => $node instanceof DOMElement && strtolower($node->tagName) === 'br';

    // Teks lepas di akar dibungkus paragraf.
    $paragraph = null;
    foreach (iterator_to_array($root->childNodes) as $node) {
        $isBlock = $node instanceof DOMElement && in_array(strtolower($node->tagName), ['p', 'h2', 'h3', 'ul', 'ol', 'blockquote'], true);
        if ($isBlock) {
            $paragraph = null;
            continue;
        }
        if ($paragraph === null) {
            if ($isBlank($node)) {
                $root->removeChild($node);
                continue;
            }
            $paragraph = $document->createElement('p');
            $root->insertBefore($paragraph, $node);
        }
        $paragraph->appendChild($node);
    }

    // Dua baris baru berurutan berarti paragraf baru.
    foreach (iterator_to_array($root->childNodes) as $block) {
        if (!$block instanceof DOMElement || strtolower($block->tagName) !== 'p') {
            continue;
        }
        $current = $document->createElement('p');
        $root->insertBefore($current, $block);
        $children = iterator_to_array($block->childNodes);
        foreach ($children as $index => $child) {
            if ($isBreak($child)) {
                $next = $index + 1;
                while (isset($children[$next]) && $isBlank($children[$next])) {
                    $next++;
                }
                $previousIsBreak = false;
                for ($back = $index - 1; $back >= 0 && !$previousIsBreak; $back--) {
                    if ($isBreak($children[$back])) {
                        $previousIsBreak = true;
                    } elseif (!$isBlank($children[$back])) {
                        break;
                    }
                }
                if ($previousIsBreak) {
                    continue;
                }
                if (isset($children[$next]) && $isBreak($children[$next])) {
                    $paragraphBreak = $document->createElement('p');
                    $root->insertBefore($paragraphBreak, $block);
                    $current = $paragraphBreak;
                    continue;
                }
            }
            $current->appendChild($child);
        }
        $root->removeChild($block);
    }

    // Bakukan teks: spasi ganda, spasi sebelum tanda baca, dan tanda kutip lurus.
    $xpath = new DOMXPath($document);
    foreach (iterator_to_array($xpath->query('.//text()', $root)) as $text) {
        $value = str_replace("\u{A0}", ' ', $text->nodeValue);
        $value = preg_replace('/\s+/u', ' ', $value);
        $value = preg_replace('/ +([,.;!?])/u', '$1', $value);
        $value = preg_replace('/\( +/u', '(', $value);
        $value = preg_replace('/ +\)/u', ')', $value);
        $value = preg_replace('/(^|[\s(\[])"/u', '$1“', $value);
        $text->nodeValue = str_replace('"', '”', $value);
    }

    foreach (iterator_to_array($root->childNodes) as $block) {
        if (!$block instanceof DOMElement) {
            continue;
        }
        $tag = strtolower($block->tagName);
        if (in_array($tag, ['p', 'li', 'h2', 'h3'], true)) {
            // Baris baru dan spasi di tepi blok tidak berarti apa-apa.
            while ($block->firstChild && ($isBlank($block->firstChild) || $isBreak($block->firstChild))) {
                $block->removeChild($block->firstChild);
            }
            while ($block->lastChild && ($isBlank($block->lastChild) || $isBreak($block->lastChild))) {
                $block->removeChild($block->lastChild);
            }
            if ($block->firstChild instanceof DOMText) {
                $block->firstChild->nodeValue = ltrim($block->firstChild->nodeValue);
            }
            if ($block->lastChild instanceof DOMText) {
                $block->lastChild->nodeValue = rtrim($block->lastChild->nodeValue);
            }
        }
        if (trim($block->textContent) === '') {
            $root->removeChild($block);
            continue;
        }
        if ($tag === 'p' && $block->childNodes->length === 1 && $block->firstChild instanceof DOMElement
            && in_array(strtolower($block->firstChild->tagName), ['strong', 'b'], true)
            && mb_strlen(trim($block->textContent)) <= 100 && !preg_match('/[.,;:!?]$/u', trim($block->textContent))) {
            $heading = $document->createElement('h2');
            $heading->appendChild($document->createTextNode(trim($block->textContent)));
            $root->replaceChild($heading, $block);
        }
    }

    $clean = '';
    foreach ($root->childNodes as $child) {
        $clean .= $document->saveHTML($child);
    }
    return trim($clean);
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

/** Video pendek unggulan setiap unit: kunci => [label, ikon]. Disimpan di tb_cms_settings. */
const CMS_UNIT_SOCIALS = [
    'facebook' => ['Reels Facebook', 'facebook'],
    'instagram' => ['Reels Instagram', 'instagram'],
    'youtube' => ['Shorts YouTube', 'youtube'],
    'tiktok' => ['Video TikTok', 'tiktok'],
];

function cms_unit_social_key(string $code, string $network): string
{
    return 'unit_social_'.$code.'_'.$network;
}

/**
 * Video unit yang sudah diisi, berurutan sesuai CMS_UNIT_SOCIALS. Instagram juga
 * tampil tanpa tautan bila akun unit terhubung (reels terbaru). $withMedia memuat
 * berkas reels dari Instagram API untuk halaman publik.
 */
function cms_unit_socials(mysqli $conn, string $code, bool $withMedia = false): array
{
    $links = [];
    foreach (CMS_UNIT_SOCIALS as $network => [$label, $icon]) {
        $url = cms_setting($conn, cms_unit_social_key($code, $network));
        $igLinked = $network === 'instagram' && cms_setting($conn, cms_unit_ig_token_key($code)) !== '';
        if ($url === '' && !$igLinked) {
            continue;
        }
        $video = $igLinked && $withMedia ? cms_unit_instagram_video($conn, $code, $url) : null;
        if ($url === '' && !$video && $withMedia) {
            continue;
        }
        $links[] = [
            'network' => $network,
            'label' => $label,
            'icon' => $icon,
            'url' => $video['permalink'] ?? $url,
            'embed' => cms_unit_video_embed($network, $url),
            'video' => $video,
        ];
    }
    return $links;
}

/**
 * Alamat pemutar sematan untuk tautan video pendek. Pemutar dibuat tanpa suara
 * agar peramban mengizinkan putar otomatis. Null bila tautan tidak dikenali.
 */
function cms_unit_video_embed(string $network, string $url): ?string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $path = (string) parse_url($url, PHP_URL_PATH);
    switch ($network) {
        case 'youtube':
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $id = '';
            if (preg_match('#^/(?:shorts|embed|live)/([\w-]{11})#', $path, $m) && str_contains($host, 'youtube.com')) {
                $id = $m[1];
            } elseif ($host === 'youtu.be' && preg_match('#^/([\w-]{11})#', $path, $m)) {
                $id = $m[1];
            } elseif (str_contains($host, 'youtube.com') && preg_match('#^[\w-]{11}$#', (string) ($query['v'] ?? ''))) {
                $id = $query['v'];
            }
            return $id === '' ? null : 'https://www.youtube.com/embed/'.$id.'?autoplay=1&mute=1&loop=1&playlist='.$id.'&playsinline=1&rel=0';
        case 'instagram':
            return str_ends_with($host, 'instagram.com') && preg_match('#^/(?:reels?|p)/([\w-]+)#', $path, $m)
                ? 'https://www.instagram.com/reel/'.$m[1].'/embed/'
                : null;
        case 'facebook':
            return str_ends_with($host, 'facebook.com') || $host === 'fb.watch'
                ? 'https://www.facebook.com/plugins/video.php?href='.rawurlencode($url).'&show_text=false&autoplay=true&mute=true&width=320&height=568'
                : null;
        case 'tiktok':
            return str_ends_with($host, 'tiktok.com') && preg_match('#/video/(\d+)#', $path, $m)
                ? 'https://www.tiktok.com/player/v1/'.$m[1].'?autoplay=1&muted=1&loop=1&description=0&music_info=0&rel=0'
                : null;
    }
    return null;
}

/**
 * Reels Instagram lewat Instagram API (Instagram Login). Instagram tidak mengizinkan
 * sematan diputar otomatis, jadi berkas videonya diambil dengan token akun unit dan
 * diputar langsung. Hasil disimpan sementara agar API tidak dipanggil setiap kunjungan.
 */
const CMS_IG_CACHE_SECONDS = 3600;
const CMS_IG_RETRY_SECONDS = 900;
const CMS_IG_REFRESH_AFTER_SECONDS = 20 * 86400;

function cms_unit_ig_token_key(string $code): string
{
    return 'unit_ig_token_'.$code;
}

/** Permintaan GET ke graph.instagram.com; null bila gagal. */
function cms_instagram_get(string $path, array $query): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init('https://graph.instagram.com/'.$path.'?'.http_build_query($query));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
    ]);
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    if ($httpCode !== 200 || !is_array($data)) {
        error_log('Instagram API '.$path.' gagal (HTTP '.$httpCode.'): '.substr((string) $body, 0, 300));
        return null;
    }
    return $data;
}

/** Nama akun pemilik token, sekaligus memeriksa token masih berlaku. */
function cms_instagram_username(string $token): ?string
{
    $data = cms_instagram_get('me', ['fields' => 'username', 'access_token' => $token]);
    return isset($data['username']) ? (string) $data['username'] : null;
}

function cms_unit_ig_save_token(mysqli $conn, string $code, string $token): void
{
    cms_save_setting($conn, cms_unit_ig_token_key($code), $token);
    cms_save_setting($conn, 'unit_ig_token_at_'.$code, $token === '' ? '' : (string) time());
    cms_save_setting($conn, 'unit_ig_cache_'.$code, '');
}

/** Token berumur 60 hari; diperbarui otomatis setelah 20 hari. */
function cms_unit_ig_token(mysqli $conn, string $code): string
{
    $token = cms_setting($conn, cms_unit_ig_token_key($code));
    $issuedAt = (int) cms_setting($conn, 'unit_ig_token_at_'.$code, '0');
    if ($token !== '' && time() - $issuedAt > CMS_IG_REFRESH_AFTER_SECONDS) {
        $data = cms_instagram_get('refresh_access_token', ['grant_type' => 'ig_refresh_token', 'access_token' => $token]);
        if (!empty($data['access_token'])) {
            $token = (string) $data['access_token'];
            cms_save_setting($conn, cms_unit_ig_token_key($code), $token);
            cms_save_setting($conn, 'unit_ig_token_at_'.$code, (string) time());
        }
    }
    return $token;
}

/**
 * Video reels untuk halaman unit: reels sesuai tautan bila diisi, selain itu reels
 * terbaru akun. Mengembalikan ['src','poster','permalink'] atau null.
 */
function cms_unit_instagram_video(mysqli $conn, string $code, string $reelUrl): ?array
{
    $cacheKey = 'unit_ig_cache_'.$code;
    $cached = json_decode(cms_setting($conn, $cacheKey), true);
    if (is_array($cached) && ($cached['for'] ?? null) === $reelUrl
        && time() - (int) ($cached['at'] ?? 0) < (empty($cached['video']) ? CMS_IG_RETRY_SECONDS : CMS_IG_CACHE_SECONDS)) {
        return $cached['video'] ?: null;
    }

    $token = cms_unit_ig_token($conn, $code);
    if ($token === '') {
        return null;
    }
    $shortcode = preg_match('#instagram\.com/(?:reels?|p)/([\w-]+)#i', $reelUrl, $m) ? $m[1] : '';
    // Batas waktu total agar halaman unit tidak tertahan bila Instagram lambat.
    $deadline = microtime(true) + 8;
    $video = null;
    $query =['fields' => 'media_type,media_url,thumbnail_url,permalink', 'limit' => 50, 'access_token' => $token];
    for ($page = 0, $after = null; $page < 4 && $video === null && microtime(true) < $deadline; $page++) {
        $data = cms_instagram_get('me/media', $after ? $query + ['after' => $after] : $query);
        if (!$data) {
            break;
        }
        foreach ($data['data'] ?? [] as $media) {
            if (($media['media_type'] ?? '') !== 'VIDEO' || !preg_match('#^https://#', (string) ($media['media_url'] ?? ''))) {
                continue;
            }
            if ($shortcode === '' || preg_match('#/(?:reels?|p)/'.preg_quote($shortcode, '#').'(?:/|$)#', (string) ($media['permalink'] ?? ''))) {
                $video = ['src' => $media['media_url'], 'poster' => preg_match('#^https://#', (string) ($media['thumbnail_url'] ?? '')) ? $media['thumbnail_url'] : '', 'permalink' => $media['permalink'] ?? $reelUrl];
                break;
            }
        }
        $after = $data['paging']['cursors']['after'] ?? null;
        if (!$after || empty($data['paging']['next'])) {
            break;
        }
    }
    cms_save_setting($conn, $cacheKey, json_encode(['for' => $reelUrl, 'at' => time(), 'video' => $video]));
    return $video;
}
