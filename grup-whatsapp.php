<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_admin();
require_once __DIR__ . '/admin-partials.php';

$adminUnitCode = ppdb_is_super_admin() ? '' : (string) $_SESSION['admin_unit_code'];
$adminId = (int) $_SESSION['admin_id'];
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unitCode = is_string($_POST['kode_unit'] ?? null) ? trim($_POST['kode_unit']) : '';
    $groupLink = is_string($_POST['link_grup'] ?? null) ? trim($_POST['link_grup']) : '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi formulir kedaluwarsa. Muat ulang halaman dan coba kembali.';
    } elseif ($unitCode === '' || ($adminUnitCode !== '' && $unitCode !== $adminUnitCode)) {
        $error = 'Unit yang dipilih tidak valid atau Anda tidak memiliki akses.';
    } elseif (strlen($groupLink) > 500) {
        $error = 'Tautan grup maksimal 500 karakter.';
    } elseif ($groupLink !== '') {
        $linkParts = parse_url($groupLink);
        if (!is_array($linkParts)
            || strtolower((string) ($linkParts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($linkParts['host'] ?? '')) !== 'chat.whatsapp.com'
            || !preg_match('~^/[A-Za-z0-9_-]+/?$~D', (string) ($linkParts['path'] ?? ''))
            || isset($linkParts['user'])
            || isset($linkParts['pass'])) {
            $error = 'Gunakan tautan undangan WhatsApp grup yang valid, misalnya https://chat.whatsapp.com/kodeundangan.';
        }
    }

    if ($error === '') {
        $unitQuery = mysqli_prepare($conn, 'SELECT kode_unit,nama_unit FROM tb_unit_pendidikan WHERE kode_unit=? LIMIT 1');
        mysqli_stmt_bind_param($unitQuery, 's', $unitCode);
        mysqli_stmt_execute($unitQuery);
        $unit = mysqli_fetch_assoc(mysqli_stmt_get_result($unitQuery));
        mysqli_stmt_close($unitQuery);
        if (!$unit || ($adminUnitCode !== '' && $unit['kode_unit'] !== $adminUnitCode)) {
            $error = 'Unit tidak ditemukan atau Anda tidak memiliki akses.';
        } else {
            $save = mysqli_prepare($conn, 'INSERT INTO tb_unit_whatsapp_groups (kode_unit,link_grup,diperbarui_oleh) VALUES (?,?,?) ON DUPLICATE KEY UPDATE link_grup=VALUES(link_grup),diperbarui_oleh=VALUES(diperbarui_oleh)');
            mysqli_stmt_bind_param($save, 'ssi', $unitCode, $groupLink, $adminId);
            if (mysqli_stmt_execute($save)) {
                $notice = 'Tautan grup WhatsApp '.$unit['nama_unit'].' berhasil disimpan.';
            } else {
                error_log('PPDB unit WhatsApp group link save failed: '.mysqli_stmt_error($save));
                $error = 'Tautan grup belum dapat disimpan. Silakan coba kembali.';
            }
            mysqli_stmt_close($save);
        }
    }
}

if ($adminUnitCode === '') {
    $unitsResult = mysqli_query($conn, "SELECT u.kode_unit,u.nama_unit,u.jenjang,g.link_grup FROM tb_unit_pendidikan u LEFT JOIN tb_unit_whatsapp_groups g ON g.kode_unit=u.kode_unit ORDER BY FIELD(u.jenjang,'KB','TPA','TK','SD','SMP')");
} else {
    $unitsResult = mysqli_prepare($conn, 'SELECT u.kode_unit,u.nama_unit,u.jenjang,g.link_grup FROM tb_unit_pendidikan u LEFT JOIN tb_unit_whatsapp_groups g ON g.kode_unit=u.kode_unit WHERE u.kode_unit=?');
    mysqli_stmt_bind_param($unitsResult, 's', $adminUnitCode);
    mysqli_stmt_execute($unitsResult);
    $unitsResult = mysqli_stmt_get_result($unitsResult);
}

$activeAdminPage = 'groups';
$adminPageTitle = 'Grup WhatsApp';
$adminPageDescription = 'Kelola tautan grup WhatsApp unit yang disertakan saat peserta dinyatakan diterima.';
require 'admin_header.php';
?>
<div class="admin-content">
    <header class="page-heading">
        <div>
            <p class="eyebrow">Konfigurasi per unit</p>
            <h1>Grup WhatsApp</h1>
            <p>Atur tautan undangan grup setiap unit. Tautan ini dikirim kepada pendaftar saat peserta dinyatakan diterima.</p>
        </div>
    </header>

    <?php if ($error !== '') { ?><div class="alert-error" role="alert"><?php echo h($error); ?></div><?php } ?>
    <?php if ($notice !== '') { ?><div class="alert-success" role="status"><?php echo h($notice); ?></div><?php } ?>

    <section class="panel group-links-panel">
        <div class="panel-header">
            <div>
                <h2>Tautan undangan per unit</h2>
                <span class="panel-subtitle">Hanya tautan resmi undangan grup WhatsApp yang dapat disimpan.</span>
            </div>
        </div>
        <?php if (mysqli_num_rows($unitsResult) === 0) { ?>
            <div class="empty-state">
                <strong>Unit belum tersedia</strong>
                <p>Tambahkan unit pendidikan sebelum mengatur tautan grup.</p>
            </div>
        <?php } else { ?>
            <div class="group-links-list">
                <?php while ($unit = mysqli_fetch_assoc($unitsResult)) {
                    $linkValue = (string) ($unit['link_grup'] ?? '');
                    $inputId = 'group-link-'.preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $unit['kode_unit']);
                ?>
                    <form class="group-link-row" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="kode_unit" value="<?php echo h($unit['kode_unit']); ?>">
                        <div class="group-link-unit">
                            <strong><?php echo h(ppdb_unit_display_name($unit['nama_unit'])); ?></strong>
                            <small><?php echo h($unit['jenjang']); ?> · <?php echo h($unit['kode_unit']); ?></small>
                        </div>
                        <label class="group-link-field" for="<?php echo h($inputId); ?>">
                            <span>Tautan grup WhatsApp</span>
                            <input id="<?php echo h($inputId); ?>" name="link_grup" type="url" inputmode="url" maxlength="500" placeholder="https://chat.whatsapp.com/kodeundangan" value="<?php echo h($linkValue); ?>">
                            <?php if ($linkValue !== '') { ?><a href="<?php echo h($linkValue); ?>" target="_blank" rel="noopener noreferrer">Buka tautan grup</a><?php } ?>
                        </label>
                        <button class="button-primary" type="submit">Simpan tautan</button>
                    </form>
                <?php } ?>
            </div>
        <?php } ?>
    </section>
</div>
<script src="js/admin.js"></script>
<?php require 'admin_footer.php'; ?>
