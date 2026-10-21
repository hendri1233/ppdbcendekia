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

/** Lokasi berkas GeoLite2-City.mmdb; atur lewat GEOIP_DB_PATH di .env. */
function ppdb_geoip_database_path(): string
{
    return trim((string) getenv('GEOIP_DB_PATH')) ?: __DIR__.'/storage/geoip/GeoLite2-City.mmdb';
}

function ppdb_geoip_ready(): bool
{
    return is_file(ppdb_geoip_database_path()) && class_exists(\MaxMind\Db\Reader::class);
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
 * Titik peta pengakses tautan selama $days hari terakhir, dikelompokkan per
 * lokasi: kota, jumlah pengunjung berbeda, rincian per unit, dan apakah ada
 * yang membuka hari ini. $unitCode kosong berarti semua unit.
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
            $key = $row['lat'].','.$row['lon'];
            $points[$key] ??= [
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
                'place' => implode(', ', array_filter([$row['kota'], $row['wilayah']])) ?: 'Lokasi tanpa nama',
                'visitors' => 0,
                'today' => false,
                'units' => [],
            ];
            $points[$key]['visitors'] += (int) $row['visitors'];
            $points[$key]['today'] = $points[$key]['today'] || (bool) $row['today'];
            $points[$key]['units'][] = ['name' => ppdb_unit_display_name($row['nama_unit']), 'visitors' => (int) $row['visitors']];
        }
        mysqli_stmt_close($stmt);
    } catch (Throwable $e) {
        error_log('Rekap lokasi akses tautan gagal: '.$e->getMessage());
    }
    $points = array_values($points);
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
