<?php
/**
 * Integrasi Wablas: kirim pesan WhatsApp otomatis dari server.
 * Konfigurasi dibaca dari .env (WABLAS_BASE_URL, WABLAS_TOKEN, WABLAS_SECRET).
 * Jika belum diisi, sistem kembali ke tombol wa.me manual.
 */

function ppdb_wablas_enabled(): bool
{
    return trim((string) getenv('WABLAS_TOKEN')) !== '';
}

function ppdb_wablas_send(string $phone, string $message): array
{
    if (!function_exists('curl_init')) {
        error_log('Wablas tidak dapat mengirim pesan: ekstensi cURL PHP tidak aktif.');
        return ['ok' => false, 'error' => 'Ekstensi cURL server belum aktif.'];
    }

    $baseUrl = rtrim(trim((string) getenv('WABLAS_BASE_URL')) ?: 'https://jkt.wablas.com', '/');
    $token = trim((string) getenv('WABLAS_TOKEN'));
    $secret = trim((string) getenv('WABLAS_SECRET'));
    $authorization = $secret !== '' ? $token.'.'.$secret : $token;

    $ch = curl_init($baseUrl.'/api/send-message');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['phone' => $phone, 'message' => $message]),
        CURLOPT_HTTPHEADER => ['Authorization: '.$authorization],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($body === false) {
        error_log('Wablas gagal: '.$curlError);
        return ['ok' => false, 'error' => 'Server WhatsApp tidak dapat dihubungi.'];
    }
    $json = json_decode((string) $body, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($json['status'])) {
        return ['ok' => true, 'error' => ''];
    }
    error_log('Wablas ditolak ('.$httpCode.'): '.$body);
    return ['ok' => false, 'error' => (string) ($json['message'] ?? 'Pengiriman ditolak Wablas.')];
}

/** Kirim pesan ke semua nomor dalam $shareLinks; hasil disimpan per tautan. */
function ppdb_wablas_send_links(array $shareLinks): array
{
    if (!ppdb_wablas_enabled()) {
        return $shareLinks;
    }
    foreach ($shareLinks['links'] as $i => $link) {
        $result = ppdb_wablas_send($link['phone'], $shareLinks['message']);
        $shareLinks['links'][$i]['sent'] = $result['ok'];
        $shareLinks['links'][$i]['send_error'] = $result['error'];
    }
    return $shareLinks;
}

/** Selesaikan respons ke peramban lebih dulu bila server mendukung, lalu lanjutkan pekerjaan di belakang. */
function ppdb_finish_response(): void
{
    ignore_user_abort(true);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
}

/**
 * Pesan konfirmasi kursi untuk nomor pendaftar setelah registrasi awal.
 *
 * @param array{buka?: ?string, batas?: ?string} $formWindow jadwal pengisian formulir dari ppdb_form_window()
 */
function ppdb_registration_whatsapp_message(array $registration, string $continueUrl, array $formWindow = []): string
{
    $lines = [
        'Assalamu’alaikum warahmatullahi wabarakatuh.',
        '',
        'Yth. Ayah/Bunda dari Ananda *'.$registration['nm_peserta'].'*,',
        '',
        'Alhamdulillah, registrasi awal telah kami terima dan Ananda *sudah mendapatkan kursi* di *'.$registration['nama_unit'].'* untuk Tahun Ajaran '.$registration['th_ajaran'].'.',
        '',
        'Kode pendaftaran: *'.$registration['id_pendaftaran'].'*',
    ];
    if ((float) $registration['biaya_pendaftaran'] > 0) {
        $lines[] = 'Biaya pendaftaran: Rp '.number_format((float) $registration['biaya_pendaftaran'], 0, ',', '.');
        if (!empty($registration['tanggal_batas_bayar'])) {
            $lines[] = 'Batas pembayaran: '.ppdb_format_date_id($registration['tanggal_batas_bayar']);
        }
    }
    if (!empty($formWindow['batas'])) {
        $lines[] = '';
        $lines[] = '*Jadwal pengisian formulir lengkap*';
        if (!empty($formWindow['buka'])) {
            $lines[] = 'Dibuka: '.ppdb_format_day_date_id($formWindow['buka']).' pukul '.date('H.i', strtotime($formWindow['buka'])).' WIB';
        }
        $lines[] = 'Batas akhir: *'.ppdb_format_day_date_id($formWindow['batas']).' pukul '.date('H.i', strtotime($formWindow['batas'])).' WIB*';
        $lines[] = 'Apabila pembayaran dan formulir lengkap belum diselesaikan sampai batas tersebut, Ananda *dinyatakan gugur*.';
    }
    array_push(
        $lines,
        '',
        'Lanjutkan pendaftaran melalui tautan berikut:',
        $continueUrl,
        '',
        'Tautan ini bersifat pribadi. Mohon disimpan dan tidak dibagikan kepada orang lain.',
        '',
        'Terima kasih atas kepercayaan Ayah/Bunda kepada Yayasan Wakaf Cendekia Takengon.',
        'Wassalamu’alaikum warahmatullahi wabarakatuh.',
        '',
        'Admin SPMB Yayasan Wakaf Cendekia Takengon',
        'WhatsApp: '.ppdb_admin_whatsapp_display()
    );
    return implode("\n", $lines);
}

/**
 * Masukkan konfirmasi kursi ke antrean WhatsApp berjeda (wa_queue.php); pesan tidak
 * dikirim langsung agar nomor Wablas tidak diblokir saat pendaftar ramai.
 */
function ppdb_send_registration_whatsapp(mysqli $conn, string $registrationId): bool
{
    require_once __DIR__.'/wa_queue.php';
    try {
        $stmt = mysqli_prepare($conn, 'SELECT p.id_pendaftaran,p.kode_unit,p.nm_peserta,p.th_ajaran,p.no_hp_pendaftar,p.tanggal_batas_bayar,p.token_bukti,u.nama_unit,q.biaya_pendaftaran FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit LEFT JOIN tb_pengaturan_ppdb q ON q.kode_unit=p.kode_unit AND q.th_ajaran=p.th_ajaran WHERE p.id_pendaftaran=? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 's', $registrationId);
        mysqli_stmt_execute($stmt);
        $registration = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $phone = ppdb_normalize_whatsapp_number((string) ($registration['no_hp_pendaftar'] ?? ''));
        if ($phone === '') {
            return false;
        }
        ppdb_resume_token($conn, $registrationId);
        $formWindow = ppdb_form_window($conn, (string) $registration['kode_unit'], (string) $registration['th_ajaran']);
        $queued = wa_queue_add($conn, $phone, ppdb_registration_whatsapp_message($registration, ppdb_continue_url($registration), $formWindow), 'registrasi', $registrationId);
        if (!$queued) {
            error_log('Konfirmasi WhatsApp '.$registrationId.' belum masuk antrean (migrasi 038 belum dijalankan?).');
        }
        return $queued;
    } catch (Throwable $exception) {
        error_log('Konfirmasi WhatsApp '.$registrationId.' gagal: '.$exception->getMessage());
        return false;
    }
}
