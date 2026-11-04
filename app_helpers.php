<?php

require_once __DIR__ . '/vendor/autoload.php';
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__);
    $dotenv->safeLoad();
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

function require_admin(): void
{
    start_app_session();
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }

    $role = $_SESSION['admin_role'] ?? 'super_admin';
    if (!in_array($role, ['super_admin', 'admin_unit'], true)
        || ($role === 'admin_unit' && empty($_SESSION['admin_unit_code']))) {
        http_response_code(403);
        exit('Akses admin tidak valid. Silakan masuk kembali.');
    }

    // Akun yang sudah dihapus super admin tidak boleh terus memakai sesinya.
    // Sebagian halaman memanggil fungsi ini sebelum koneksi dimuat.
    if (!isset($GLOBALS['conn'])) {
        require_once __DIR__.'/koneksi.php';
        $GLOBALS['conn'] = $conn;
    }
    $check = mysqli_prepare($GLOBALS['conn'], 'SELECT 1 FROM tbadmin WHERE id = ? LIMIT 1');
    $adminId = (int) $_SESSION['admin_id'];
    mysqli_stmt_bind_param($check, 'i', $adminId);
    mysqli_stmt_execute($check);
    $exists = mysqli_stmt_get_result($check)->num_rows > 0;
    mysqli_stmt_close($check);
    if (!$exists) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php');
        exit;
    }
}

function require_super_admin(): void
{
    require_admin();
    if (($_SESSION['admin_role'] ?? 'super_admin') !== 'super_admin') {
        http_response_code(403);
        exit('Fitur ini hanya dapat diakses super admin.');
    }
}

/** Memeriksa kolom hasil migrasi yang mungkin belum dijalankan di server. */
function ppdb_column_exists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table.'.'.$column;
    if (!isset($cache[$key])) {
        $stmt = mysqli_prepare($conn, 'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'ss', $table, $column);
        mysqli_stmt_execute($stmt);
        $cache[$key] = mysqli_stmt_get_result($stmt)->num_rows > 0;
        mysqli_stmt_close($stmt);
    }
    return $cache[$key];
}

/** Jenjang di dalam unit TK; setiap jenjang mempunyai kuota sendiri (migrasi 026). */
const PPDB_TK_LEVELS = ['TK A', 'TK B'];

function ppdb_table_exists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $stmt = mysqli_prepare($conn, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 's', $table);
        mysqli_stmt_execute($stmt);
        $cache[$table] = mysqli_stmt_get_result($stmt)->num_rows > 0;
        mysqli_stmt_close($stmt);
    }
    return $cache[$table];
}

/**
 * Kuota dan pendaftar aktif per jenjang TK untuk satu unit dan tahun:
 * ['TK A' => ['internal' => [kuota, terisi], 'eksternal' => [kuota, terisi]], ...].
 * Kosong bila kuota per jenjang belum diatur atau migrasinya belum dijalankan.
 */
function ppdb_level_quotas(mysqli $conn, string $unitCode, string $academicYear): array
{
    if (!ppdb_table_exists($conn, 'tb_kuota_tingkat') || !ppdb_column_exists($conn, 'tb_pendaftaran', 'tingkat_kelas')) {
        return [];
    }
    $stmt = mysqli_prepare($conn, 'SELECT tingkat_kelas,kuota_internal,kuota_eksternal FROM tb_kuota_tingkat WHERE kode_unit=? AND th_ajaran=?');
    mysqli_stmt_bind_param($stmt, 'ss', $unitCode, $academicYear);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    if (!$rows) {
        return [];
    }
    $levels = [];
    foreach (PPDB_TK_LEVELS as $level) {
        $levels[$level] = ['internal' => [0, 0], 'eksternal' => [0, 0]];
    }
    foreach ($rows as $row) {
        if (isset($levels[$row['tingkat_kelas']])) {
            $levels[$row['tingkat_kelas']]['internal'][0] = (int) $row['kuota_internal'];
            $levels[$row['tingkat_kelas']]['eksternal'][0] = (int) $row['kuota_eksternal'];
        }
    }
    $count = mysqli_prepare($conn, "SELECT tingkat_kelas,jalur_pendaftaran,COUNT(*) AS total FROM tb_pendaftaran WHERE kode_unit=? AND th_ajaran=? AND tingkat_kelas IS NOT NULL AND status_ppdb NOT IN ('ditolak','data_lama') GROUP BY tingkat_kelas,jalur_pendaftaran");
    mysqli_stmt_bind_param($count, 'ss', $unitCode, $academicYear);
    mysqli_stmt_execute($count);
    $result = mysqli_stmt_get_result($count);
    while ($row = mysqli_fetch_assoc($result)) {
        if (isset($levels[$row['tingkat_kelas']][$row['jalur_pendaftaran']])) {
            $levels[$row['tingkat_kelas']][$row['jalur_pendaftaran']][1] = (int) $row['total'];
        }
    }
    mysqli_stmt_close($count);
    return $levels;
}

/** Jenjang TK yang kuotanya pada jalur tertentu sudah habis. */
function ppdb_full_levels(array $levelQuotas, string $route): array
{
    $full = [];
    foreach ($levelQuotas as $level => $routes) {
        [$quota, $filled] = $routes[$route] ?? [0, 0];
        if ($filled >= $quota) {
            $full[] = $level;
        }
    }
    return $full;
}

function ppdb_is_super_admin(): bool
{
    return ($_SESSION['admin_role'] ?? 'super_admin') === 'super_admin';
}

function h($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    start_app_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token): bool
{
    start_app_session();
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function ppdb_normalize_whatsapp_number(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '0')) {
        $digits = '62'.substr($digits, 1);
    }
    return preg_match('/^628[0-9]{8,11}$/', $digits) ? $digits : '';
}

function ppdb_public_base_url(): string
{
    $configuredUrl = trim((string) getenv('PPDB_BASE_URL'));
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/D', $host)) {
        throw new RuntimeException('Alamat situs SPMB tidak dapat ditentukan.');
    }
    $hostname = strtolower(preg_replace('/:[0-9]+$/', '', $host) ?? $host);
    $isLocal = $hostname === 'localhost' || preg_match('/^127(?:\.[0-9]{1,3}){3}$/', $hostname);
    if ($isLocal) {
        if ($configuredUrl !== '') {
            $parts = parse_url($configuredUrl);
            if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new RuntimeException('Alamat situs SPMB belum dikonfigurasi dengan benar.');
            }
            $configuredHost = strtolower((string) $parts['host']);
            if ($configuredHost === 'localhost' || preg_match('/^127(?:\.[0-9]{1,3}){3}$/', $configuredHost)) {
                return rtrim($configuredUrl, '/');
            }
        }

        $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $basePath = $basePath === '/' || $basePath === '.' ? '' : rtrim($basePath, '/');
        return $scheme.'://'.$host.$basePath;
    }

    return 'https://cendekiatakengon.or.id';
}

function ppdb_unit_public_slug(array $unit): string
{
    $knownSlugs = [
        'KB-CEN' => 'kb-it-cendekia-takengon',
        'TPA-CEN' => 'tpa-it-cendekia-takengon',
        'TK-CEN' => 'tk-islam-terpadu-cendekia-takengon',
        'SD-CEN' => 'sd-it-cendekia-takengon',
        'SMP-CEN' => 'smp-it-cendekia-takengon',
    ];
    $code = strtoupper(trim((string) ($unit['kode_unit'] ?? '')));
    if (isset($knownSlugs[$code])) {
        return $knownSlugs[$code];
    }

    $name = strtolower(trim((string) ($unit['nama_unit'] ?? '')));
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $name) ?? '', '-');
    $codeSlug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($code)) ?? '', '-');
    return $slug !== '' ? $slug.'-'.($codeSlug ?: 'unit') : 'unit-'.($codeSlug ?: 'umum');
}

function ppdb_unit_public_path(array $unit): string
{
    return 'unit/'.rawurlencode(ppdb_unit_public_slug($unit));
}

function ppdb_is_valid_whatsapp_number(string $phone): bool
{
    return ppdb_normalize_whatsapp_number($phone) !== '';
}

function ppdb_display_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '0')) {
        $digits = '62'.substr($digits, 1);
    }
    if (str_starts_with($digits, '62')) {
        $local = substr($digits, 2);
        return '+62 '.trim(chunk_split($local, 4, ' '));
    }
    return $digits;
}

/** Nama unit di database ditulis kapital; ubah ke huruf judul tanpa merusak singkatan. */
function ppdb_unit_display_name(?string $name): string
{
    $words = preg_split('/\s+/', trim((string) $name)) ?: [];
    $acronyms = ['KB', 'TPA', 'TK', 'SD', 'SMP', 'IT'];
    return implode(' ', array_map(static function (string $word) use ($acronyms): string {
        $upper = mb_strtoupper($word);
        return in_array($upper, $acronyms, true) ? $upper : mb_convert_case($word, MB_CASE_TITLE);
    }, $words));
}

/** Kepanjangan jenjang untuk orang tua yang belum akrab dengan singkatannya. */
function ppdb_jenjang_label(?string $jenjang): string
{
    return [
        'KB' => 'Kelompok Bermain',
        'TPA' => 'Taman Penitipan Anak',
        'TK' => 'Taman Kanak-kanak',
        'SD' => 'Sekolah Dasar',
        'SMP' => 'Sekolah Menengah Pertama',
    ][(string) $jenjang] ?? (string) $jenjang;
}

/**
 * Petunjuk teknis SPMB disimpan sebagai JSON agar panitia dapat memperbaruinya
 * tanpa menyentuh kode. Mengembalikan array kosong bila berkas tidak ada atau rusak.
 */
function ppdb_technical_guide(): array
{
    $path = __DIR__.'/petunjuk_teknis_spmb_wakaf_cendekia.json';
    if (!is_file($path)) {
        return [];
    }
    $guide = json_decode((string) file_get_contents($path), true);
    if (!is_array($guide)) {
        return [];
    }

    $result = ['programs' => [], 'ages' => [], 'documents' => [], 'instructions' => []];
    foreach ($guide['sections'] ?? [] as $section) {
        if (!empty($section['programs'])) {
            $result['programs'] = array_values(array_filter($section['programs'], 'is_string'));
        }
        foreach ($section['instructions'] ?? [] as $instruction) {
            if (!empty($instruction['ageGuidelines'])) {
                $result['ages'] = $instruction['ageGuidelines'];
            }
            if (!empty($instruction['requiredDocuments'])) {
                $result['documents'] = array_values(array_filter($instruction['requiredDocuments'], 'is_string'));
            }
            if (!empty($instruction['title'])) {
                $result['instructions'][] = $instruction;
            }
        }
    }
    return $result;
}

function ppdb_format_date_id(?string $date): string
{
    if (!$date || $date === '0000-00-00') {
        return '-';
    }
    $months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return '-';
    }
    return (int) date('j', $timestamp).' '.$months[(int) date('n', $timestamp)].' '.date('Y', $timestamp);
}

/** Tanggal dan jam WIB, contoh "30 Oktober 2026 pukul 08.00 WIB". */
function ppdb_format_datetime_id(?string $dateTime): string
{
    $timestamp = $dateTime ? strtotime($dateTime) : false;
    if ($timestamp === false) {
        return '-';
    }
    return ppdb_format_date_id($dateTime).' pukul '.date('H.i', $timestamp).' WIB';
}

/** Tanggal dan jam ringkas, contoh "30 Okt 2026 08.00". */
function ppdb_format_datetime_short(string $dateTime): string
{
    $months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $timestamp = strtotime($dateTime);
    return $timestamp === false ? '-' : (int) date('j', $timestamp).' '.$months[(int) date('n', $timestamp)].' '.date('Y H.i', $timestamp);
}

/** Rentang jadwal pendaftaran untuk ditampilkan; kosong bila unit tidak memakai jadwal. */
function ppdb_format_schedule_range(?string $opensAt, ?string $closesAt): string
{
    if ($opensAt && $closesAt) {
        return ppdb_format_datetime_short($opensAt).' – '.ppdb_format_datetime_short($closesAt).' WIB';
    }
    if ($opensAt) {
        return 'Mulai '.ppdb_format_datetime_short($opensAt).' WIB';
    }
    return $closesAt ? 'Sampai '.ppdb_format_datetime_short($closesAt).' WIB' : '';
}

/** Waktu sekarang dalam WIB; jadwal buka/tutup disimpan sebagai jam WIB. */
function ppdb_now_wib(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
}

/**
 * Syarat SQL bahwa sekarang berada di dalam jadwal buka-tutup unit (migrasi 027).
 * $alias adalah alias tabel tb_pengaturan_ppdb, kosongkan bila tanpa alias.
 * Bila kolom jadwal belum ada, syaratnya selalu terpenuhi.
 */
function ppdb_schedule_open_sql(mysqli $conn, string $alias = 'q'): string
{
    if (!ppdb_column_exists($conn, 'tb_pengaturan_ppdb', 'jadwal_buka')) {
        return '1';
    }
    $prefix = $alias === '' ? '' : $alias.'.';
    $now = "'".ppdb_now_wib()."'";
    return '(('.$prefix.'jadwal_buka IS NULL OR '.$prefix.'jadwal_buka<='.$now.') AND ('.$prefix.'jadwal_tutup IS NULL OR '.$prefix.'jadwal_tutup>'.$now.'))';
}

/** Kolom SQL status jalur yang sudah memperhitungkan jadwal, misalnya "(q.eksternal_dibuka=1 AND ...) AS eksternal_dibuka". */
function ppdb_route_open_sql(mysqli $conn, string $column, string $alias = 'q'): string
{
    $prefix = $alias === '' ? '' : $alias.'.';
    return '('.$prefix.$column.'=1 AND '.ppdb_schedule_open_sql($conn, $alias).') AS '.$column;
}

/** Kolom jadwal untuk SELECT; bernilai NULL bila migrasi 027 belum dijalankan. */
function ppdb_schedule_columns_sql(mysqli $conn, string $alias = 'q'): string
{
    if (!ppdb_column_exists($conn, 'tb_pengaturan_ppdb', 'jadwal_buka')) {
        return 'NULL AS jadwal_buka,NULL AS jadwal_tutup';
    }
    $prefix = $alias === '' ? '' : $alias.'.';
    return $prefix.'jadwal_buka,'.$prefix.'jadwal_tutup';
}

/** Pesan penutupan jalur yang menyebut jadwal unit bila pendaftaran belum dimulai atau sudah berakhir. */
function ppdb_schedule_closed_message(?array $setting, string $default): string
{
    $phase = ppdb_schedule_phase($setting['jadwal_buka'] ?? null, $setting['jadwal_tutup'] ?? null);
    if ($phase === 'belum') {
        return 'Pendaftaran unit ini baru dibuka '.ppdb_format_datetime_id($setting['jadwal_buka']).'.';
    }
    if ($phase === 'selesai') {
        return 'Pendaftaran unit ini telah ditutup '.ppdb_format_datetime_id($setting['jadwal_tutup']).'.';
    }
    return $default;
}

/** Posisi sekarang terhadap jadwal: 'belum' (sebelum buka), 'selesai' (lewat tutup), atau 'berjalan'. */
function ppdb_schedule_phase(?string $opensAt, ?string $closesAt): string
{
    $now = ppdb_now_wib();
    if ($opensAt && $now < $opensAt) {
        return 'belum';
    }
    if ($closesAt && $now >= $closesAt) {
        return 'selesai';
    }
    return 'berjalan';
}

function ppdb_dapodik_age(?string $birthDate, ?string $academicYear): ?array
{
    if (!$birthDate || !preg_match('/^([0-9]{4})\/[0-9]{4}$/', (string) $academicYear, $matches)) {
        return null;
    }

    $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
    $cutoff = DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1].'-06-30');
    if (!$birth || !$cutoff || $birth->format('Y-m-d') !== $birthDate || $cutoff->format('Y-m-d') !== $matches[1].'-06-30') {
        return null;
    }

    if ($birth > $cutoff) {
        return ['reference_date' => $cutoff->format('d/m/Y'), 'years' => null, 'months' => null, 'days' => null];
    }

    $difference = $birth->diff($cutoff);
    return [
        'reference_date' => $cutoff->format('d/m/Y'),
        'years' => $difference->y,
        'months' => $difference->m,
        'days' => $difference->d,
    ];
}

function ppdb_dapodik_age_label(?array $age): string
{
    if (!$age) {
        return '-';
    }
    if ($age['years'] === null) {
        return 'Tanggal lahir setelah tanggal acuan';
    }
    return $age['years'].' tahun, '.$age['months'].' bulan, '.$age['days'].' hari';
}

function ppdb_pendaftar_label(?string $value): string
{
    $labels = [
        'Ayah' => 'Ayah',
        'Bunda' => 'Bunda',
        'Ayah dan Bunda' => 'Ayah dan Bunda',
        'Wali' => 'Wali',
    ];
    return $value !== null && isset($labels[$value]) ? $labels[$value] : '-';
}

function ppdb_jenis_kelamin_label(?string $value): string
{
    if ($value === 'laki-laki') {
        return 'Laki-laki';
    }
    if ($value === 'perempuan') {
        return 'Perempuan';
    }
    return '-';
}

/**
 * Satu kamus status dipakai bersama oleh tampilan publik, pesan WhatsApp,
 * dan tombol admin agar keterangan selalu konsisten.
 */
function ppdb_status_catalog(): array
{
    return [
        'menunggu_kontak' => [
            'label' => 'Belum melanjutkan pendaftaran',
            'short' => 'Belum lanjut',
            'step' => 1,
            'tone' => 'neutral',
            'public' => 'Data awal diterima. Silakan lanjutkan ke tahap berikutnya.',
        ],
        'menunggu_pembayaran' => [
            'label' => 'Menunggu bukti pembayaran',
            'short' => 'Menunggu bayar',
            'step' => 2,
            'tone' => 'warn',
            'public' => 'Silakan lakukan pembayaran dan unggah bukti transfer pada halaman ini.',
        ],
        'pembayaran_diperiksa' => [
            'label' => 'Bukti pembayaran sedang diperiksa',
            'short' => 'Cek bayar',
            'step' => 2,
            'tone' => 'warn',
            'public' => 'Bukti pembayaran sudah diterima. Silakan lanjutkan mengisi formulir sambil panitia memeriksanya.',
        ],
        'pembayaran_terverifikasi' => [
            'label' => 'Pembayaran disetujui, formulir menunggu',
            'short' => 'Menunggu formulir',
            'step' => 3,
            'tone' => 'info',
            'public' => 'Pembayaran Anda disetujui. Silakan lanjutkan mengisi formulir lengkap.',
        ],
        'menunggu_formulir' => [
            'label' => 'Menunggu formulir lengkap',
            'short' => 'Menunggu formulir',
            'step' => 3,
            'tone' => 'info',
            'public' => 'Silakan lanjutkan mengisi formulir lengkap.',
        ],
        'formulir_terisi' => [
            'label' => 'Formulir lengkap sedang diperiksa',
            'short' => 'Cek formulir',
            'step' => 4,
            'tone' => 'ok',
            'public' => 'Formulir lengkap Anda sedang diperiksa panitia.',
        ],
        'diterima' => [
            'label' => 'Diterima',
            'short' => 'Diterima',
            'step' => 5,
            'tone' => 'ok',
            'public' => 'Selamat, ananda telah diterima.',
        ],
        'daftar_tunggu' => [
            'label' => 'Daftar tunggu kuota',
            'short' => 'Daftar tunggu',
            'step' => 5,
            'tone' => 'warn',
            'public' => 'Formulir Anda sudah lengkap, tetapi kursi pada jalur ini sedang penuh. Ananda masuk daftar tunggu dan hasilnya akan tampil di halaman ini bila kursi tersedia.',
        ],
        'ditolak' => [
            'label' => 'Perlu tindak lanjut panitia',
            'short' => 'Tindak lanjut',
            'step' => 5,
            'tone' => 'danger',
            'public' => 'Pendaftaran Anda memerlukan tindak lanjut. Hubungi panitia SPMB.',
        ],
        'data_lama' => [
            'label' => 'Data arsip lama',
            'short' => 'Arsip',
            'step' => 0,
            'tone' => 'neutral',
            'public' => 'Data ini merupakan arsip pendaftaran lama.',
        ],
    ];
}

/**
 * Jumlah tahap yang benar-benar dilalui pendaftaran yang ditolak, agar tahap yang
 * belum dikerjakan tidak ikut tampil selesai. Kolom alamat baru terisi saat formulir dikirim.
 */
function ppdb_rejected_completed_steps(array $registration): int
{
    if (!in_array($registration['status_pembayaran'] ?? '', ['terverifikasi', 'menunggu_verifikasi'], true)) {
        return 1;
    }
    if (trim((string) ($registration['alamat'] ?? '')) === '') {
        return 2;
    }
    return !empty($registration['data_diperiksa_pada']) && ($registration['status_data'] ?? '') === 'perlu_perbaikan' ? 4 : 3;
}

function ppdb_status_info(?string $status): array
{
    $catalog = ppdb_status_catalog();
    return $catalog[$status] ?? [
        'label' => 'Status diperbarui panitia',
        'short' => 'Diproses',
        'step' => 0,
        'tone' => 'neutral',
        'public' => 'Status pendaftaran akan diperbarui panitia.',
    ];
}

/** Lokasi bersama untuk bukti pembayaran dan berkas privat SPMB. */
function ppdb_payment_storage_root(): string
{
    $configured = trim((string) (getenv('PPDB_PAYMENT_STORAGE') ?: ''));
    $configuredRoot = null;
    if ($configured !== '') {
        if (DIRECTORY_SEPARATOR === '\\' || !preg_match('/^[A-Za-z]:[\\\\\/]/', $configured)) {
            $configuredRoot = rtrim(str_replace('\\', '/', $configured), '/');
            if (is_dir($configuredRoot)) {
                return $configuredRoot;
            }
        } else {
            error_log('PPDB_PAYMENT_STORAGE uses a Windows path on a non-Windows server; using the platform default.');
        }
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        return $configuredRoot ?? 'C:/xampp/private/ppdb-payment-proofs';
    }

    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== false) {
        $ancestor = $documentRoot;
        for ($depth = 0; $depth < 8; $depth++) {
            $candidate = rtrim($ancestor, '/\\').'/private/ppdb-payment-proofs';
            if (is_dir($candidate)) {
                return $candidate;
            }

            if (strtolower(basename($ancestor)) === 'domains') {
                return dirname($ancestor).'/private/ppdb-payment-proofs';
            }

            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                break;
            }
            $ancestor = $parent;
        }

        if ($configuredRoot !== null) {
            return $configuredRoot;
        }
        return dirname($documentRoot).'/private/ppdb-payment-proofs';
    }

    if ($configuredRoot !== null) {
        return $configuredRoot;
    }
    return rtrim(sys_get_temp_dir(), '/\\').'/ppdb-payment-proofs-'.substr(hash('sha256', __DIR__), 0, 16);
}

/** Menyimpan berkas privat di luar document root dan mengembalikan metadata. */
function ppdb_store_private_upload(array $file, string $subDirectory, array $allowedMimes, int $maxBytes): array
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Berkas belum lengkap atau gagal diunggah.');
    }
    if ((int) $file['size'] < 1 || (int) $file['size'] > $maxBytes) {
        throw new RuntimeException('Ukuran berkas melebihi batas '.round($maxBytes / 1048576, 1).' MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($file['tmp_name']);
    if (!isset($allowedMimes[$mimeType])) {
        throw new RuntimeException('Format berkas tidak didukung: '.implode(', ', array_values($allowedMimes)).'.');
    }

    $root = ppdb_payment_storage_root();
    $targetDirectory = rtrim(str_replace('\\', '/', $root), '/').'/'.trim($subDirectory, '/');
    if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0700, true) && !is_dir($targetDirectory)) {
        $error = error_get_last();
        error_log('SPMB private upload directory creation failed: '.$targetDirectory.'; '.($error['message'] ?? 'unknown filesystem error'));
        throw new RuntimeException('Penyimpanan berkas sedang tidak tersedia. Hubungi panitia.');
    }
    if (!is_writable($targetDirectory)) {
        error_log('SPMB private upload directory is not writable: '.$targetDirectory);
        throw new RuntimeException('Penyimpanan berkas sedang tidak tersedia. Hubungi panitia.');
    }
    $realTargetDirectory = realpath($targetDirectory);
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    if (!$realTargetDirectory) {
        throw new RuntimeException('Penyimpanan berkas belum dikonfigurasi dengan benar.');
    }
    if ($documentRoot && strpos(strtolower($realTargetDirectory), strtolower($documentRoot.DIRECTORY_SEPARATOR)) === 0) {
        throw new RuntimeException('Penyimpanan berkas harus berada di luar folder publik situs.');
    }

    $storedName = bin2hex(random_bytes(24)).'.'.$allowedMimes[$mimeType];
    $storedPath = $realTargetDirectory.DIRECTORY_SEPARATOR.$storedName;
    if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
        throw new RuntimeException('Berkas belum dapat disimpan. Silakan coba lagi.');
    }

    return [
        'original_name' => basename((string) ($file['name'] ?? 'berkas')),
        'path' => $storedPath,
        'mime' => $mimeType,
        'size' => (int) $file['size'],
    ];
}

/** Mencegah path traversal saat admin membuka berkas pendaftar. */
function ppdb_resolve_stored_file(string $subDirectory, string $storedPath): ?string
{
    $root = ppdb_payment_storage_root();
    $realBase = realpath(rtrim(str_replace('\\', '/', $root), '/').'/'.trim($subDirectory, '/'));
    $realFile = realpath($storedPath);
    if (!$realBase || !$realFile) {
        return null;
    }
    if (strpos(strtolower($realFile), strtolower($realBase.DIRECTORY_SEPARATOR)) !== 0) {
        return null;
    }
    return $realFile;
}

/**
 * Membangun tautan WhatsApp (wa.me) tanpa layanan pihak ketiga.
 * Nomor tujuan harus sudah dalam format 62xxx.
 */
function ppdb_whatsapp_link(string $phone, string $message): string
{
    $number = ppdb_normalize_whatsapp_number($phone);
    if ($number === '') {
        return '';
    }
    return 'https://wa.me/'.$number.'?text='.rawurlencode($message);
}

/** Ringkasan data pendaftar sesuai hak admin; nilai rev berubah bila ada perubahan data atau status. */
function ppdb_admin_live_state(mysqli $conn): ?array
{
    try {
        $sql = 'SELECT status_ppdb,status_pembayaran,COUNT(*) AS total,MAX(waktu_daftar) AS terakhir FROM tb_pendaftaran';
        if (ppdb_is_super_admin()) {
            $stmt = mysqli_prepare($conn, $sql.' GROUP BY status_ppdb,status_pembayaran');
        } else {
            $unitCode = (string) $_SESSION['admin_unit_code'];
            $stmt = mysqli_prepare($conn, $sql.' WHERE kode_unit=? GROUP BY status_ppdb,status_pembayaran');
            mysqli_stmt_bind_param($stmt, 's', $unitCode);
        }
        mysqli_stmt_execute($stmt);
        $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
    } catch (Throwable $exception) {
        error_log('Ringkasan pembaruan admin gagal: '.$exception->getMessage());
        return null;
    }
    $total = 0;
    $proof = 0;
    foreach ($rows as $row) {
        $total += (int) $row['total'];
        if ($row['status_pembayaran'] === 'menunggu_verifikasi' && $row['status_ppdb'] !== 'ditolak') {
            $proof += (int) $row['total'];
        }
    }
    return ['rev' => md5((string) json_encode($rows)), 'total' => $total, 'proof' => $proof];
}

/** Spreadsheet perlu diperbarui: ada antrean baru, atau antrean gagal yang sudah lewat 15 menit. */
function ppdb_admin_sync_is_due(mysqli $conn): bool
{
    try {
        $result = mysqli_query($conn, "SELECT 1 FROM tb_google_sync_outbox WHERE status='pending' OR (status='failed' AND (last_attempt_at IS NULL OR last_attempt_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE))) LIMIT 1");
        return $result && mysqli_num_rows($result) > 0;
    } catch (Throwable $exception) {
        return false;
    }
}

/** Kontak resmi admin SPMB untuk ditampilkan dan dicantumkan pada pesan pendaftar. */
function ppdb_admin_whatsapp_number(): string
{
    return '6285184839694';
}

function ppdb_admin_whatsapp_display(): string
{
    return '0851 8483 9694';
}

function ppdb_admin_whatsapp_url(string $message = ''): string
{
    $url = 'https://wa.me/'.ppdb_admin_whatsapp_number();
    return $message === '' ? $url : $url.'?text='.rawurlencode($message);
}

function ppdb_admin_contact_message(): string
{
    return 'Bantuan panitia SPMB: '.ppdb_admin_whatsapp_display().' ('.ppdb_admin_whatsapp_url().')';
}

/** Daftar nomor pendaftar beserta perannya, tanpa duplikat dan tanpa yang kosong. */
function ppdb_registrant_recipients(array $registration): array
{
    $sources = [
        'Pendaftar' => (string) ($registration['no_hp_pendaftar'] ?? $registration['no_hp'] ?? ''),
        'Ayah' => (string) ($registration['no_hp_ayah'] ?? ''),
        'Ibu' => (string) ($registration['no_hp_ibu'] ?? ''),
    ];
    $recipients = [];
    foreach ($sources as $role => $phone) {
        $number = ppdb_normalize_whatsapp_number((string) $phone);
        if ($number === '' || isset($recipients[$number])) {
            continue;
        }
        $recipients[$number] = $role;
    }
    return $recipients;
}

/** Nomor surat keterangan diterima, misalnya 00046/SPMB-KB/YWCT/XI/2026. */
function ppdb_acceptance_number(array $registration): string
{
    $issuedAt = strtotime((string) ($registration['data_diperiksa_pada'] ?? '')) ?: time();
    $months = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    return substr((string) $registration['id_pendaftaran'], -5).'/SPMB-'.($registration['jenjang'] ?? 'UNIT').'/YWCT/'.$months[(int) date('n', $issuedAt) - 1].'/'.date('Y', $issuedAt);
}

/** Tanda keaslian tautan verifikasi; kuncinya hash token yang tidak pernah tampil ke publik. */
function ppdb_acceptance_signature(array $registration): string
{
    return substr(hash_hmac('sha256', 'surat-diterima|'.$registration['id_pendaftaran'], (string) ($registration['token_bukti'] ?? '')), 0, 20);
}

/** Tata tertib pada surat keterangan diterima, dibedakan untuk usia dini dan sekolah. */
function ppdb_acceptance_rules(?string $jenjang): array
{
    if (in_array($jenjang, ['KB', 'TPA', 'TK'], true)) {
        return [
            'Orang tua/wali mengantar dan menjemput ananda tepat waktu sesuai jadwal kegiatan.',
            'Ananda mengenakan seragam atau pakaian yang rapi, bersih, dan menutup aurat sesuai ketentuan sekolah.',
            'Orang tua/wali membiasakan adab dan akhlak islami serta bekerja sama dengan pendidik dalam pembinaan ananda.',
            'Orang tua/wali memberi tahu sekolah apabila ananda sakit atau berhalangan hadir, dan tidak mengantar ananda dalam keadaan sakit menular.',
            'Ananda tidak membawa benda berbahaya atau barang berharga ke sekolah.',
            'Orang tua/wali menjaga kebersihan dan fasilitas sekolah, serta menyelesaikan kewajiban administrasi sesuai rincian biaya yang berlaku.',
        ];
    }
    return [
        'Peserta didik hadir tepat waktu dan mengikuti seluruh kegiatan sesuai kalender pendidikan sekolah.',
        'Peserta didik mengenakan seragam lengkap, rapi, dan menutup aurat sesuai ketentuan sekolah.',
        'Peserta didik menjunjung tinggi adab dan akhlak islami serta menghormati pendidik, tenaga kependidikan, dan sesama peserta didik.',
        'Peserta didik dilarang melakukan perundungan, perkelahian, atau kekerasan dalam bentuk apa pun.',
        'Peserta didik dilarang membawa atau menggunakan rokok, narkoba, senjata tajam, dan benda terlarang lainnya.',
        'Peserta didik menjaga kebersihan dan fasilitas sekolah, serta orang tua/wali menyelesaikan kewajiban administrasi sesuai rincian biaya yang berlaku.',
    ];
}

/**
 * Pendaftar yang sudah mengirim formulir dan bukti transfer langsung diterima selama
 * kursi jalurnya tersedia; bila kursi habis, masuk daftar tunggu. Dipanggil di dalam transaksi.
 */
function ppdb_finalize_registration(mysqli $conn, string $registrationId): void
{
    $lock = mysqli_prepare($conn, 'SELECT p.status_ppdb,p.status_pembayaran,p.kode_unit,p.th_ajaran,p.jalur_pendaftaran,c.kuota_internal,c.kuota_eksternal FROM tb_pendaftaran p LEFT JOIN tb_pengaturan_ppdb c ON c.kode_unit=p.kode_unit AND c.th_ajaran=p.th_ajaran WHERE p.id_pendaftaran=? FOR UPDATE');
    mysqli_stmt_bind_param($lock, 's', $registrationId);
    mysqli_stmt_execute($lock);
    $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
    mysqli_stmt_close($lock);
    if (!$locked || $locked['status_ppdb'] !== 'formulir_terisi' || !in_array($locked['status_pembayaran'], ['terverifikasi', 'menunggu_verifikasi'], true)) {
        return;
    }

    $acceptedQuery = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM tb_pendaftaran WHERE kode_unit=? AND th_ajaran=? AND jalur_pendaftaran=? AND status_ppdb='diterima'");
    mysqli_stmt_bind_param($acceptedQuery, 'sss', $locked['kode_unit'], $locked['th_ajaran'], $locked['jalur_pendaftaran']);
    mysqli_stmt_execute($acceptedQuery);
    $accepted = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($acceptedQuery))['total'];
    mysqli_stmt_close($acceptedQuery);

    $quota = (int) ($locked['jalur_pendaftaran'] === 'internal' ? $locked['kuota_internal'] : $locked['kuota_eksternal']);
    $newStatus = $accepted < $quota ? 'diterima' : 'daftar_tunggu';
    $update = mysqli_prepare($conn, 'UPDATE tb_pendaftaran SET status_ppdb=?,data_diperiksa_oleh=NULL,data_diperiksa_pada=NOW() WHERE id_pendaftaran=?');
    mysqli_stmt_bind_param($update, 'ss', $newStatus, $registrationId);
    mysqli_stmt_execute($update);
    mysqli_stmt_close($update);
}

/**
 * Pendaftar langsung melanjutkan sendiri setelah registrasi awal: masuk ke pembayaran
 * dengan batas waktunya, atau langsung ke formulir bila unit tidak memungut biaya.
 */
function ppdb_activate_registration(mysqli $conn, string $registrationId): bool
{
    $update = mysqli_prepare($conn, "UPDATE tb_pendaftaran p LEFT JOIN tb_pengaturan_ppdb c ON c.kode_unit=p.kode_unit AND c.th_ajaran=p.th_ajaran
        SET p.tanggal_batas_bayar=IF(COALESCE(c.biaya_pendaftaran,0)>0,COALESCE(p.tanggal_batas_bayar,DATE_ADD(CURDATE(),INTERVAL GREATEST(1,COALESCE(c.masa_pembayaran_hari,1)) DAY)),p.tanggal_batas_bayar),
            p.status_pembayaran=IF(COALESCE(c.biaya_pendaftaran,0)>0,'belum_bayar','terverifikasi'),
            p.status_ppdb=IF(COALESCE(c.biaya_pendaftaran,0)>0,'menunggu_pembayaran','menunggu_formulir')
        WHERE p.id_pendaftaran=? AND p.status_ppdb='menunggu_kontak'");
    mysqli_stmt_bind_param($update, 's', $registrationId);
    mysqli_stmt_execute($update);
    $changed = mysqli_stmt_affected_rows($update) > 0;
    mysqli_stmt_close($update);
    return $changed;
}

/**
 * Tautan perbaikan saat panitia membuka kembali formulir: token pertama disimpan
 * dan tetap berlaku, lalu teks WhatsApp disiapkan untuk dibuka admin.
 */
function ppdb_prepare_formulir_link(mysqli $conn, string $registrationId, string $revisionNote = ''): array
{
    $lookup = mysqli_prepare($conn, 'SELECT p.status_ppdb,p.nm_peserta,p.no_hp,p.no_hp_pendaftar,p.no_hp_ayah,p.no_hp_ibu FROM tb_pendaftaran p WHERE p.id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($lookup, 's', $registrationId);
    mysqli_stmt_execute($lookup);
    $registration = mysqli_fetch_assoc(mysqli_stmt_get_result($lookup));
    mysqli_stmt_close($lookup);
    if (!$registration) {
        throw new RuntimeException('Data pendaftaran tidak ditemukan.');
    }
    if (!in_array($registration['status_ppdb'], ['menunggu_formulir', 'diterima'], true)) {
        throw new RuntimeException('Formulir hanya dapat dibuka kembali setelah pendaftar mengisi formulir.');
    }

    $formToken = bin2hex(random_bytes(32));
    $formTokenHash = hash('sha256', $formToken);
    $tokenUpdate = mysqli_prepare($conn, 'UPDATE tb_pendaftaran SET token_formulir=COALESCE(token_formulir,?),token_whatsapp=? WHERE id_pendaftaran=?');
    mysqli_stmt_bind_param($tokenUpdate, 'sss', $formTokenHash, $formTokenHash, $registrationId);
    if (!mysqli_stmt_execute($tokenUpdate)) {
        mysqli_stmt_close($tokenUpdate);
        throw new RuntimeException('Token formulir belum dapat disimpan.');
    }
    mysqli_stmt_close($tokenUpdate);

    $formUrl = ppdb_public_base_url().'/formulir.php?'.http_build_query([
        'kode' => $registrationId,
        'token' => $formToken,
    ], '', '&', PHP_QUERY_RFC3986);

    $lines = [
        'Assalamu’alaikum warahmatullahi wabarakatuh.',
        '',
        'Yth. Ayah/Bunda/Wali dari Ananda '.$registration['nm_peserta'].',',
        'Panitia SPMB Yayasan Wakaf Cendekia Takengon membuka kembali formulir pendaftaran Ananda untuk diperbaiki.',
        'Kode pendaftaran: '.$registrationId,
    ];
    if ($revisionNote !== '') {
        $lines[] = '';
        $lines[] = 'Catatan perbaikan dari panitia: '.$revisionNote;
    }
    array_push(
        $lines,
        '',
        'Isian yang telah dikirim sebelumnya tetap tersimpan. Mohon Ayah/Bunda memperbaiki bagian yang diminta, memeriksa kembali seluruh data, kemudian mengirimkan ulang formulir melalui tautan berikut:',
        $formUrl,
        '',
        'Terima kasih atas perhatian dan kerja sama Ayah/Bunda.',
        'Wassalamu’alaikum warahmatullahi wabarakatuh.'
    );

    $lines[] = '';
    $lines[] = ppdb_admin_contact_message();

    $recipients = ppdb_registrant_recipients($registration);
    if ($recipients === []) {
        throw new RuntimeException('Nomor WhatsApp pendaftar tidak valid.');
    }

    $links = [];
    foreach ($recipients as $number => $role) {
        $links[] = [
            'role' => $role,
            'phone' => $number,
            'display' => ppdb_display_phone($number),
            'url' => ppdb_whatsapp_link($number, implode("\n", $lines)),
        ];
    }

    return [
        'status_url' => $formUrl,
        'message' => implode("\n", $lines),
        'links' => $links,
    ];
}
