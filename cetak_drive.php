<?php
require_once __DIR__.'/app_helpers.php';
require_once __DIR__.'/google_drive_sync.php';
require_once __DIR__.'/cetak_registrasi_data.php';
require_once __DIR__.'/cetak_pdf.php';

/*
 * Salinan PDF cetak peserta di Google Drive, dipisah per unit:
 *   <folder cetak> / <nama unit> / P2026xxxxx - Nama - Registrasi.pdf
 *                                 / P2026xxxxx - Nama - Data lengkap.pdf
 * Cetak registrasi dibuat setelah tahap Registrasi selesai, data lengkap setelah peserta
 * diterima; isinya sama dengan tombol printer di Daftar Peserta (cetak-registrasi.php).
 * Setiap perubahan data menandai cetakan untuk diperiksa; PDF diganti bila isinya berubah.
 * Pekerjaan berjalan di latar belakang (admin-latar.php dan tombol sinkronisasi dasbor).
 */

/** Folder tujuan bawaan yang dibuat panitia; dapat diganti lewat GOOGLE_DRIVE_CETAK_FOLDER_ID. */
const CETAK_DRIVE_DEFAULT_FOLDER = '18rZYQlta3Wq59mii0Hda11pfIZrgAkvY';
/** Naikkan bila tata letak PDF berubah agar semua cetakan dibuat ulang. */
const CETAK_DRIVE_TEMPLATE_VERSION = '1';
// Naikkan bila isi cetakan berubah agar PDF lama di Drive diperiksa ulang (2: jalur prestasi).
// Terpisah dari versi templat (bagian sidik) supaya hanya PDF yang isinya berubah yang diunggah ulang.
const CETAK_DRIVE_RECHECK_VERSION = '2';
const CETAK_DRIVE_KINDS = ['registrasi' => 'Registrasi', 'lengkap' => 'Data lengkap'];
/** Ruang gambar lampiran pada A4 96 dpi, setelah margin dan judul lampiran. */
const CETAK_DRIVE_IMAGE_BOX = [662, 940];

function cetak_drive_folder_id(): string
{
    $configured = trim((string) getenv('GOOGLE_DRIVE_CETAK_FOLDER_ID'));
    return $configured !== '' ? $configured : CETAK_DRIVE_DEFAULT_FOLDER;
}

/** Fitur siap: migrasi 041 sudah dijalankan dan Google Drive yayasan terhubung. */
function cetak_drive_ready(mysqli $conn): bool
{
    return ppdb_table_exists($conn, 'tb_cetak_drive') && google_drive_configured($conn) && google_drive_account($conn) !== null;
}

/** Status yang sudah melewati tahap Registrasi (langkah 2 ke atas pada Daftar Peserta). */
function cetak_drive_registered_statuses(): array
{
    $statuses = [];
    foreach (ppdb_status_catalog() as $status => $info) {
        if ((int) $info['step'] >= 2) {
            $statuses[] = $status;
        }
    }
    return $statuses;
}

/** Jenis cetakan yang berlaku untuk peserta, sama dengan tombol printer di Daftar Peserta. */
function cetak_drive_kinds_for(array $participant): array
{
    $status = (string) ($participant['status_ppdb'] ?? '');
    $kinds = [];
    if (in_array($status, cetak_drive_registered_statuses(), true)) {
        $kinds[] = 'registrasi';
    }
    if ($status === 'diterima') {
        $kinds[] = 'lengkap';
    }
    return $kinds;
}

/** Tandai cetakan peserta untuk diperiksa ulang; dipanggil setiap kali datanya berubah. */
function cetak_drive_mark_dirty(mysqli $conn, string $registrationId): void
{
    if (!ppdb_table_exists($conn, 'tb_cetak_drive')) {
        return;
    }
    $stmt = mysqli_prepare($conn, 'UPDATE tb_cetak_drive SET perlu_periksa=1 WHERE id_pendaftaran=?');
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * Peserta yang cetakannya belum ada di Drive atau perlu diperiksa ulang.
 * Dicocokkan di PHP, bukan JOIN SQL, karena collation tb_cetak_drive di server dapat
 * berbeda dengan tb_pendaftaran dan JOIN-nya akan gagal ("Illegal mix of collations").
 */
function cetak_drive_pending_ids(mysqli $conn, int $limit = 200): array
{
    $stored = [];
    $dirty = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT id_pendaftaran,jenis,perlu_periksa FROM tb_cetak_drive'), MYSQLI_ASSOC) as $row) {
        $stored[$row['id_pendaftaran'].'|'.$row['jenis']] = true;
        if ((int) $row['perlu_periksa'] === 1) {
            $dirty[$row['id_pendaftaran']] = true;
        }
    }
    $ids = [];
    $participants = mysqli_query($conn, "SELECT id_pendaftaran,status_ppdb FROM tb_pendaftaran WHERE status_ppdb<>'data_lama' ORDER BY tgl_daftar,id_pendaftaran");
    while (($participant = mysqli_fetch_assoc($participants)) && count($ids) < max(1, $limit)) {
        $id = (string) $participant['id_pendaftaran'];
        $missing = array_filter(cetak_drive_kinds_for($participant), static fn (string $kind): bool => !isset($stored[$id.'|'.$kind]));
        if ($missing || isset($dirty[$id])) {
            $ids[] = $id;
        }
    }
    mysqli_free_result($participants);
    return $ids;
}

/**
 * Saat CETAK_DRIVE_RECHECK_VERSION naik, semua cetakan diperiksa ulang sekali. Hanya PDF yang sidiknya
 * berubah yang diunggah ulang; sisanya cukup dibandingkan tanpa dirender.
 */
function cetak_drive_apply_template_version(mysqli $conn): void
{
    $key = 'cetak:periksa-ulang';
    if (google_drive_cached_folder($conn, $key) === CETAK_DRIVE_RECHECK_VERSION) {
        return;
    }
    mysqli_query($conn, 'UPDATE tb_cetak_drive SET perlu_periksa=1');
    $version = CETAK_DRIVE_RECHECK_VERSION;
    $stmt = mysqli_prepare($conn, 'INSERT INTO tb_google_drive_folders (folder_key,folder_id) VALUES (?,?) ON DUPLICATE KEY UPDATE folder_id=VALUES(folder_id)');
    mysqli_stmt_bind_param($stmt, 'ss', $key, $version);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function cetak_drive_has_pending(mysqli $conn): bool
{
    if (!cetak_drive_ready($conn)) {
        return false;
    }
    cetak_drive_apply_template_version($conn);
    return (google_drive_cached_folder($conn, CETAK_DRIVE_SPLIT_MARKER) === '' || cetak_drive_pending_ids($conn, 1) !== []);
}

/** Sidik isi cetakan; tanggal cetak tidak ikut agar PDF tidak dibuat ulang setiap hari. */
function cetak_drive_signature(string $kind, array $participant, array $printData): string
{
    $attachments = array_map(static fn (array $item): array => [$item['label'], $item['mime'], $item['path'], is_file($item['path']) ? (int) filemtime($item['path']) : 0], $printData['attachments']);
    return hash('sha256', json_encode([
        CETAK_DRIVE_TEMPLATE_VERSION,
        $kind,
        $participant['nm_peserta'],
        $participant['nama_unit'],
        $participant['th_ajaran'],
        $printData['sections'],
        $attachments,
    ], JSON_UNESCAPED_UNICODE));
}

/**
 * Gambar lampiran sebagai <img> data URI berukuran pas satu halaman, atau null bila tidak
 * dapat dipasang (PDF, berkas hilang, atau PNG/WebP tanpa ekstensi GD).
 * Dengan GD, foto diperkecil, diputar sesuai EXIF, dan disimpan ulang sebagai JPEG.
 */
function cetak_drive_image_html(string $path, string $mime): ?string
{
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !is_file($path)) {
        return null;
    }
    $size = @getimagesize($path);
    if (!$size || $size[0] < 1 || $size[1] < 1) {
        return null;
    }
    [$width, $height] = $size;
    $data = null;
    // Foto di atas 25 MP tidak dibuka GD agar memori server tidak habis.
    $canDecode = extension_loaded('gd') && $width * $height <= 25000000;
    if ($canDecode) {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        };
        if ($image instanceof GdImage) {
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
                $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
                if ($angle !== 0) {
                    $rotated = imagerotate($image, $angle, 0);
                    if ($rotated instanceof GdImage) {
                        $image = null;
                        $image = $rotated;
                    }
                }
            }
            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, 1800 / max($width, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            // Latar putih agar bagian transparan PNG tidak menjadi hitam di JPEG.
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            $image = null;
            ob_start();
            imagejpeg($canvas, null, 82);
            $data = (string) ob_get_clean();
            $canvas = null;
            [$width, $height] = [$targetWidth, $targetHeight];
        }
    }
    if ($data === null) {
        // Tanpa GD hanya JPEG yang dapat dipasang Dompdf apa adanya.
        if ($mime !== 'image/jpeg') {
            return null;
        }
        $data = (string) file_get_contents($path);
    }
    [$boxWidth, $boxHeight] = CETAK_DRIVE_IMAGE_BOX;
    $fit = min($boxWidth / $width, $boxHeight / $height);
    return '<img src="data:image/jpeg;base64,'.base64_encode($data).'" width="'.(int) floor($width * $fit).'" height="'.(int) floor($height * $fit).'" alt="">';
}

/** HTML cetakan untuk Dompdf, meniru tampilan cetak browser (cetak-registrasi.php). */
function cetak_drive_html(array $participant, array $printData): string
{
    $registrationId = (string) $participant['id_pendaftaran'];
    $sectionsHtml = '';
    foreach ($printData['sections'] as $index => [$sectionTitle, $sectionRows]) {
        $rowsHtml = '';
        foreach ($sectionRows as [$label, $value]) {
            $rowsHtml .= '<tr><th>'.h($label).'</th>'.(cetak_registrasi_is_empty($value)
                ? '<td class="is-empty">Belum diisi</td>'
                : '<td>'.nl2br(h((string) $value)).'</td>').'</tr>';
        }
        $sectionsHtml .= '<h2>'.chr(65 + $index).'. '.h($sectionTitle).'</h2><table class="data">'.$rowsHtml.'</table>';
    }
    $attachmentListHtml = '';
    foreach ($printData['attachments'] as $attachment) {
        $attachmentListHtml .= '<li>'.h($attachment['label']).'</li>';
    }
    $attachmentPages = '';
    foreach ($printData['attachments'] as $index => $attachment) {
        $title = 'Lampiran '.($index + 1).' · '.$attachment['label'];
        $image = cetak_drive_image_html($attachment['path'], (string) $attachment['mime']);
        $body = $image !== null
            ? '<div class="attachment-image">'.$image.'</div>'
            : '<p class="attachment-note">'.($attachment['mime'] === 'application/pdf'
                ? 'Lampiran ini berupa berkas PDF. Salinannya tersimpan terpisah di folder dokumen peserta pada Google Drive.'
                : 'Lampiran ini belum dapat ditampilkan di PDF. Buka berkas aslinya dari halaman detail peserta.').'</p>';
        $attachmentPages .= '<div class="attachment"><table class="attachment-head"><tr><td class="attachment-logo">'.cetak_image(__DIR__.'/img/logo-pdf.jpg', 'logo-small').'</td><td><h2>'.h($title).'</h2></td></tr></table>'.$body.'</div>';
    }

    return '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>
        @page { margin: 0; }
        body { margin: 0; color: #1f2622; font-family: "Source Serif", "DejaVu Serif", serif; font-size: 11px; line-height: 1.3; }
        .watermark { position: fixed; top: 360px; left: 197px; width: 400px; height: 400px; opacity: 0.05; }
        .top-band { position: fixed; top: 0; left: 0; width: 100%; height: 9px; background: #123f32; }
        .page { padding: 30px 66px 40px; }
        '.cetak_kop_css().'
        h1 { margin: 14px 0 0; color: #123f32; font-size: 15.5px; text-align: center; letter-spacing: .6px; text-decoration: underline; }
        .subtitle { margin: 1px 0 0; color: #4c5852; font-size: 11px; text-align: center; }
        .code { margin: 10px 0 8px; padding: 6px 12px; text-align: center; border-top: 1px solid #123f32; border-bottom: 1px solid #123f32; }
        .code span { display: block; color: #4c5852; font-size: 9px; letter-spacing: 1px; }
        .code strong { color: #123f32; font-size: 19px; letter-spacing: 2px; }
        .empty-summary { margin: 6px 0; padding: 5px 10px; color: #7a4a00; background: #fff6e5; border-left: 2.5px solid #c98a12; font-size: 10px; }
        h2 { margin: 12px 0 4px; color: #123f32; font-size: 12px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th, table.data td { padding: 3px 6px; vertical-align: top; text-align: left; border-bottom: .5px solid #d5ddd8; }
        table.data th { width: 36%; color: #4c5852; font-weight: normal; }
        td.is-empty { color: #a1640b; font-style: italic; }
        ol { margin: 3px 0 0; padding-left: 18px; }
        .signature { margin: 22px 0 0 auto; width: 220px; }
        .signature p { margin: 0 0 2px; }
        .signature-line { margin-top: 52px; border-top: .8px solid #1f2622; }
        .attachment { page-break-before: always; padding: 30px 66px 30px; }
        .attachment-head { width: 100%; border-collapse: collapse; border-bottom: 1px solid #123f32; }
        .attachment-head td { vertical-align: middle; padding-bottom: 6px; }
        .attachment-logo { width: 42px; }
        .logo-small { width: 34px; height: 34px; }
        .attachment-head h2 { margin: 0; }
        .attachment-image { margin-top: 12px; text-align: center; }
        .attachment-note { margin-top: 16px; padding: 9px 12px; color: #4c5852; background: #f4f6f4; border-left: 2.5px solid #123f32; }
        .footer { position: fixed; left: 66px; width: 662px; bottom: 16px; padding-top: 4px; color: #6a756f; border-top: .6px solid #b9c4be; font-size: 7.8px; text-align: center; }
    </style></head><body>
        <div class="top-band"></div>
        '.cetak_image(__DIR__.'/img/logo-pdf.jpg', 'watermark').'
        <div class="footer">Diterbitkan melalui sistem SPMB Yayasan Wakaf Cendekia Takengon · Kode '.h($registrationId).' · '.h($participant['nm_peserta']).'</div>
        <div class="page">
            '.cetak_kop((string) $participant['nama_unit'], ppdb_admin_whatsapp_display()).'
            <h1>'.h($printData['title']).'</h1>
            <p class="subtitle">Tahun ajaran '.h($participant['th_ajaran']).'</p>
            <div class="code"><span>KODE PENDAFTARAN</span><strong>'.h($registrationId).'</strong></div>
            '.($printData['empty_count'] > 0 ? '<p class="empty-summary">'.(int) $printData['empty_count'].' isian belum diisi. Bila diperlukan, buka revisi formulir untuk wali murid.</p>' : '').'
            '.$sectionsHtml.'
            <h2>'.chr(65 + count($printData['sections'])).'. Daftar lampiran</h2>
            '.($attachmentListHtml !== '' ? '<ol>'.$attachmentListHtml.'</ol>' : '<p>Belum ada berkas yang diunggah.</p>').'
            <div class="signature">
                <p>Takengon, '.h(ppdb_format_date_id(date('Y-m-d'))).'</p>
                <p>Panitia SPMB</p>
                <div class="signature-line"></div>
            </div>
        </div>
        '.$attachmentPages.'
    </body></html>';
}

/** Isi PDF satu jenis cetakan peserta, beserta sidiknya. */
function cetak_drive_build_pdf(mysqli $conn, array $participant, string $kind): array
{
    $printData = cetak_registrasi_build($conn, $participant, $kind === 'lengkap');
    // Path berkas di server dipastikan berada di folder penyimpanan, seperti halaman unduh admin.
    foreach ($printData['attachments'] as &$attachment) {
        $attachment['path'] = ppdb_resolve_stored_file($attachment['subdir'], $attachment['path']) ?? '';
    }
    unset($attachment);
    return [
        'signature' => cetak_drive_signature($kind, $participant, $printData),
        'render' => static fn (): string => cetak_pdf_render(cetak_drive_html($participant, $printData)),
    ];
}

function cetak_drive_file_name(array $participant, string $kind): string
{
    $name = trim(preg_replace('/[\\\\\/:*?"<>|\s]+/u', ' ', (string) $participant['nm_peserta']) ?? '');
    return $participant['id_pendaftaran'].' - '.mb_substr($name, 0, 80).' - '.CETAK_DRIVE_KINDS[$kind].'.pdf';
}

/** Folder cetak dengan kunci tertentu, dibuat di dalam $parentId bila belum ada. */
function cetak_drive_folder(mysqli $conn, \Google\Service\Drive $drive, string $key, string $name, string $parentId): string
{
    $folderId = google_drive_cached_folder($conn, $key);
    if ($folderId !== '') {
        return $folderId;
    }
    $folder = $drive->files->create(new \Google\Service\Drive\DriveFile([
        'name' => $name,
        'mimeType' => GOOGLE_DRIVE_FOLDER_MIME,
        'parents' => [$parentId],
    ]), ['fields' => 'id', 'supportsAllDrives' => true]);
    $folderId = (string) $folder->getId();
    $stmt = mysqli_prepare($conn, 'INSERT INTO tb_google_drive_folders (folder_key,folder_id) VALUES (?,?) ON DUPLICATE KEY UPDATE folder_id=VALUES(folder_id)');
    mysqli_stmt_bind_param($stmt, 'ss', $key, $folderId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $folderId;
}

function cetak_drive_folder_keys(array $participant, string $kind): array
{
    return ['cetak:unit:'.$participant['kode_unit'], 'cetak:unit:'.$participant['kode_unit'].':'.$kind];
}

/** Folder tujuan satu jenis cetakan: <folder cetak> / <unit> / Registrasi atau Data lengkap. */
function cetak_drive_kind_folder(mysqli $conn, \Google\Service\Drive $drive, array $participant, string $kind): string
{
    [$unitKey, $kindKey] = cetak_drive_folder_keys($participant, $kind);
    $unitId = cetak_drive_folder($conn, $drive, $unitKey, ppdb_unit_display_name($participant['nama_unit'] ?? $participant['kode_unit']), cetak_drive_folder_id());
    return cetak_drive_folder($conn, $drive, $kindKey, CETAK_DRIVE_KINDS[$kind], $unitId);
}

/** Penanda bahwa PDF lama sudah dipindah ke subfolder per jenis (dicatat sekali). */
const CETAK_DRIVE_SPLIT_MARKER = 'cetak:pisah-jenis';

/**
 * PDF yang diunggah sebelum ada subfolder per jenis dipindahkan (bukan diunggah ulang)
 * ke subfolder Registrasi/Data lengkap. Mengembalikan true bila semuanya sudah selesai.
 */
function cetak_drive_move_to_kind_folders(mysqli $conn, \Google\Service\Drive $drive, float $deadline): bool
{
    if (google_drive_cached_folder($conn, CETAK_DRIVE_SPLIT_MARKER) !== '') {
        return true;
    }
    // Posisi terakhir disimpan agar kunjungan berikutnya melanjutkan, bukan mengulang dari awal.
    $progressKey = CETAK_DRIVE_SPLIT_MARKER.':sampai';
    $resumeAfter = google_drive_cached_folder($conn, $progressKey);
    $participants = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT id_pendaftaran,jenis,drive_file_id FROM tb_cetak_drive ORDER BY id_pendaftaran,jenis'), MYSQLI_ASSOC) as $record) {
        $position = $record['id_pendaftaran'].'|'.$record['jenis'];
        if ($resumeAfter !== '' && strcmp($position, $resumeAfter) <= 0) {
            continue;
        }
        if (microtime(true) > $deadline) {
            return false;
        }
        $registrationId = $record['id_pendaftaran'];
        $participants[$registrationId] ??= cetak_registrasi_participant($conn, $registrationId);
        if (!$participants[$registrationId] || !isset(CETAK_DRIVE_KINDS[$record['jenis']])) {
            continue;
        }
        $targetId = cetak_drive_kind_folder($conn, $drive, $participants[$registrationId], $record['jenis']);
        try {
            $parents = (array) $drive->files->get($record['drive_file_id'], ['fields' => 'parents', 'supportsAllDrives' => true])->getParents();
            if (!in_array($targetId, $parents, true)) {
                $drive->files->update($record['drive_file_id'], new \Google\Service\Drive\DriveFile(), [
                    'addParents' => $targetId,
                    'removeParents' => implode(',', $parents),
                    'fields' => 'id',
                    'supportsAllDrives' => true,
                ]);
            }
        } catch (\Google\Service\Exception $exception) {
            if ((int) $exception->getCode() !== 404) {
                throw $exception;
            }
            // Berkas sudah dihapus manual dari Drive: catatannya dibuang agar dibuat ulang.
            $delete = mysqli_prepare($conn, 'DELETE FROM tb_cetak_drive WHERE id_pendaftaran=? AND jenis=?');
            mysqli_stmt_bind_param($delete, 'ss', $registrationId, $record['jenis']);
            mysqli_stmt_execute($delete);
            mysqli_stmt_close($delete);
        }
        $progress = mysqli_prepare($conn, 'INSERT INTO tb_google_drive_folders (folder_key,folder_id) VALUES (?,?) ON DUPLICATE KEY UPDATE folder_id=VALUES(folder_id)');
        mysqli_stmt_bind_param($progress, 'ss', $progressKey, $position);
        mysqli_stmt_execute($progress);
        mysqli_stmt_close($progress);
    }
    $marker = CETAK_DRIVE_SPLIT_MARKER;
    $done = 'selesai';
    $stmt = mysqli_prepare($conn, 'INSERT INTO tb_google_drive_folders (folder_key,folder_id) VALUES (?,?) ON DUPLICATE KEY UPDATE folder_id=VALUES(folder_id)');
    mysqli_stmt_bind_param($stmt, 'ss', $marker, $done);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return true;
}

/** Cetakan yang sudah tercatat untuk peserta, per jenis. */
function cetak_drive_records(mysqli $conn, string $registrationId): array
{
    $stmt = mysqli_prepare($conn, 'SELECT jenis,drive_file_id,sidik,perlu_periksa FROM tb_cetak_drive WHERE id_pendaftaran=?');
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
    mysqli_stmt_execute($stmt);
    $records = [];
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $row) {
        $records[$row['jenis']] = $row;
    }
    mysqli_stmt_close($stmt);
    return $records;
}

/** Unggah atau perbarui cetakan satu peserta. Mengembalikan jumlah PDF yang diunggah. */
function cetak_drive_sync_registration(mysqli $conn, \Google\Service\Drive $drive, string $registrationId): int
{
    $participant = cetak_registrasi_participant($conn, $registrationId);
    if (!$participant) {
        return 0;
    }
    $records = cetak_drive_records($conn, $registrationId);
    $kinds = cetak_drive_kinds_for($participant);
    $uploaded = 0;
    foreach ($kinds as $kind) {
        $record = $records[$kind] ?? null;
        $pdf = cetak_drive_build_pdf($conn, $participant, $kind);
        if ($record && hash_equals($record['sidik'], $pdf['signature'])) {
            if ((int) $record['perlu_periksa'] === 1) {
                $clear = mysqli_prepare($conn, 'UPDATE tb_cetak_drive SET perlu_periksa=0 WHERE id_pendaftaran=? AND jenis=?');
                mysqli_stmt_bind_param($clear, 'ss', $registrationId, $kind);
                mysqli_stmt_execute($clear);
                mysqli_stmt_close($clear);
            }
            continue;
        }
        $temporary = tempnam(sys_get_temp_dir(), 'ppdb-cetak-');
        if ($temporary === false) {
            throw new RuntimeException('Folder sementara server tidak dapat ditulis untuk membuat PDF cetak.');
        }
        try {
            try {
                file_put_contents($temporary, ($pdf['render'])());
            } catch (Throwable $exception) {
                // Satu peserta yang gagal dirender tidak boleh menghentikan antrean peserta lain.
                error_log('SPMB cetak Drive render failed for '.$registrationId.' ('.$kind.'): '.$exception->getMessage());
                continue;
            }
            try {
                $folderId = cetak_drive_kind_folder($conn, $drive, $participant, $kind);
                $fileId = google_drive_upload_file($drive, $folderId, cetak_drive_file_name($participant, $kind), $temporary, 'application/pdf', ['ppdb_kind' => 'cetak:'.$kind], ['supportsAllDrives' => true]);
            } catch (\Google\Service\Exception $exception) {
                if ((int) $exception->getCode() === 404) {
                    // Folder unit/jenis dihapus dari Drive: dibuat ulang pada percobaan berikutnya.
                    google_drive_forget_folders($conn, cetak_drive_folder_keys($participant, $kind));
                    throw new RuntimeException('Folder cetak peserta di Google Drive tidak ditemukan atau tidak dapat diakses akun yayasan. Pastikan akun tersebut punya akses edit ke folder tujuan.');
                }
                throw $exception;
            }
        } finally {
            @unlink($temporary);
        }
        $save = mysqli_prepare($conn, 'INSERT INTO tb_cetak_drive (id_pendaftaran,jenis,drive_file_id,sidik,perlu_periksa,diunggah_pada) VALUES (?,?,?,?,0,NOW()) ON DUPLICATE KEY UPDATE drive_file_id=VALUES(drive_file_id),sidik=VALUES(sidik),perlu_periksa=0,diunggah_pada=NOW()');
        mysqli_stmt_bind_param($save, 'ssss', $registrationId, $kind, $fileId, $pdf['signature']);
        mysqli_stmt_execute($save);
        mysqli_stmt_close($save);
        // Versi lama dipindah ke sampah Drive agar hanya PDF terbaru yang tersisa.
        google_drive_trash($drive, $record['drive_file_id'] ?? null);
        $uploaded++;
    }
    // Jenis yang tidak berlaku lagi (misalnya penerimaan dibatalkan) dibuang dari Drive.
    foreach ($records as $kind => $record) {
        if (!in_array($kind, $kinds, true)) {
            google_drive_trash($drive, $record['drive_file_id']);
            $delete = mysqli_prepare($conn, 'DELETE FROM tb_cetak_drive WHERE id_pendaftaran=? AND jenis=?');
            mysqli_stmt_bind_param($delete, 'ss', $registrationId, $kind);
            mysqli_stmt_execute($delete);
            mysqli_stmt_close($delete);
        }
    }
    return $uploaded;
}

/** Proses cetakan tertunda semua unit hingga $deadline (microtime). Mengembalikan jumlah PDF diunggah. */
function cetak_drive_sync_pending(mysqli $conn, float $deadline): int
{
    if (!cetak_drive_ready($conn)) {
        return 0;
    }
    $drive = google_drive_service($conn);
    if ($drive === null) {
        return 0;
    }
    if (!google_drive_has_full_access()) {
        throw new RuntimeException('Izin Google Drive belum mencakup folder cetak peserta. Super admin perlu menekan "Putuskan Google Drive" lalu "Hubungkan Google Drive" lagi di dasbor.');
    }
    // PDF lama dirapikan ke subfolder per jenis lebih dulu, sebelum PDF baru dibuat.
    if (!cetak_drive_move_to_kind_folders($conn, $drive, $deadline)) {
        return 0;
    }
    $uploaded = 0;
    foreach (cetak_drive_pending_ids($conn) as $registrationId) {
        if (microtime(true) > $deadline) {
            break;
        }
        $lockName = 'ppdb_cetak_'.$registrationId;
        $lock = mysqli_prepare($conn, 'SELECT GET_LOCK(?, 0) AS locked');
        mysqli_stmt_bind_param($lock, 's', $lockName);
        mysqli_stmt_execute($lock);
        $locked = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($lock))['locked'] ?? 0) === 1;
        mysqli_stmt_close($lock);
        if (!$locked) {
            continue;
        }
        try {
            $uploaded += cetak_drive_sync_registration($conn, $drive, $registrationId);
        } finally {
            $release = mysqli_prepare($conn, 'SELECT RELEASE_LOCK(?)');
            mysqli_stmt_bind_param($release, 's', $lockName);
            mysqli_stmt_execute($release);
            mysqli_stmt_close($release);
        }
    }
    return $uploaded;
}

/** PDF cetak peserta yang dihapus ikut dipindah ke sampah Drive. */
function cetak_drive_trash_registration(mysqli $conn, string $registrationId): void
{
    if (!ppdb_table_exists($conn, 'tb_cetak_drive')) {
        return;
    }
    $records = cetak_drive_records($conn, $registrationId);
    if ($records) {
        try {
            $drive = google_drive_service($conn);
            if ($drive !== null) {
                foreach ($records as $record) {
                    google_drive_trash($drive, $record['drive_file_id']);
                }
            }
        } catch (Throwable $exception) {
            error_log('SPMB cetak Drive trash failed: '.$exception->getMessage());
        }
    }
    $delete = mysqli_prepare($conn, 'DELETE FROM tb_cetak_drive WHERE id_pendaftaran=?');
    mysqli_stmt_bind_param($delete, 's', $registrationId);
    mysqli_stmt_execute($delete);
    mysqli_stmt_close($delete);
}
