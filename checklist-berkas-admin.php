<?php
/**
 * Pantauan berkas yang dikumpulkan unit ke yayasan, beserta tautan centang per unit.
 */
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_super_admin();
require_once __DIR__.'/admin-partials.php';
require_once __DIR__.'/cms_helpers.php';
require_once __DIR__.'/checklist_berkas_helpers.php';

$units = cms_units($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = is_string($_POST['kode_unit'] ?? null) ? $_POST['kode_unit'] : '';
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $_SESSION['checklist_flash'] = ['error', 'Sesi formulir kedaluwarsa. Muat ulang halaman dan coba kembali.'];
    } elseif (!in_array($code, array_column($units, 'kode_unit'), true)) {
        $_SESSION['checklist_flash'] = ['error', 'Unit yang dipilih tidak valid.'];
    } else {
        checklist_reset($conn, $code);
        $_SESSION['checklist_flash'] = ['success', 'Centang unit dikosongkan. Tautan unit tetap sama.'];
    }
    header('Location: checklist-berkas-admin.php');
    exit;
}

$flash = $_SESSION['checklist_flash'] ?? null;
unset($_SESSION['checklist_flash']);
$requiredTotal = count(checklist_required_items());

$activeAdminPage = 'file-checklist';
$adminPageTitle = 'Berkas dari unit';
$adminPageDescription = 'Tautan centang berkas per unit dan berkas yang belum diserahkan.';
require 'admin_header.php';
?>
<div class="admin-content cms-page">
    <?php if ($flash) { ?><div class="<?php echo $flash[0] === 'error' ? 'alert-error' : 'alert-success'; ?>" role="status"><?php echo h($flash[1]); ?></div><?php } ?>
    <header class="page-heading">
        <div>
            <p class="eyebrow">Konfigurasi per unit</p>
            <h1>Berkas dari unit</h1>
            <p>Kirim tautan setiap unit ke grup WhatsApp unit tersebut. Unit mencentang berkas yang sudah diserahkan, tanpa perlu masuk ke panel.</p>
        </div>
    </header>

    <?php foreach ($units as $unit) {
        $code = $unit['kode_unit'];
        $name = ppdb_unit_display_name($unit['nama_unit']);
        $checked = checklist_checked($conn, $code);
        $link = ppdb_public_base_url().'/checklist-berkas.php?t='.checklist_unit_token($conn, $code);
        $message = "Assalamu'alaikum. Mohon centang berkas ".$name.' yang sudah diserahkan ke yayasan melalui tautan ini: '.$link;
        $missing = array_diff_key(checklist_required_items(), $checked);
        $inputId = 'checklist-link-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $code);
    ?>
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2><?php echo h($name); ?></h2>
                    <span class="panel-subtitle"><?php echo checklist_required_done($checked); ?> dari <?php echo $requiredTotal; ?> berkas wajib<?php echo $checked ? ' · terakhir dicentang '.h(date('d/m/Y H.i', strtotime(max($checked)))) : ''; ?></span>
                </div>
            </div>
            <div class="cms-form">
                <div class="cms-field">
                    <label class="cms-label" for="<?php echo h($inputId); ?>">Tautan unit</label>
                    <input id="<?php echo h($inputId); ?>" type="text" value="<?php echo h($link); ?>" readonly>
                </div>
                <p><?php echo $missing ? '<strong>Belum diserahkan:</strong> '.h(implode(', ', array_column($missing, 0))) : '<strong>Semua berkas wajib sudah dicentang.</strong>'; ?></p>
                <div class="cms-actions">
                    <a class="button-primary" href="https://wa.me/?text=<?php echo rawurlencode($message); ?>" target="_blank" rel="noopener">Kirim lewat WhatsApp</a>
                    <a class="button-secondary" href="<?php echo h($link); ?>" target="_blank" rel="noopener">Buka halaman centang</a>
                    <form method="post" data-confirm="Kosongkan semua centang <?php echo h($name); ?>? Gunakan ini saat memulai tahun ajaran baru.">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="kode_unit" value="<?php echo h($code); ?>">
                        <button class="button-secondary" type="submit"<?php echo $checked ? '' : ' disabled'; ?>>Kosongkan centang</button>
                    </form>
                </div>
            </div>
        </section>
    <?php } ?>
</div>
<script src="js/admin.js?v=<?php echo (int) @filemtime(__DIR__.'/js/admin.js'); ?>"></script>
<?php require 'admin_footer.php'; ?>
