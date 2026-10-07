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
    curl_close($ch);

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
