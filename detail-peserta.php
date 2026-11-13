<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
require_once 'formulir_helpers.php';
require_once 'prestasi_helpers.php';
require_once 'wablas.php';
require_admin();
require_once __DIR__ . '/admin-partials.php';

$registrationId = $_GET['id'] ?? $_POST['id'] ?? '';
// Daftar asal (filter, pencarian, halaman) agar admin dapat kembali dan lanjut ke peserta berikutnya.
$returnQuery = (string) ($_GET['kembali'] ?? '');
if (!preg_match('/^\?[A-Za-z0-9_\-=&%.+]*$/', $returnQuery)) {
    $returnQuery = '';
}
parse_str(ltrim($returnQuery, '?'), $returnFilters);
$returnLabels = ['menunggu_kontak' => 'Menunggu persetujuan', 'pembayaran_diperiksa' => 'Bukti bayar masuk'];
$returnLabel = $returnLabels[$returnFilters['status'] ?? ''] ?? 'daftar peserta';
$returnUrl = 'daftar_peserta.php'.$returnQuery;
$nextParticipant = null;
$nextRemaining = 0;
$error = '';
$notice = '';
$whatsappDeliveryNotice = '';
$whatsappDeliveryTone = 'success';
$routeShareLinks = null;
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
        'seat_available' => $quota !== null && $acceptedCount < (int) $quota
            && ppdb_gender_seat_open($conn, $locked['kode_unit'], $locked['th_ajaran'], $locked['jalur_pendaftaran'], $locked['jenis_kelamin'] ?? null) !== false,
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
                    $notice = 'Data operator berhasil diperbarui.';
                } else {
                    $error = 'Data operator belum dapat disimpan.';
                }
                mysqli_stmt_close($update);
            }
        } elseif ($action === 'approve_registration') {
            // Setujui registrasi awal: tahap pembayaran dibuka, lalu tautan lanjutan dikirim lewat WhatsApp.
            try {
                mysqli_begin_transaction($conn);
                $lock = mysqli_prepare($conn, 'SELECT status_ppdb FROM tb_pendaftaran WHERE id_pendaftaran=? FOR UPDATE');
                mysqli_stmt_bind_param($lock, 's', $registrationId);
                mysqli_stmt_execute($lock);
                $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
                mysqli_stmt_close($lock);
                if (!$locked || $locked['status_ppdb'] !== 'menunggu_kontak') {
                    throw new RuntimeException('Registrasi ini sudah disetujui atau tidak lagi menunggu persetujuan.');
                }
                ppdb_activate_registration($conn, $registrationId);
                mysqli_commit($conn);
                enqueue_google_sheets_registration($conn, $registrationId);
                $delivery = ppdb_send_registration_whatsapp($conn, $registrationId);
                if ($delivery['ok']) {
                    $notice = 'Registrasi disetujui. Tautan lanjutan pendaftaran terkirim ke WhatsApp '.ppdb_display_phone($delivery['phone']).'.';
                } else {
                    $notice = 'Registrasi disetujui.';
                    $whatsappDeliveryNotice = 'WhatsApp otomatis belum terkirim ('.$delivery['error'].'). Kirim tautannya melalui panel "Kirim pesan WhatsApp manual" di bawah.';
                    $whatsappDeliveryTone = 'warning';
                }
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Persetujuan belum dapat diproses.';
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
                    // Penerimaan otomatis ditahan sampai bukti baru diunggah; isian formulir tetap tersimpan.
                    $newPpdbStatus = [
                        'pembayaran_diperiksa' => 'menunggu_pembayaran',
                        'diterima' => 'formulir_terisi',
                        'daftar_tunggu' => 'formulir_terisi',
                    ][$locked['status_ppdb']] ?? $locked['status_ppdb'];
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
                if ($action === 'reject_payment') {
                    // Pendaftar perlu waktu mengunggah ulang: batas bayar paling cepat 2 hari dari hari ini.
                    $extendDeadline = mysqli_prepare($conn, 'UPDATE tb_pendaftaran SET tanggal_batas_bayar=GREATEST(COALESCE(tanggal_batas_bayar, CURDATE()), DATE_ADD(CURDATE(), INTERVAL 2 DAY)) WHERE id_pendaftaran=?');
                    mysqli_stmt_bind_param($extendDeadline, 's', $registrationId);
                    mysqli_stmt_execute($extendDeadline);
                    mysqli_stmt_close($extendDeadline);
                }
                mysqli_commit($conn);
                enqueue_google_sheets_registration($conn, $registrationId);

                if ($action === 'approve_payment') {
                    // Pembayaran diterima cukup tercatat di panel admin; tidak ada pesan ke pendaftar.
                    $notice = 'Pembayaran diterima. Tidak ada pesan yang dikirim ke pendaftar.';
                } else {
                    $delivery = ppdb_send_payment_rejected_whatsapp($conn, $registrationId, $reviewNote);
                    $notice = 'Bukti pembayaran ditolak'.($reviewNote !== '' ? ' (alasan: '.$reviewNote.')' : '').'.';
                    if ($delivery['ok']) {
                        $notice .= ' Tautan unggah ulang terkirim ke WhatsApp '.ppdb_display_phone($delivery['phone']).'.';
                    } else {
                        $whatsappDeliveryNotice = 'WhatsApp otomatis belum terkirim ('.$delivery['error'].'). Kirim tautan unggah ulang melalui panel "Kirim pesan WhatsApp manual" di bawah.';
                        $whatsappDeliveryTone = 'warning';
                    }
                }
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Status pembayaran belum dapat diperbarui.';
            }
        } elseif ($action === 'reopen_registration') {
            try {
                mysqli_begin_transaction($conn);
                $lock = mysqli_prepare($conn, "SELECT p.status_ppdb,p.status_pembayaran,p.jenis_kelamin,p.alamat,p.tanggal_batas_bayar,p.kode_unit,p.th_ajaran,p.jalur_pendaftaran,c.kuota_internal,c.kuota_eksternal,c.biaya_pendaftaran,c.masa_pembayaran_hari FROM tb_pendaftaran p LEFT JOIN tb_pengaturan_ppdb c ON c.kode_unit=p.kode_unit AND c.th_ajaran=p.th_ajaran WHERE p.id_pendaftaran=? FOR UPDATE");
                mysqli_stmt_bind_param($lock, 's', $registrationId);
                mysqli_stmt_execute($lock);
                $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
                mysqli_stmt_close($lock);
                if (!$locked || $locked['status_ppdb'] !== 'ditolak') {
                    throw new RuntimeException('Hanya pendaftaran yang ditolak yang dapat dilanjutkan kembali.');
                }

                // Pendaftaran yang ditolak tidak memakai slot, jadi membukanya perlu slot kosong.
                $slotQuery = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM tb_pendaftaran WHERE kode_unit=? AND th_ajaran=? AND jalur_pendaftaran=? AND status_ppdb NOT IN ('ditolak','data_lama')");
                mysqli_stmt_bind_param($slotQuery, 'sss', $locked['kode_unit'], $locked['th_ajaran'], $locked['jalur_pendaftaran']);
                mysqli_stmt_execute($slotQuery);
                $slotsUsed = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($slotQuery))['total'];
                mysqli_stmt_close($slotQuery);
                $slotQuota = (int) ($locked['jalur_pendaftaran'] === 'internal' ? $locked['kuota_internal'] : $locked['kuota_eksternal']);
                if ($slotsUsed >= $slotQuota) {
                    throw new RuntimeException('Kuota jalur ini sudah penuh. Tambah kuota di menu Kuota & pembayaran sebelum melanjutkan pendaftaran ini.');
                }
                if (in_array($locked['jenis_kelamin'], ppdb_full_levels(ppdb_gender_quotas($conn, $locked['kode_unit'], $locked['th_ajaran']), $locked['jalur_pendaftaran']), true)) {
                    throw new RuntimeException('Kuota '.strtolower(PPDB_GENDERS[$locked['jenis_kelamin']]).' pada jalur ini sudah penuh. Tambah kuota di menu Kuota & pembayaran sebelum melanjutkan pendaftaran ini.');
                }

                // Pendaftar kembali ke tahap terakhirnya; isian yang pernah dikirim tetap dimuat.
                $formSubmitted = trim((string) $locked['alamat']) !== '';
                $newPaymentStatus = $locked['status_pembayaran'];
                $newDeadline = $locked['tanggal_batas_bayar'];
                if (in_array($newPaymentStatus, ['terverifikasi', 'menunggu_verifikasi'], true)) {
                    $newStatus = $newPaymentStatus === 'menunggu_verifikasi' && !$formSubmitted ? 'pembayaran_diperiksa' : 'menunggu_formulir';
                } elseif ((float) $locked['biaya_pendaftaran'] > 0) {
                    $newStatus = 'menunggu_pembayaran';
                    if (!$newDeadline || $newDeadline < date('Y-m-d')) {
                        $newDeadline = date('Y-m-d', strtotime('+' . max(1, (int) $locked['masa_pembayaran_hari']) . ' days'));
                    }
                } else {
                    $newStatus = 'menunggu_formulir';
                    $newPaymentStatus = 'terverifikasi';
                }
                $newDataStatus = $formSubmitted ? 'perlu_perbaikan' : 'belum_diperiksa';
                $update = mysqli_prepare($conn, 'UPDATE tb_pendaftaran SET status_ppdb=?,status_pembayaran=?,tanggal_batas_bayar=?,status_data=?,data_diperiksa_oleh=?,data_diperiksa_pada=NOW() WHERE id_pendaftaran=?');
                mysqli_stmt_bind_param($update, 'ssssis', $newStatus, $newPaymentStatus, $newDeadline, $newDataStatus, $adminId, $registrationId);
                mysqli_stmt_execute($update);
                mysqli_stmt_close($update);
                mysqli_commit($conn);
                enqueue_google_sheets_registration($conn, $registrationId);
                $notice = 'Pendaftaran dilanjutkan kembali. Pendaftar dapat meneruskan dari tahap terakhirnya.';
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Pendaftaran belum dapat dilanjutkan kembali.';
            }
        } elseif (in_array($action, ['accept_final', 'waitlist_final', 'reject_formulir'], true)) {
            try {
                mysqli_begin_transaction($conn);
                $lock = mysqli_prepare($conn, 'SELECT p.status_ppdb,p.status_data,p.status_pembayaran,p.jenis_kelamin,p.kode_unit,p.th_ajaran,p.jalur_pendaftaran,p.nomor_antrian,p.nm_peserta,p.no_hp_pendaftar,u.nama_unit,g.link_grup,c.kuota_internal,c.kuota_eksternal FROM tb_pendaftaran p LEFT JOIN tb_pengaturan_ppdb c ON c.kode_unit=p.kode_unit AND c.th_ajaran=p.th_ajaran LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit LEFT JOIN tb_unit_whatsapp_groups g ON g.kode_unit=p.kode_unit WHERE p.id_pendaftaran=? FOR UPDATE');
                mysqli_stmt_bind_param($lock, 's', $registrationId);
                mysqli_stmt_execute($lock);
                $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
                mysqli_stmt_close($lock);
                // Peserta daftar tunggu dapat diterima saat kursi tersedia, atau ditolak.
                $fromWaitlist = $locked && $locked['status_ppdb'] === 'daftar_tunggu' && $action !== 'waitlist_final';
                $cancelAcceptance = $locked && $locked['status_ppdb'] === 'diterima' && $action === 'reject_formulir';
                if (!$locked || ($locked['status_ppdb'] !== 'formulir_terisi' && !$fromWaitlist && !$cancelAcceptance)) {
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
                    if (trim((string) ($locked['link_grup'] ?? '')) === '') {
                        $whatsappDeliveryNotice = 'Tautan grup WhatsApp unit belum diatur pada menu Grup WhatsApp.';
                        $whatsappDeliveryTone = 'warning';
                    }
                }

                enqueue_google_sheets_registration($conn, $registrationId);
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Keputusan belum dapat disimpan.';
            }
        } elseif ($action === 'switch_selection_route' || $action === 'resend_prestasi_link') {
            // Pindah jalur seleksi SMP (umum <-> prestasi) tanpa mengubah kuota, antrean, maupun pembayaran.
            // Data jalur prestasi diisi sendiri oleh wali murid lewat tautan WhatsApp.
            $targetRoute = $action === 'resend_prestasi_link' ? 'prestasi' : (string) ($_POST['jalur_seleksi'] ?? '');
            try {
                if (!prestasi_is_ready($conn) || !in_array($targetRoute, ['prestasi', 'umum'], true)) {
                    throw new RuntimeException('Jalur seleksi tidak dikenali.');
                }
                mysqli_begin_transaction($conn);
                $lock = mysqli_prepare($conn, 'SELECT p.status_ppdb,p.jalur_seleksi,u.jenjang FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit WHERE p.id_pendaftaran=? FOR UPDATE');
                mysqli_stmt_bind_param($lock, 's', $registrationId);
                mysqli_stmt_execute($lock);
                $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
                mysqli_stmt_close($lock);
                if (!$locked || ($locked['jenjang'] ?? '') !== 'SMP' || $locked['status_ppdb'] === 'data_lama') {
                    throw new RuntimeException('Jalur seleksi hanya dapat diubah untuk pendaftar SMP.');
                }
                $oldLetterPath = '';
                if ($action === 'resend_prestasi_link') {
                    if (($locked['jalur_seleksi'] ?? '') !== 'prestasi' || prestasi_has_data($conn, $registrationId)) {
                        throw new RuntimeException('Data prestasi peserta ini sudah terisi.');
                    }
                } else {
                    if (($locked['jalur_seleksi'] ?? '') === $targetRoute) {
                        throw new RuntimeException('Peserta sudah berada di jalur '.prestasi_jalur_label($targetRoute).'.');
                    }
                    if ($targetRoute === 'prestasi') {
                        // Data dan surat prestasi lama (bila pernah pindah jalur) dihapus; wali murid mengisi ulang.
                        $oldLetter = mysqli_prepare($conn, "SELECT path_file FROM tb_dokumen_peserta WHERE id_pendaftaran=? AND jenis_dokumen='surat_prestasi' LIMIT 1");
                        mysqli_stmt_bind_param($oldLetter, 's', $registrationId);
                        mysqli_stmt_execute($oldLetter);
                        $oldLetterPath = (string) (mysqli_fetch_assoc(mysqli_stmt_get_result($oldLetter))['path_file'] ?? '');
                        mysqli_stmt_close($oldLetter);
                        foreach (['DELETE FROM tb_prestasi_peserta WHERE id_pendaftaran=?', "DELETE FROM tb_dokumen_peserta WHERE id_pendaftaran=? AND jenis_dokumen='surat_prestasi'"] as $cleanupSql) {
                            $cleanup = mysqli_prepare($conn, $cleanupSql);
                            mysqli_stmt_bind_param($cleanup, 's', $registrationId);
                            mysqli_stmt_execute($cleanup);
                            mysqli_stmt_close($cleanup);
                        }
                    }
                    $routeUpdate = mysqli_prepare($conn, 'UPDATE tb_pendaftaran SET jalur_seleksi=? WHERE id_pendaftaran=?');
                    mysqli_stmt_bind_param($routeUpdate, 'ss', $targetRoute, $registrationId);
                    mysqli_stmt_execute($routeUpdate);
                    mysqli_stmt_close($routeUpdate);
                }
                $routeShareLinks = $targetRoute === 'prestasi' ? prestasi_prepare_wali_links($conn, $registrationId) : null;
                mysqli_commit($conn);
                if ($oldLetterPath !== '' && is_file($oldLetterPath)) {
                    @unlink($oldLetterPath);
                }
                if ($action === 'switch_selection_route') {
                    enqueue_google_sheets_registration($conn, $registrationId);
                }
                $notice = $action === 'resend_prestasi_link' ? 'Tautan isian prestasi disiapkan ulang.' : 'Peserta dipindahkan ke jalur '.prestasi_jalur_label($targetRoute).'.';
                if ($routeShareLinks) {
                    if (ppdb_wablas_enabled()) {
                        $routeShareLinks = ppdb_wablas_send_links($routeShareLinks);
                        $sent = array_values(array_filter($routeShareLinks['links'], static fn ($link) => !empty($link['sent'])));
                        $notice .= $sent
                            ? ' Tautan isian prestasi terkirim ke WhatsApp '.implode(', ', array_column($sent, 'display')).'. Bila belum diterima, kirim manual:'
                            : ' WhatsApp otomatis gagal terkirim. Kirim tautan isian prestasi secara manual:';
                    } else {
                        $notice .= ' Kirim tautan isian prestasi ke wali murid lewat WhatsApp:';
                    }
                }
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                if (!$exception instanceof RuntimeException) {
                    error_log('Pindah jalur seleksi gagal: '.$exception->getMessage());
                }
                $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Jalur seleksi belum dapat diubah.';
            }
        } else {
            $error = 'Aksi verifikasi tidak dikenali.';
        }
    }
}

$nextKind = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $nextKind = [
        'approve_registration' => 'menunggu_kontak',
        'approve_payment' => 'pembayaran_diperiksa',
        'reject_payment' => 'pembayaran_diperiksa',
    ][$_POST['workflow_action'] ?? ''] ?? '';
}
if ($nextKind !== '') {
    $nextConditions = [$nextKind === 'menunggu_kontak' ? "status_ppdb='menunggu_kontak'" : "status_pembayaran='menunggu_verifikasi'", 'id_pendaftaran<>?'];
    $nextParameters = [$registrationId];
    $nextUnit = ppdb_is_super_admin() ? (string) ($returnFilters['unit'] ?? '') : (string) $_SESSION['admin_unit_code'];
    if ($nextUnit !== '') {
        $nextConditions[] = 'kode_unit=?';
        $nextParameters[] = $nextUnit;
    }
    if (($returnFilters['year'] ?? '') !== '') {
        $nextConditions[] = 'th_ajaran=?';
        $nextParameters[] = (string) $returnFilters['year'];
    }
    $nextWhere = implode(' AND ', $nextConditions);
    $nextStatement = mysqli_prepare($conn, 'SELECT id_pendaftaran,nm_peserta,(SELECT COUNT(*) FROM tb_pendaftaran WHERE '.$nextWhere.') AS sisa FROM tb_pendaftaran WHERE '.$nextWhere.' ORDER BY waktu_daftar DESC, id_pendaftaran DESC LIMIT 1');
    $nextAll = array_merge($nextParameters, $nextParameters);
    mysqli_stmt_bind_param($nextStatement, str_repeat('s', count($nextAll)), ...$nextAll);
    mysqli_stmt_execute($nextStatement);
    $nextParticipant = mysqli_fetch_assoc(mysqli_stmt_get_result($nextStatement)) ?: null;
    mysqli_stmt_close($nextStatement);
    $nextRemaining = (int) ($nextParticipant['sisa'] ?? 0);
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

// Pesan WhatsApp manual: cadangan bila Wablas terputus. Admin membuka WhatsApp sendiri lewat wa.me.
$manualRecipients = ppdb_registrant_recipients($participant);
$manualMessages = [];
if ($manualRecipients && !empty($participant['token_bukti'])) {
    $manualRegistration = $participant + ['id_pendaftaran' => $registrationId];
    if ($participant['status_pembayaran'] === 'ditolak') {
        $rejectedPayment = mysqli_prepare($conn, "SELECT catatan_admin FROM tb_bukti_pembayaran WHERE id_pendaftaran=? AND status_verifikasi='ditolak' ORDER BY id DESC LIMIT 1");
        mysqli_stmt_bind_param($rejectedPayment, 's', $registrationId);
        mysqli_stmt_execute($rejectedPayment);
        $rejectedReason = (string) (mysqli_fetch_assoc(mysqli_stmt_get_result($rejectedPayment))['catatan_admin'] ?? '');
        mysqli_stmt_close($rejectedPayment);
        $manualMessages['bayar_ditolak'] = [
            'label' => 'Bukti pembayaran ditolak · tautan unggah ulang',
            'text' => ppdb_payment_rejected_whatsapp_message($manualRegistration, $rejectedReason, ppdb_continue_url($manualRegistration)),
        ];
    }
    $manualMessages['registrasi'] = [
        'label' => 'Konfirmasi registrasi dan tautan lanjutan',
        'text' => ppdb_registration_whatsapp_message(
            $manualRegistration,
            ppdb_continue_url($manualRegistration),
            ppdb_form_window($conn, (string) $participant['kode_unit'], (string) $participant['th_ajaran'])
        ),
    ];
    if ($participant['status_ppdb'] === 'diterima') {
        $groupStatement = mysqli_prepare($conn, 'SELECT link_grup FROM tb_unit_whatsapp_groups WHERE kode_unit=? LIMIT 1');
        mysqli_stmt_bind_param($groupStatement, 's', $participant['kode_unit']);
        mysqli_stmt_execute($groupStatement);
        $groupLink = trim((string) (mysqli_fetch_assoc(mysqli_stmt_get_result($groupStatement))['link_grup'] ?? ''));
        mysqli_stmt_close($groupStatement);
        if ($groupLink !== '') {
            $manualMessages['grup'] = [
                'label' => 'Tautan grup WhatsApp wali murid',
                'text' => implode("\n", [
                    'Assalamu’alaikum warahmatullahi wabarakatuh.',
                    '',
                    'Yth. Ayah/Bunda dari Ananda *'.$participant['nm_peserta'].'*,',
                    '',
                    'Alhamdulillah, Ananda telah *diterima* di *'.$participant['nama_unit'].'* untuk Tahun Ajaran '.$participant['th_ajaran'].'.',
                    'Mohon bergabung ke grup resmi wali murid melalui tautan berikut, karena seluruh informasi lanjutan disampaikan di grup ini:',
                    $groupLink,
                    '',
                    'Wassalamu’alaikum warahmatullahi wabarakatuh.',
                    '',
                    ppdb_admin_contact_message(),
                ]),
            ];
        }
    }
}
$participant['bank_nama'] = PPDB_BANK_NAME;
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
            <a class="button-primary" href="verifikasi-data.php?id=<?php echo rawurlencode($registrationId); ?>">Cocokkan dengan KK</a>
            <a class="button-secondary" href="cetak-bukti?id=<?php echo rawurlencode($registrationId); ?>">Unduh bukti PDF</a>
            <a class="button-secondary" href="<?php echo h($returnUrl); ?>">&larr; Kembali ke <?php echo h($returnLabel); ?></a>
        </div>
    </div>
    <?php if ($error !== '') { ?><div class="alert-error" role="alert"><?php echo h($error); ?></div><?php } ?>
    <?php if ($notice !== '') { ?><div class="alert-success" role="status"><?php echo h($notice); ?><?php if ($routeShareLinks) { ?><span class="route-share-links"><?php foreach ($routeShareLinks['links'] as $shareLink) { ?><a class="button-secondary" href="<?php echo h($shareLink['url']); ?>" target="_blank" rel="noopener noreferrer">WhatsApp <?php echo h($shareLink['role'].' · '.$shareLink['display']); ?></a><?php } ?></span><?php } ?></div><?php } ?>
    <?php if ($nextKind !== '') { ?>
        <div class="next-participant">
            <?php if ($nextParticipant) { ?>
                <span>Masih ada <strong><?php echo $nextRemaining; ?></strong> peserta <?php echo $nextKind === 'menunggu_kontak' ? 'menunggu persetujuan' : 'dengan bukti bayar belum diperiksa'; ?>.</span>
                <a class="next-participant__button" href="detail-peserta.php?id=<?php echo rawurlencode($nextParticipant['id_pendaftaran']); ?>&amp;kembali=<?php echo rawurlencode($returnQuery !== '' ? $returnQuery : '?status='.$nextKind); ?>">Peserta berikutnya: <?php echo h($nextParticipant['nm_peserta']); ?> <span aria-hidden="true">&rarr;</span></a>
            <?php } else { ?>
                <span>Semua peserta <?php echo $nextKind === 'menunggu_kontak' ? 'yang menunggu persetujuan' : 'dengan bukti bayar masuk'; ?> sudah ditangani.</span>
                <a class="button-secondary" href="<?php echo h($returnUrl); ?>">Kembali ke <?php echo h($returnLabel); ?></a>
            <?php } ?>
        </div>
    <?php } ?>
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
            <?php if ($participant['status_ppdb'] === 'diterima' && array_key_exists('grup_diklik_pada', $participant)) { ?><div><span>Grup WhatsApp unit</span><strong class="tone-<?php echo $participant['grup_diklik_pada'] ? 'ok' : 'warn'; ?>"><?php echo $participant['grup_diklik_pada'] ? 'Tautan dibuka '.h(date('d-m-Y H:i', strtotime($participant['grup_diklik_pada']))) : 'Belum membuka tautan grup'; ?></strong></div><?php } ?>
            <?php if ($participant['tanggal_batas_bayar']) { ?><div><span>Batas pembayaran</span><strong><?php echo h(date('d-m-Y', strtotime($participant['tanggal_batas_bayar']))); ?></strong></div><?php } ?>
        </div>

        <?php if ($paymentAwaitingReview) { ?>
            <div class="payment-review">
                <div class="payment-review__head">
                    <span class="payment-review__icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M3 6a2 2 0 0 1 2-2h13v3H5v1h15a1 1 0 0 1 1 1v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6Zm14 8a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/></svg></span>
                    <div>
                        <strong>Bukti pembayaran perlu diperiksa</strong>
                        <small>Diunggah <?php echo h(date('d-m-Y H.i', strtotime($latestPayment['diunggah_pada']))); ?><?php echo (float) ($participant['biaya_pendaftaran'] ?? 0) > 0 ? ' · tagihan Rp '.h(number_format((float) $participant['biaya_pendaftaran'], 0, ',', '.')) : ''; ?><?php echo trim((string) $latestPayment['nomor_referensi']) !== '' ? ' · ref. '.h($latestPayment['nomor_referensi']) : ''; ?></small>
                    </div>
                    <a class="button-secondary" href="bukti-pembayaran-admin.php?id=<?php echo (int) $latestPayment['id']; ?>" target="_blank" rel="noopener">Buka bukti &#8599;</a>
                </div>
                <?php if (str_starts_with((string) $latestPayment['mime_type'], 'image/')) { ?>
                    <a class="payment-review__preview" href="bukti-pembayaran-admin.php?id=<?php echo (int) $latestPayment['id']; ?>" target="_blank" rel="noopener"><img src="bukti-pembayaran-admin.php?id=<?php echo (int) $latestPayment['id']; ?>" alt="Pratinjau bukti transfer" loading="lazy"></a>
                <?php } ?>
                <div class="payment-review__actions">
                    <form method="post" class="payment-review__accept" data-confirm="Terima bukti pembayaran <?php echo h($participant['nm_peserta']); ?>? Tidak ada pesan yang dikirim ke pendaftar.">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                        <input type="hidden" name="payment_id" value="<?php echo (int) $latestPayment['id']; ?>">
                        <input type="hidden" name="workflow_action" value="approve_payment">
                        <button class="approval-button" type="submit"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9.5 16.2 5.3 12l-1.6 1.6 5.8 5.8L20.3 8.6 18.7 7z"/></svg><span>Terima pembayaran</span></button>
                        <p class="approval-card__note">Hanya tercatat di panel admin; pendaftar tidak dikirimi pesan.</p>
                    </form>
                    <form method="post" class="payment-review__reject" data-confirm="Tolak bukti pembayaran ini dan kirim tautan unggah ulang ke WhatsApp pendaftar?">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                        <input type="hidden" name="payment_id" value="<?php echo (int) $latestPayment['id']; ?>">
                        <input type="hidden" name="workflow_action" value="reject_payment">
                        <label for="reject-note">Alasan penolakan <span aria-hidden="true">*</span></label>
                        <input id="reject-note" name="catatan_admin" maxlength="500" required placeholder="Contoh: nominal tidak sesuai, foto buram, bukan bukti transfer">
                        <button class="reject-button" type="submit"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6z"/></svg><span>Tolak &amp; minta unggah ulang</span></button>
                        <p class="approval-card__note">Pendaftar menerima WhatsApp berisi alasan dan tautan unggah ulang; batas bayar diperpanjang minimal 2 hari.</p>
                    </form>
                </div>
            </div>
        <?php } ?>

        <?php if ($participant['status_ppdb'] === 'menunggu_kontak') {
            $approvalPhone = ppdb_normalize_whatsapp_number((string) $participant['no_hp_pendaftar']);
            ?>
            <div class="approval-card">
                <div class="approval-card__head">
                    <span class="approval-card__icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Zm-1.2 14.2-3.5-3.5 1.4-1.4 2.1 2.1 4.9-4.9 1.4 1.4-6.3 6.3Z"/></svg></span>
                    <div>
                        <strong>Registrasi awal menunggu persetujuan</strong>
                        <small>Periksa data di bawah. Setelah disetujui, pendaftar menerima WhatsApp berisi tautan lanjutan pendaftaran dan batas pembayaran mulai dihitung.</small>
                    </div>
                </div>
                <dl class="approval-card__facts">
                    <div><dt>Nama ananda</dt><dd><?php echo h($participant['nm_peserta']); ?></dd></div>
                    <div><dt>Tanggal lahir</dt><dd><?php echo h(ppdb_format_date_id($participant['tgl_lahir'])); ?><small><?php echo h($participant['umur_dapodik']); ?></small></dd></div>
                    <div><dt>Unit pendidikan</dt><dd><?php echo h(ppdb_unit_display_name($participant['nama_unit'] ?? '')); ?><?php echo !empty($participant['tingkat_kelas']) ? ' · '.h($participant['tingkat_kelas']) : ''; ?></dd></div>
                    <div><dt>Jalur</dt><dd><?php echo $participant['jalur_pendaftaran'] === 'internal' ? 'Internal · SDM' : 'Eksternal · umum'; ?></dd></div>
                    <div><dt>Didaftarkan oleh</dt><dd><?php echo h(ppdb_pendaftar_label($participant['pendaftar'])); ?></dd></div>
                    <div><dt>Terdaftar</dt><dd><?php echo h(ppdb_format_date_id($participant['tgl_daftar'])); ?></dd></div>
                </dl>
                <div class="contact-strip">
                    <div><span>Pendaftar</span><strong><?php echo h(ppdb_display_phone($participant['no_hp_pendaftar'])); ?></strong></div>
                    <div><span>Ayah</span><strong><?php echo h(ppdb_display_phone($participant['no_hp_ayah'])); ?></strong></div>
                    <div><span>Ibu</span><strong><?php echo h(ppdb_display_phone($participant['no_hp_ibu'])); ?></strong></div>
                </div>
                <div class="approval-card__actions">
                    <form method="post" data-confirm="Setujui registrasi <?php echo h($participant['nm_peserta']); ?> dan kirim tautan lanjutan ke WhatsApp <?php echo h(ppdb_display_phone($participant['no_hp_pendaftar'])); ?>?">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                        <input type="hidden" name="workflow_action" value="approve_registration">
                        <button class="approval-button" type="submit"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9.5 16.2 5.3 12l-1.6 1.6 5.8 5.8L20.3 8.6 18.7 7z"/></svg><span>Setujui registrasi &amp; kirim WhatsApp</span></button>
                        <?php if (!ppdb_wablas_enabled()) { ?>
                            <p class="approval-card__note is-warning">Wablas belum aktif. Setelah disetujui, kirim tautannya lewat panel WhatsApp manual.</p>
                        <?php } elseif ($approvalPhone === '') { ?>
                            <p class="approval-card__note is-warning">Nomor WhatsApp pendaftar tidak valid; pesan perlu dikirim manual ke nomor ayah atau ibu.</p>
                        <?php } else { ?>
                            <p class="approval-card__note">Pesan dikirim ke <?php echo h(ppdb_display_phone($approvalPhone)); ?> (pendaftar).</p>
                        <?php } ?>
                    </form>
                    <details class="approval-reject">
                        <summary>Tolak pendaftaran</summary>
                        <?php ppdb_detail_reject_form($registrationId); ?>
                    </details>
                </div>
            </div>
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
            <p class="workflow-hint">Peserta diterima otomatis setelah menyelesaikan pendaftaran. Hasil, tautan grup WhatsApp unit, dan surat keterangan diterima tampil pada halaman pendaftar. Bila bukti transfer ditolak, penerimaan ditahan sampai pendaftar mengunggah bukti baru.</p>
            <div class="workflow-actions">
                <form method="post" class="stacked-action" data-confirm="Batalkan penerimaan peserta ini?">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="workflow_action" value="reject_formulir">
                    <label for="cancel-acceptance-note">Alasan pembatalan</label>
                    <input id="cancel-acceptance-note" name="catatan_admin" maxlength="500" required>
                    <button class="button-danger" type="submit">Batalkan penerimaan</button>
                </form>
            </div>
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
        <?php } elseif ($participant['status_ppdb'] === 'ditolak') { ?>
            <p class="workflow-hint"><?php echo $participant['status_data'] === 'belum_diperiksa' && in_array($participant['status_pembayaran'], ['terverifikasi', 'menunggu_verifikasi'], true)
                ? 'Pendaftar memilih tidak melanjutkan pengisian formulir.'
                : 'Pendaftaran ditolak dan pendaftar diminta menghubungi panitia.'; ?> Tekan Lanjutkan pendaftaran untuk membuka kembali aksesnya; pendaftar meneruskan dari tahap terakhirnya.</p>
            <div class="workflow-actions">
                <form method="post" data-confirm="Buka kembali akses pendaftaran peserta ini?">
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="id" value="<?php echo h($registrationId); ?>">
                    <input type="hidden" name="workflow_action" value="reopen_registration">
                    <button class="button-primary" type="submit">Lanjutkan pendaftaran</button>
                </form>
            </div>
        <?php } ?>
    </section>

    <?php if ($manualMessages && $participant['status_ppdb'] !== 'menunggu_kontak') { ?>
        <details class="detail-panel wide manual-wa" data-manual-wa>
            <summary>
                <span class="manual-wa__icon" aria-hidden="true"><svg viewBox="0 0 32 32" focusable="false"><path d="M16.04 3C8.87 3 3.04 8.77 3.04 15.88c0 2.27.6 4.48 1.75 6.43L3 29l6.9-1.8a13.1 13.1 0 0 0 6.14 1.52h.01c7.17 0 13-5.78 13-12.89 0-3.45-1.36-6.69-3.83-9.12A12.94 12.94 0 0 0 16.04 3Zm5.92 15.5c-.32-.16-1.9-.93-2.2-1.04-.3-.11-.51-.16-.73.16-.21.32-.84 1.04-1.03 1.25-.19.21-.38.24-.7.08-.32-.16-1.36-.5-2.59-1.59-.96-.85-1.61-1.9-1.8-2.22-.19-.32-.02-.49.14-.65.14-.14.32-.37.48-.56.16-.19.21-.32.32-.53.11-.21.05-.4-.03-.56-.08-.16-.73-1.74-1-2.38-.26-.62-.53-.54-.73-.55l-.62-.01c-.21 0-.56.08-.86.4-.3.32-1.12 1.09-1.12 2.67s1.15 3.1 1.31 3.31c.16.21 2.26 3.43 5.47 4.81.76.33 1.36.52 1.82.66.76.24 1.45.21 2 .13.61-.09 1.9-.77 2.17-1.52.27-.75.27-1.39.19-1.52-.08-.13-.3-.21-.62-.37Z"/></svg></span>
                <span class="manual-wa__title"><strong>Kirim pesan WhatsApp manual</strong><small>Gunakan bila pesan otomatis (Wablas) tidak terkirim.</small></span>
            </summary>
            <div class="manual-wa__body">
                <?php if (count($manualMessages) > 1) { ?>
                    <div class="manual-wa__choices" role="radiogroup" aria-label="Jenis pesan">
                        <?php $firstMessage = true; foreach ($manualMessages as $messageKey => $message) { ?>
                            <label><input type="radio" name="manual_wa_jenis" value="<?php echo h($messageKey); ?>" data-manual-wa-choice<?php echo $firstMessage ? ' checked' : ''; ?>> <?php echo h($message['label']); ?></label>
                        <?php $firstMessage = false; } ?>
                    </div>
                <?php } ?>
                <?php $firstMessage = true; foreach ($manualMessages as $messageKey => $message) { ?>
                    <textarea class="manual-wa__text" rows="12" data-manual-wa-text="<?php echo h($messageKey); ?>" aria-label="<?php echo h($message['label']); ?>"<?php echo $firstMessage ? '' : ' hidden'; ?>><?php echo h($message['text']); ?></textarea>
                <?php $firstMessage = false; } ?>
                <p class="manual-wa__hint">Teks dapat diubah sebelum dikirim. Tombol di bawah membuka WhatsApp dengan pesan ini; tekan kirim di WhatsApp.</p>
                <div class="manual-wa__actions">
                    <?php foreach ($manualRecipients as $number => $role) { ?>
                        <a class="manual-wa__send" href="<?php echo h(ppdb_whatsapp_link($number, reset($manualMessages)['text'])); ?>" target="_blank" rel="noopener noreferrer" data-manual-wa-send="<?php echo h($number); ?>">Kirim ke <?php echo h($role); ?> · <?php echo h(ppdb_display_phone($number)); ?></a>
                    <?php } ?>
                    <button class="button-secondary" type="button" data-manual-wa-copy>Salin pesan</button>
                </div>
            </div>
        </details>
    <?php } ?>

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
            if ($selectionData['jalur_seleksi'] === 'prestasi' && ($selectionData['id_pendaftaran'] ?? null) === null) {
                $selectionData['status_isian_prestasi'] = 'Menunggu wali murid mengisi lewat tautan WhatsApp';
                $selectionFields[] = ['Data prestasi', 'status_isian_prestasi'];
            } elseif ($selectionData['jalur_seleksi'] === 'prestasi') {
                foreach ([['Prestasi akademik', 'jenis_prestasi'], ['Kelas 4 semester 1', 'peringkat_k4_s1'], ['Kelas 4 semester 2', 'peringkat_k4_s2'], ['Kelas 5 semester 1', 'peringkat_k5_s1'], ['Kelas 5 semester 2', 'peringkat_k5_s2'], ['Kelas 6 semester 1', 'peringkat_k6_s1'], ['Kategori prestasi', 'kategori_hafalan'], ['Jumlah hafalan', 'jumlah_hafalan'], ['Riwayat lomba', 'riwayat_lomba'], ['Lomba yang dimenangkan', 'daftar_lomba']] as $selectionField) { if (trim((string) ($selectionData[$selectionField[1]] ?? '')) !== '') { $selectionFields[] = $selectionField; } }
            }
            render_detail_panel('Jalur pendaftaran SMP', $selectionFields, $selectionData);
        } ?>
        <?php if (prestasi_is_ready($conn) && ($participant['jenjang'] ?? '') === 'SMP' && $participant['status_ppdb'] !== 'data_lama') {
            $currentRoute = (string) ($participant['jalur_seleksi'] ?? '');
            $routeFormAction = 'detail-peserta.php?id='.rawurlencode($registrationId); ?>
        <section class="detail-panel wide route-switch">
            <h2>Ubah jalur seleksi</h2>
            <?php
            $routeHidden = static function (string $action, string $route = '') use ($registrationId): void {
                echo '<input type="hidden" name="csrf_token" value="'.h(csrf_token()).'">';
                echo '<input type="hidden" name="id" value="'.h($registrationId).'">';
                echo '<input type="hidden" name="workflow_action" value="'.h($action).'">';
                if ($route !== '') {
                    echo '<input type="hidden" name="jalur_seleksi" value="'.h($route).'">';
                }
            };
            if ($currentRoute === 'prestasi') {
                $awaitingParent = !prestasi_has_data($conn, $registrationId); ?>
                <div class="route-switch__body">
                    <?php if ($awaitingParent) { ?>
                        <p class="route-switch__hint">Peserta di jalur <strong>Prestasi</strong> dan <strong>menunggu wali murid mengisi data prestasi</strong> lewat tautan WhatsApp. Kuota, nomor antrean, dan pembayaran tidak berubah bila jalur dipindah.</p>
                    <?php } else { ?>
                        <p class="route-switch__hint">Peserta saat ini di jalur <strong>Prestasi</strong>. Kuota, nomor antrean, dan pembayaran tidak berubah bila jalur dipindah.</p>
                    <?php } ?>
                    <div class="route-switch__actions">
                        <?php if ($awaitingParent) { ?>
                            <form method="post" action="<?php echo h($routeFormAction); ?>"><?php $routeHidden('resend_prestasi_link'); ?><button class="button-primary" type="submit">Kirim ulang tautan ke wali murid</button></form>
                        <?php } ?>
                        <form method="post" action="<?php echo h($routeFormAction); ?>" data-confirm="Pindahkan peserta ini ke jalur umum? Data prestasi tidak lagi ditampilkan."><?php $routeHidden('switch_selection_route', 'umum'); ?><button class="button-secondary" type="submit">Pindah ke jalur umum</button></form>
                    </div>
                </div>
            <?php } else { ?>
                <form class="route-switch__body" method="post" action="<?php echo h($routeFormAction); ?>" data-confirm="Pindahkan peserta ini ke jalur prestasi? Wali murid diminta mengisi data prestasi lewat tautan WhatsApp.">
                    <?php $routeHidden('switch_selection_route', 'prestasi'); ?>
                    <p class="route-switch__hint">Peserta saat ini di jalur <strong><?php echo h(prestasi_jalur_label($currentRoute) ?: 'belum dipilih'); ?></strong>. Setelah dipindah, wali murid menerima tautan WhatsApp untuk mengisi data prestasi (surat pengantar, peringkat raport, hafalan, dan lomba). Kuota, nomor antrean, dan pembayaran tidak berubah.</p>
                    <button class="button-primary" type="submit">Pindah ke jalur prestasi</button>
                </form>
            <?php } ?>
        </section>
        <?php } ?>
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
