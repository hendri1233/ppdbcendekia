<?php
require_once __DIR__.'/koneksi.php';
require_once __DIR__.'/cms_helpers.php';

$baseUrl = rtrim(ppdb_public_base_url(), '/');
$paths = [
    '',
    'tentang',
    'kontak',
    'kurikulum',
    'spmb',
    'berita',
    'cerita',
    'program',
    'galeri',
    'unit',
];

foreach (cms_units($conn) as $unit) {
    $paths[] = ppdb_unit_public_path($unit);
}

$newsResult = mysqli_query($conn, "SELECT slug FROM tb_cms_news WHERE status='published' AND published_at<=NOW() ORDER BY published_at DESC,id DESC");
if ($newsResult) {
    while ($news = mysqli_fetch_assoc($newsResult)) {
        $paths[] = 'berita/'.rawurlencode((string) $news['slug']);
    }
    mysqli_free_result($newsResult);
}

$urls = array_values(array_unique(array_map(
    static fn (string $path): string => $baseUrl.($path === '' ? '/' : '/'.$path),
    $paths
)));

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=300');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $url) {
    echo "  <url>\n";
    echo '    <loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8')."</loc>\n";
    echo "  </url>\n";
}
echo "</urlset>\n";
