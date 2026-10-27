<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
start_app_session();

if (!empty($_SESSION['admin_id'])) {
    header('Location: admin.php');
    exit;
}

// Jumlah percobaan gagal dari IP ini dalam jendela 15 menit yang masih berjalan.
function login_failed_attempts(mysqli $conn, string $ipHash): int
{
    try {
        $stmt = mysqli_prepare($conn, 'SELECT attempts FROM tb_login_attempts WHERE ip_hash=? AND window_started_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
        mysqli_stmt_bind_param($stmt, 's', $ipHash);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        return (int) ($row['attempts'] ?? 0);
    } catch (Throwable $exception) {
        error_log('Admin login rate check failed: '.$exception->getMessage());

        return 0;
    }
}

// Berhasil masuk menghapus catatan; gagal menambah hitungan atau memulai jendela baru.
function login_record_attempt(mysqli $conn, string $ipHash, bool $success): void
{
    try {
        if ($success) {
            $stmt = mysqli_prepare($conn, 'DELETE FROM tb_login_attempts WHERE ip_hash=?');
        } else {
            $stmt = mysqli_prepare($conn, 'INSERT INTO tb_login_attempts (ip_hash,window_started_at,attempts) VALUES (?,NOW(),1)
                ON DUPLICATE KEY UPDATE
                    attempts=IF(window_started_at <= DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, LEAST(attempts+1, 255)),
                    window_started_at=IF(window_started_at <= DATE_SUB(NOW(), INTERVAL 15 MINUTE), NOW(), window_started_at)');
        }
        mysqli_stmt_bind_param($stmt, 's', $ipHash);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } catch (Throwable $exception) {
        error_log('Admin login rate update failed: '.$exception->getMessage());
    }
}

$error = '';
$email = trim($_POST['email'] ?? '');
$role = $_POST['role'] ?? 'super_admin';
if (!in_array($role, ['super_admin', 'admin_unit'], true)) {
    $role = 'super_admin';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi formulir kedaluwarsa. Muat ulang halaman dan coba kembali.';
    } elseif (login_failed_attempts($conn, $ipHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''))) >= 10) {
        $error = 'Terlalu banyak percobaan masuk. Silakan coba kembali setelah 15 menit.';
    } else {
        $password = $_POST['password'] ?? '';
        $stmt = mysqli_prepare($conn, 'SELECT a.id,a.username,a.password,a.role,a.kode_unit,u.nama_unit FROM tbadmin a LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=a.kode_unit WHERE a.email=? AND a.role=? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'ss', $email, $role);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $admin = mysqli_fetch_assoc($result);
        $authenticated = $admin
            && ($admin['role'] === 'super_admin' || $admin['kode_unit'] !== null)
            && password_verify($password, $admin['password']);

        if ($authenticated) {
            login_record_attempt($conn, $ipHash, true);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_name'] = $admin['username'];
            $_SESSION['admin_role'] = $admin['role'];
            $_SESSION['admin_unit_code'] = $admin['kode_unit'];
            $_SESSION['admin_unit_name'] = $admin['nama_unit'];
            header('Location: admin.php');
            exit;
        }

        login_record_attempt($conn, $ipHash, false);
        $error = 'Email atau kata sandi tidak cocok.';
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Area pengelola PPDB Yayasan Wakaf Cendekia Takengon.">
    <title>Masuk Admin · Panel PPDB Cendekia Takengon</title>
    <link rel="stylesheet" href="css/stlogin.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300;0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/tokens.css">
    <meta name="theme-color" content="#ffffff">
    <link rel="icon" type="image/x-icon" href="img/favicons/favicon.ico">
    <link rel="icon" type="image/png" sizes="16x16" href="img/favicons/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicons/favicon-32x32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="img/favicons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="img/favicons/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="img/favicons/android-chrome-512x512.png">
</head>
<body class="auth-page--glass">
    <div class="auth-scene" aria-hidden="true">
        <img class="auth-scene__photo" src="img/login-bg.webp" alt="" width="1600" height="900" fetchpriority="high">
        <span class="auth-scene__glow auth-scene__glow--gold"></span>
        <span class="auth-scene__glow auth-scene__glow--green"></span>
    </div>
    <main class="auth-glass">
        <a class="auth-glass__brand" href="index.php">
            <img src="img/logo-display.png" alt="" width="64" height="63">
            <span><strong>Yayasan Wakaf Cendekia</strong><small>Takengon, Aceh Tengah</small></span>
        </a>
        <section class="auth-panel auth-panel--glass">
            <p class="eyebrow">Area pengelola</p>
            <h1>Selamat datang kembali.</h1>
            <p class="auth-copy">Pilih akses sesuai peran Anda untuk mengelola pendaftaran peserta didik.</p>
            <?php if ($error !== '') { ?><div class="auth-alert" role="alert"><?php echo h($error); ?></div><?php } ?>
            <form method="post" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <label for="role">Masuk sebagai</label>
                <span class="auth-field">
                    <svg class="auth-field__icon" viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="6.5" r="3"/><path d="M4 16.5a6 6 0 0 1 12 0"/></svg>
                    <select id="role" name="role">
                        <option value="super_admin"<?php echo $role === 'super_admin' ? ' selected' : ''; ?>>Super Admin · semua unit</option>
                        <option value="admin_unit"<?php echo $role === 'admin_unit' ? ' selected' : ''; ?>>Admin Unit · unit pendidikan</option>
                    </select>
                </span>
                <label for="email">Email</label>
                <span class="auth-field">
                    <svg class="auth-field__icon" viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="4.5" width="15" height="11" rx="2"/><path d="m3 5.5 7 5.5 7-5.5"/></svg>
                    <input id="email" type="email" name="email" value="<?php echo h($email); ?>" autocomplete="username" placeholder="nama@cendekia.sch.id" required>
                </span>
                <label for="password">Kata sandi</label>
                <span class="auth-password auth-field">
                    <svg class="auth-field__icon" viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="9" width="12" height="8.5" rx="2"/><path d="M7 9V6.5a3 3 0 0 1 6 0V9"/></svg>
                    <input id="password" type="password" name="password" autocomplete="current-password" required>
                    <button class="auth-password__toggle" type="button" data-password-toggle aria-controls="password" aria-pressed="false" aria-label="Tampilkan kata sandi">
                        <svg class="auth-eye auth-eye--show" viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1.8 10S5 4.5 10 4.5 18.2 10 18.2 10 15 15.5 10 15.5 1.8 10 1.8 10Z"/><circle cx="10" cy="10" r="2.6"/></svg>
                        <svg class="auth-eye auth-eye--hide" viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l14 14"/><path d="M8.2 5a8 8 0 0 1 1.8-.2c5 0 8.2 5.2 8.2 5.2a14 14 0 0 1-2.4 2.9M12.1 15.1A7.6 7.6 0 0 1 10 15.5c-5 0-8.2-5.5-8.2-5.5a14.4 14.4 0 0 1 3.5-3.9"/><path d="M8.2 8.3a2.6 2.6 0 0 0 3.5 3.5"/></svg>
                    </button>
                </span>
                <button type="submit">Masuk ke dashboard <span aria-hidden="true">&rarr;</span></button>
            </form>
            <p class="auth-note"><strong>Lupa kata sandi?</strong> <span class="auth-note__detail">Hubungi super admin untuk mendapatkan kata sandi sementara, lalu ganti lewat menu <em>Ubah kata sandi</em> setelah masuk.</span><span class="auth-note__short">Hubungi super admin untuk kata sandi sementara.</span></p>
            <a class="auth-back" href="index.php">Kembali ke situs PPDB</a>
        </section>
    </main>
    <footer class="auth-glass__foot">&copy; <?php echo date('Y'); ?> Yayasan Wakaf Cendekia Takengon</footer>
    <script src="js/auth.js"></script>
</body>
</html>