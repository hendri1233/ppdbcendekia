<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
start_app_session();

const LANJUT_MAX_PER_IP = 20;
const LANJUT_MAX_PER_CODE = 8;

// Percobaan gagal dalam jendela 15 menit, memakai tabel yang sama dengan login admin.
function lanjut_failed_attempts(mysqli $conn, string $key): int
{
    try {
        $keyHash = hash('sha256', $key);
        $stmt = mysqli_prepare($conn, 'SELECT attempts FROM tb_login_attempts WHERE ip_hash=? AND window_started_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
        mysqli_stmt_bind_param($stmt, 's', $keyHash);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        return (int) ($row['attempts'] ?? 0);
    } catch (Throwable $exception) {
        error_log('Registration lookup rate check failed: '.$exception->getMessage());

        return 0;
    }
}

function lanjut_record_failure(mysqli $conn, string $key): void
{
    try {
        $keyHash = hash('sha256', $key);
        $stmt = mysqli_prepare($conn, 'INSERT INTO tb_login_attempts (ip_hash,window_started_at,attempts) VALUES (?,NOW(),1)
            ON DUPLICATE KEY UPDATE
                attempts=IF(window_started_at <= DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, LEAST(attempts+1, 255)),
                window_started_at=IF(window_started_at <= DATE_SUB(NOW(), INTERVAL 15 MINUTE), NOW(), window_started_at)');
        mysqli_stmt_bind_param($stmt, 's', $keyHash);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } catch (Throwable $exception) {
        error_log('Registration lookup rate update failed: '.$exception->getMessage());
    }
}

$code = strtoupper(trim((string) ($_POST['kode'] ?? $_GET['kode'] ?? '')));
$birthDate = trim((string) ($_POST['tanggal_lahir'] ?? ''));
$phone = trim((string) ($_POST['no_hp'] ?? ''));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ipKey = 'lanjut-ip|'.($_SERVER['REMOTE_ADDR'] ?? '');
    $codeKey = 'lanjut-kode|'.$code;
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi halaman berakhir. Muat ulang halaman lalu coba kembali.';
    } elseif (lanjut_failed_attempts($conn, $ipKey) >= LANJUT_MAX_PER_IP || lanjut_failed_attempts($conn, $codeKey) >= LANJUT_MAX_PER_CODE) {
        $error = 'Terlalu banyak percobaan. Silakan coba lagi 15 menit lagi atau hubungi panitia.';
    } else {
        $registration = null;
        if (preg_match('/^P[0-9]{9}$/', $code)) {
            $lookup = mysqli_prepare($conn, "SELECT id_pendaftaran,tgl_lahir,no_hp,no_hp_pendaftar,no_hp_ayah,no_hp_ibu FROM tb_pendaftaran WHERE id_pendaftaran=? AND status_ppdb<>'data_lama' LIMIT 1");
            mysqli_stmt_bind_param($lookup, 's', $code);
            mysqli_stmt_execute($lookup);
            $registration = mysqli_fetch_assoc(mysqli_stmt_get_result($lookup));
            mysqli_stmt_close($lookup);
        }
        // Pesan dibuat sama agar tidak membocorkan kode mana yang terdaftar.
        $number = ppdb_normalize_whatsapp_number($phone);
        if (!$registration
            || $registration['tgl_lahir'] !== $birthDate
            || $number === ''
            || !isset(ppdb_registrant_recipients($registration)[$number])) {
            lanjut_record_failure($conn, $ipKey);
            lanjut_record_failure($conn, $codeKey);
            $error = 'Data tidak cocok. Periksa kembali kode pendaftaran, tanggal lahir ananda, dan nomor WhatsApp.';
        } else {
            $token = ppdb_resume_token($conn, $code);
            header('Location: formulir.php?'.http_build_query(['kode' => $code, 'token' => $token]));
            exit;
        }
    }
}

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Lanjutkan pendaftaran | SPMB Cendekia</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300;0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/tokens.css?v=<?php echo (int) @filemtime(__DIR__.'/css/tokens.css'); ?>">
    <link rel="stylesheet" href="css/daftar.css?v=<?php echo (int) filemtime(__DIR__.'/css/daftar.css'); ?>">
    <link rel="stylesheet" href="css/alur.css?v=<?php echo (int) @filemtime(__DIR__.'/css/alur.css'); ?>">
    <meta name="theme-color" content="#ffffff">
    <link rel="icon" type="image/x-icon" href="img/favicons/favicon.ico" sizes="16x16 32x32 48x48">
</head>
<body class="page-flow">
<div class="page-shell">
    <header class="site-header">
        <a class="brand" href="./" aria-label="Yayasan Wakaf Cendekia Takengon, beranda">
            <img class="brand-logo" src="img/logo-display.png" alt="" width="46" height="45">
            <span class="brand-copy"><strong>Wakaf Cendekia</strong><small>Takengon · SPMB online</small></span>
        </a>
        <a class="back-link" href="daftar.php">Daftar baru</a>
    </header>
    <main>
        <section class="gate-panel">
            <p class="eyebrow">Sudah pernah mendaftar</p>
            <h1>Lanjutkan<br><span>pendaftaran Anda.</span></h1>
            <p class="intro-copy">Masukkan data berikut untuk melanjutkan pembayaran, mengisi formulir, atau melihat hasil pendaftaran.</p>

            <?php if ($error !== '') { ?>
                <div class="error-summary" role="alert"><strong>Belum dapat dilanjutkan</strong><ul><li><?php echo h($error); ?></li></ul></div>
            <?php } ?>

            <form class="gate-form" action="lanjutkan-pendaftaran.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <div class="field field-wide">
                    <label for="kode">Kode pendaftaran <span class="required-mark" aria-hidden="true">*</span></label>
                    <input id="kode" name="kode" value="<?php echo h($code); ?>" placeholder="Contoh: P202600123" maxlength="10" pattern="[Pp][0-9]{9}" required>
                    <small class="field-hint">Tertera pada halaman setelah registrasi awal dan pada bukti pendaftaran PDF.</small>
                </div>
                <div class="field field-wide">
                    <label for="tanggal_lahir">Tanggal lahir ananda <span class="required-mark" aria-hidden="true">*</span></label>
                    <input id="tanggal_lahir" name="tanggal_lahir" type="date" value="<?php echo h($birthDate); ?>" required>
                </div>
                <div class="field field-wide">
                    <label for="no_hp">Nomor WhatsApp yang didaftarkan <span class="required-mark" aria-hidden="true">*</span></label>
                    <input id="no_hp" name="no_hp" type="tel" inputmode="tel" maxlength="20" placeholder="Contoh: 081234567890" value="<?php echo h($phone); ?>" required>
                    <small class="field-hint">Nomor pendaftar, ayah, atau ibu yang diisi saat registrasi awal.</small>
                </div>
                <button type="submit" class="submit-button">Lanjutkan pendaftaran</button>
            </form>
        </section>
    </main>
    <footer class="site-footer">
        <span>Yayasan Wakaf Cendekia Takengon</span>
        <a class="admin-wa-link" href="<?php echo h(ppdb_admin_whatsapp_url('Assalamu’alaikum, saya memerlukan bantuan untuk melanjutkan pendaftaran SPMB.')); ?>" target="_blank" rel="noopener noreferrer">Bantuan admin <?php echo h(ppdb_admin_whatsapp_display()); ?></a>
    </footer>
</div>
<script src="js/alur.js?v=<?php echo (int) @filemtime(__DIR__.'/js/alur.js'); ?>"></script>
</body>
</html>
