<?php
/**
 * Antrean WhatsApp berjeda (migrasi 038). Pesan otomatis tidak dikirim sekaligus,
 * tetapi satu per satu dengan jeda acak, batas harian, dan jam kirim, agar nomor
 * Wablas tidak dianggap spam. Antrean dijalankan oleh kunjungan halaman situs
 * (setelah respons terkirim), jadi tidak memerlukan cron.
 */

require_once __DIR__.'/app_helpers.php';
require_once __DIR__.'/wablas.php';

const WA_QUEUE_MIN_GAP = 75;           // detik, jeda minimum antarpesan
const WA_QUEUE_MAX_GAP = 150;          // detik, jeda maksimum antarpesan
const WA_QUEUE_DAILY_LIMIT = 1000;     // pesan terkirim per hari (paket Wablas tanpa batas)
const WA_QUEUE_START_HOUR = 5;         // jam kirim WIB, mulai (termasuk)
const WA_QUEUE_END_HOUR = 24;          // jam kirim WIB, selesai (tidak termasuk)
const WA_QUEUE_MAX_ATTEMPTS = 3;       // percobaan per pesan sebelum dinyatakan gagal
const WA_QUEUE_FAIL_PAUSE = 3;         // gagal beruntun sebelum antrean dijeda otomatis
const WA_QUEUE_PAUSE_SECONDS = 1800;   // lama jeda otomatis
const WA_QUEUE_STATE_KEY = 'wa_antrean_status';

function wa_queue_ready(mysqli $conn): bool
{
    return ppdb_table_exists($conn, 'tb_wa_antrean');
}

/** Status antrean: waktu kirim berikutnya, jeda manual/otomatis, dan jumlah gagal beruntun. */
function wa_queue_state(mysqli $conn): array
{
    $statement = mysqli_prepare($conn, 'SELECT setting_value FROM tb_cms_settings WHERE setting_key=? LIMIT 1');
    $key = WA_QUEUE_STATE_KEY;
    mysqli_stmt_bind_param($statement, 's', $key);
    mysqli_stmt_execute($statement);
    $value = (string) (mysqli_fetch_assoc(mysqli_stmt_get_result($statement))['setting_value'] ?? '');
    mysqli_stmt_close($statement);
    $state = json_decode($value, true);
    return (is_array($state) ? $state : []) + ['next_at' => 0, 'paused' => false, 'paused_until' => 0, 'fails' => 0, 'last_error' => ''];
}

function wa_queue_save_state(mysqli $conn, array $state): void
{
    $statement = mysqli_prepare($conn, 'INSERT INTO tb_cms_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $key = WA_QUEUE_STATE_KEY;
    $value = (string) json_encode($state, JSON_UNESCAPED_UNICODE);
    mysqli_stmt_bind_param($statement, 'ss', $key, $value);
    mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
}

/**
 * Masukkan pesan ke antrean. Pesan sejenis untuk peserta dan nomor yang sama yang
 * belum terkirim diperbarui, bukan digandakan.
 */
function wa_queue_add(mysqli $conn, string $phone, string $message, string $type, ?string $registrationId = null): bool
{
    $number = ppdb_normalize_whatsapp_number($phone);
    if ($number === '' || trim($message) === '' || !wa_queue_ready($conn)) {
        return false;
    }
    $existing = mysqli_prepare($conn, "SELECT id FROM tb_wa_antrean WHERE nomor=? AND jenis=? AND id_pendaftaran<=>? AND status='menunggu' LIMIT 1");
    mysqli_stmt_bind_param($existing, 'sss', $number, $type, $registrationId);
    mysqli_stmt_execute($existing);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($existing));
    mysqli_stmt_close($existing);
    if ($row) {
        $update = mysqli_prepare($conn, 'UPDATE tb_wa_antrean SET pesan=? WHERE id=?');
        mysqli_stmt_bind_param($update, 'si', $message, $row['id']);
        $ok = mysqli_stmt_execute($update);
        mysqli_stmt_close($update);
        return $ok;
    }
    $insert = mysqli_prepare($conn, 'INSERT INTO tb_wa_antrean (nomor,pesan,jenis,id_pendaftaran) VALUES (?,?,?,?)');
    mysqli_stmt_bind_param($insert, 'ssss', $number, $message, $type, $registrationId);
    $ok = mysqli_stmt_execute($insert);
    mysqli_stmt_close($insert);
    return $ok;
}

/** Variasi salam pembuka agar pesan beruntun tidak identik. */
function wa_queue_vary_message(string $message, int $id): string
{
    $greetings = [
        'Assalamu’alaikum warahmatullahi wabarakatuh.',
        'Assalamu’alaikum wr. wb.',
        'Assalamualaikum warahmatullahi wabarakatuh.',
        'Assalamu’alaikum warahmatullah wabarakatuh.',
    ];
    if (!str_starts_with($message, $greetings[0])) {
        return $message;
    }
    return $greetings[$id % count($greetings)].substr($message, strlen($greetings[0]));
}

/**
 * Kirim paling banyak satu pesan bila jeda, jam kirim, dan batas harian mengizinkan.
 * Mengembalikan hasil pengiriman, atau null bila tidak ada yang dikirim.
 */
function wa_queue_tick(mysqli $conn): ?array
{
    $now = time();
    $state = wa_queue_state($conn);
    if (!empty($state['paused']) || (int) $state['paused_until'] > $now || (int) $state['next_at'] > $now) {
        return null;
    }
    $hour = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('G');
    if ($hour < WA_QUEUE_START_HOUR || $hour >= WA_QUEUE_END_HOUR || !ppdb_wablas_enabled() || !wa_queue_ready($conn)) {
        wa_queue_save_state($conn, ['next_at' => $now + 600] + $state);
        return null;
    }
    $lock = mysqli_query($conn, "SELECT GET_LOCK('ppdb_wa_antrean', 0) AS ok");
    if (!$lock || (int) (mysqli_fetch_assoc($lock)['ok'] ?? 0) !== 1) {
        return null;
    }
    try {
        // Dibaca ulang di dalam kunci agar dua kunjungan bersamaan tidak sama-sama mengirim.
        $state = wa_queue_state($conn);
        if (!empty($state['paused']) || (int) $state['paused_until'] > $now || (int) $state['next_at'] > $now) {
            return null;
        }
        $today = mysqli_query($conn, "SELECT COUNT(*) AS total FROM tb_wa_antrean WHERE status='terkirim' AND dikirim_pada>=CURDATE()");
        if ((int) (mysqli_fetch_assoc($today)['total'] ?? 0) >= WA_QUEUE_DAILY_LIMIT) {
            wa_queue_save_state($conn, ['next_at' => $now + 600] + $state);
            return null;
        }
        $pick = mysqli_query($conn, "SELECT id,nomor,pesan,percobaan FROM tb_wa_antrean WHERE status='menunggu' AND dijadwalkan_pada<=NOW() ORDER BY id LIMIT 1");
        $item = $pick ? mysqli_fetch_assoc($pick) : null;
        if (!$item) {
            // Antrean kosong: periksa lagi 30 detik kemudian, bukan di setiap kunjungan.
            wa_queue_save_state($conn, ['next_at' => $now + 30] + $state);
            return null;
        }
        // Jadwal berikutnya disimpan sebelum mengirim agar kunjungan lain langsung menunggu.
        $state['next_at'] = $now + random_int(WA_QUEUE_MIN_GAP, WA_QUEUE_MAX_GAP);
        wa_queue_save_state($conn, $state);

        $result = ppdb_wablas_send((string) $item['nomor'], wa_queue_vary_message((string) $item['pesan'], (int) $item['id']));
        $id = (int) $item['id'];
        if ($result['ok']) {
            mysqli_query($conn, "UPDATE tb_wa_antrean SET status='terkirim',dikirim_pada=NOW(),percobaan=percobaan+1,galat='' WHERE id=".$id);
            $state['fails'] = 0;
            $state['last_error'] = '';
        } else {
            $attempts = (int) $item['percobaan'] + 1;
            $error = mb_substr((string) $result['error'], 0, 250);
            $retry = mysqli_prepare($conn, $attempts >= WA_QUEUE_MAX_ATTEMPTS
                ? "UPDATE tb_wa_antrean SET status='gagal',percobaan=?,galat=? WHERE id=?"
                : 'UPDATE tb_wa_antrean SET percobaan=?,galat=?,dijadwalkan_pada=DATE_ADD(NOW(), INTERVAL 10*? MINUTE) WHERE id=?');
            if ($attempts >= WA_QUEUE_MAX_ATTEMPTS) {
                mysqli_stmt_bind_param($retry, 'isi', $attempts, $error, $id);
            } else {
                mysqli_stmt_bind_param($retry, 'isii', $attempts, $error, $attempts, $id);
            }
            mysqli_stmt_execute($retry);
            mysqli_stmt_close($retry);
            $state['fails'] = (int) $state['fails'] + 1;
            $state['last_error'] = $error;
            // Wablas terputus atau diblokir: hentikan sementara agar tidak terus mencoba.
            if ($state['fails'] >= WA_QUEUE_FAIL_PAUSE) {
                $state['paused_until'] = $now + WA_QUEUE_PAUSE_SECONDS;
                $state['fails'] = 0;
                error_log('Antrean WhatsApp dijeda '.(WA_QUEUE_PAUSE_SECONDS / 60).' menit: '.$error);
            }
        }
        wa_queue_save_state($conn, $state);
        return $result;
    } finally {
        mysqli_query($conn, "SELECT RELEASE_LOCK('ppdb_wa_antrean')");
    }
}

/**
 * Jalankan antrean di akhir permintaan halaman. Respons dikirim lebih dulu bila
 * waktunya mengirim, sehingga pengunjung tidak menunggu.
 */
function wa_queue_run_after_response(mysqli $conn): void
{
    try {
        $state = wa_queue_state($conn);
        $now = time();
        if (!empty($state['paused']) || (int) $state['paused_until'] > $now || (int) $state['next_at'] > $now) {
            return;
        }
        ignore_user_abort(true);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        wa_queue_tick($conn);
    } catch (Throwable $exception) {
        error_log('Antrean WhatsApp gagal dijalankan: '.$exception->getMessage());
    }
}
