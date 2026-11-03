<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
require_once 'wablas.php';
require_once 'formulir_helpers.php';
require_once 'prestasi_helpers.php';
require_admin();
require_once __DIR__ . '/admin-partials.php';

$registrationId = $_GET['id'] ?? $_POST['id'] ?? '';
$error = '';
$notice = '';
$whatsappDeliveryNotice = '';
$whatsappDeliveryTone = 'success';
if (!preg_match('/^P[0-9]{9}$/', $registrationId)) {
    http_response_code(404);
    exit('Data peserta tidak ditemukan.');
}
$accessCheck = mysqli_prepare($conn, 'SELECT kode_unit FROM tb_pendaftaran WHERE id_pendaftaran=? LIMIT 1');
mysqli_stmt_bind_param($accessCheck, 's', $registrationId);
mysqli_stmt_execute($accessCheck);
$accessibleRegistration = mysqli_fetch_assoc(mysqli_stmt_get_result($accessCheck));
mysqli_stmt_close($accessCheck);
if (!$accessibleRegistration || (!ppdb_is_super_admin() && $accessibleRegistration['kode_unit'] !== $_SESSION['admin_unit_code'])) {
    http_response_code(404);
    exit('Data peserta tidak ditemukan.');
}

/** Menghitung ketersediaan kursi berdasarkan peserta yang sudah diterima dan kuota jalur. */
function ppdb_seat_availability(mysqli $conn, array $locked): array
{
    $quota = $locked['jalur_pendaftaran'] === 'internal' ? $locked['kuota_internal'] : $locked['kuota_eksternal'];

    $acceptedQuery = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM tb_pendaftaran WHERE kode_unit=? AND th_ajaran=? AND jalur_pendaftaran=? AND status_ppdb='diterima'");
    mysqli_stmt_bind_param($acceptedQuery, 'sss', $locked['kode_unit'], $locked['th_ajaran'], $locked['jalur_pendaftaran']);
    mysqli_stmt_execute($acceptedQuery);
    $acceptedCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($acceptedQuery))['total'];
    mysqli_stmt_close($acceptedQuery);

    return [
        'accepted' => $acceptedCount,
        'quota' => (int) $quota,
        'seat_available' => $quota !== null && $acceptedCount < (int) $quota,
    ];
}

/** Pendaftaran yang tidak valid dapat ditolak sebelum formulir masuk pemeriksaan. */
function ppdb_detail_reject_form(string $registrationId): void
{
    echo '<div class="workflow-actions"><form method="post" class="stacked-action">';
    echo '<input type="hidden" name="csrf_token" value="'.h(csrf_token()).'">';
    echo '<input type="hidden" name="id" value="'.h($registrationId).'">';
    echo '<input type="hidden" name="workflow_action" value="reject_initial">';
    echo '<label for="reject-initial-note">Alasan penolakan (opsional)</label>';
    echo '<input id="reject-initial-note" name="catatan_admin" maxlength="500">';
    echo '<button class="button-danger" type="submit">Tolak pendaftaran</button>';
    echo '</form></div>';
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
        } elseif ($action === 'reject_initial') {
            try {
                mysqli_begin_transaction($conn);
                $lock = mysqli_prepare($conn, "SELECT status_ppdb FROM tb_pendaftaran WHERE id_pendaftaran=? FOR UPDATE");
                mysqli_stmt_bind_param($lock, 's', $registrationId);
                mysqli_stmt_execute($lock);
                $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
                mysqli_stmt_close($lock);
                if (!$locked || !in_array($locked['status_ppdb'], ['menunggu_kontak', 'menunggu_pembayaran', 'pembayaran_diperiksa', 'pembayaran_terverifikasi', 'menunggu_formulir'], true)) {
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
                    // Pendaftar yang sudah mengirim formulir tetap di tahapnya, cukup unggah ulang bukti.
                    $newPpdbStatus = $locked['status_ppdb'] === 'pembayaran_diperiksa' ? 'menunggu_pembayaran' : $locked['status_ppdb'];
                    $newPaymentStatus = 'ditolak';
                } else {
                    $availability = ppdb_seat_availability($conn, $locked);
                    $newPpdbStatus = $locked['status_ppdb'] === 'pembayaran_diperiksa' ? 'menunggu_formulir' : $locked['status_ppdb'];
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
                    $notice = 'Pembayaran disetujui.';
                } else {
                    $notice = $reviewNote !== ''
                        ? 'Bukti pembayaran ditolak. Peserta dapat mengunggah ulang: ' . $reviewNote
                        : 'Bukti pembayaran ditolak. Peserta dapat mengunggah ulang sebelum tenggat.';
                }
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Status pembayaran belum dapat diperbarui.';
            }
        } elseif ($action === 'reopen_registration') {
            try {
                mysqli_begin_transaction($conn);
                $update = mysqli_prepare($conn, "UPDATE tb_pendaftaran SET status_ppdb='menunggu_formulir' WHERE id_pendaftaran=? AND status_ppdb='ditolak' AND status_data='belum_diperiksa' AND status_pembayaran IN ('terverifikasi','menunggu_verifikasi')");
                mysqli_stmt_bind_param($update, 's', $registrationId);
                mysqli_stmt_execute($update);
                if (mysqli_stmt_affected_rows($update) !== 1) {
                    mysqli_stmt_close($update);
                    throw new RuntimeException('Pendaftaran ini tidak dapat dibuka kembali.');
                }
                mysqli_stmt_close($update);
                mysqli_commit($conn);
                enqueue_google_sheets_registration($conn, $registrationId);
                sync_google_sheets_registration($conn, $registrationId);
                $notice = 'Pendaftaran dibuka kembali. Pendaftar dapat melanjutkan lewat halaman Lanjutkan pendaftaran.';
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Pendaftaran belum dapat dibuka kembali.';
            }
        } elseif (in_array($action, ['accept_final', 'waitlist_final', 'reject_formulir'], true)) {
            try {
                mysqli_begin_transaction($conn);
                $lock = mysqli_prepare($conn, 'SELECT p.status_ppdb,p.status_data,p.status_pembayaran,p.kode_unit,p.th_ajaran,p.jalur_pendaftaran,p.nomor_antrian,p.nm_peserta,p.no_hp_pendaftar,u.nama_unit,g.link_grup,c.kuota_internal,c.kuota_eksternal FROM tb_pendaftaran p LEFT JOIN tb_pengaturan_ppdb c ON c.kode_unit=p.kode_unit AND c.th_ajaran=p.th_ajaran LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit LEFT JOIN tb_unit_whatsapp_groups g ON g.kode_unit=p.kode_unit WHERE p.id_pendaftaran=? FOR UPDATE');
                mysqli_stmt_bind_param($lock, 's', $registrationId);
                mysqli_stmt_execute($lock);
                $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
                mysqli_stmt_close($lock);
                // Peserta daftar tunggu dapat diterima saat kursi tersedia, atau ditolak.
                $fromWaitlist = $locked && $locked['status_ppdb'] === 'daftar_tunggu' && $action !== 'waitlist_final';
                if (!$locked || ($locked['status_ppdb'] !== 'formulir_terisi' && !$fromWaitlist)) {
                    throw new RuntimeException('Formulir lengkap belum masuk antrean pemeriksaan.');
                }
                if ($action !== 'reject_formulir' && $locked['status_pembayaran'] !== 'terverifikasi') {
                    throw new RuntimeException('Setujui bukti pembayaran terlebih dahulu sebelum menentukan keputusan.');
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
                $notice = [
                    'accept_final' => 'Peserta dinyatakan diterima.',
                    'waitlist_final' => 'Peserta ditempatkan pada daftar tunggu kuota.',
                    'reject_formulir' => 'Formulir ditolak. Catatan: ' . $reviewNote,
                ][$action];

                if ($action === 'accept_final') {
                    if (!ppdb_wablas_enabled()) {
                        $whatsappDeliveryNotice = 'Status penerimaan tersimpan dan sudah tampil pada halaman pendaftaran pendaftar. Ucapan WhatsApp otomatis belum dikirim karena integrasi Wablas belum dikonfigurasi.';
                        $whatsappDeliveryTone = 'warning';
                    } else {
                        $recipientPhone = ppdb_normalize_whatsapp_number((string) $locked['no_hp_pendaftar']);
                        if ($recipientPhone === '') {
                            $whatsappDeliveryNotice = 'Peserta sudah diterima, tetapi nomor WhatsApp pendaftar tidak valid sehingga pesan belum dapat dikirim.';
                            $whatsappDeliveryTone = 'warning';
                        } else {
                            $messageLines = [
                                'Assalamu’alaikum warahmatullahi wabarakatuh.',
                                '',
                                'Alhamdulillah, ananda *'.$locked['nm_peserta'].'* telah dinyatakan *DITERIMA* di *'.$locked['nama_unit'].'*.',
                                'Terima kasih telah mempercayakan pendidikan ananda kepada kami.',
                                'Tetap semangat belajar dan bertumbuh. Semoga ananda meraih prestasi dan menjadi kebanggaan keluarga.',
                            ];
                            $groupLink = trim((string) ($locked['link_grup'] ?? ''));
                            if ($groupLink !== '') {
                                $messageLines[] = '';
                                $messageLines[] = 'Silakan bergabung dengan grup WhatsApp '.$locked['nama_unit'].' melalui tautan berikut:';
                                $messageLines[] = $groupLink;
                            } else {
                                $messageLines[] = '';
                                $messageLines[] = 'Informasi grup WhatsApp akan disampaikan oleh panitia.';
                            }
                            $messageLines[] = '';
                            $messageLines[] = ppdb_admin_contact_message();

                            try {
                                $delivery = ppdb_wablas_send($recipientPhone, implode("\n", $messageLines));
                                if ($delivery['ok']) {
                                    $whatsappDeliveryNotice = 'Ucapan penerimaan berhasil dikirim otomatis melalui WhatsApp pendaftar.';
                                } else {
                                    $whatsappDeliveryNotice = 'Peserta sudah diterima, tetapi ucapan WhatsApp belum terkirim: '.$delivery['error'];
                                    $whatsappDeliveryTone = 'warning';
                                }
                            } catch (Throwable $exception) {
                                error_log('SPMB acceptance WhatsApp notification failed: '.$exception->getMessage());
                                $whatsappDeliveryNotice = 'Peserta sudah diterima, tetapi ucapan WhatsApp belum terkirim. Periksa konfigurasi Wablas dan coba hubungi pendaftar secara manual.';
                                $whatsappDeliveryTone = 'warning';
                            }
                        }
                    }
                    if (trim((string) ($locked['link_grup'] ?? '')) === '') {
                        $groupReminder = 'Tautan grup WhatsApp unit belum diatur pada menu Grup WhatsApp.';
                        $whatsappDeliveryNotice = trim($whatsappDeliveryNotice.' '.$groupReminder);
                        $whatsappDeliveryTone = 'warning';
                    }
                }

                enqueue_google_sheets_registration($conn, $registrationId);
                sync_google_sheets_registration($conn, $registrationId);
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Keputusan belum dapat disimpan.';
            }
        } else {
            $error = 'Aksi verifikasi tidak dikenali.';
        }
    }
}

$stmt = mysqli_prepare($conn, 'SELECT p.*,u.nama_unit,u.jenjang,d.*,c.kuota_internal,c.kuota_eksternal,c.biaya_pendaftaran,c.bank_nama,c.nomor_rekening,c.nama_pemilik_rekening,c.masa_pembayaran_hari,i.nama_sdm,i.unit_kerja FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit LEFT JOIN tb_detail_dapodik d ON d.id_pendaftaran=p.id_pendaftaran LEFT JOIN tb_pengaturan_ppdb c ON c.kode_unit=p.kode_unit AND c.th_ajaran=p.th_ajaran LEFT JOIN tb_undangan_internal i ON i.id=p.undangan_internal_id WHERE p.id_pendaftaran=? LIMIT 1');
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
$participant += formulir_flatten_parent_profiles(formulir_parent_profiles($conn, $registrationId));

// Daftar tunggu: posisi antrean (urut nomor antrean pendaftaran) dan kursi di jalur yang sama.
$waitlist = null;
if ($participant['status_ppdb'] === 'daftar_tunggu') {
    $waitlistQuery = mysqli_prepare($conn, "SELECT id_pendaftaran FROM tb_pendaftaran WHERE kode_unit=? AND th_ajaran=? AND jalur_pendaftaran=? AND status_ppdb='daftar_tunggu' ORDER BY nomor_antrian IS NULL, nomor_antrian, waktu_daftar, id_pendaftaran");
    mysqli_stmt_bind_param($waitlistQuery, 'sss', $participant['kode_unit'], $participant['th_ajaran'], $participant['jalur_pendaftaran']);
    mysqli_stmt_execute($waitlistQuery);
    $waitlistIds = array_column(mysqli_fetch_all(mysqli_stmt_get_result($waitlistQuery), MYSQLI_ASSOC), 'id_pendaftaran');
    mysqli_stmt_close($waitlistQuery);
    $waitlist = ppdb_seat_availability($conn, $participant) + [
        'position' => (int) array_search($registrationId, $waitlistIds, true) + 1,
        'total' => count($waitlistIds),
    ];
}
$participant['draf_formulir'] = '';
$draftQuery = mysqli_prepare($conn, 'SELECT langkah,updated_at FROM tb_formulir_draf WHERE id_pendaftaran=? LIMIT 1');
mysqli_stmt_bind_param($draftQuery, 's', $registrationId);
mysqli_stmt_execute($draftQuery);
$draftRow = mysqli_fetch_assoc(mysqli_stmt_get_result($draftQuery));
mysqli_stmt_close($draftQuery);
if ($draftRow) {
    $participant['draf_formulir'] = 'Sedang diisi · langkah '.((int) $draftRow['langkah'] + 1).' · disimpan '.date('d-m-Y H:i', strtotime($draftRow['updated_at']));
}

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
$selectionData = prestasi_load($conn, $registrationId);
if (($selectionData['jalur_seleksi'] ?? '') === 'prestasi') {
    $documentLabels['surat_prestasi'] = 'Surat pengantar prestasi';
}
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

$revisions = [];
$revisionQuery = mysqli_prepare($conn, 'SELECT perubahan_json,jumlah_perubahan,dikirim_pada FROM tb_formulir_revisi WHERE id_pendaftaran=? ORDER BY dikirim_pada DESC, id DESC');
mysqli_stmt_bind_param($revisionQuery, 's', $registrationId);
mysqli_stmt_execute($revisionQuery);
$revisionResult = mysqli_stmt_get_result($revisionQuery);
while ($revisionRow = mysqli_fetch_assoc($revisionResult)) {
    $revisionRow['perubahan'] = json_decode($revisionRow['perubahan_json'], true) ?: [];
    $revisions[] = $revisionRow;
}
mysqli_stmt_close($revisionQuery);

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
            <p class="eyebrow">Data peserta didik</p>
            <h1><?php echo h($participant['nm_peserta']); ?></h1>
            <p><?php echo h($participant['id_pendaftaran']); ?> · <?php echo h(ppdb_unit_display_name($participant['nama_unit'] ?? 'Data lama')); ?><?php echo !empty($participant['tingkat_kelas']) ? ' · '.h($participant['tingkat_kelas']) : ''; ?> · <?php echo h($participant['th_ajaran']); ?></p>
        </div>
        <div class="table-actions">
            <a class="button-secondary" href="cetak-bukti.php?id=<?php echo rawurlencode($registrationId); ?>">Unduh bukti PDF</a>
            <a class="button-secondary" href="daftar_peserta.php">&larr; Kembali</a>
        </div>
    </div>
    <?php if ($error !== '') { ?><div class="alert-error" role="alert"><?php echo h($error); ?></div><?php } ?>
    <?php if ($notice !== '') { ?><div class="alert-success" role="status"><?php echo h($notice); ?></div><?php } ?>
    <?php if ($whatsappDeliveryNotice !== '') { ?><div class="alert-<?php echo h($whatsappDeliveryTone); ?>" role="status"><?php echo h($whatsappDeliveryNotice); ?></div><?php } ?>

<?php $paymentAwaitingReview = $latestPayment && $latestPayment['status_verifikasi'] === 'menunggu_verifikasi' && $participant['status_pembayaran'] === 'menunggu_verifikasi'; ?>
    <section class="detail-panel wide workflow-panel">
        <h2>Alur penerimaan</h2>
        <div class="workflow-summary">
            <div><span>Status SPMB</span><strong class="tone-<?php echo h($statusInfo['tone']); ?>"><?php echo h($statusInfo['label']); ?></strong></div>
            <div><span>Status data</span><strong><?php echo h($dataStatusLabels[$participant['status_data']] ?? $participant['status_data']); ?></strong></div>
            <div><span>Status pembayaran</span><strong><?php echo h($paymentStatusLabels[$participant['status_pembayaran']] ?? $participant['status_pembayaran']); ?></strong></div>
            <div><span>Jalur pendaftaran</span><strong><?php echo $participant['jalur_pendaftaran'] === 'internal' ? 'Internal · SDM yayasan' : 'Eksternal · umum'; ?></strong></div>
            <?php if ($participant['jalur_pendaftaran'] === 'internal' && $participant['nama_sdm']) { ?><div><span>Undangan SDM</span><strong><?php echo h($participant['nama_sdm']); ?><?php echo $participant['unit_kerja'] !== '' ? ' · ' . h($participant['unit_kerja']) : ''; ?></strong></div><?php } ?>
            <div><span>Antrean / kuota jalur</span><strong><?php echo $participant['nomor_antrian'] ? '#' . (int) $participant['nomor_antrian'] . ' / ' . (int) ($participant['jalur_pendaftaran'] === 'internal' ? $participant['kuota_internal'] : $participant['kuota_eksternal']) : 'Arsip lama'; ?></strong></div>
            <?php if ($participant['tanggal_batas_bayar']) { ?><div><span>Batas pembayaran</span><strong><?php echo h(date('d-m-Y', strtotime($participant['tanggal_batas_bayar']))); ?></strong></div><?php } ?>
        </div>

        <?php if ($paymentAwaitingReview) { ?>
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
        <?php } ?>

        <?php if ($participant['status_ppdb'] === 'menunggu_kontak') { ?>
            <p class="workflow-hint">Pendaftar belum melanjutkan ke tahap pembayaran. Halaman ini akan berubah otomatis saat pendaftar melanjutkan.</p>
            <div class="contact-strip">
                <div><span>Pendaftar</span><strong><?php echo h(ppdb_display_phone($participant['no_hp_pendaftar'])); ?></strong></div>
                <div><span>Ayah</span><strong><?php echo h(ppdb_display_phone($participant['no_hp_ayah'])); ?></strong></div>
                <div><span>Ibu</span><strong><?php echo h(ppdb_display_phone($participant['no_hp_ibu'])); ?></strong></div>
            </div>
            <?php ppdb_detail_reject_form($registrationId); ?>
        <?php } elseif ($participant['status_ppdb'] === 'pembayaran_diperiksa') { ?>
            <p class="workflow-hint">Bukti transfer sudah masuk. Pendaftar dapat terus mengisi formulir sambil bukti ini diperiksa.</p>
            <?php ppdb_detail_reject_form($registrationId); ?>
        <?php } elseif ($participant['status_ppdb'] === 'pembayaran_terverifikasi') { ?>
            <p class="workflow-hint">Pembayaran disetujui. Menunggu pendaftar mengisi formulir lengkap.</p>
            <?php ppdb_detail_reject_form($registrationId); ?>
        <?php } elseif ($participant['status_ppdb'] === 'menunggu_formulir') { ?>
            <p class="workflow-hint">Menunggu pendaftar mengisi formulir lengkap beserta dokumen pendukung.</p>
            <?php ppdb_detail_reject_form($registrationId); ?>
        <?php } elseif ($participant['status_ppdb'] === 'formulir_terisi') { ?>
            <?php $paymentSettled = $participant['status_pembayaran'] === 'terverifikasi'; ?>
            <p class="workflow-hint"><?php echo $paymentSettled
                ? 'Formulir lengkap dan dokumen sudah masuk. Periksa kelengkapan berkas lalu tentukan keputusan akhir.'
                : 'Formulir lengkap dan dokumen sudah masuk, tetapi pembayaran belum disetujui. Selesaikan pemeriksaan bukti transfer sebelum menerima peserta.'; ?></p>
            <div class="workflow-actions payment-actions">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="workflow_action" value="accept_final">
                    <button class="button-primary" type="submit"<?php echo $paymentSettled ? '' : ' disabled title="Pembayaran belum disetujui"'; ?>>Terima peserta</button>
                </form>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="workflow_action" value="waitlist_final">
                    <button class="button-secondary" type="submit"<?php echo $paymentSettled ? '' : ' disabled title="Pembayaran belum disetujui"'; ?>>Masukkan daftar tunggu</button>
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
            <?php ppdb_detail_reject_form($registrationId); ?>
        <?php } elseif ($participant['status_ppdb'] === 'diterima') { ?>
            <p class="workflow-hint">Peserta telah diterima pada unit ini sesuai urutan dan kuota. Hasil dan tautan grup WhatsApp unit tampil pada halaman pendaftaran pendaftar.</p>
        <?php } elseif ($participant['status_ppdb'] === 'daftar_tunggu') { ?>
            <p class="workflow-hint">
                Peserta berada pada daftar tunggu jalur <?php echo h($participant['jalur_pendaftaran']); ?>, urutan ke-<?php echo $waitlist['position']; ?> dari <?php echo $waitlist['total']; ?>.
                Kursi terisi <?php echo $waitlist['accepted']; ?> dari <?php echo $waitlist['quota']; ?>:
                <?php echo $waitlist['seat_available']
                    ? '<strong>masih ada '.($waitlist['quota'] - $waitlist['accepted']).' kursi kosong</strong>, peserta dapat diterima sekarang.'
                    : 'kuota masih penuh. Terima peserta setelah ada kursi kosong, atau tambah kuota di menu Kuota &amp; pembayaran.'; ?>
                <?php if ($waitlist['seat_available'] && $waitlist['position'] > 1) { ?>Ada <?php echo $waitlist['position'] - 1; ?> peserta yang lebih dahulu masuk daftar tunggu.<?php } ?>
            </p>
            <div class="workflow-actions payment-actions">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="workflow_action" value="accept_final">
                    <button class="button-primary" type="submit"<?php echo $waitlist['seat_available'] ? '' : ' disabled title="Kuota jalur ini masih penuh"'; ?>>Terima dari daftar tunggu</button>
                </form>
                <form method="post" class="stacked-action">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="workflow_action" value="reject_formulir">
                    <label for="reject-waitlist-note">Alasan penolakan</label>
                    <input id="reject-waitlist-note" name="catatan_admin" maxlength="500" required placeholder="Contoh: mengundurkan diri, kuota tahun ini sudah final">
                    <button class="button-danger" type="submit">Tolak dari daftar tunggu</button>
                </form>
            </div>
        <?php } elseif ($participant['status_ppdb'] === 'ditolak' && $participant['status_data'] === 'belum_diperiksa' && in_array($participant['status_pembayaran'], ['terverifikasi', 'menunggu_verifikasi'], true)) { ?>
            <p class="workflow-hint">Pendaftar memilih tidak melanjutkan pengisian formulir. Pilihan ini sudah tercatat untuk panitia. Jika pembatalan terjadi karena salah klik, Anda dapat membuka kembali pendaftarannya.</p>
            <div class="workflow-actions">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="workflow_action" value="reopen_registration">
                    <button class="button-primary" type="submit">Buka kembali pendaftaran</button>
                </form>
            </div>
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

    <?php if (!empty($participant['catatan_validasi'])) { ?>
        <div class="alert-warning" role="status">
            <div><strong>Pemeriksaan NIK otomatis menemukan ketidakcocokan.</strong> Cocokkan dengan scan KK dan akta sebelum memverifikasi data.
            <ul style="margin: 6px 0 0; padding-left: 18px;"><?php foreach (explode("\n", (string) $participant['catatan_validasi']) as $flag) { ?><li><?php echo h($flag); ?></li><?php } ?></ul></div>
        </div>
    <?php } ?>
    <div class="detail-grid">
        <?php if ($selectionData) {
            $selectionData['jalur_seleksi_label'] = prestasi_jalur_label($selectionData['jalur_seleksi']);
            $selectionFields = [['Jalur seleksi', 'jalur_seleksi_label']];
            if ($selectionData['jalur_seleksi'] === 'prestasi') {
                foreach ([['Prestasi akademik', 'jenis_prestasi'], ['Kelas 5 semester 1', 'peringkat_k5_s1'], ['Kelas 5 semester 2', 'peringkat_k5_s2'], ['Kelas 6 semester 1', 'peringkat_k6_s1'], ['Kategori prestasi', 'kategori_hafalan'], ['Jumlah hafalan', 'jumlah_hafalan'], ['Riwayat lomba', 'riwayat_lomba'], ['Lomba yang dimenangkan', 'daftar_lomba']] as $selectionField) { if (trim((string) ($selectionData[$selectionField[1]] ?? '')) !== '') { $selectionFields[] = $selectionField; } }
            }
            render_detail_panel('Jalur pendaftaran SMP', $selectionFields, $selectionData);
        } ?>
        <?php render_detail_panel('Registrasi awal', [['Kode pendaftaran', 'id_pendaftaran'], ['Tanggal pendaftaran', 'tgl_daftar'], ['Unit pendidikan', 'nama_unit'], ...(!empty($participant['tingkat_kelas']) ? [['Daftar ke jenjang', 'tingkat_kelas']] : []), ['Tahun ajaran', 'th_ajaran'], ['Nomor antrean', 'nomor_antrian'], ['Nama ananda', 'nm_peserta'], ['Tanggal lahir', 'tgl_lahir'], ['Umur ananda terdeteksi berdasarkan Dapodik dari tanggal ' . ($dapodikAge['reference_date'] ?? '-') . ' berumur:', 'umur_dapodik'], ['Jenis kelamin', 'jenis_kelamin'], ['Yang mendaftarkan', 'pendaftar'], ['WhatsApp pendaftar', 'no_hp_pendaftar'], ['WhatsApp ayah', 'no_hp_ayah'], ['WhatsApp ibu', 'no_hp_ibu'], ['Draf formulir lengkap', 'draf_formulir']], $participant); ?>
        <?php if ($revisions || !empty($participant['revisi_dibuka'])) { ?>
        <section class="detail-panel wide revision-history">
            <h2>Riwayat revisi wali murid</h2>
            <?php if (!empty($participant['revisi_dibuka'])) { ?>
                <p class="revision-history__waiting">Formulir sedang dibuka untuk revisi. Menunggu wali murid mengirim perbaikan.</p>
            <?php } ?>
            <?php foreach ($revisions as $index => $revision) { ?>
                <div class="revision-history__item">
                    <p class="revision-history__meta">
                        <strong>Revisi <?php echo count($revisions) - $index; ?></strong>
                        · dikirim <?php echo h(date('d-m-Y H:i', strtotime($revision['dikirim_pada']))); ?>
                        · <?php echo (int) $revision['jumlah_perubahan'] > 0 ? (int) $revision['jumlah_perubahan'].' perubahan' : 'tanpa perubahan data'; ?>
                    </p>
                    <?php if ($revision['perubahan']) { ?>
                        <div class="table-wrap">
                            <table class="data-table revision-history__table">
                                <thead><tr><th scope="col">Bagian</th><th scope="col">Isian</th><th scope="col">Sebelum</th><th scope="col">Sesudah</th></tr></thead>
                                <tbody>
                                <?php foreach ($revision['perubahan'] as [$sectionTitle, $fieldLabel, $before, $after]) { ?>
                                    <tr>
                                        <td data-label="Bagian"><?php echo h($sectionTitle); ?></td>
                                        <td data-label="Isian"><strong><?php echo h($fieldLabel); ?></strong></td>
                                        <td data-label="Sebelum" class="revision-history__old"><?php echo $before === '' ? '<em>kosong</em>' : h($before); ?></td>
                                        <td data-label="Sesudah" class="revision-history__new"><?php echo $after === '' ? '<em>kosong</em>' : h($after); ?></td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>
        <?php } ?>
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
        <?php foreach (formulir_sections_for(null, (string) ($participant['jenjang'] ?? '')) as $sectionKey => $section) {
            $panelFields = [];
            foreach ($section['fields'] as $field) {
                if (($field['type'] ?? 'text') !== 'heading') {
                    $panelFields[] = [$field['label'], $field['name']];
                }
            }
            if ($sectionKey === 'alamat') {
                $panelFields[] = ['Lintang', 'latitude'];
                $panelFields[] = ['Bujur', 'longitude'];
            }
            render_detail_panel($section['title'], $panelFields, $participant);
        } ?>
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
