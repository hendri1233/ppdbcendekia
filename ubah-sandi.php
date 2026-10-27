<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_admin();

$activeAdminPage = 'password';
$adminPageTitle = 'Ubah kata sandi';
$adminPageDescription = 'Ganti kata sandi akun pengelola PPDB Anda.';
$error = '';
$success = !empty($_SESSION['password_changed']);
unset($_SESSION['password_changed']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi formulir kedaluwarsa. Muat ulang halaman dan coba kembali.';
    } elseif (strlen($newPassword) < 12) {
        $error = 'Kata sandi baru harus terdiri dari minimal 12 karakter.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Konfirmasi kata sandi baru tidak cocok.';
    } elseif (hash_equals($currentPassword, $newPassword)) {
        $error = 'Kata sandi baru harus berbeda dari kata sandi saat ini.';
    } else {
        $adminId = (int) $_SESSION['admin_id'];
        $stmt = mysqli_prepare($conn, 'SELECT password FROM tbadmin WHERE id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $adminId);
        mysqli_stmt_execute($stmt);
        $account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$account || !password_verify($currentPassword, $account['password'])) {
            $error = 'Kata sandi saat ini tidak cocok.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = mysqli_prepare($conn, 'UPDATE tbadmin SET password = ? WHERE id = ?');
            mysqli_stmt_bind_param($update, 'si', $newHash, $adminId);
            if (mysqli_stmt_execute($update)) {
                session_regenerate_id(true);
                $_SESSION['password_changed'] = true;
                header('Location: ubah-sandi.php');
                exit;
            }
            $error = 'Kata sandi belum dapat disimpan. Coba kembali.';
            mysqli_stmt_close($update);
        }
    }
}

require 'admin_header.php';
?>
<div class="admin-content">
    <header class="page-heading">
        <div>
            <p class="eyebrow">Keamanan akun</p>
            <h1>Ubah kata sandi</h1>
            <p>Ganti kata sandi sementara dari super admin dengan kata sandi pribadi yang hanya Anda ketahui.</p>
        </div>
    </header>

    <?php if ($success) { ?><div class="alert-success" role="status">Kata sandi berhasil diperbarui. Gunakan kata sandi baru saat masuk berikutnya.</div><?php } ?>
    <?php if ($error !== '') { ?><div class="alert-error" role="alert"><?php echo h($error); ?></div><?php } ?>

    <section class="panel admin-account-panel">
        <div class="panel-header">
            <div><h2>Kata sandi baru</h2><span class="panel-subtitle">Minimal 12 karakter. Gabungkan huruf besar, huruf kecil, angka, dan simbol.</span></div>
        </div>
        <form method="post" class="admin-account-form" data-loading-form>
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
            <div class="admin-account-fields">
                <div class="filter-field">
                    <label for="current_password">Kata sandi saat ini</label>
                    <input id="current_password" type="password" name="current_password" autocomplete="current-password" required>
                </div>
            </div>
            <div class="admin-account-fields">
                <div class="filter-field">
                    <label for="password">Kata sandi baru <span>Minimal 12 karakter</span></label>
                    <input id="password" type="password" name="password" autocomplete="new-password" minlength="12" required data-password-meter>
                    <div class="password-meter" data-meter hidden>
                        <div class="password-meter__track"><span class="password-meter__bar" data-meter-bar></span></div>
                        <small data-meter-label>Masukkan kata sandi</small>
                    </div>
                </div>
                <div class="filter-field">
                    <label for="confirm_password">Ulangi kata sandi baru</label>
                    <input id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" minlength="12" required>
                    <p class="auth-hint" data-match-hint hidden>Kedua kata sandi harus sama.</p>
                </div>
            </div>
            <div class="admin-account-actions">
                <a class="button-secondary" href="admin.php">Batal</a>
                <button class="button-primary" type="submit">Simpan kata sandi</button>
            </div>
        </form>
    </section>
</div>
<?php require 'admin_footer.php'; ?>
<script src="js/auth.js?v=<?php echo (int) @filemtime(__DIR__.'/js/auth.js'); ?>"></script>
