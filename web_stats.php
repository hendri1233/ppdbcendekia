<?php
/**
 * Statistik publik: pencatatan kunjungan web dan rekap kunjungan harian.
 * Pengunjung dikenali lewat cookie acak yang di-hash; IP tidak disimpan.
 */

function ppdb_stats_today(): DateTimeImmutable
{
    return new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'));
}

/**
 * Hash pengunjung untuk permintaan GET dari peramban, atau null bila tidak
 * perlu dicatat (POST, bot). Memasang cookie baru bila belum ada, jadi wajib
 * dipanggil sebelum ada output.
 */
function ppdb_visitor_hash(): ?string
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return null;
    }
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($userAgent === '' || preg_match('/bot|crawl|spider|slurp|preview|monitor|curl|wget|python|headless/i', $userAgent)) {
        return null;
    }

    $visitorId = (string) ($_COOKIE['ppdb_vid'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $visitorId)) {
        $visitorId = bin2hex(random_bytes(16));
        setcookie('ppdb_vid', $visitorId, [
            'expires' => time() + 365 * 86400,
            'path' => '/',
            'httponly' => true,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'samesite' => 'Lax',
        ]);
        $_COOKIE['ppdb_vid'] = $visitorId;
    }

    return hash('sha256', $visitorId);
}

/** Catat satu tayangan halaman. Wajib dipanggil sebelum ada output. */
function ppdb_track_visit(mysqli $conn): void
{
    $hash = ppdb_visitor_hash();
    if ($hash === null) {
        return;
    }

    try {
        $stmt = mysqli_prepare($conn, 'INSERT INTO tb_kunjungan_web (tanggal, visitor_hash) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE jumlah_tayang = jumlah_tayang + 1');
        $date = ppdb_stats_today()->format('Y-m-d');
        mysqli_stmt_bind_param($stmt, 'ss', $date, $hash);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } catch (Throwable $e) {
        error_log('Pencatatan kunjungan gagal: '.$e->getMessage());
    }
}

/**
 * Lokasi berkas GeoLite2-City.mmdb. GEOIP_DB_PATH di .env didahulukan; bila
 * berkasnya tidak ada di sana, storage/geoip/ di folder aplikasi dipakai.
 */
function ppdb_geoip_database_path(): string
{
    $default = __DIR__.'/storage/geoip/GeoLite2-City.mmdb';
    $configured = trim((string) getenv('GEOIP_DB_PATH'));
    if ($configured !== '' && (is_file($configured) || !is_file($default))) {
        return $configured;
    }

    return $default;
}

/** Alasan database lokasi belum bisa dipakai, atau null bila siap. */
function ppdb_geoip_problem(): ?string
{
    if (!class_exists(\MaxMind\Db\Reader::class)) {
        return 'Pustaka maxmind-db/reader belum terpasang. Jalankan composer install atau unggah ulang folder vendor.';
    }
    $path = ppdb_geoip_database_path();
    if (!is_file($path)) {
        return 'Berkas GeoLite2-City.mmdb tidak ditemukan di '.$path.'.';
    }
    if (!is_readable($path)) {
        return 'Berkas '.$path.' ada tetapi tidak dapat dibaca. Ubah izinnya menjadi 644.';
    }
    if (filesize($path) < 1048576) {
        return 'Berkas '.$path.' terlalu kecil; kemungkinan unggahan terpotong. Unggah ulang berkas aslinya (sekitar 50 MB).';
    }

    return null;
}

function ppdb_geoip_ready(): bool
{
    return ppdb_geoip_problem() === null;
}

/**
 * Perkiraan kota dari IP publik lewat database GeoLite2 lokal; IP tidak
 * dikirim ke mana pun. Null untuk IP lokal/privat atau bila database belum ada.
 */
function ppdb_geo_lookup(string $ip): ?array
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || !ppdb_geoip_ready()) {
        return null;
    }

    try {
        static $reader = null;
        $reader ??= new \MaxMind\Db\Reader(ppdb_geoip_database_path());
        $record = $reader->get($ip);
    } catch (Throwable $e) {
        error_log('Pencarian lokasi IP gagal: '.$e->getMessage());
        return null;
    }
    if (!isset($record['location']['latitude'], $record['location']['longitude'])) {
        return null;
    }
    $name = static fn (?array $names): ?string => $names ? mb_substr((string) ($names['id'] ?? $names['en'] ?? reset($names)), 0, 80) : null;

    return [
        'kota' => $name($record['city']['names'] ?? null),
        'wilayah' => $name($record['subdivisions'][0]['names'] ?? null),
        'negara' => $record['country']['iso_code'] ?? null,
        'lat' => round((float) $record['location']['latitude'], 2),
        'lon' => round((float) $record['location']['longitude'], 2),
    ];
}

/** Catat satu pembukaan tautan pendaftaran unit. Wajib dipanggil sebelum ada output. */
function ppdb_track_unit_link_visit(mysqli $conn, string $unitCode): void
{
    $hash = ppdb_visitor_hash();
    if ($hash === null || $unitCode === '') {
        return;
    }

    try {
        $geo = ppdb_geo_lookup((string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?? ['kota' => null, 'wilayah' => null, 'negara' => null, 'lat' => null, 'lon' => null];
        $stmt = mysqli_prepare($conn, 'INSERT INTO tb_kunjungan_unit (tanggal, kode_unit, visitor_hash, kota, wilayah, negara, lat, lon) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE jumlah_tayang = jumlah_tayang + 1');
        $date = ppdb_stats_today()->format('Y-m-d');
        mysqli_stmt_bind_param($stmt, 'ssssssdd', $date, $unitCode, $hash, $geo['kota'], $geo['wilayah'], $geo['negara'], $geo['lat'], $geo['lon']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } catch (Throwable $e) {
        error_log('Pencatatan akses tautan unit gagal: '.$e->getMessage());
    }
}

/** Pengakses berbeda tautan pendaftaran per unit sejak pencatatan dimulai, [kode_unit => jumlah]. */
function ppdb_unit_link_visitors(mysqli $conn): array
{
    $counts = [];
    try {
        $result = mysqli_query($conn, 'SELECT kode_unit, COUNT(DISTINCT visitor_hash) AS visitors FROM tb_kunjungan_unit GROUP BY kode_unit');
        while ($row = mysqli_fetch_assoc($result)) {
            $counts[$row['kode_unit']] = (int) $row['visitors'];
        }
    } catch (Throwable $e) {
        error_log('Rekap akses tautan unit gagal: '.$e->getMessage());
    }

    return $counts;
}

/**
 * Rekap harian $days hari terakhir untuk grafik capaian: pengakses tautan
 * berbeda dan pendaftar baru per hari. $unitCode kosong berarti semua unit.
 */
function ppdb_unit_daily_progress(mysqli $conn, string $unitCode = '', int $days = 30): array
{
    $dayNames = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
    $months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $today = ppdb_stats_today();
    $start = $today->modify('-'.($days - 1).' days');
    $series = [];
    for ($day = $start; $day <= $today; $day = $day->modify('+1 day')) {
        $key = $day->format('Y-m-d');
        $series[$key] = [
            'date' => $key,
            'day' => $dayNames[(int) $day->format('w')],
            'label' => $day->format('j').' '.$months[(int) $day->format('n')],
            'full' => ppdb_format_date_id($key),
            'visits' => 0,
            'registrants' => 0,
        ];
    }

    $startDate = $start->format('Y-m-d');
    $queries = [
        'visits' => 'SELECT tanggal AS d, COUNT(DISTINCT visitor_hash) AS total FROM tb_kunjungan_unit
            WHERE tanggal >= ? AND (? = \'\' OR kode_unit = ?) GROUP BY tanggal',
        'registrants' => "SELECT tgl_daftar AS d, COUNT(*) AS total FROM tb_pendaftaran
            WHERE tgl_daftar >= ? AND status_ppdb <> 'data_lama' AND kode_unit IS NOT NULL AND (? = '' OR kode_unit = ?) GROUP BY tgl_daftar",
    ];
    foreach ($queries as $field => $sql) {
        try {
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 'sss', $startDate, $unitCode, $unitCode);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                if (isset($series[$row['d']])) {
                    $series[$row['d']][$field] = (int) $row['total'];
                }
            }
            mysqli_stmt_close($stmt);
        } catch (Throwable $e) {
            error_log('Rekap capaian harian gagal: '.$e->getMessage());
        }
    }

    return array_values($series);
}

/**
 * Kunci pembanding nama wilayah: "KABUPATEN ACEH TENGAH", "Kab. Aceh Tengah",
 * dan "Aceh Tengah" menjadi "aceh tengah". Awalan "Kota" dipertahankan agar
 * Kota Medan tidak tertukar dengan kabupaten bernama sama.
 */
function ppdb_region_key(string $name): string
{
    $name = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    $name = preg_replace('/^(kabupaten|kab\.?|provinsi|prov\.?|kecamatan|kec\.?|desa|kelurahan|kel\.?)\s+/u', '', $name);

    return trim($name);
}

/** Nama wilayah untuk ditampilkan: "KABUPATEN ACEH TENGAH" menjadi "Aceh Tengah". */
function ppdb_region_label(string $name): string
{
    $key = ppdb_region_key($name);

    return $key === '' ? '' : mb_convert_case($key, MB_CASE_TITLE);
}

/**
 * Satu permintaan ke OpenStreetMap Nominatim dengan cache di tb_geo_cache.
 * Kebijakan Nominatim membatasi 1 permintaan per detik, jadi tiap halaman
 * hanya boleh memakai beberapa permintaan baru; sisanya dilengkapi saat
 * dashboard dibuka berikutnya. Hasil gagal disimpan 7 hari sebelum dicoba lagi.
 */
function ppdb_geo_cached_request(mysqli $conn, string $cacheKey, string $endpoint, array $params): ?array
{
    static $budget = 3;
    static $lastCall = 0.0;
    $cacheKey = mb_substr($cacheKey, 0, 191);

    try {
        $stmt = mysqli_prepare($conn, 'SELECT found, lat, lon, kabupaten, provinsi, created_at FROM tb_geo_cache WHERE cache_key = ?');
        mysqli_stmt_bind_param($stmt, 's', $cacheKey);
        mysqli_stmt_execute($stmt);
        $cached = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    } catch (Throwable $e) {
        error_log('Cache geocoding tidak tersedia: '.$e->getMessage());
        return null;
    }
    if ($cached && ((int) $cached['found'] === 1 || strtotime($cached['created_at']) > time() - 7 * 86400)) {
        return (int) $cached['found'] === 1 ? $cached : null;
    }
    if ($budget < 1 || !function_exists('curl_init')) {
        return null;
    }
    $budget--;

    $wait = 1.05 - (microtime(true) - $lastCall);
    if ($wait > 0) {
        usleep((int) ($wait * 1000000));
    }
    $lastCall = microtime(true);
    $curl = curl_init('https://nominatim.openstreetmap.org/'.$endpoint.'?'.http_build_query($params + ['format' => 'jsonv2', 'addressdetails' => 1]));
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT => 'SPMB Cendekia Takengon (+https://cendekiatakengon.or.id)',
        CURLOPT_HTTPHEADER => ['Accept-Language: id'],
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($body === false || $status !== 200) {
        error_log('Geocoding Nominatim gagal (HTTP '.$status.') untuk '.$cacheKey);
        return null;
    }
    $data = json_decode((string) $body, true);
    if ($endpoint === 'search') {
        $data = is_array($data) ? ($data[0] ?? null) : null;
    }
    $address = is_array($data) ? ($data['address'] ?? []) : [];
    $row = [
        'found' => isset($data['lat'], $data['lon']) ? 1 : 0,
        'lat' => isset($data['lat']) ? round((float) $data['lat'], 7) : null,
        'lon' => isset($data['lon']) ? round((float) $data['lon'], 7) : null,
        'kabupaten' => mb_substr((string) ($address['county'] ?? $address['city'] ?? $address['town'] ?? $address['municipality'] ?? ''), 0, 100) ?: null,
        'provinsi' => mb_substr((string) ($address['state'] ?? ''), 0, 100) ?: null,
    ];
    try {
        $stmt = mysqli_prepare($conn, 'REPLACE INTO tb_geo_cache (cache_key, found, lat, lon, kabupaten, provinsi, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        mysqli_stmt_bind_param($stmt, 'siddss', $cacheKey, $row['found'], $row['lat'], $row['lon'], $row['kabupaten'], $row['provinsi']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } catch (Throwable $e) {
        error_log('Gagal menyimpan cache geocoding: '.$e->getMessage());
    }

    return $row['found'] ? $row : null;
}

/** Kabupaten/kota dari koordinat perkiraan IP pengunjung. */
function ppdb_reverse_kabupaten(mysqli $conn, float $lat, float $lon): ?array
{
    $lat = round($lat, 2);
    $lon = round($lon, 2);

    return ppdb_geo_cached_request($conn, 'rev:'.$lat.','.$lon, 'reverse', ['lat' => $lat, 'lon' => $lon, 'zoom' => 8]);
}

/**
 * Koordinat alamat pendaftar dari isian formulir, dicari dari yang paling
 * rinci (desa) ke yang paling umum (kabupaten). Hasil ditolak bila jatuh di
 * provinsi lain. Mengembalikan koordinat beserta tingkat ketepatannya.
 */
function ppdb_geocode_address(mysqli $conn, string $desa, string $kecamatan, string $kabupaten, string $provinsi): ?array
{
    $parts = array_map('ppdb_region_label', ['desa' => $desa, 'kecamatan' => $kecamatan, 'kabupaten' => $kabupaten, 'provinsi' => $provinsi]);
    $levels = [
        'desa' => [$parts['desa'], $parts['kecamatan'], $parts['kabupaten'], $parts['provinsi']],
        'kecamatan' => [$parts['kecamatan'], $parts['kabupaten'], $parts['provinsi']],
        'kabupaten' => [$parts['kabupaten'], $parts['provinsi']],
    ];
    foreach ($levels as $level => $query) {
        if (in_array('', array_slice($query, 0, -1), true) || $parts['kabupaten'] === '') {
            continue;
        }
        $text = implode(', ', array_filter($query));
        $result = ppdb_geo_cached_request($conn, 'adr:'.mb_strtolower($text), 'search', ['q' => $text, 'countrycodes' => 'id', 'limit' => 1]);
        if ($result === null) {
            continue;
        }
        if ($parts['provinsi'] !== '' && $result['provinsi'] !== null
            && ppdb_region_key($result['provinsi']) !== ppdb_region_key($parts['provinsi'])) {
            continue;
        }

        return ['lat' => (float) $result['lat'], 'lon' => (float) $result['lon'], 'precision' => $level];
    }

    return null;
}

/**
 * Lokasi pendaftar yang sudah menyelesaikan formulir lengkap. Koordinat
 * Dapodik yang dicatat operator dipakai lebih dulu; bila kosong, alamat
 * formulir diubah menjadi koordinat desa/kecamatan lewat geocoding.
 */
function ppdb_registrant_locations(mysqli $conn, string $unitCode = ''): array
{
    $points = [];
    $pending = 0;
    try {
        $stmt = mysqli_prepare($conn, "SELECT p.id_pendaftaran, p.status_ppdb, u.nama_unit, d.desa_kelurahan, d.kecamatan, d.kabupaten, d.provinsi, d.latitude, d.longitude
            FROM tb_pendaftaran p
            JOIN tb_detail_dapodik d ON d.id_pendaftaran = p.id_pendaftaran
            JOIN tb_unit_pendidikan u ON u.kode_unit = p.kode_unit
            WHERE p.status_ppdb IN ('formulir_terisi', 'diterima', 'daftar_tunggu') AND (? = '' OR p.kode_unit = ?)");
        mysqli_stmt_bind_param($stmt, 'ss', $unitCode, $unitCode);
        mysqli_stmt_execute($stmt);
        $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
    } catch (Throwable $e) {
        error_log('Rekap lokasi pendaftar gagal: '.$e->getMessage());
        return ['points' => [], 'pending' => 0];
    }

    foreach ($rows as $row) {
        if ($row['latitude'] !== null && $row['longitude'] !== null) {
            $geo = ['lat' => (float) $row['latitude'], 'lon' => (float) $row['longitude'], 'precision' => 'titik'];
        } else {
            $geo = ppdb_geocode_address($conn, (string) $row['desa_kelurahan'], (string) $row['kecamatan'], (string) $row['kabupaten'], (string) $row['provinsi']);
        }
        if ($geo === null) {
            $pending++;
            continue;
        }
        $place = implode(', ', array_filter(array_map('ppdb_region_label', [(string) $row['desa_kelurahan'], (string) $row['kecamatan']])));
        $key = round($geo['lat'], 4).','.round($geo['lon'], 4);
        $points[$key] ??= [
            'lat' => $geo['lat'],
            'lon' => $geo['lon'],
            'place' => $place ?: ppdb_region_label((string) $row['kabupaten']),
            'kabupaten' => ppdb_region_label((string) $row['kabupaten']),
            'kabupatenKey' => ppdb_region_key((string) $row['kabupaten']),
            'precision' => $geo['precision'],
            'registrants' => 0,
            'units' => [],
        ];
        $points[$key]['registrants']++;
        $unitName = ppdb_unit_display_name($row['nama_unit']);
        $points[$key]['units'][$unitName] = ($points[$key]['units'][$unitName] ?? 0) + 1;
    }
    $points = array_values(array_map(static function (array $point): array {
        $point['units'] = array_map(static fn (string $name, int $count): array => ['name' => $name, 'registrants' => $count], array_keys($point['units']), $point['units']);
        return $point;
    }, $points));

    return ['points' => $points, 'pending' => $pending];
}

/**
 * Baris tabel lokasi dashboard: pengunjung tautan dan pendaftar yang sudah
 * menyelesaikan formulir, digabung per kabupaten/kota.
 */
function ppdb_location_summary(array $visitorPoints, array $registrantPoints): array
{
    $rows = [];
    foreach ($visitorPoints as $point) {
        $key = $point['kabupatenKey'] ?: $point['place'];
        $rows[$key] ??= ['place' => $point['place'], 'visitors' => 0, 'registrants' => 0, 'today' => false, 'units' => []];
        $rows[$key]['visitors'] += $point['visitors'];
        $rows[$key]['today'] = $rows[$key]['today'] || $point['today'];
        foreach ($point['units'] as $unit) {
            $rows[$key]['units'][$unit['name']] = true;
        }
    }
    foreach ($registrantPoints as $point) {
        $key = $point['kabupatenKey'] ?: $point['place'];
        $rows[$key] ??= ['place' => $point['kabupaten'] ?: $point['place'], 'visitors' => 0, 'registrants' => 0, 'today' => false, 'units' => []];
        $rows[$key]['registrants'] += $point['registrants'];
        foreach ($point['units'] as $unit) {
            $rows[$key]['units'][$unit['name']] = true;
        }
    }
    $rows = array_values(array_map(static function (array $row): array {
        $row['units'] = array_keys($row['units']);
        return $row;
    }, $rows));
    usort($rows, static fn (array $a, array $b): int => [$b['registrants'], $b['visitors']] <=> [$a['registrants'], $a['visitors']]);

    return $rows;
}

/**
 * Titik peta pengakses tautan selama $days hari terakhir, dikelompokkan per
 * kabupaten/kota pengakses (dari koordinat perkiraan IP): jumlah pengunjung
 * berbeda, rincian per unit, dan apakah ada yang membuka hari ini.
 * $unitCode kosong berarti semua unit.
 */
function ppdb_unit_link_locations(mysqli $conn, string $unitCode = '', int $days = 30): array
{
    $points = [];
    $unlocated = 0;
    try {
        $startDate = ppdb_stats_today()->modify('-'.($days - 1).' days')->format('Y-m-d');
        $today = ppdb_stats_today()->format('Y-m-d');
        $stmt = mysqli_prepare($conn, "SELECT k.lat, k.lon, k.kota, k.wilayah, k.kode_unit, u.nama_unit,
                COUNT(DISTINCT k.visitor_hash) AS visitors, MAX(k.tanggal = ?) AS today
            FROM tb_kunjungan_unit k JOIN tb_unit_pendidikan u ON u.kode_unit = k.kode_unit
            WHERE k.tanggal >= ? AND (? = '' OR k.kode_unit = ?)
            GROUP BY k.lat, k.lon, k.kota, k.wilayah, k.kode_unit, u.nama_unit");
        mysqli_stmt_bind_param($stmt, 'ssss', $today, $startDate, $unitCode, $unitCode);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            if ($row['lat'] === null || $row['lon'] === null) {
                $unlocated += (int) $row['visitors'];
                continue;
            }
            $region = ppdb_reverse_kabupaten($conn, (float) $row['lat'], (float) $row['lon']);
            $kabupaten = $region['kabupaten'] ?? ($row['kota'] ?: '');
            $province = $region['provinsi'] ?? ($row['wilayah'] ?: '');
            $key = ppdb_region_key($kabupaten) ?: $row['lat'].','.$row['lon'];
            $points[$key] ??= [
                'latSum' => 0.0,
                'lonSum' => 0.0,
                'weight' => 0,
                'place' => implode(', ', array_filter([ppdb_region_label($kabupaten), $province])) ?: 'Lokasi tanpa nama',
                'kabupaten' => ppdb_region_label($kabupaten),
                'kabupatenKey' => ppdb_region_key($kabupaten),
                'visitors' => 0,
                'today' => false,
                'units' => [],
            ];
            $visitors = (int) $row['visitors'];
            $points[$key]['latSum'] += (float) $row['lat'] * $visitors;
            $points[$key]['lonSum'] += (float) $row['lon'] * $visitors;
            $points[$key]['weight'] += $visitors;
            $points[$key]['visitors'] += $visitors;
            $points[$key]['today'] = $points[$key]['today'] || (bool) $row['today'];
            $unitName = ppdb_unit_display_name($row['nama_unit']);
            $points[$key]['units'][$unitName] = ($points[$key]['units'][$unitName] ?? 0) + $visitors;
        }
        mysqli_stmt_close($stmt);
    } catch (Throwable $e) {
        error_log('Rekap lokasi akses tautan gagal: '.$e->getMessage());
    }
    // Satu titik per kabupaten/kota, di rata-rata koordinat pengunjungnya.
    $points = array_values(array_map(static function (array $point): array {
        $point['lat'] = round($point['latSum'] / max(1, $point['weight']), 2);
        $point['lon'] = round($point['lonSum'] / max(1, $point['weight']), 2);
        $point['units'] = array_map(static fn (string $name, int $count): array => ['name' => $name, 'visitors' => $count], array_keys($point['units']), $point['units']);
        unset($point['latSum'], $point['lonSum'], $point['weight']);
        return $point;
    }, $points));
    usort($points, static fn (array $a, array $b): int => $b['visitors'] <=> $a['visitors']);

    return ['points' => $points, 'unlocated' => $unlocated];
}

/** Rekap kunjungan per hari selama $days hari terakhir, hari kosong bernilai 0. */
function ppdb_daily_visits(mysqli $conn, int $days = 30): array
{
    $today = ppdb_stats_today();
    $start = $today->modify('-'.($days - 1).' days');
    $series = [];
    for ($day = $start; $day <= $today; $day = $day->modify('+1 day')) {
        $series[$day->format('Y-m-d')] = ['date' => $day->format('Y-m-d'), 'visitors' => 0, 'views' => 0];
    }

    try {
        $stmt = mysqli_prepare($conn, 'SELECT tanggal, COUNT(*) AS visitors, SUM(jumlah_tayang) AS views
            FROM tb_kunjungan_web WHERE tanggal >= ? GROUP BY tanggal');
        $startDate = $start->format('Y-m-d');
        mysqli_stmt_bind_param($stmt, 's', $startDate);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            if (isset($series[$row['tanggal']])) {
                $series[$row['tanggal']]['visitors'] = (int) $row['visitors'];
                $series[$row['tanggal']]['views'] = (int) $row['views'];
            }
        }
        mysqli_stmt_close($stmt);
    } catch (Throwable $e) {
        error_log('Rekap kunjungan gagal: '.$e->getMessage());
    }

    return array_values($series);
}

/** Jumlah pengunjung berbeda selama $days hari terakhir. */
function ppdb_unique_visitors(mysqli $conn, int $days = 30): int
{
    try {
        $stmt = mysqli_prepare($conn, 'SELECT COUNT(DISTINCT visitor_hash) FROM tb_kunjungan_web WHERE tanggal >= ?');
        $startDate = ppdb_stats_today()->modify('-'.($days - 1).' days')->format('Y-m-d');
        mysqli_stmt_bind_param($stmt, 's', $startDate);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $count);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
        return (int) $count;
    } catch (Throwable $e) {
        error_log('Rekap pengunjung gagal: '.$e->getMessage());
        return 0;
    }
}
