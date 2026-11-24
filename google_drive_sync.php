<?php
require_once __DIR__.'/app_helpers.php';

/*
 * Salinan dokumen peserta (foto, NISN, KK, akta, KTP) dan bukti transfer di Google Drive.
 * Service account tidak punya kuota Drive, sehingga unggahan memakai akun Google yayasan
 * yang dihubungkan super admin lewat OAuth (google-drive-auth.php). Cakupan penuh "drive"
 * diperlukan karena PDF cetak peserta (cetak_drive.php) ditulis ke folder yang dibuat
 * panitia sendiri; cakupan drive.file hanya dapat menulis ke folder buatan aplikasi.
 *
 * Struktur folder: Dokumen Peserta SPMB Cendekia / 2027-2028 / <unit> / <kode> - <nama>.
 * Berkas di server tetap menjadi sumber utama; Drive hanya salinan untuk spreadsheet.
 */

const GOOGLE_DRIVE_ROOT_FOLDER = 'Dokumen Peserta SPMB Cendekia';
const GOOGLE_DRIVE_FOLDER_MIME = 'application/vnd.google-apps.folder';
const GOOGLE_DRIVE_CHUNK_BYTES = 4 * 1024 * 1024;
const GOOGLE_DRIVE_DOCUMENT_NAMES = [
    'foto' => 'Foto peserta didik',
    'nisn' => 'NISN',
    'kk' => 'Kartu Keluarga',
    'akta_lahir' => 'Akta Kelahiran',
    'ktp_ayah' => 'KTP Ayah',
    'ktp_ibu' => 'KTP Ibu',
    'surat_prestasi' => 'Surat Pengantar Prestasi',
];

/** Fitur Drive siap dipakai: migrasi 029 sudah dijalankan dan OAuth client sudah diisi di .env. */
function google_drive_configured(mysqli $conn): bool
{
    return ppdb_table_exists($conn, 'tb_google_drive_akun')
        && trim((string) getenv('GOOGLE_OAUTH_CLIENT_ID')) !== ''
        && trim((string) getenv('GOOGLE_OAUTH_CLIENT_SECRET')) !== '';
}

/** Akun Google yang terhubung, atau null. */
function google_drive_account(mysqli $conn): ?array
{
    if (!ppdb_table_exists($conn, 'tb_google_drive_akun')) {
        return null;
    }
    $result = mysqli_query($conn, 'SELECT * FROM tb_google_drive_akun WHERE id=1 LIMIT 1');
    $account = $result ? mysqli_fetch_assoc($result) : null;
    return $account && $account['refresh_token'] !== '' ? $account : null;
}

function google_drive_redirect_uri(): string
{
    return ppdb_public_base_url().'/google-drive-auth.php';
}

/** Klien OAuth tanpa token, untuk alur izin dan sebagai dasar klien Drive. */
function google_drive_oauth_client(): \Google\Client
{
    require_once __DIR__.'/vendor/autoload.php';
    $client = new \Google\Client();
    $client->setApplicationName('SPMB Yayasan Wakaf Cendekia Takengon');
    $client->setClientId(trim((string) getenv('GOOGLE_OAUTH_CLIENT_ID')));
    $client->setClientSecret(trim((string) getenv('GOOGLE_OAUTH_CLIENT_SECRET')));
    $client->setRedirectUri(google_drive_redirect_uri());
    $client->setScopes([\Google\Service\Drive::DRIVE]);
    $client->setAccessType('offline');
    // Selalu minta persetujuan agar Google mengirim refresh token baru.
    $client->setPrompt('consent');
    $client->setIncludeGrantedScopes(true);
    return $client;
}

/** Klien Drive atas nama akun yayasan; null bila belum terhubung. */
function google_drive_service(mysqli $conn): ?\Google\Service\Drive
{
    static $services = [];
    if (!google_drive_configured($conn)) {
        return null;
    }
    $account = google_drive_account($conn);
    if (!$account) {
        return null;
    }
    $key = spl_object_id($conn);
    if (!isset($services[$key])) {
        $client = google_drive_oauth_client();
        $token = $client->fetchAccessTokenWithRefreshToken($account['refresh_token']);
        if (isset($token['error'])) {
            throw new RuntimeException('Izin Google Drive yayasan tidak berlaku lagi ('.$token['error'].'). Hubungkan ulang Google Drive di dasbor.');
        }
        google_drive_granted_scope((string) ($token['scope'] ?? ''));
        $services[$key] = new \Google\Service\Drive($client);
    }
    return $services[$key];
}

/** Cakupan izin token terakhir; diisi google_drive_service(). */
function google_drive_granted_scope(?string $scope = null): string
{
    static $granted = '';
    if ($scope !== null) {
        $granted = $scope;
    }
    return $granted;
}

/**
 * Izin penuh Drive sudah diberikan. Akun yang dihubungkan sebelum cakupan diperluas masih
 * memegang drive.file saja dan perlu dihubungkan ulang. Panggil setelah google_drive_service().
 */
function google_drive_has_full_access(): bool
{
    return in_array(\Google\Service\Drive::DRIVE, preg_split('/\s+/', google_drive_granted_scope()) ?: [], true);
}

function google_drive_file_url(string $fileId): string
{
    return 'https://drive.google.com/file/d/'.rawurlencode($fileId).'/view';
}

function google_drive_folder_url(string $folderId): string
{
    return 'https://drive.google.com/drive/folders/'.rawurlencode($folderId);
}

/** ID folder yang tersimpan untuk sebuah kunci, atau ''. */
function google_drive_cached_folder(mysqli $conn, string $key): string
{
    if (!ppdb_table_exists($conn, 'tb_google_drive_folders')) {
        return '';
    }
    $stmt = mysqli_prepare($conn, 'SELECT folder_id FROM tb_google_drive_folders WHERE folder_key=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $key);
    mysqli_stmt_execute($stmt);
    $folderId = (string) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['folder_id'] ?? '');
    mysqli_stmt_close($stmt);
    return $folderId;
}

/** Folder dengan kunci tertentu; dibuat di dalam $parentId bila belum ada. */
function google_drive_folder(mysqli $conn, \Google\Service\Drive $drive, string $key, string $name, ?string $parentId): string
{
    $folderId = google_drive_cached_folder($conn, $key);
    if ($folderId !== '') {
        return $folderId;
    }
    $metadata = ['name' => $name, 'mimeType' => GOOGLE_DRIVE_FOLDER_MIME];
    if ($parentId !== null) {
        $metadata['parents'] = [$parentId];
    }
    $folder = $drive->files->create(new \Google\Service\Drive\DriveFile($metadata), ['fields' => 'id']);
    $folderId = (string) $folder->getId();
    $stmt = mysqli_prepare($conn, 'INSERT INTO tb_google_drive_folders (folder_key,folder_id) VALUES (?,?) ON DUPLICATE KEY UPDATE folder_id=VALUES(folder_id)');
    mysqli_stmt_bind_param($stmt, 'ss', $key, $folderId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $folderId;
}

/** Lupakan folder tersimpan agar dibuat ulang, misalnya setelah folder dihapus dari Drive. */
function google_drive_forget_folders(mysqli $conn, array $keys): void
{
    $stmt = mysqli_prepare($conn, 'DELETE FROM tb_google_drive_folders WHERE folder_key=?');
    foreach ($keys as $key) {
        mysqli_stmt_bind_param($stmt, 's', $key);
        mysqli_stmt_execute($stmt);
    }
    mysqli_stmt_close($stmt);
}

/** Kunci folder dari root hingga folder peserta. */
function google_drive_folder_keys(array $registration): array
{
    return [
        'root',
        'year:'.$registration['th_ajaran'],
        'unit:'.$registration['kode_unit'].':'.$registration['th_ajaran'],
        'peserta:'.$registration['id_pendaftaran'],
    ];
}

/** Folder milik satu peserta, dibuat beserta folder induknya bila perlu. */
function google_drive_registration_folder(mysqli $conn, \Google\Service\Drive $drive, array $registration): string
{
    [$rootKey, $yearKey, $unitKey, $participantKey] = google_drive_folder_keys($registration);
    $rootId = google_drive_folder($conn, $drive, $rootKey, GOOGLE_DRIVE_ROOT_FOLDER, null);
    $yearId = google_drive_folder($conn, $drive, $yearKey, str_replace('/', '-', $registration['th_ajaran']), $rootId);
    $unitId = google_drive_folder($conn, $drive, $unitKey, ppdb_unit_display_name($registration['nama_unit'] ?? $registration['kode_unit']), $yearId);
    $participantName = $registration['id_pendaftaran'].' - '.trim(preg_replace('/\s+/', ' ', (string) $registration['nm_peserta']) ?? '');
    return google_drive_folder($conn, $drive, $participantKey, mb_substr($participantName, 0, 120), $unitId);
}

/** Unggah satu berkas secara bertahap (aman untuk berkas di atas 5 MB). */
function google_drive_upload_file(\Google\Service\Drive $drive, string $folderId, string $name, string $path, string $mimeType, array $appProperties, array $extraParams = []): string
{
    $client = $drive->getClient();
    $client->setDefer(true);
    try {
        $request = $drive->files->create(new \Google\Service\Drive\DriveFile([
            'name' => $name,
            'parents' => [$folderId],
            'appProperties' => $appProperties,
        ]), ['fields' => 'id'] + $extraParams);
        $media = new \Google\Http\MediaFileUpload($client, $request, $mimeType, null, true, GOOGLE_DRIVE_CHUNK_BYTES);
        $media->setFileSize((int) filesize($path));
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Berkas '.$name.' tidak dapat dibaca di server.');
        }
        $uploaded = false;
        try {
            while ($uploaded === false && !feof($handle)) {
                $uploaded = $media->nextChunk((string) fread($handle, GOOGLE_DRIVE_CHUNK_BYTES));
            }
        } finally {
            fclose($handle);
        }
        if (!$uploaded instanceof \Google\Service\Drive\DriveFile) {
            throw new RuntimeException('Unggahan '.$name.' ke Google Drive tidak selesai.');
        }
        return (string) $uploaded->getId();
    } finally {
        $client->setDefer(false);
    }
}

/** Pindahkan berkas lama ke sampah Drive; galat diabaikan karena hanya merapikan. */
function google_drive_trash(\Google\Service\Drive $drive, ?string $fileId): void
{
    if ($fileId === null || $fileId === '') {
        return;
    }
    try {
        $drive->files->update($fileId, new \Google\Service\Drive\DriveFile(['trashed' => true]));
    } catch (Throwable $exception) {
        error_log('SPMB Google Drive trash failed: '.$exception->getMessage());
    }
}

function google_drive_extension(string $mimeType, string $originalName): string
{
    $known = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    if (isset($known[$mimeType])) {
        return $known[$mimeType];
    }
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    return preg_match('/^[a-z0-9]{1,5}$/', $extension) ? $extension : 'bin';
}

/**
 * Unggah dokumen dan bukti transfer satu peserta yang belum ada (atau sudah diganti) di Drive.
 * Berhenti saat melewati $deadline (microtime) agar permintaan pengguna tidak terlalu lama;
 * sisanya diunggah pada sinkronisasi berikutnya. Mengembalikan jumlah berkas yang diunggah.
 */
function google_drive_sync_registration(mysqli $conn, string $registrationId, float $deadline): int
{
    $drive = google_drive_service($conn);
    if ($drive === null || !ppdb_column_exists($conn, 'tb_dokumen_peserta', 'drive_file_id')) {
        return 0;
    }
    $stmt = mysqli_prepare($conn, 'SELECT p.id_pendaftaran,p.nm_peserta,p.kode_unit,p.th_ajaran,u.nama_unit FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit WHERE p.id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
    mysqli_stmt_execute($stmt);
    $registration = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$registration) {
        return 0;
    }

    $pending = [];
    $stmt = mysqli_prepare($conn, 'SELECT id,jenis_dokumen,nama_file_asli,path_file,mime_type,drive_file_id FROM tb_dokumen_peserta WHERE id_pendaftaran=? AND (drive_file_id IS NULL OR drive_source_path IS NULL OR drive_source_path<>path_file)');
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
    mysqli_stmt_execute($stmt);
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $row) {
        $label = GOOGLE_DRIVE_DOCUMENT_NAMES[$row['jenis_dokumen']] ?? $row['jenis_dokumen'];
        $pending[] = $row + ['table' => 'tb_dokumen_peserta', 'subdir' => 'participant-documents/'.$registrationId, 'label' => $label, 'kind' => 'doc:'.$row['jenis_dokumen']];
    }
    mysqli_stmt_close($stmt);
    $stmt = mysqli_prepare($conn, 'SELECT id,nama_file_asli,path_file,mime_type,drive_file_id FROM tb_bukti_pembayaran WHERE id_pendaftaran=? AND (drive_file_id IS NULL OR drive_source_path IS NULL OR drive_source_path<>path_file) ORDER BY id');
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
    mysqli_stmt_execute($stmt);
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $row) {
        $pending[] = $row + ['table' => 'tb_bukti_pembayaran', 'subdir' => 'payment-proofs', 'label' => 'Bukti transfer '.$row['id'], 'kind' => 'payment:'.$row['id']];
    }
    mysqli_stmt_close($stmt);
    if (!$pending) {
        return 0;
    }

    // Kunci per peserta agar dua permintaan bersamaan tidak mengunggah berkas yang sama dua kali.
    $lockName = 'ppdb_drive_'.$registrationId;
    $lockStmt = mysqli_prepare($conn, 'SELECT GET_LOCK(?, 0) AS locked');
    mysqli_stmt_bind_param($lockStmt, 's', $lockName);
    mysqli_stmt_execute($lockStmt);
    $locked = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($lockStmt))['locked'] ?? 0) === 1;
    mysqli_stmt_close($lockStmt);
    if (!$locked) {
        return 0;
    }
    try {
        return google_drive_upload_pending($conn, $drive, $registration, $pending, $deadline);
    } finally {
        $releaseStmt = mysqli_prepare($conn, 'SELECT RELEASE_LOCK(?)');
        mysqli_stmt_bind_param($releaseStmt, 's', $lockName);
        mysqli_stmt_execute($releaseStmt);
        mysqli_stmt_close($releaseStmt);
    }
}

function google_drive_upload_pending(mysqli $conn, \Google\Service\Drive $drive, array $registration, array $pending, float $deadline): int
{
    $registrationId = $registration['id_pendaftaran'];
    try {
        $folderId = google_drive_registration_folder($conn, $drive, $registration);
    } catch (\Google\Service\Exception $exception) {
        // Folder tersimpan sudah dihapus dari Drive: buat ulang pada sinkronisasi berikutnya.
        if ((int) $exception->getCode() === 404) {
            google_drive_forget_folders($conn, google_drive_folder_keys($registration));
        }
        throw $exception;
    }

    $uploaded = 0;
    foreach ($pending as $item) {
        if (microtime(true) > $deadline) {
            break;
        }
        $path = ppdb_resolve_stored_file($item['subdir'], $item['path_file']);
        if ($path === null) {
            error_log('SPMB Google Drive: berkas lokal tidak ditemukan untuk '.$item['table'].' #'.$item['id']);
            continue;
        }
        $name = $registrationId.' - '.$item['label'].'.'.google_drive_extension($item['mime_type'], $item['nama_file_asli']);
        try {
            $fileId = google_drive_upload_file($drive, $folderId, $name, $path, $item['mime_type'], ['ppdb_kind' => $item['kind']]);
        } catch (\Google\Service\Exception $exception) {
            if ((int) $exception->getCode() === 404) {
                google_drive_forget_folders($conn, [google_drive_folder_keys($registration)[3]]);
            }
            throw $exception;
        }
        $update = mysqli_prepare($conn, 'UPDATE '.$item['table'].' SET drive_file_id=?,drive_source_path=? WHERE id=?');
        mysqli_stmt_bind_param($update, 'ssi', $fileId, $item['path_file'], $item['id']);
        mysqli_stmt_execute($update);
        mysqli_stmt_close($update);
        // Dokumen yang diganti: salinan lama di Drive dipindah ke sampah.
        google_drive_trash($drive, $item['drive_file_id']);
        $uploaded++;
    }
    return $uploaded;
}

/** Unggah berkas tertunda untuk semua peserta satu unit dan tahun ajaran, dibatasi $deadline. */
function google_drive_sync_unit(mysqli $conn, string $unitCode, string $academicYear, float $deadline): int
{
    if (google_drive_service($conn) === null || !ppdb_column_exists($conn, 'tb_dokumen_peserta', 'drive_file_id')) {
        return 0;
    }
    $stmt = mysqli_prepare($conn, "SELECT p.id_pendaftaran FROM tb_pendaftaran p WHERE p.kode_unit=? AND p.th_ajaran=? AND (
        EXISTS (SELECT 1 FROM tb_dokumen_peserta d WHERE d.id_pendaftaran=p.id_pendaftaran AND (d.drive_file_id IS NULL OR d.drive_source_path IS NULL OR d.drive_source_path<>d.path_file))
        OR EXISTS (SELECT 1 FROM tb_bukti_pembayaran b WHERE b.id_pendaftaran=p.id_pendaftaran AND (b.drive_file_id IS NULL OR b.drive_source_path IS NULL OR b.drive_source_path<>b.path_file))
    ) ORDER BY p.tgl_daftar,p.id_pendaftaran");
    mysqli_stmt_bind_param($stmt, 'ss', $unitCode, $academicYear);
    mysqli_stmt_execute($stmt);
    $registrationIds = array_column(mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC), 'id_pendaftaran');
    mysqli_stmt_close($stmt);
    $uploaded = 0;
    foreach ($registrationIds as $registrationId) {
        if (microtime(true) > $deadline) {
            break;
        }
        $uploaded += google_drive_sync_registration($conn, $registrationId, $deadline);
    }
    return $uploaded;
}

/** Simpan galat Drive terakhir untuk dasbor; null menghapusnya setelah unggahan berhasil. */
function google_drive_record_error(mysqli $conn, ?string $message): void
{
    if (!ppdb_column_exists($conn, 'tb_google_drive_akun', 'galat_terakhir')) {
        return;
    }
    $message = $message === null ? null : mb_substr($message, 0, 500);
    $stmt = mysqli_prepare($conn, 'UPDATE tb_google_drive_akun SET galat_terakhir=? WHERE id=1');
    mysqli_stmt_bind_param($stmt, 's', $message);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/** Pesan galat Drive yang aman ditampilkan ke admin. */
function google_drive_error_message(Throwable $exception): string
{
    if ($exception instanceof \Google\Service\Exception) {
        $reason = $exception->getErrors()[0]['reason'] ?? '';
        if ($reason === 'storageQuotaExceeded') {
            return 'Kuota Google Drive yayasan penuh. Kosongkan ruang Drive lalu sinkronkan ulang.';
        }
        if ((int) $exception->getCode() === 401 || (int) $exception->getCode() === 403) {
            return 'Google Drive menolak akses ('.($reason ?: $exception->getCode()).'). Hubungkan ulang Google Drive di dasbor.';
        }
        return 'Google Drive mengembalikan galat '.$exception->getCode().($reason !== '' ? ' ('.$reason.')' : '').'.';
    }
    return $exception instanceof RuntimeException ? $exception->getMessage() : 'Unggahan ke Google Drive gagal.';
}

/** Folder peserta yang dihapus dipindah ke sampah Drive agar data pribadinya tidak tertinggal. */
function google_drive_trash_registration(mysqli $conn, string $registrationId): void
{
    $key = 'peserta:'.$registrationId;
    $folderId = google_drive_cached_folder($conn, $key);
    if ($folderId === '') {
        return;
    }
    try {
        $drive = google_drive_service($conn);
        if ($drive !== null) {
            google_drive_trash($drive, $folderId);
        }
    } catch (Throwable $exception) {
        error_log('SPMB Google Drive trash registration failed: '.$exception->getMessage());
        return;
    }
    google_drive_forget_folders($conn, [$key]);
}
