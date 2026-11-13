<?php
/*
 * Isian data jalur prestasi oleh wali murid setelah panitia memindahkan peserta SMP
 * ke jalur prestasi. Dibuka lewat tautan WhatsApp: data-prestasi?kode={kode}&sig={tanda}.
 */
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'google_sheets_sync.php';
require_once 'prestasi_helpers.php';
start_app_session();

$code = strtoupper(trim((string) ($_GET['kode'] ?? $_POST['kode'] ?? '')));
$signature = trim((string) ($_GET['sig'] ?? $_POST['sig'] ?? ''));
$registration = null;
if (prestasi_is_ready($conn) && preg_match('/^P[0-9]{9}$/', $code) && preg_match('/^[a-f0-9]{16}$/', $signature)) {
    $stmt = mysqli_prepare($conn, 'SELECT p.id_pendaftaran,p.nm_peserta,p.status_ppdb,p.jalur_seleksi,p.token_bukti,u.nama_unit,u.jenjang FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit WHERE p.id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $code);
    mysqli_stmt_execute($stmt);
    $registration = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    if ($registration && !hash_equals(prestasi_wali_signature($registration), $signature)) {
        $registration = null;
    }
}

// Tahap halaman: tautan tidak berlaku, isian, atau sudah terkirim.
$stage = 'invalid';
if ($registration && ($registration['jenjang'] ?? '') === 'SMP' && $registration['jalur_seleksi'] === 'prestasi' && !in_array($registration['status_ppdb'], ['data_lama', 'ditolak'], true)) {
    $stage = prestasi_has_data($conn, $code) ? 'done' : 'form';
}
$errors = [];
$justSubmitted = false;

if ($stage === 'form' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Sesi halaman berakhir. Muat ulang halaman lalu kirim kembali.';
    } else {
        [$achievementData, $errors] = prestasi_validate($_POST, $_FILES);
    }
    if (!$errors) {
        $storedLetterPath = '';
        $oldLetterPath = '';
        try {
            mysqli_begin_transaction($conn);
            $lock = mysqli_prepare($conn, 'SELECT jalur_seleksi FROM tb_pendaftaran WHERE id_pendaftaran=? FOR UPDATE');
            mysqli_stmt_bind_param($lock, 's', $code);
            mysqli_stmt_execute($lock);
            $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);
            if (($locked['jalur_seleksi'] ?? '') !== 'prestasi') {
                throw new RuntimeException('Peserta tidak lagi berada di jalur prestasi. Hubungi panitia.');
            }
            if (prestasi_has_data($conn, $code)) {
                throw new RuntimeException('Data prestasi Ananda sudah pernah dikirim.');
            }
            $oldLetter = mysqli_prepare($conn, "SELECT path_file FROM tb_dokumen_peserta WHERE id_pendaftaran=? AND jenis_dokumen='surat_prestasi' LIMIT 1");
            mysqli_stmt_bind_param($oldLetter, 's', $code);
            mysqli_stmt_execute($oldLetter);
            $oldLetterPath = (string) (mysqli_fetch_assoc(mysqli_stmt_get_result($oldLetter))['path_file'] ?? '');
            mysqli_stmt_close($oldLetter);
            $cleanup = mysqli_prepare($conn, "DELETE FROM tb_dokumen_peserta WHERE id_pendaftaran=? AND jenis_dokumen='surat_prestasi'");
            mysqli_stmt_bind_param($cleanup, 's', $code);
            mysqli_stmt_execute($cleanup);
            mysqli_stmt_close($cleanup);
            prestasi_save($conn, $code, $achievementData);
            $storedLetterPath = prestasi_store_letter($conn, $code, $_FILES['surat_prestasi']);
            mysqli_commit($conn);
            if ($oldLetterPath !== '' && $oldLetterPath !== $storedLetterPath && is_file($oldLetterPath)) {
                @unlink($oldLetterPath);
            }
            enqueue_google_sheets_registration($conn, $code);
            $stage = 'done';
            $justSubmitted = true;
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            if ($storedLetterPath !== '' && is_file($storedLetterPath)) {
                @unlink($storedLetterPath);
            }
            if (!$exception instanceof RuntimeException) {
                error_log('Isian prestasi wali murid gagal: '.$exception->getMessage());
            }
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Data prestasi belum dapat disimpan. Silakan coba lagi.';
        }
    }
}

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
$formValues = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Data jalur prestasi | SPMB Cendekia</title>
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
    </header>
    <main>
        <section class="gate-panel">
            <?php if ($stage === 'invalid') { ?>
                <p class="eyebrow">Jalur prestasi</p>
                <h1>Tautan tidak<br><span>berlaku.</span></h1>
                <p class="intro-copy">Tautan isian data prestasi tidak dikenali atau peserta tidak lagi berada di jalur prestasi. Silakan hubungi panitia SPMB.</p>
            <?php } elseif ($stage === 'done') { ?>
                <p class="eyebrow">Jalur prestasi · <?php echo h($registration['id_pendaftaran']); ?></p>
                <h1><?php echo $justSubmitted ? 'Terima kasih,<br><span>data terkirim.</span>' : 'Data prestasi<br><span>sudah diterima.</span>'; ?></h1>
                <p class="intro-copy">Data prestasi Ananda <strong><?php echo h($registration['nm_peserta']); ?></strong> sudah diterima panitia dan akan diperiksa. Bila ada yang perlu diperbaiki, panitia akan menghubungi Ayah/Bunda.</p>
            <?php } else { ?>
                <p class="eyebrow">Jalur prestasi · <?php echo h($registration['id_pendaftaran']); ?></p>
                <h1>Lengkapi data<br><span>prestasi Ananda.</span></h1>
                <p class="intro-copy">Ananda <strong><?php echo h($registration['nm_peserta']); ?></strong> telah dipindahkan panitia ke jalur prestasi <?php echo h(ppdb_unit_display_name($registration['nama_unit'] ?? 'SMP')); ?>. Mohon lengkapi isian berikut, lalu kirim.</p>

                <?php if ($errors) { ?>
                    <div class="error-summary" role="alert"><strong>Data belum dapat dikirim</strong><ul><?php foreach ($errors as $message) { ?><li><?php echo h($message); ?></li><?php } ?></ul></div>
                <?php } ?>

                <form class="gate-form prestasi-wali-form" action="data-prestasi" method="post" enctype="multipart/form-data" data-prestasi-form>
                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="kode" value="<?php echo h($code); ?>">
                    <input type="hidden" name="sig" value="<?php echo h($signature); ?>">
                    <?php prestasi_render_section($formValues, true); ?>
                    <button type="submit" class="submit-button">Kirim data prestasi</button>
                </form>
            <?php } ?>
        </section>
    </main>
    <footer class="site-footer">
        <span>Yayasan Wakaf Cendekia Takengon</span>
        <a class="admin-wa-link" href="<?php echo h(ppdb_admin_whatsapp_url('Assalamu’alaikum, saya memerlukan bantuan mengisi data jalur prestasi SPMB.')); ?>" target="_blank" rel="noopener noreferrer">Bantuan admin <?php echo h(ppdb_admin_whatsapp_display()); ?></a>
    </footer>
</div>
<script src="js/alur.js?v=<?php echo (int) @filemtime(__DIR__.'/js/alur.js'); ?>"></script>
<?php if ($stage === 'form') { ?><script src="js/prestasi-wali.js?v=<?php echo (int) @filemtime(__DIR__.'/js/prestasi-wali.js'); ?>"></script><?php } ?>
</body>
</html>
