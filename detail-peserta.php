<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
require_once 'wablas.php';
require_admin();
require_once __DIR__ . '/admin-partials.php';

$registrationId = $_GET['id'] ?? $_POST['id'] ?? '';
$error = '';
$notice = '';
$shareLinks = null;
$shareTitle = '';
if (!preg_match('/^P[0-9]{9}$/', $registrationId)) {
    http_response_code(404);
    exit('Data peserta tidak ditemukan.');
}

function ppdb_active_queue_rank(mysqli $conn, string $unitCode, string $academicYear, string $track, int $queueNumber): int
{
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM tb_pendaftaran WHERE kode_unit=? AND th_ajaran=? AND jalur_pendaftaran=? AND nomor_antrian<? AND status_ppdb NOT IN ('ditolak','data_lama')");
    mysqli_stmt_bind_param($stmt, 'sssi', $unitCode, $academicYear, $track, $queueNumber);
    mysqli_stmt_execute($stmt);
    $rank = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'] + 1;
    mysqli_stmt_close($stmt);
    return $rank;
}

/** Menghitung kursi yang tersisa berdasarkan urutan antrean dan kuota. */
function ppdb_seat_availability(mysqli $conn, array $locked): array
{
    $quota = $locked['jalur_pendaftaran'] === 'internal' ? $locked['kuota_internal'] : $locked['kuota_eksternal'];
    $hasQuota = $quota !== null;
    $activeRank = ppdb_active_queue_rank($conn, $locked['kode_unit'], $locked['th_ajaran'], $locked['jalur_pendaftaran'], (int) $locked['nomor_antrian']);
    $withinQuota = $hasQuota && $activeRank <= (int) $quota;

    $acceptedQuery = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM tb_pendaftaran WHERE kode_unit=? AND th_ajaran=? AND jalur_pendaftaran=? AND status_ppdb='diterima'");
    mysqli_stmt_bind_param($acceptedQuery, 'sss', $locked['kode_unit'], $locked['th_ajaran'], $locked['jalur_pendaftaran']);
    mysqli_stmt_execute($acceptedQuery);
    $acceptedCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($acceptedQuery))['total'];
    mysqli_stmt_close($acceptedQuery);

    $earlierQuery = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM tb_pendaftaran WHERE kode_unit=? AND th_ajaran=? AND jalur_pendaftaran=? AND nomor_antrian<? AND status_ppdb NOT IN ('diterima','ditolak','data_lama')");
    mysqli_stmt_bind_param($earlierQuery, 'sssi', $locked['kode_unit'], $locked['th_ajaran'], $locked['jalur_pendaftaran'], $locked['nomor_antrian']);
    mysqli_stmt_execute($earlierQuery);
    $earlierOpen = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($earlierQuery))['total'];
    mysqli_stmt_close($earlierQuery);

    return [
        'active_rank' => $activeRank,
        'accepted' => $acceptedCount,
        'earlier_open' => $earlierOpen,
        'seat_available' => $withinQuota && $acceptedCount < (int) $quota && $earlierOpen === 0,
        'within_quota' => $withinQuota,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi formulir kedaluwarsa. Muat ulang halaman dan coba kembali.';
    } else {
        $action = $_POST['workflow_action'] ?? 'operator_update';
        $adminId = (int) $_SESSION['admin_id'];
        $reviewNote = trim($_POST['catatan_admin'] ?? '');

        if ($action === 'operator_update') {
            $nipd = trim($_POST['nipd'] ?? '');
            $admissionDate = trim($_POST['tanggal_masuk_sekolah'] ?? '');
            $latitude = trim($_POST['latitude'] ?? '');
            $longitude = trim($_POST['longitude'] ?? '');
            if (strlen($nipd) > 30) {
                $error = 'NIPD maksimal 30 karakter.';
            } elseif ($admissionDate !== '' && !DateTime::createFromFormat('Y-m-d', $admissionDate)) {
                $error = 'Tanggal masuk sekolah tidak valid.';
            } elseif (($latitude === '') !== ($longitude === '')) {
                $error = 'Lintang dan bujur harus diisi berpasangan.';
            } elseif ($latitude !== '' && (!is_numeric($latitude) || (float) $latitude < -90 || (float) $latitude > 90)) {
                $error = 'Nilai lintang harus berada antara -90 dan 90.';
            } elseif ($longitude !== '' && (!is_numeric($longitude) || (float) $longitude < -180 || (float) $longitude > 180)) {
                $error = 'Nilai bujur harus berada antara -180 dan 180.';
            } else {
                $update = mysqli_prepare($conn, 'INSERT INTO tb_detail_dapodik (id_pendaftaran,nipd,tanggal_masuk_sekolah,latitude,longitude) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE nipd=VALUES(nipd),tanggal_masuk_sekolah=VALUES(tanggal_masuk_sekolah),latitude=VALUES(latitude),longitude=VALUES(longitude)');
                mysqli_stmt_bind_param($update, 'sssss', $registrationId, $nipd, $admissionDate === '' ? null : $admissionDate, $latitude === '' ? null : $latitude, $longitude === '' ? null : $longitude);
                if (mysqli_stmt_execute($update)) {
                    enqueue_google_sheets_registration($conn, $registrationId);
                    sync_google_sheets_registration($conn, $registrationId);
                    $notice = 'Data operator berhasil diperbarui.';
                } else {
                    $error = 'Data operator belum dapat disimpan.';
                }
                mysqli_stmt_close($update);
            }
        } elseif ($action === 'send_payment_link') {
            try {
                mysqli_begin_transaction($conn);
                $lock = mysqli_prepare($conn, 'SELECT p.status_ppdb,p.kode_unit,p.th_ajaran,p.jalur_pendaftaran,p.nomor_antrian,c.kuota_internal,c.kuota_eksternal,c.masa_pembayaran_hari FROM tb_pendaftaran p LEFT JOIN tb_pengaturan_ppdb c ON c.kode_unit=p.kode_unit AND c.th_ajaran=p.th_ajaran WHERE p.id_pendaftaran=? FOR UPDATE');
                mysqli_stmt_bind_param($lock, 's', $registrationId);
                mysqli_stmt_execute($lock);
                $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
                mysqli_stmt_close($lock);
                if (!$locked || !in_array($locked['status_ppdb'], ['menunggu_kontak', 'menunggu_pembayaran'], true)) {
                    throw new RuntimeException('Tautan pembayaran hanya dapat disiapkan saat peserta menunggu dihubungi.');
                }
                $availability = ppdb_seat_availability($conn, $locked);
                if (!$availability['within_quota']) {
                    throw new RuntimeException('Posisi antrean peserta di luar kuota saat ini. Tunda sampai kursi tersedia.');
                }
                // Batas bayar hanya dibuat saat pertama kali transitioning dari kontak.
                $nextDeadline = $locked['status_ppdb'] === 'menunggu_pembayaran'
                    ? null
                    : date('Y-m-d', strtotime('+' . max(1, (int) $locked['masa_pembayaran_hari']) . ' days'));
                $update = mysqli_prepare($conn, "UPDATE tb_pendaftaran SET status_ppdb='menunggu_pembayaran',status_pembayaran='belum_bayar',tanggal_batas_bayar=COALESCE(tanggal_batas_bayar,?) WHERE id_pendaftaran=?");
                mysqli_stmt_bind_param($update, 'ss', $nextDeadline, $registrationId);
                mysqli_stmt_execute($update);
                mysqli_stmt_close($update);
                mysqli_commit($conn);

                $shareLinks = ppdb_wablas_send_links(ppdb_prepare_payment_link($conn, $registrationId));
                $shareTitle = 'Tautan pembayaran siap dikirim. Pilih nomor di bawah untuk membuka WhatsApp.';
                enqueue_google_sheets_registration($conn, $registrationId);
                sync_google_sheets_registration($conn, $registrationId);
                $notice = 'Tautan pembayaran sudah aktif. Klik salah satu nomor untuk mengirim lewat WhatsApp.';
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Tautan pembayaran gagal disiapkan.';
            }
        } elseif ($action === 'reject_initial') {
            try {
                mysqli_begin_transaction($conn);
                $lock = mysqli_prepare($conn, "SELECT status_ppdb FROM tb_pendaftaran WHERE id_pendaftaran=? FOR UPDATE");
                mysqli_stmt_bind_param($lock, 's', $registrationId);
                mysqli_stmt_execute($lock);
                $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
                mysqli_stmt_close($lock);
                if (!$locked || !in_array($locked['status_ppdb'], ['menunggu_kontak', 'menunggu_pembayaran', 'pembayaran_diperiksa'], true)) {
                    throw new RuntimeException('Pendaftaran ini sudah tidak dapat ditolak pada tahap ini.');
                }
                $update = mysqli_prepare($conn, "UPDATE tb_pendaftaran SET status_ppdb='ditolak',status_data='perlu_perbaikan',data_diperiksa_oleh=?,data_diperiksa_pada=NOW() WHERE id_pendaftaran=?");
                mysqli_stmt_bind_param($update, 'is', $adminId, $registrationId);
                mysqli_stmt_execute($update);
                mysqli_stmt_close($update);
                mysqli_commit($conn);
                enqueue_google_sheets_registration($conn, $registrationId);
                sync_google_sheets_registration($conn, $registrationId);
                $notice = $reviewNote !== '' ? 'Pendaftaran ditolak. Catatan: ' . $reviewNote : 'Pendaftaran ditolak setelah pemeriksaan data awal.';
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Penolakan gagal diproses.';
            }
        } elseif (in_array($action, ['approve_payment', 'reject_payment'], true)) {
            $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT);
            try {
                mysqli_begin_transaction($conn);
                $lock = mysqli_prepare($conn, 'SELECT p.status_ppdb,p.status_pembayaran,p.kode_unit,p.th_ajaran,p.jalur_pendaftaran,p.nomor_antrian,c.kuota_internal,c.kuota_eksternal,b.status_verifikasi FROM tb_pendaftaran p JOIN tb_pengaturan_ppdb c ON c.kode_unit=p.kode_unit AND c.th_ajaran=p.th_ajaran JOIN tb_bukti_pembayaran b ON b.id=? AND b.id_pendaftaran=p.id_pendaftaran WHERE p.id_pendaftaran=? FOR UPDATE');
                mysqli_stmt_bind_param($lock, 'is', $paymentId, $registrationId);
                mysqli_stmt_execute($lock);
                $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
                mysqli_stmt_close($lock);
                if (!$locked || $locked['status_verifikasi'] !== 'menunggu_verifikasi') {
                    throw new RuntimeException('Bukti pembayaran tidak ditemukan atau sudah ditinjau.');
                }

                $paymentStatus = $action === 'approve_payment' ? 'disetujui' : 'ditolak';
                $paymentUpdate = mysqli_prepare($conn, 'UPDATE tb_bukti_pembayaran SET status_verifikasi=?,catatan_admin=?,diperiksa_oleh=?,diperiksa_pada=NOW() WHERE id=?');
                mysqli_stmt_bind_param($paymentUpdate, 'ssii', $paymentStatus, $reviewNote, $adminId, $paymentId);
                mysqli_stmt_execute($paymentUpdate);
                mysqli_stmt_close($paymentUpdate);

                if ($action === 'reject_payment') {
                    $newPpdbStatus = 'menunggu_pembayaran';
                    $newPaymentStatus = 'ditolak';
                } else {
                    $availability = ppdb_seat_availability($conn, $locked);
                    $newPpdbStatus = 'pembayaran_terverifikasi';
                    $newPaymentStatus = 'terverifikasi';
                }
                $registrationUpdate = mysqli_prepare($conn, 'UPDATE tb_pendaftaran SET status_ppdb=?,status_pembayaran=?,pembayaran_diperiksa_oleh=?,pembayaran_diperiksa_pada=NOW() WHERE id_pendaftaran=?');
                mysqli_stmt_bind_param($registrationUpdate, 'ssis', $newPpdbStatus, $newPaymentStatus, $adminId, $registrationId);
                mysqli_stmt_execute($registrationUpdate);
                mysqli_stmt_close($registrationUpdate);
                mysqli_commit($conn);
                enqueue_google_sheets_registration($conn, $registrationId);
                sync_google_sheets_registration($conn, $registrationId);

                if ($action === 'approve_payment') {
                    $notice = 'Pembayaran disetujui. Kirim tautan formulir lengkap ke pendaftar.';
                } else {
                    $notice = $reviewNote !== ''
                        ? 'Bukti pembayaran ditolak. Peserta dapat mengunggah ulang: ' . $reviewNote
                        : 'Bukti pembayaran ditolak. Peserta dapat mengunggah ulang sebelum tenggat.';
                }
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Status pembayaran belum dapat diperbarui.';
            }
        } elseif ($action === 'send_formulir_link') {
            try {
                mysqli_begin_transaction($conn);
                $lock = mysqli_prepare($conn, "SELECT status_ppdb FROM tb_pendaftaran WHERE id_pendaftaran=? FOR UPDATE");
                mysqli_stmt_bind_param($lock, 's', $registrationId);
                mysqli_stmt_execute($lock);
                $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
                mysqli_stmt_close($lock);
                if (!$locked || !in_array($locked['status_ppdb'], ['pembayaran_terverifikasi', 'menunggu_formulir'], true)) {
                    throw new RuntimeException('Tautan formulir hanya dapat disiapkan setelah pembayaran disetujui.');
                }
                $update = mysqli_prepare($conn, "UPDATE tb_pendaftaran SET status_ppdb=IF(status_ppdb='pembayaran_terverifikasi','menunggu_formulir',status_ppdb) WHERE id_pendaftaran=?");
                mysqli_stmt_bind_param($update, 's', $registrationId);
                mysqli_stmt_execute($update);
                mysqli_stmt_close($update);
                mysqli_commit($conn);

                $shareLinks = ppdb_wablas_send_links(ppdb_prepare_formulir_link($conn, $registrationId));
                $shareTitle = 'Tautan formulir lengkap siap dikirim. Pilih nomor di bawah untuk membuka WhatsApp.';
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Tautan formulir gagal disiapkan.';
            }
        } elseif (in_array($action, ['accept_final', 'waitlist_final', 'reject_formulir'], true)) {
            try {
                mysqli_begin_transaction($conn);
                $lock = mysqli_prepare($conn, 'SELECT p.status_ppdb,p.status_data,p.status_pembayaran,p.kode_unit,p.th_ajaran,p.jalur_pendaftaran,p.nomor_antrian,c.kuota_internal,c.kuota_eksternal FROM tb_pendaftaran p LEFT JOIN tb_pengaturan_ppdb c ON c.kode_unit=p.kode_unit AND c.th_ajaran=p.th_ajaran WHERE p.id_pendaftaran=? FOR UPDATE');
                mysqli_stmt_bind_param($lock, 's', $registrationId);
                mysqli_stmt_execute($lock);
                $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
                mysqli_stmt_close($lock);
                if (!$locked || $locked['status_ppdb'] !== 'formulir_terisi') {
                    throw new RuntimeException('Formulir lengkap belum masuk antrean pemeriksaan.');
                }

                if ($action === 'accept_final') {
                    $availability = ppdb_seat_availability($conn, $locked);
                    if (!$availability['seat_available']) {
                        throw new RuntimeException('Kursi belum tersedia. Gunakan daftar tunggu bila kuota masih penuh.');
                    }
                    $newStatus = 'diterima';
                    $newDataStatus = 'terverifikasi';
                } elseif ($action === 'waitlist_final') {
                    $newStatus = 'daftar_tunggu';
                    $newDataStatus = 'terverifikasi';
                } else {
                    if ($reviewNote === '') {
                        throw new RuntimeException('Alasan penolakan wajib diisi.');
                    }
                    $newStatus = 'ditolak';
                    $newDataStatus = 'perlu_perbaikan';
                }

                $update = mysqli_prepare($conn, 'UPDATE tb_pendaftaran SET status_ppdb=?,status_data=?,data_diperiksa_oleh=?,data_diperiksa_pada=NOW() WHERE id_pendaftaran=?');
                mysqli_stmt_bind_param($update, 'ssis', $newStatus, $newDataStatus, $adminId, $registrationId);
                mysqli_stmt_execute($update);
                mysqli_stmt_close($update);
                mysqli_commit($conn);
                enqueue_google_sheets_registration($conn, $registrationId);
                sync_google_sheets_registration($conn, $registrationId);
                $notice = [
                    'accept_final' => 'Peserta dinyatakan diterima.',
                    'waitlist_final' => 'Peserta ditempatkan pada daftar tunggu kuota.',
                    'reject_formulir' => 'Formulir ditolak. Catatan: ' . $reviewNote,
                ][$action];
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Keputusan belum dapat disimpan.';
            }
        } else {
            $error = 'Aksi verifikasi tidak dikenali.';
        }
    }
}

$stmt = mysqli_prepare($conn, 'SELECT p.*,u.nama_unit,d.*,c.kuota_internal,c.kuota_eksternal,c.biaya_pendaftaran,c.bank_nama,c.nomor_rekening,c.nama_pemilik_rekening,c.masa_pembayaran_hari,i.nama_sdm,i.unit_kerja FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit LEFT JOIN tb_detail_dapodik d ON d.id_pendaftaran=p.id_pendaftaran LEFT JOIN tb_pengaturan_ppdb c ON c.kode_unit=p.kode_unit AND c.th_ajaran=p.th_ajaran LEFT JOIN tb_undangan_internal i ON i.id=p.undangan_internal_id WHERE p.id_pendaftaran=? LIMIT 1');
mysqli_stmt_bind_param($stmt, 's', $registrationId);
mysqli_stmt_execute($stmt);
$participantResult = mysqli_stmt_get_result($stmt);
$participant = $participantResult ? mysqli_fetch_assoc($participantResult) : null;
mysqli_stmt_free_result($stmt);
mysqli_stmt_close($stmt);
if (!$participant) {
    http_response_code(404);
    exit('Data peserta tidak ditemukan.');
}
$dapodikAge = ppdb_dapodik_age($participant['tgl_lahir'], $participant['th_ajaran']);
$participant['umur_dapodik'] = ppdb_dapodik_age_label($dapodikAge);

$paymentQuery = mysqli_prepare($conn, 'SELECT id,nomor_referensi,nama_file_asli,mime_type,ukuran_byte,status_verifikasi,catatan_admin,diunggah_pada,diperiksa_pada FROM tb_bukti_pembayaran WHERE id_pendaftaran=? ORDER BY id DESC LIMIT 1');
mysqli_stmt_bind_param($paymentQuery, 's', $registrationId);
mysqli_stmt_execute($paymentQuery);
$paymentResult = mysqli_stmt_get_result($paymentQuery);
$latestPayment = $paymentResult ? mysqli_fetch_assoc($paymentResult) : null;
mysqli_stmt_free_result($paymentQuery);
mysqli_stmt_close($paymentQuery);

$documentLabels = [
    'foto' => 'Foto peserta didik',
    'nisn' => 'Foto / scan NISN',
    'kk' => 'Scan Kartu Keluarga',
    'akta_lahir' => 'Scan Akta Kelahiran',
    'ktp_ayah' => 'Scan KTP Ayah',
    'ktp_ibu' => 'Scan KTP Ibu',
];
$documents = [];
$documentQuery = mysqli_prepare($conn, 'SELECT id,jenis_dokumen,nama_file_asli,mime_type,ukuran_byte,diunggah_pada FROM tb_dokumen_peserta WHERE id_pendaftaran=?');
mysqli_stmt_bind_param($documentQuery, 's', $registrationId);
mysqli_stmt_execute($documentQuery);
$documentResult = mysqli_stmt_get_result($documentQuery);
if ($documentResult) {
    while ($row = mysqli_fetch_assoc($documentResult)) {
        $documents[$row['jenis_dokumen']] = $row;
    }
    mysqli_free_result($documentResult);
}
mysqli_stmt_close($documentQuery);

$questionnaireAnswers = null;
$questionnaireQuery = mysqli_prepare($conn, 'SELECT jawaban_json,submitted_at FROM tb_ppdb_questionnaire_answers WHERE id_pendaftaran=? LIMIT 1');
mysqli_stmt_bind_param($questionnaireQuery, 's', $registrationId);
mysqli_stmt_execute($questionnaireQuery);
$questionnaireRow = mysqli_fetch_assoc(mysqli_stmt_get_result($questionnaireQuery));
mysqli_stmt_close($questionnaireQuery);
if ($questionnaireRow) {
    $questionnaireAnswers = json_decode($questionnaireRow['jawaban_json'], true);
    if (!is_array($questionnaireAnswers)) {
        $questionnaireAnswers = null;
    }
}

$statusInfo = ppdb_status_info($participant['status_ppdb']);
$paymentStatusLabels = [
    'belum_bayar' => 'Belum bayar',
    'menunggu_verifikasi' => 'Menunggu verifikasi',
    'terverifikasi' => 'Terverifikasi',
    'ditolak' => 'Ditolak',
];
$dataStatusLabels = [
    'belum_diperiksa' => 'Belum diperiksa',
    'terverifikasi' => 'Terverifikasi',
    'perlu_perbaikan' => 'Perlu perbaikan',
];

function render_detail_panel(string $title, array $fields, array $participant, bool $wide = false): void
{
    echo '<section class="detail-panel' . ($wide ? ' wide' : '') . '"><h2>' . h($title) . '</h2><dl class="detail-list">';
    foreach ($fields as [$label, $key]) {
        $value = $participant[$key] ?? '';
        if (in_array($key, ['tgl_lahir', 'tanggal_batas_bayar', 'waktu_daftar', 'tanggal_masuk_sekolah'], true) && $value !== '' && $value !== null) {
            $value = date('d-m-Y', strtotime($value));
        } elseif ($key === 'biaya_pendaftaran' && $value !== '' && $value !== null) {
            $value = 'Rp ' . number_format((float) $value, 0, ',', '.');
        } elseif ($key === 'pendaftar') {
            $value = ppdb_pendaftar_label($value);
        } elseif ($key === 'jenis_kelamin' && $value !== null) {
            $value = ppdb_jenis_kelamin_label($value);
        } elseif (in_array($key, ['no_hp_pendaftar', 'no_hp_ayah', 'no_hp_ibu'], true) && $value !== '') {
            $value = ppdb_display_phone($value);
        }
        echo '<div><dt>' . h($label) . '</dt><dd>' . h($value === '' || $value === null ? 'Belum diisi' : $value) . '</dd></div>';
    }
    echo '</dl></section>';
}

$activeAdminPage = 'registrations';
$adminPageTitle = 'Detail peserta';
$adminPageDescription = 'Kelola satu pendaftaran: data awal, pembayaran, dokumen, dan keputusan penerimaan.';
require 'admin_header.php';
?>
<div class="admin-content">
    <div class="page-heading">
        <div>
            <p class="eyebrow">DATA PESERTA DIDIK</p>
            <h1><?php echo h($participant['nm_peserta']); ?></h1>
            <p><?php echo h($participant['id_pendaftaran']); ?> · <?php echo h($participant['nama_unit'] ?? 'Data lama'); ?> · <?php echo h($participant['th_ajaran']); ?></p>
        </div>
        <div class="table-actions">
            <a class="button-secondary" href="cetak-bukti.php?id=<?php echo rawurlencode($registrationId); ?>">Unduh bukti PDF</a>
            <a class="button-secondary" href="daftar_peserta.php">&larr; Kembali</a>
        </div>
    </div>
    <?php if ($error !== '') { ?><div class="alert-error" role="alert"><?php echo h($error); ?></div><?php } ?>
    <?php if ($notice !== '') { ?><div class="alert-success" role="status"><?php echo h($notice); ?></div><?php } ?>

    <?php if ($shareLinks) { ?>
        <section class="detail-panel wide share-panel" aria-labelledby="share-title">
            <div class="share-panel__head">
                <h2 id="share-title"><?php echo h($shareTitle); ?></h2>
                <button class="button-secondary" type="button" data-copy-target="#share-url">Salin tautan</button>
            </div>
            <p class="share-panel__hint"><?php echo ppdb_wablas_enabled()
                ? 'Pesan dikirim otomatis lewat Wablas. Jika ada yang gagal, klik nomornya untuk mengirim manual.'
                : 'Pesan sudah terisi otomatis pada WhatsApp. Pilih nomor yang ingin dihubungi, lalu tekan tombol kirim di WhatsApp.'; ?></p>
            <div class="share-links">
                <?php foreach ($shareLinks['links'] as $link) { ?>
                    <a class="share-link" href="<?php echo h($link['url']); ?>" target="_blank" rel="noopener">
                        <span class="share-link__role"><?php echo h($link['role']); ?></span>
                        <span class="share-link__phone"><?php echo h($link['display']); ?></span>
                        <span class="share-link__action"><?php
                            if (!isset($link['sent'])) { echo 'Buka WhatsApp &rarr;'; }
                            elseif ($link['sent']) { echo '&#10003; Terkirim otomatis'; }
                            else { echo 'Gagal ('.h($link['send_error']).') &middot; kirim manual &rarr;'; }
                        ?></span>
                    </a>
                <?php } ?>
            </div>
            <label class="share-field">
                <span>Tautan langsung</span>
                <input id="share-url" type="text" readonly value="<?php echo h($shareLinks['status_url']); ?>" data-copy-source>
            </label>
        </section>
    <?php } ?>

    <section class="detail-panel wide workflow-panel">
        <h2>Alur penerimaan</h2>
        <div class="workflow-summary">
            <div><span>Status PPDB</span><strong class="tone-<?php echo h($statusInfo['tone']); ?>"><?php echo h($statusInfo['label']); ?></strong></div>
            <div><span>Status data</span><strong><?php echo h($dataStatusLabels[$participant['status_data']] ?? $participant['status_data']); ?></strong></div>
            <div><span>Status pembayaran</span><strong><?php echo h($paymentStatusLabels[$participant['status_pembayaran']] ?? $participant['status_pembayaran']); ?></strong></div>
            <div><span>Jalur pendaftaran</span><strong><?php echo $participant['jalur_pendaftaran'] === 'internal' ? 'Internal · SDM yayasan' : 'Eksternal · umum'; ?></strong></div>
            <?php if ($participant['jalur_pendaftaran'] === 'internal' && $participant['nama_sdm']) { ?><div><span>Undangan SDM</span><strong><?php echo h($participant['nama_sdm']); ?><?php echo $participant['unit_kerja'] !== '' ? ' · ' . h($participant['unit_kerja']) : ''; ?></strong></div><?php } ?>
            <div><span>Antrean / kuota jalur</span><strong><?php echo $participant['nomor_antrian'] ? '#' . (int) $participant['nomor_antrian'] . ' / ' . (int) ($participant['jalur_pendaftaran'] === 'internal' ? $participant['kuota_internal'] : $participant['kuota_eksternal']) : 'Arsip lama'; ?></strong></div>
            <?php if ($participant['tanggal_batas_bayar']) { ?><div><span>Batas pembayaran</span><strong><?php echo h(date('d-m-Y', strtotime($participant['tanggal_batas_bayar']))); ?></strong></div><?php } ?>
        </div>

        <?php if ($participant['status_ppdb'] === 'menunggu_kontak') { ?>
            <p class="workflow-hint">Data awal sudah lengkap. Periksa nama, tanggal lahir, dan unit, lalu siapkan tautan pembayaran untuk dikirim ke nomor WhatsApp pendaftar.</p>
            <div class="contact-strip">
                <div><span>Pendaftar</span><strong><?php echo h(ppdb_display_phone($participant['no_hp_pendaftar'])); ?></strong></div>
                <div><span>Ayah</span><strong><?php echo h(ppdb_display_phone($participant['no_hp_ayah'])); ?></strong></div>
                <div><span>Ibu</span><strong><?php echo h(ppdb_display_phone($participant['no_hp_ibu'])); ?></strong></div>
            </div>
            <div class="workflow-actions">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="workflow_action" value="send_payment_link">
                    <button class="button-primary" type="submit">Siapkan tautan pembayaran</button>
                </form>
                <form method="post" class="stacked-action">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="workflow_action" value="reject_initial">
                    <label for="reject-initial-note">Alasan penolakan (opsional)</label>
                    <input id="reject-initial-note" name="catatan_admin" maxlength="500">
                    <button class="button-danger" type="submit">Tolak pendaftaran</button>
                </form>
            </div>
        <?php } elseif ($participant['status_ppdb'] === 'pembayaran_diperiksa' && $latestPayment) { ?>
            <div class="payment-review-summary">
                <span>Bukti transfer diunggah <?php echo h(date('d-m-Y H:i', strtotime($latestPayment['diunggah_pada']))); ?></span>
                <a class="button-secondary" href="bukti-pembayaran-admin.php?id=<?php echo (int) $latestPayment['id']; ?>" target="_blank" rel="noopener">Lihat bukti transfer &#8599;</a>
            </div>
            <div class="workflow-actions payment-actions">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="payment_id" value="<?php echo (int) $latestPayment['id']; ?>">
                    <input type="hidden" name="workflow_action" value="approve_payment">
                    <label for="approve-note">Catatan (opsional)</label>
                    <input id="approve-note" name="catatan_admin" maxlength="500">
                    <button class="button-primary" type="submit">Setujui pembayaran</button>
                </form>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="payment_id" value="<?php echo (int) $latestPayment['id']; ?>">
                    <input type="hidden" name="workflow_action" value="reject_payment">
                    <label for="reject-note">Alasan penolakan</label>
                    <input id="reject-note" name="catatan_admin" maxlength="500" required>
                    <button class="button-danger" type="submit">Tolak bukti</button>
                </form>
            </div>
        <?php } elseif ($participant['status_ppdb'] === 'pembayaran_terverifikasi') { ?>
            <p class="workflow-hint">Pembayaran disetujui. Siapkan tautan formulir lengkap untuk pendaftar; formulir hanya bisa diisi sekali.</p>
            <div class="workflow-actions">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="workflow_action" value="send_formulir_link">
                    <button class="button-primary" type="submit">Siapkan tautan formulir lengkap</button>
                </form>
            </div>
        <?php } elseif ($participant['status_ppdb'] === 'menunggu_formulir') { ?>
            <p class="workflow-hint">Tautan formulir sudah disiapkan. Menunggu pendaftar mengisi formulir lengkap beserta dokumen pendukung.</p>
            <div class="workflow-actions">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="workflow_action" value="send_formulir_link">
                    <button class="button-secondary" type="submit">Siapkan ulang tautan formulir</button>
                </form>
            </div>
        <?php } elseif ($participant['status_ppdb'] === 'formulir_terisi') { ?>
            <p class="workflow-hint">Formulir lengkap dan dokumen sudah masuk. Periksa kelengkapan berkas lalu tentukan keputusan akhir.</p>
            <div class="workflow-actions payment-actions">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="workflow_action" value="accept_final">
                    <button class="button-primary" type="submit">Terima peserta</button>
                </form>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="workflow_action" value="waitlist_final">
                    <button class="button-secondary" type="submit">Masukkan daftar tunggu</button>
                </form>
                <form method="post" class="stacked-action">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="workflow_action" value="reject_formulir">
                    <label for="reject-formulir-note">Alasan penolakan</label>
                    <input id="reject-formulir-note" name="catatan_admin" maxlength="500" required>
                    <button class="button-danger" type="submit">Tolak formulir</button>
                </form>
            </div>
        <?php } elseif ($participant['status_ppdb'] === 'menunggu_pembayaran') { ?>
            <p class="workflow-hint">Menunggu bukti transfer dari pendaftar. Nomor <?php echo h($registrationId); ?> menjadi berita transfer.</p>
            <div class="workflow-actions">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="workflow_action" value="send_payment_link">
                    <button class="button-secondary" type="submit">Siapkan ulang tautan pembayaran</button>
                </form>
            </div>
        <?php } elseif ($participant['status_ppdb'] === 'diterima') { ?>
            <p class="workflow-hint">Peserta telah diterima pada unit ini sesuai urutan dan kuota.</p>
        <?php } elseif ($participant['status_ppdb'] === 'daftar_tunggu') { ?>
            <p class="workflow-hint">Peserta berada pada daftar tunggu. Hubungi bila tersedia kursi sesuai antrean.</p>
        <?php } elseif ($participant['status_ppdb'] === 'ditolak') { ?>
            <p class="workflow-hint">Pendaftaran ditolak. Peserta perlu menghubungi panitia untuk tindak lanjut.</p>
        <?php } ?>
    </section>

    <section class="detail-panel wide">
        <h2>Dokumen pendukung</h2>
        <?php if (!$documents) { ?>
            <p class="panel-note" style="padding: 0 17px 17px;">Belum ada dokumen yang diunggah pendaftar.</p>
        <?php } else { ?>
            <div class="document-grid admin-documents">
                <?php foreach ($documentLabels as $slot => $label) {
                    $document = $documents[$slot] ?? null;
                ?>
                    <div class="document-card <?php echo $document ? 'has-file' : 'is-missing'; ?>">
                        <div class="document-card__head"><strong><?php echo h($label); ?></strong></div>
                        <?php if ($document) { ?>
                            <p class="document-card__done"><?php echo h($document['nama_file_asli']); ?> · <?php echo h(number_format((int) $document['ukuran_byte'] / 1048576, 2)); ?> MB</p>
                            <a class="button-secondary" href="dokumen-admin.php?id=<?php echo (int) $document['id']; ?>" target="_blank" rel="noopener">Buka berkas &nearr;</a>
                        <?php } else { ?>
                            <p class="document-card__done is-missing">Belum diunggah</p>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    </section>

    <div class="detail-grid">
        <?php render_detail_panel('Registrasi awal', [['Kode pendaftaran', 'id_pendaftaran'], ['Tanggal pendaftaran', 'tgl_daftar'], ['Unit pendidikan', 'nama_unit'], ['Tahun ajaran', 'th_ajaran'], ['Nomor antrean', 'nomor_antrian'], ['Nama ananda', 'nm_peserta'], ['Tanggal lahir', 'tgl_lahir'], ['Umur ananda terdeteksi berdasarkan Dapodik dari tanggal ' . ($dapodikAge['reference_date'] ?? '-') . ' berumur:', 'umur_dapodik'], ['Jenis kelamin', 'jenis_kelamin'], ['Yang mendaftarkan', 'pendaftar'], ['WhatsApp pendaftar', 'no_hp_pendaftar'], ['WhatsApp ayah', 'no_hp_ayah'], ['WhatsApp ibu', 'no_hp_ibu']], $participant); ?>
        <section class="detail-panel wide">
            <h2>Jawaban kuisioner</h2>
            <?php if (!$questionnaireAnswers || empty($questionnaireAnswers['questions'])) { ?>
                <p class="panel-note" style="padding: 0 17px 17px;">Belum ada jawaban kuisioner yang tersimpan.</p>
            <?php } else { ?>
                <dl class="detail-list">
                    <?php foreach ($questionnaireAnswers['questions'] as $question) {
                        $questionKey = (string) ($question['key'] ?? '');
                        $answer = $questionnaireAnswers['answers'][$questionKey] ?? '';
                        $answerText = is_array($answer) ? implode(', ', array_map('strval', $answer)) : (string) $answer;
                    ?>
                        <div>
                            <dt><?php echo h($question['prompt'] ?? 'Pertanyaan'); ?></dt>
                            <dd><?php echo h($answerText === '' ? 'Tidak diisi (opsional)' : $answerText); ?></dd>
                        </div>
                    <?php } ?>
                </dl>
                <p class="panel-note" style="padding: 0 17px 17px;">Dikirim pada <?php echo h($questionnaireRow['submitted_at']); ?>.</p>
            <?php } ?>
        </section>
        <?php render_detail_panel('Identitas pribadi', [['NIK', 'nik'], ['Nomor KK', 'nomor_kk'], ['NISN', 'NISN'], ['Tempat lahir', 'tmp_lahir'], ['Nomor registrasi akta', 'nomor_registrasi_akta'], ['Agama/kepercayaan', 'agama'], ['Kewarganegaraan', 'kewarganegaraan'], ['Berkebutuhan khusus', 'kebutuhan_khusus'], ['Jenis pendaftaran', 'jenis_pendaftaran'], ['Sekolah asal', 'asal_sekolah'], ['Pernah PAUD/TK', 'pernah_paud_tk']], $participant); ?>
        <?php render_detail_panel('Alamat dan tempat tinggal', [['Alamat jalan/dusun', 'alamat_jalan'], ['RT', 'rt'], ['RW', 'rw'], ['Desa/kelurahan', 'desa_kelurahan'], ['Kecamatan', 'kecamatan'], ['Kabupaten/kota', 'kabupaten'], ['Provinsi', 'provinsi'], ['Jenis tinggal', 'jenis_tinggal'], ['Transportasi', 'alat_transportasi'], ['Lintang', 'latitude'], ['Bujur', 'longitude']], $participant); ?>
        <?php render_detail_panel('Data ayah', [['Nama', 'ayah_nama'], ['NIK', 'ayah_nik'], ['Tahun lahir', 'ayah_tahun_lahir'], ['Pendidikan', 'ayah_pendidikan'], ['Pekerjaan', 'ayah_pekerjaan'], ['Penghasilan bulanan', 'ayah_penghasilan']], $participant); ?>
        <?php render_detail_panel('Data ibu', [['Nama', 'ibu_nama'], ['NIK', 'ibu_nik'], ['Tahun lahir', 'ibu_tahun_lahir'], ['Pendidikan', 'ibu_pendidikan'], ['Pekerjaan', 'ibu_pekerjaan'], ['Penghasilan bulanan', 'ibu_penghasilan']], $participant); ?>
        <?php render_detail_panel('Data wali', [['Nama', 'wali_nama'], ['NIK', 'wali_nik'], ['Tahun lahir', 'wali_tahun_lahir'], ['Pendidikan', 'wali_pendidikan'], ['Pekerjaan', 'wali_pekerjaan'], ['Penghasilan bulanan', 'wali_penghasilan']], $participant); ?>
        <?php render_detail_panel('Data periodik', [['Tinggi badan (cm)', 'tinggi_badan_cm'], ['Berat badan (kg)', 'berat_badan_kg'], ['Lingkar kepala (cm)', 'lingkar_kepala_cm'], ['Jarak ke sekolah (km)', 'jarak_ke_sekolah_km'], ['Waktu tempuh (menit)', 'waktu_tempuh_menit'], ['Jumlah saudara kandung', 'jumlah_saudara_kandung']], $participant); ?>
        <?php render_detail_panel('Pembayaran', [['Biaya pendaftaran', 'biaya_pendaftaran'], ['Bank', 'bank_nama'], ['Nomor rekening', 'nomor_rekening'], ['Atas nama', 'nama_pemilik_rekening'], ['Batas pembayaran', 'tanggal_batas_bayar']], $participant); ?>
        <section class="detail-panel wide">
            <h2>Data operator sekolah</h2>
            <form class="operator-form" method="post" action="detail-peserta.php?id=<?php echo rawurlencode($registrationId); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                <div class="filter-field"><label for="nipd">NIPD</label><input id="nipd" name="nipd" maxlength="30" value="<?php echo h($participant['nipd']); ?>"></div>
                <div class="filter-field"><label for="tanggal_masuk_sekolah">Tanggal masuk sekolah</label><input id="tanggal_masuk_sekolah" type="date" name="tanggal_masuk_sekolah" value="<?php echo h($participant['tanggal_masuk_sekolah']); ?>"></div>
                <div class="filter-field"><label for="latitude">Lintang</label><input id="latitude" name="latitude" type="number" min="-90" max="90" step="0.0000001" value="<?php echo h($participant['latitude']); ?>"></div>
                <div class="filter-field"><label for="longitude">Bujur</label><input id="longitude" name="longitude" type="number" min="-180" max="180" step="0.0000001" value="<?php echo h($participant['longitude']); ?>"></div>
                <button class="button-primary" type="submit">Simpan data operator</button>
            </form>
        </section>
    </div>
</div>
<?php require 'admin_footer.php'; ?>