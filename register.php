<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
start_app_session();

$adminCountResult = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM tbadmin');
$adminCount = (int) mysqli_fetch_assoc($adminCountResult)['total'];
$isBootstrap = $adminCount === 0;
$isSignedIn = !empty($_SESSION['admin_id']);
if (!$isBootstrap && !$isSignedIn) {
    header('Location: login.php');
    exit;
}
if (!$isBootstrap) {
    require_super_admin();
}

$error = '';
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$role = $_POST['role'] ?? 'super_admin';
if (!in_array($role, ['super_admin', 'admin_unit'], true)) {
    $role = 'super_admin';
}
$unitResult = mysqli_query($conn, "SELECT kode_unit,nama_unit FROM tb_unit_pendidikan ORDER BY FIELD(jenjang,'KB','TPA','TK','SD','SMP')");
$units = [];
while ($unit = mysqli_fetch_assoc($unitResult)) {
    $units[$unit['kode_unit']] = $unit['nama_unit'];
}
$showAdminLayout = !$isBootstrap && !empty($_SESSION['admin_id']);
$activeAdminPage = 'accounts';
$adminPageTitle = 'Administrator';
$adminPageDescription = 'Kelola akun administrator PPDB Yayasan Wakaf Cendekia Takengon.';

// Kata sandi sementara 12 karakter tanpa huruf yang mudah tertukar (0/O, 1/l/I).
function ppdb_temporary_password(): string
{
    $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789'];
    $all = implode('', $sets);
    $characters = [];
    foreach ($sets as $set) {
        $characters[] = $set[random_int(0, strlen($set) - 1)];
    }
    while (count($characters) < 12) {
        $characters[] = $all[random_int(0, strlen($all) - 1)];
    }
    for ($i = count($characters) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
    }

    return implode('', $characters);
}

// Super admin mereset kata sandi akun lain; hasilnya ditampilkan sekali setelah dialihkan.
if (!$isBootstrap && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    $targetId = (int) ($_POST['admin_id'] ?? 0);
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi formulir kedaluwarsa. Muat ulang halaman dan coba kembali.';
    } elseif ($targetId === (int) $_SESSION['admin_id']) {
        $error = 'Untuk akun Anda sendiri, gunakan menu Ubah kata sandi.';
    } else {
        $target = mysqli_prepare($conn, 'SELECT id,username,email FROM tbadmin WHERE id = ? LIMIT 1');
        mysqli_stmt_bind_param($target, 'i', $targetId);
        mysqli_stmt_execute($target);
        $targetAccount = mysqli_fetch_assoc(mysqli_stmt_get_result($target));
        mysqli_stmt_close($target);

        if (!$targetAccount) {
            $error = 'Akun yang dipilih tidak ditemukan.';
        } else {
            $temporaryPassword = ppdb_temporary_password();
            $temporaryHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
            $update = mysqli_prepare($conn, 'UPDATE tbadmin SET password = ? WHERE id = ?');
            mysqli_stmt_bind_param($update, 'si', $temporaryHash, $targetId);
            if (mysqli_stmt_execute($update)) {
                $_SESSION['account_reset'] = [
                    'name' => $targetAccount['username'],
                    'email' => $targetAccount['email'],
                    'password' => $temporaryPassword,
                ];
                header('Location: register.php#akun');
                exit;
            }
            $error = 'Kata sandi belum dapat direset. Coba kembali.';
            mysqli_stmt_close($update);
        }
    }
}

$accountReset = $_SESSION['account_reset'] ?? null;
unset($_SESSION['account_reset']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? 'create') === 'create') {
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
        if (!$isStillBootstrap && (empty($_SESSION['admin_id']) || !ppdb_is_super_admin())) {
            $error = 'Pembuatan admin pertama sudah dilakukan. Silakan masuk.';
        } elseif (!$isStillBootstrap && $role === 'admin_unit' && !isset($units[$_POST['kode_unit'] ?? ''])) {
            $error = 'Pilih unit pendidikan yang valid untuk akun admin unit.';
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
                $role = $isStillBootstrap ? 'super_admin' : $role;
                $unitCode = $role === 'admin_unit' ? (string) $_POST['kode_unit'] : null;
                $insert = mysqli_prepare($conn, 'INSERT INTO tbadmin (username,email,password,role,kode_unit) VALUES (?,?,?,?,?)');
                mysqli_stmt_bind_param($insert, 'sssss', $username, $email, $passwordHash, $role, $unitCode);
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
<?php if ($showAdminLayout) {
    $accountResult = mysqli_query($conn, 'SELECT a.id,a.username,a.email,a.role,u.nama_unit FROM tbadmin a LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=a.kode_unit ORDER BY a.role=\'admin_unit\', u.nama_unit, a.username');
    $accounts = mysqli_fetch_all($accountResult, MYSQLI_ASSOC);
    require 'admin_header.php';
?>
<div class="admin-content">
    <header class="page-heading">
        <div>
            <p class="eyebrow">Akses pengelola</p>
            <h1>Administrator</h1>
            <p>Buat akun pengelola baru atau reset kata sandi admin yang lupa.</p>
        </div>
    </header>

    <?php if ($error !== '') { ?><div class="alert-error" role="alert"><?php echo h($error); ?></div><?php } ?>

    <?php if ($accountReset) { ?>
        <section class="temp-password" role="status" aria-labelledby="temp-password-title">
            <h2 id="temp-password-title">Kata sandi sementara untuk <?php echo h($accountReset['name']); ?></h2>
            <p>Sampaikan kata sandi ini secara pribadi kepada <?php echo h($accountReset['email']); ?>. Kata sandi hanya ditampilkan sekali; minta pemilik akun segera menggantinya lewat menu <strong>Ubah kata sandi</strong>.</p>
            <div class="temp-password__field">
                <input id="temp-password-value" type="text" value="<?php echo h($accountReset['password']); ?>" readonly aria-label="Kata sandi sementara">
                <button class="button-secondary" type="button" data-copy-target="#temp-password-value">Salin</button>
            </div>
        </section>
    <?php } ?>

    <section class="panel account-list" id="akun">
        <div class="panel-header">
            <div><h2>Daftar akun</h2><span class="panel-subtitle"><?php echo count($accounts); ?> pengelola terdaftar. Reset menghasilkan kata sandi sementara yang baru.</span></div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th scope="col">Nama</th><th scope="col">Email</th><th scope="col">Peran</th><th scope="col"><span class="visually-hidden">Aksi</span></th></tr></thead>
                <tbody>
                <?php foreach ($accounts as $account) { ?>
                    <tr>
                        <td class="primary-cell" data-label="Nama"><?php echo h($account['username']); ?></td>
                        <td data-label="Email"><?php echo h($account['email']); ?></td>
                        <td data-label="Peran"><?php echo $account['role'] === 'super_admin' ? 'Super Admin' : 'Admin Unit &middot; '.h($account['nama_unit'] ?? '-'); ?></td>
                        <td data-label="Aksi">
                            <?php if ((int) $account['id'] === (int) $_SESSION['admin_id']) { ?>
                                <a class="account-self" href="ubah-sandi.php">Akun Anda &middot; ubah kata sandi</a>
                            <?php } else { ?>
                                <form method="post" onsubmit="return confirm('Reset kata sandi <?php echo h(addslashes($account['username'])); ?>? Kata sandi lama tidak berlaku lagi.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="admin_id" value="<?php echo (int) $account['id']; ?>">
                                    <button class="table-action" type="submit">Reset kata sandi</button>
                                </form>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel admin-account-panel">
        <div class="panel-header">
            <div><h2>Buat akun baru</h2><span class="panel-subtitle">Gunakan email unik dan kata sandi minimal 12 karakter.</span></div>
        </div>
        <form method="post" class="admin-account-form">
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="create">
            <div class="admin-account-fields">
                <div class="filter-field">
                    <label for="role">Peran akun</label>
                    <select id="role" name="role">
                        <option value="super_admin"<?php echo $role === 'super_admin' ? ' selected' : ''; ?>>Super Admin · semua unit</option>
                        <option value="admin_unit"<?php echo $role === 'admin_unit' ? ' selected' : ''; ?>>Admin Unit · satu unit</option>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="kode_unit">Unit pendidikan</label>
                    <select id="kode_unit" name="kode_unit">
                        <option value="">Pilih unit</option>
                        <?php foreach ($units as $code => $name) { ?>
                            <option value="<?php echo h($code); ?>"<?php echo ($_POST['kode_unit'] ?? '') === $code ? ' selected' : ''; ?>><?php echo h($name); ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
            <div class="admin-account-fields">
                <div class="filter-field">
                    <label for="username">Nama pengelola</label>
                    <input id="username" type="text" name="username" value="<?php echo h($username); ?>" autocomplete="name" maxlength="80" required>
                </div>
                <div class="filter-field">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" value="<?php echo h($email); ?>" autocomplete="email" required>
                </div>
                <div class="filter-field">
                    <label for="password">Kata sandi <span>Minimal 12 karakter</span></label>
                    <input id="password" type="password" name="password" autocomplete="new-password" minlength="12" required data-password-meter>
                    <div class="password-meter" data-meter hidden>
                        <div class="password-meter__track"><span class="password-meter__bar" data-meter-bar></span></div>
                        <small data-meter-label>Masukkan kata sandi</small>
                    </div>
                </div>
                <div class="filter-field">
                    <label for="confirm_password">Ulangi kata sandi</label>
                    <input id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" minlength="12" required>
                    <p class="auth-hint" data-match-hint hidden>Kedua kata sandi harus sama.</p>
                </div>
            </div>
            <div class="admin-account-actions">
                <a class="button-secondary" href="admin.php">Batal</a>
                <button class="button-primary" type="submit">Buat akun</button>
            </div>
        </form>
    </section>
</div>
<?php
    require 'admin_footer.php';
?>
<script src="js/auth.js"></script>
<script src="js/admin-share.js"></script>
<?php } else { ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Buat akun pengelola PPDB Yayasan Wakaf Cendekia Takengon.">
    <title><?php echo $isBootstrap ? 'Penyiapan Awal' : 'Admin Baru'; ?> · Panel PPDB Cendekia Takengon</title>
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
<body>
    <main class="auth-shell">
        <a class="auth-brand" href="index.php">
            <img class="brand-logo" src="img/logo-display.png" alt="" width="48" height="47">
            <span><strong>Wakaf Cendekia</strong><small>Takengon · PPDB</small></span>
        </a>
        <section class="auth-panel">
            <p class="eyebrow"><?php echo $isBootstrap ? 'PENYIAPAN AWAL' : 'Area pengelola'; ?></p>
            <h1>Buat akun admin.</h1>
            <p class="auth-copy"><?php echo $isBootstrap ? 'Buat akun pengelola pertama untuk memulai.' : 'Tambahkan akun pengelola PPDB.'; ?></p>
            <?php if ($error !== '') { ?><div class="auth-alert" role="alert"><?php echo h($error); ?></div><?php } ?>
            <form method="post" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <?php if (!$isBootstrap) { ?>
                <label for="role">Peran akun</label>
                <select id="role" name="role">
                    <option value="super_admin"<?php echo $role === 'super_admin' ? ' selected' : ''; ?>>Super Admin · semua unit</option>
                    <option value="admin_unit"<?php echo $role === 'admin_unit' ? ' selected' : ''; ?>>Admin Unit · satu unit</option>
                </select>
                <label for="kode_unit">Unit pendidikan</label>
                <select id="kode_unit" name="kode_unit">
                    <option value="">Pilih unit</option>
                    <?php foreach ($units as $code => $name) { ?>
                        <option value="<?php echo h($code); ?>"<?php echo ($_POST['kode_unit'] ?? '') === $code ? ' selected' : ''; ?>><?php echo h($name); ?></option>
                    <?php } ?>
                </select>
                <?php } ?>
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
<?php } ?>