<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
start_app_session();

if (!empty($_SESSION['admin_id'])) {
    header('Location: admin.php');
    exit;
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
    } else {
        $password = $_POST['password'] ?? '';
        $stmt = mysqli_prepare($conn, 'SELECT a.id,a.username,a.password,a.role,a.kode_unit,u.nama_unit FROM tbadmin a LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=a.kode_unit WHERE a.email=? AND a.role=? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'ss', $email, $role);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $admin = mysqli_fetch_assoc($result);
        $authenticated = false;

        if ($admin && ($admin['role'] === 'super_admin' || $admin['kode_unit'] !== null) && password_verify($password, $admin['password'])) {
            $authenticated = true;
        } elseif ($admin && ($admin['role'] === 'super_admin' || $admin['kode_unit'] !== null) && hash_equals($admin['password'], md5($password))) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $update = mysqli_prepare($conn, 'UPDATE tbadmin SET password = ? WHERE id = ?');
            mysqli_stmt_bind_param($update, 'si', $newHash, $admin['id']);
            mysqli_stmt_execute($update);
            mysqli_stmt_close($update);
            $authenticated = true;
        }

        if ($authenticated) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_name'] = $admin['username'];
            $_SESSION['admin_role'] = $admin['role'];
            $_SESSION['admin_unit_code'] = $admin['kode_unit'];
            $_SESSION['admin_unit_name'] = $admin['nama_unit'];
            header('Location: admin.php');
            exit;
        }

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
    <meta name="theme-color" content="#0f3a2e">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Area pengelola PPDB Yayasan Wakaf Cendekia Takengon.">
    <title>Masuk Admin · Panel PPDB Cendekia Takengon</title>
    <link rel="stylesheet" href="css/stlogin.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <main class="auth-shell">
        <a class="auth-brand" href="index.php">
            <img class="brand-logo" src="img/logo-display.png" alt="" width="48" height="47">
            <span><strong>Wakaf Cendekia</strong><small>TAKENGON · PPDB</small></span>
        </a>
        <section class="auth-panel">
            <p class="eyebrow">AREA PENGELOLA</p>
            <h1>Selamat datang kembali.</h1>
            <p class="auth-copy">Pilih akses sesuai peran Anda untuk mengelola pendaftaran peserta didik.</p>
            <?php if ($error !== '') { ?><div class="auth-alert" role="alert"><?php echo h($error); ?></div><?php } ?>
            <form method="post" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <label for="role">Masuk sebagai</label>
                <select id="role" name="role">
                    <option value="super_admin"<?php echo $role === 'super_admin' ? ' selected' : ''; ?>>Super Admin · semua unit</option>
                    <option value="admin_unit"<?php echo $role === 'admin_unit' ? ' selected' : ''; ?>>Admin Unit · unit pendidikan</option>
                </select>
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="<?php echo h($email); ?>" autocomplete="username" required>
                <label for="password">Kata sandi</label>
                <input id="password" type="password" name="password" autocomplete="current-password" required>
                <button type="submit">Masuk ke dashboard <span aria-hidden="true">&rarr;</span></button>
            </form>
            <p class="auth-note">Admin unit menggunakan email dan kata sandi sementara yang diberikan super admin. Login Google akan tersedia pada tahap berikutnya.</p>
            <a class="auth-back" href="index.php">Kembali ke situs PPDB</a>
        </section>
        <footer>Yayasan Wakaf Cendekia Takengon</footer>
    </main>
    <script src="js/auth.js"></script>
</body>
</html>