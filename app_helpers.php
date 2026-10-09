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
}

function require_super_admin(): void
{
    require_admin();
    if (($_SESSION['admin_role'] ?? 'super_admin') !== 'super_admin') {
        http_response_code(403);
        exit('Fitur ini hanya dapat diakses super admin.');
    }
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
    if ($configuredUrl !== '') {
        $parts = parse_url($configuredUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Alamat situs PPDB belum dikonfigurasi dengan benar.');
        }
        return rtrim($configuredUrl, '/');
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/D', $host)) {
        throw new RuntimeException('Alamat situs PPDB tidak dapat ditentukan.');
    }
    $hostname = strtolower(preg_replace('/:[0-9]+$/', '', $host) ?? $host);
    $isLocal = $hostname === 'localhost' || preg_match('/^127(?:\.[0-9]{1,3}){3}$/', $hostname);
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $basePath = $basePath === '/' || $basePath === '.' ? '' : rtrim($basePath, '/');
    if ($isLocal) {
        // Server lokal tetap boleh membuat tautan agar admin bisa menguji alur.
        return $scheme.'://'.$host.$basePath;
    }
    return $scheme.'://'.$host.$basePath;
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
            'label' => 'Menunggu menghubungi pendaftar',
            'short' => 'Menunggu kontak',
            'step' => 1,
            'tone' => 'neutral',
            'public' => 'Data awal diterima. Panitia akan menghubungi nomor WhatsApp Anda untuk proses pembayaran.',
        ],
        'menunggu_pembayaran' => [
            'label' => 'Menunggu bukti pembayaran',
            'short' => 'Menunggu bayar',
            'step' => 2,
            'tone' => 'warn',
            'public' => 'Silakan unggah bukti pembayaran pada halaman status pendaftaran.',
        ],
        'pembayaran_diperiksa' => [
            'label' => 'Bukti pembayaran sedang diperiksa',
            'short' => 'Cek bayar',
            'step' => 2,
            'tone' => 'warn',
            'public' => 'Bukti pembayaran Anda sudah diterima dan sedang diperiksa panitia.',
        ],
        'pembayaran_terverifikasi' => [
            'label' => 'Pembayaran disetujui, formulir menunggu',
            'short' => 'Menunggu formulir',
            'step' => 3,
            'tone' => 'info',
            'public' => 'Pembayaran Anda disetujui. Gunakan kode pendaftaran untuk mengisi formulir lengkap.',
        ],
        'menunggu_formulir' => [
            'label' => 'Menunggu formulir lengkap',
            'short' => 'Menunggu formulir',
            'step' => 3,
            'tone' => 'info',
            'public' => 'Pembayaran disetujui. Tautan formulir lengkap sudah dikirim melalui WhatsApp.',
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
            'public' => 'Selamat, pendaftaran Anda telah diterima.',
        ],
        'daftar_tunggu' => [
            'label' => 'Daftar tunggu kuota',
            'short' => 'Daftar tunggu',
            'step' => 5,
            'tone' => 'warn',
            'public' => 'Anda berada pada daftar tunggu. Panitia akan menghubungi bila tersedia kursi.',
        ],
        'ditolak' => [
            'label' => 'Perlu tindak lanjut panitia',
            'short' => 'Tindak lanjut',
            'step' => 5,
            'tone' => 'danger',
            'public' => 'Pendaftaran Anda memerlukan tindak lanjut. Hubungi panitia PPDB.',
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

    $root = getenv('PPDB_PAYMENT_STORAGE') ?: 'C:/xampp/private/ppdb-payment-proofs';
    $targetDirectory = rtrim(str_replace('\\', '/', $root), '/').'/'.trim($subDirectory, '/');
    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0700, true) && !is_dir($targetDirectory)) {
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
    $root = getenv('PPDB_PAYMENT_STORAGE') ?: 'C:/xampp/private/ppdb-payment-proofs';
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

/**
 * Tautan pembayaran dibuat secara lokal di server: token baru dibuat, status
 * diubah menjadi menunggu pembayaran, lalu teks WhatsApp disiapkan untuk
 * dibuka admin lewat wa.me ke nomor pendaftar.
 */
function ppdb_prepare_payment_link(mysqli $conn, string $registrationId): array
{
    $lookup = mysqli_prepare($conn, 'SELECT p.status_ppdb,p.nm_peserta,p.tgl_lahir,p.jenis_kelamin,p.no_hp,p.no_hp_pendaftar,p.no_hp_ayah,p.no_hp_ibu,p.tanggal_batas_bayar,p.kode_unit,p.th_ajaran,u.nama_unit,q.biaya_pendaftaran,q.bank_nama,q.nomor_rekening,q.nama_pemilik_rekening FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit LEFT JOIN tb_pengaturan_ppdb q ON q.kode_unit=p.kode_unit AND q.th_ajaran=p.th_ajaran WHERE p.id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($lookup, 's', $registrationId);
    mysqli_stmt_execute($lookup);
    $registration = mysqli_fetch_assoc(mysqli_stmt_get_result($lookup));
    mysqli_stmt_close($lookup);
    if (!$registration) {
        throw new RuntimeException('Data pendaftaran tidak ditemukan.');
    }
    if ($registration['status_ppdb'] !== 'menunggu_kontak' && $registration['status_ppdb'] !== 'menunggu_pembayaran') {
        throw new RuntimeException('Tautan pembayaran hanya dapat disiapkan saat peserta menunggu dihubungi.');
    }

    $receiptToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $receiptToken);
    $tokenUpdate = mysqli_prepare($conn, 'UPDATE tb_pendaftaran SET token_whatsapp=? WHERE id_pendaftaran=?');
    mysqli_stmt_bind_param($tokenUpdate, 'ss', $tokenHash, $registrationId);
    if (!mysqli_stmt_execute($tokenUpdate)) {
        mysqli_stmt_close($tokenUpdate);
        throw new RuntimeException('Token tautan pembayaran belum dapat disimpan.');
    }
    mysqli_stmt_close($tokenUpdate);

    $statusUrl = ppdb_public_base_url().'/status-pendaftaran.php?'.http_build_query([
        'id' => $registrationId,
        'token' => $receiptToken,
    ], '', '&', PHP_QUERY_RFC3986);

    $lines = [
        'Assalamualaikum Bapak/Ibu pendaftar PPDB Cendekia Takengon.',
        'Registrasi awal ananda '.$registration['nm_peserta'].' sudah kami terima.',
        'Unit: '.($registration['nama_unit'] ?? '-').' · '.$registration['th_ajaran'],
        'Kode pendaftaran: '.$registrationId,
    ];
    if ((float) $registration['biaya_pendaftaran'] > 0) {
        $lines[] = 'Biaya pendaftaran: Rp '.number_format((float) $registration['biaya_pendaftaran'], 0, ',', '.');
    } else {
        $lines[] = 'Tidak ada biaya pendaftaran yang dipungut.';
    }
    if ($registration['bank_nama'] && $registration['nomor_rekening'] && $registration['nama_pemilik_rekening']) {
        $lines[] = 'Transfer ke: '.$registration['bank_nama'].' '.$registration['nomor_rekening'].' a.n. '.$registration['nama_pemilik_rekening'];
    }
    if ($registration['tanggal_batas_bayar']) {
        $lines[] = 'Batas pembayaran: '.date('d-m-Y', strtotime($registration['tanggal_batas_bayar']));
    }
    $lines[] = 'Gunakan kode '.$registrationId.' sebagai berita transfer.';
    $lines[] = 'Buka tautan ini untuk mengunggah bukti pembayaran:';
    $lines[] = $statusUrl;
    $lines[] = 'Terima kasih.';

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
        'status_url' => $statusUrl,
        'message' => implode("\n", $lines),
        'links' => $links,
    ];
}

/**
 * Tautan formulir lengkap dibuat secara lokal: token formulir disimpan sekali
 * dan tetap berlaku, lalu teks WhatsApp disiapkan untuk dibuka admin.
 */
function ppdb_prepare_formulir_link(mysqli $conn, string $registrationId): array
{
    $lookup = mysqli_prepare($conn, 'SELECT p.status_ppdb,p.nm_peserta,p.kode_unit,p.th_ajaran,p.no_hp,p.no_hp_pendaftar,p.no_hp_ayah,p.no_hp_ibu,p.token_formulir,u.nama_unit FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit WHERE p.id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($lookup, 's', $registrationId);
    mysqli_stmt_execute($lookup);
    $registration = mysqli_fetch_assoc(mysqli_stmt_get_result($lookup));
    mysqli_stmt_close($lookup);
    if (!$registration) {
        throw new RuntimeException('Data pendaftaran tidak ditemukan.');
    }
    if (!in_array($registration['status_ppdb'], ['pembayaran_terverifikasi', 'menunggu_formulir', 'formulir_terisi'], true)) {
        throw new RuntimeException('Formulir hanya dapat dibuka setelah pembayaran disetujui.');
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
        'Assalamualaikum, terima kasih telah menyelesaikan pembayaran PPDB Cendekia.',
        'Pembayaran untuk '.$registration['nm_peserta'].' ('.$registration['nama_unit'].') telah disetujui panitia.',
        'Kode pendaftaran: '.$registrationId,
        '',
        'Silakan buka tautan berikut untuk mengisi formulir lengkap dan mengunggah berkas:',
        $formUrl,
        '',
        'Catatan: formulir hanya dapat diisi satu kali. Pastikan data sesuai dokumen resmi.',
    ];

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
