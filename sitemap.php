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

$unitsResult = @mysqli_query($conn, 'SELECT kode_unit,nama_unit FROM tb_unit_pendidikan ORDER BY FIELD(jenjang,\'KB\',\'TPA\',\'TK\',\'SD\',\'SMP\')');
if (!$unitsResult) {
    http_response_code(503);
    header('Retry-After: 300');
    exit('Sitemap sementara tidak tersedia.');
}
while ($unit = mysqli_fetch_assoc($unitsResult)) {
    $paths[] = ppdb_unit_public_path($unit);
}
mysqli_free_result($unitsResult);

$newsResult = mysqli_query($conn, "SELECT slug FROM tb_cms_news WHERE status='published' AND published_at<=NOW() ORDER BY published_at DESC,id DESC");
if (!$newsResult) {
    http_response_code(503);
    header('Retry-After: 300');
    exit('Sitemap sementara tidak tersedia.');
}
while ($news = mysqli_fetch_assoc($newsResult)) {
    $slug = strtolower((string) $news['slug']);
    if (preg_match('/^[a-z0-9-]+$/D', $slug)) {
        $paths[] = 'berita/'.rawurlencode($slug);
    }
}
mysqli_free_result($newsResult);

$urls = array_values(array_unique(array_map(
    static fn (string $path): string => $baseUrl.($path === '' ? '/' : '/'.$path),
    $paths
)));

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=300');
http_response_code(200);
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $url) {
    echo "  <url>\n";
    echo '    <loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8')."</loc>\n";
    echo "  </url>\n";
}
echo "</urlset>\n";
