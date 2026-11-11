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
 * Kirim konfirmasi kursi beserta tautan lanjutan ke nomor pendaftar. Dipanggil saat
 * admin menyetujui registrasi awal di detail peserta.
 *
 * @return array{ok: bool, error: string, phone: string}
 */
function ppdb_send_registration_whatsapp(mysqli $conn, string $registrationId): array
{
    if (!ppdb_wablas_enabled()) {
        return ['ok' => false, 'error' => 'Wablas belum diatur.', 'phone' => ''];
    }
    try {
        $stmt = mysqli_prepare($conn, 'SELECT p.id_pendaftaran,p.kode_unit,p.nm_peserta,p.th_ajaran,p.no_hp_pendaftar,p.tanggal_batas_bayar,p.token_bukti,u.nama_unit,q.biaya_pendaftaran FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit LEFT JOIN tb_pengaturan_ppdb q ON q.kode_unit=p.kode_unit AND q.th_ajaran=p.th_ajaran WHERE p.id_pendaftaran=? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 's', $registrationId);
        mysqli_stmt_execute($stmt);
        $registration = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $phone = ppdb_normalize_whatsapp_number((string) ($registration['no_hp_pendaftar'] ?? ''));
        if ($phone === '') {
            return ['ok' => false, 'error' => 'Nomor WhatsApp pendaftar tidak valid.', 'phone' => ''];
        }
        ppdb_resume_token($conn, $registrationId);
        $formWindow = ppdb_form_window($conn, (string) $registration['kode_unit'], (string) $registration['th_ajaran']);
        $delivery = ppdb_wablas_send($phone, ppdb_registration_whatsapp_message($registration, ppdb_continue_url($registration), $formWindow));
        if (!$delivery['ok']) {
            error_log('Konfirmasi WhatsApp '.$registrationId.' gagal: '.$delivery['error']);
        }
        return $delivery + ['phone' => $phone];
    } catch (Throwable $exception) {
        error_log('Konfirmasi WhatsApp '.$registrationId.' gagal: '.$exception->getMessage());
        return ['ok' => false, 'error' => 'Pesan belum dapat dikirim.', 'phone' => ''];
    }
}

/** Pesan untuk pendaftar yang bukti pembayarannya ditolak: alasan dan tautan unggah ulang. */
function ppdb_payment_rejected_whatsapp_message(array $registration, string $reason, string $continueUrl): string
{
    $lines = [
        'Assalamu’alaikum warahmatullahi wabarakatuh.',
        '',
        'Yth. Ayah/Bunda dari Ananda *'.$registration['nm_peserta'].'*,',
        '',
        'Mohon maaf, bukti pembayaran pendaftaran Ananda di *'.$registration['nama_unit'].'* (kode *'.$registration['id_pendaftaran'].'*) *belum dapat kami terima*.',
    ];
    if (trim($reason) !== '') {
        $lines[] = 'Alasan: '.trim($reason);
    }
    array_push($lines, '', 'Mohon unggah ulang bukti pembayaran yang benar melalui tautan berikut:', $continueUrl);
    if (!empty($registration['tanggal_batas_bayar'])) {
        $lines[] = 'Batas unggah ulang: '.ppdb_format_date_id($registration['tanggal_batas_bayar']);
    }
    array_push(
        $lines,
        '',
        'Isian formulir yang sudah tersimpan tidak hilang.',
        '',
        'Terima kasih atas perhatian Ayah/Bunda.',
        'Wassalamu’alaikum warahmatullahi wabarakatuh.',
        '',
        'Admin SPMB Yayasan Wakaf Cendekia Takengon',
        'WhatsApp: '.ppdb_admin_whatsapp_display()
    );
    return implode("\n", $lines);
}

/** Data pendaftar untuk pesan penolakan bukti pembayaran. */
function ppdb_payment_rejected_registration(mysqli $conn, string $registrationId): ?array
{
    $stmt = mysqli_prepare($conn, 'SELECT p.id_pendaftaran,p.nm_peserta,p.no_hp_pendaftar,p.tanggal_batas_bayar,p.token_bukti,u.nama_unit FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit WHERE p.id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
    mysqli_stmt_execute($stmt);
    $registration = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $registration;
}

/**
 * Kirim pemberitahuan bukti pembayaran ditolak beserta tautan unggah ulang.
 *
 * @return array{ok: bool, error: string, phone: string}
 */
function ppdb_send_payment_rejected_whatsapp(mysqli $conn, string $registrationId, string $reason): array
{
    if (!ppdb_wablas_enabled()) {
        return ['ok' => false, 'error' => 'Wablas belum diatur.', 'phone' => ''];
    }
    try {
        $registration = ppdb_payment_rejected_registration($conn, $registrationId);
        $phone = ppdb_normalize_whatsapp_number((string) ($registration['no_hp_pendaftar'] ?? ''));
        if (!$registration || $phone === '') {
            return ['ok' => false, 'error' => 'Nomor WhatsApp pendaftar tidak valid.', 'phone' => ''];
        }
        ppdb_resume_token($conn, $registrationId);
        $delivery = ppdb_wablas_send($phone, ppdb_payment_rejected_whatsapp_message($registration, $reason, ppdb_continue_url($registration)));
        if (!$delivery['ok']) {
            error_log('Pemberitahuan bukti ditolak '.$registrationId.' gagal: '.$delivery['error']);
        }
        return $delivery + ['phone' => $phone];
    } catch (Throwable $exception) {
        error_log('Pemberitahuan bukti ditolak '.$registrationId.' gagal: '.$exception->getMessage());
        return ['ok' => false, 'error' => 'Pesan belum dapat dikirim.', 'phone' => ''];
    }
}

/**
 * Tahap yang dapat diingatkan kepada orang tua:
 * 'bayar' (belum bayar / bukti ditolak), 'formulir' (formulir lengkap belum dikirim), atau '' bila tidak ada.
 */
function ppdb_reminder_stage(array $registration): string
{
    $status = (string) ($registration['status_ppdb'] ?? '');
    $payment = (string) ($registration['status_pembayaran'] ?? '');
    if ($status === 'menunggu_pembayaran' && !in_array($payment, ['menunggu_verifikasi', 'terverifikasi'], true)) {
        return 'bayar';
    }
    if (in_array($status, ['menunggu_pembayaran', 'pembayaran_diperiksa', 'pembayaran_terverifikasi', 'menunggu_formulir'], true)) {
        return 'formulir';
    }
    return '';
}

/** Alasan singkat bila peserta tidak perlu (atau belum dapat) diingatkan. */
function ppdb_reminder_unavailable_note(array $registration): string
{
    return match ((string) ($registration['status_ppdb'] ?? '')) {
        'menunggu_kontak' => 'Registrasi awal belum disetujui. Setujui dulu di halaman detail; tautan lanjutan terkirim otomatis.',
        'formulir_terisi' => 'Formulir lengkap sudah dikirim dan sedang diperiksa panitia.',
        'diterima' => 'Peserta sudah diterima.',
        'daftar_tunggu' => 'Peserta berada di daftar tunggu kuota.',
        'ditolak' => 'Pendaftaran memerlukan tindak lanjut panitia.',
        default => 'Tidak ada tahap yang perlu dilanjutkan orang tua.',
    };
}

/**
 * Kontak admin yang dicantumkan di akhir pengingat: nomor WhatsApp admin unit
 * (Profil unit di cms-unit.php), atau nomor admin SPMB yayasan bila belum diisi.
 *
 * @return array{name: string, display: string, unit: bool}
 */
function ppdb_reminder_admin_contact(array $registration): array
{
    $unitPhone = ppdb_normalize_whatsapp_number((string) ($registration['telepon_unit'] ?? ''));
    if ($unitPhone === '') {
        return ['name' => 'Admin SPMB Yayasan Wakaf Cendekia Takengon', 'display' => ppdb_admin_whatsapp_display(), 'unit' => false];
    }
    $local = '0'.substr($unitPhone, 2);
    return [
        'name' => 'Admin SPMB '.ppdb_unit_display_name((string) ($registration['nama_unit'] ?? '')),
        'display' => trim(chunk_split($local, 4, ' ')),
        'unit' => true,
    ];
}

/** Pesan pengingat profesional sesuai tahap yang belum diselesaikan. */
function ppdb_reminder_whatsapp_message(array $registration, string $stage, string $continueUrl, array $formWindow = []): string
{
    $contact = ppdb_reminder_admin_contact($registration);
    $lines = [
        'Assalamu’alaikum warahmatullahi wabarakatuh.',
        '',
        'Yth. Ayah/Bunda dari Ananda *'.$registration['nm_peserta'].'*,',
        '',
        'Dengan hormat, kami mengingatkan bahwa proses pendaftaran Ananda di *'.$registration['nama_unit'].'* Tahun Ajaran '.$registration['th_ajaran'].' *belum selesai*.',
        'Kode pendaftaran: *'.$registration['id_pendaftaran'].'*',
        '',
        '*Tahap yang perlu diselesaikan:*',
    ];
    if ($stage === 'bayar') {
        $fee = (float) ($registration['biaya_pendaftaran'] ?? 0);
        $lines[] = '1. Melakukan pembayaran biaya pendaftaran'.($fee > 0 ? ' sebesar *Rp '.number_format($fee, 0, ',', '.').'*' : '').'.';
        $lines[] = ($registration['status_pembayaran'] ?? '') === 'ditolak'
            ? '2. Mengunggah ulang bukti transfer yang benar (bukti sebelumnya belum dapat diterima).'
            : '2. Mengunggah foto bukti transfer.';
        $lines[] = '3. Mengisi formulir pendaftaran lengkap.';
        if (!empty($registration['tanggal_batas_bayar'])) {
            $lines[] = '';
            $lines[] = 'Batas pembayaran: *'.ppdb_format_date_id($registration['tanggal_batas_bayar']).'*';
        }
    } else {
        $lines[] = 'Mengisi dan mengirim *formulir pendaftaran lengkap* (data Ananda, alamat, orang tua/wali, dan kesehatan).';
    }
    if (!empty($formWindow['batas'])) {
        if ($stage !== 'bayar' || empty($registration['tanggal_batas_bayar'])) {
            $lines[] = '';
        }
        $lines[] = 'Batas pengisian formulir: *'.ppdb_format_day_date_id($formWindow['batas']).' pukul '.date('H.i', strtotime($formWindow['batas'])).' WIB*';
        $lines[] ='Apabila belum diselesaikan sampai batas tersebut, Ananda *dinyatakan gugur*.';
    }
    array_push(
        $lines,
        '',
        'Silakan lanjutkan melalui tautan pribadi berikut:',
        $continueUrl,
        '',
        'Apabila Ayah/Bunda sudah menyelesaikannya, mohon abaikan pesan ini. Bila mengalami kendala, silakan hubungi admin melalui nomor WhatsApp di bawah ini.',
        '',
        'Terima kasih atas perhatian Ayah/Bunda.',
        'Wassalamu’alaikum warahmatullahi wabarakatuh.',
        '',
        $contact['name'],
        'WhatsApp: '.$contact['display']
    );
    return implode("\n", $lines);
}
