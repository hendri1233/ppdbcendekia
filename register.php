<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
start_app_session();

$adminCountResult = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM tbadmin');
$adminCount = (int) mysqli_fetch_assoc($adminCountResult)['total'];
$isBootstrap = $adminCount === 0;
if (!$isBootstrap && empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi formulir kedaluwarsa. Muat ulang halaman dan coba kembali.';
    } elseif (strlen($username) < 2 || strlen($username) > 80 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Periksa kembali nama dan alamat email.';
    } elseif (strlen($password) < 12) {
        $error = 'Kata sandi harus terdiri dari minimal 12 karakter.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Konfirmasi kata sandi tidak cocok.';
    } else {
        $countResult = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM tbadmin');
        $isStillBootstrap = (int) mysqli_fetch_assoc($countResult)['total'] === 0;
        if (!$isStillBootstrap && empty($_SESSION['admin_id'])) {
            $error = 'Pembuatan admin pertama sudah dilakukan. Silakan masuk.';
        } else {
            $check = mysqli_prepare($conn, 'SELECT id FROM tbadmin WHERE email = ? LIMIT 1');
            mysqli_stmt_bind_param($check, 's', $email);
            mysqli_stmt_execute($check);
            $exists = mysqli_stmt_get_result($check)->num_rows > 0;
            mysqli_stmt_close($check);

            if ($exists) {
                $error = 'Email tersebut sudah terdaftar.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $insert = mysqli_prepare($conn, 'INSERT INTO tbadmin (username, email, password) VALUES (?, ?, ?)');
                mysqli_stmt_bind_param($insert, 'sss', $username, $email, $passwordHash);
                if (mysqli_stmt_execute($insert)) {
                    if ($isStillBootstrap) {
                        header('Location: login.php?created=1');
                    } else {
                        header('Location: admin.php?admin_created=1');
                    }
                    exit;
                }
                $error = 'Akun admin belum dapat dibuat. Coba kembali.';
                mysqli_stmt_close($insert);
            }
        }
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
    <meta name="description" content="Buat akun pengelola PPDB Yayasan Wakaf Cendekia Takengon.">
    <title><?php echo $isBootstrap ? 'Penyiapan Awal' : 'Admin Baru'; ?> · Panel PPDB Cendekia Takengon</title>
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
            <p class="eyebrow"><?php echo $isBootstrap ? 'PENYIAPAN AWAL' : 'AREA PENGELOLA'; ?></p>
            <h1>Buat akun admin.</h1>
            <p class="auth-copy"><?php echo $isBootstrap ? 'Buat akun pengelola pertama untuk memulai.' : 'Tambahkan akun pengelola PPDB.'; ?></p>
            <?php if ($error !== '') { ?><div class="auth-alert" role="alert"><?php echo h($error); ?></div><?php } ?>
            <form method="post" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <label for="username">Nama pengelola</label>
                <input id="username" type="text" name="username" value="<?php echo h($username); ?>" autocomplete="name" maxlength="80" required>
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="<?php echo h($email); ?>" autocomplete="email" required>
                <label for="password">Kata sandi <span>Minimal 12 karakter</span></label>
                <input id="password" type="password" name="password" autocomplete="new-password" minlength="12" required data-password-meter>
                <div class="password-meter" data-meter hidden>
                    <div class="password-meter__track"><span class="password-meter__bar" data-meter-bar></span></div>
                    <small data-meter-label>Masukkan kata sandi</small>
                </div>
                <label for="confirm_password">Ulangi kata sandi</label>
                <input id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" minlength="12" required>
                <p class="auth-hint" data-match-hint hidden>Kedua kata sandi harus sama.</p>
                <button type="submit">Buat akun <span aria-hidden="true">&rarr;</span></button>
            </form>
            <a class="auth-back" href="<?php echo $isBootstrap ? 'login.php' : 'admin.php'; ?>">Kembali</a>
        </section>
        <footer>Yayasan Wakaf Cendekia Takengon</footer>
    </main>
    <script src="js/auth.js"></script>
</body>
</html>