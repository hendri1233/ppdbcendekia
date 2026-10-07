<?php
/**
 * Statistik publik: pencatatan kunjungan web dan rekap kunjungan harian.
 * Pengunjung dikenali lewat cookie acak yang di-hash; IP tidak disimpan.
 */

function ppdb_stats_today(): DateTimeImmutable
{
    return new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'));
}

/** Catat satu tayangan halaman. Wajib dipanggil sebelum ada output. */
function ppdb_track_visit(mysqli $conn): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($userAgent === '' || preg_match('/bot|crawl|spider|slurp|preview|monitor|curl|wget|python|headless/i', $userAgent)) {
        return;
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
    }

    try {
        $stmt = mysqli_prepare($conn, 'INSERT INTO tb_kunjungan_web (tanggal, visitor_hash) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE jumlah_tayang = jumlah_tayang + 1');
        $date = ppdb_stats_today()->format('Y-m-d');
        $hash = hash('sha256', $visitorId);
        mysqli_stmt_bind_param($stmt, 'ss', $date, $hash);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } catch (Throwable $e) {
        error_log('Pencatatan kunjungan gagal: '.$e->getMessage());
    }
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
