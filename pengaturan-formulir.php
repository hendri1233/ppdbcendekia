<?php
require_once __DIR__.'/admin_cms.php';
require_once __DIR__.'/formulir_helpers.php';
require_super_admin();

$self = 'pengaturan-formulir.php';
$allFields = formulir_input_fields();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cms_require_post_token($self);
    try {
        $adminId = (int) $_SESSION['admin_id'];
        mysqli_begin_transaction($conn);
        if (($_POST['action'] ?? '') === 'reset') {
            mysqli_query($conn, 'DELETE FROM tb_formulir_aturan_isian');
            $message = 'Aturan isian dikembalikan ke bawaan.';
        } else {
            $submitted = is_array($_POST['wajib'] ?? null) ? $_POST['wajib'] : [];
            $upsert = mysqli_prepare($conn, 'INSERT INTO tb_formulir_aturan_isian (nama_isian,wajib,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE wajib=VALUES(wajib),updated_by=VALUES(updated_by)');
            foreach ($allFields as $name => $field) {
                if (!empty($field['locked'])) {
                    continue;
                }
                $required = isset($submitted[$name]) ? 1 : 0;
                mysqli_stmt_bind_param($upsert, 'sii', $name, $required, $adminId);
                mysqli_stmt_execute($upsert);
            }
            mysqli_stmt_close($upsert);
            $message = 'Aturan wajib/opsional formulir disimpan. Berlaku untuk pengisian berikutnya di semua unit.';
        }
        mysqli_commit($conn);
        cms_flash('success', $message);
    } catch (Throwable $exception) {
        mysqli_rollback($conn);
        error_log('Gagal menyimpan aturan isian formulir: '.$exception->getMessage());
        cms_flash('error', 'Aturan isian belum dapat disimpan. Silakan coba lagi.');
    }
    cms_redirect($self);
}

$sections = formulir_sections_for($conn);
$labels = [];
foreach ($allFields as $name => $field) {
    $labels[$name] = $field['label'];
}

/** Ringkas syarat tampil menjadi kalimat, mis. "Keadaan ayah kandung: Masih hidup". */
function pengaturan_formulir_condition_text(array $field, array $labels): string
{
    $parts = [];
    foreach ($field['show_if'] ?? [] as [$controller, $values]) {
        $parts[] = ($labels[$controller] ?? $controller).': '.implode(' / ', $values);
    }
    if (!empty($field['jenjang'])) {
        $parts[] = 'khusus '.implode(', ', $field['jenjang']);
    }
    if (!empty($field['wajib_jenjang'])) {
        $parts[] = '(selalu tampil dan wajib untuk '.implode(', ', $field['wajib_jenjang']).')';
    }
    return $parts ? 'Tampil bila '.implode(' dan ', $parts) : '';
}

$activeAdminPage = 'form-fields';
$adminPageTitle = 'Isian formulir';
$adminPageDescription = 'Atur isian formulir lengkap yang wajib dan yang opsional.';
require 'admin_header.php';
?>
<div class="admin-content cms-page">
    <?php cms_render_flash(); ?>
    <header class="page-heading">
        <div>
            <p class="eyebrow">Pengaturan</p>
            <h1>Isian formulir lengkap</h1>
            <p>Centang isian yang <strong>wajib</strong> diisi orang tua. Isian bersyarat hanya diwajibkan saat tampil, misalnya pekerjaan ayah hanya bila ayah masih hidup.</p>
        </div>
    </header>

    <form method="post" data-loading-form>
        <?php echo cms_csrf_field(); ?>
        <?php foreach ($sections as $sectionKey => $section) { ?>
            <section class="panel">
                <div class="panel-header"><div><h2><?php echo h($section['title']); ?></h2><span class="panel-subtitle"><?php echo h($section['description']); ?></span></div></div>
                <div class="form-rule-list">
                    <?php foreach ($section['fields'] as $field) {
                        if (($field['type'] ?? 'text') === 'heading') { ?>
                            <h3 class="form-rule-heading"><?php echo h($field['label']); ?></h3>
                        <?php continue; }
                        $name = $field['name'];
                        $condition = pengaturan_formulir_condition_text($field, $labels);
                        $locked = !empty($field['locked']);
                    ?>
                        <label class="form-rule<?php echo $locked ? ' is-locked' : ''; ?>">
                            <input type="checkbox" name="wajib[<?php echo h($name); ?>]" value="1"<?php echo !empty($field['required']) ? ' checked' : ''; ?><?php echo $locked ? ' disabled' : ''; ?>>
                            <span class="form-rule__text">
                                <strong><?php echo h($field['label']); ?></strong>
                                <?php if ($locked) { ?><small>Selalu wajib · pengendali alur atau data inti DAPODIK</small><?php } ?>
                                <?php if ($condition !== '') { ?><small><?php echo h($condition); ?></small><?php } ?>
                            </span>
                        </label>
                    <?php } ?>
                </div>
            </section>
        <?php } ?>
        <div class="cms-actions cms-actions--sticky">
            <button class="button-secondary" type="submit" form="reset-aturan-isian">Kembalikan bawaan</button>
            <button class="button-primary" type="submit" name="action" value="save">Simpan aturan</button>
        </div>
    </form>
    <form id="reset-aturan-isian" method="post" data-confirm="Kembalikan semua aturan wajib/opsional ke bawaan?">
        <?php echo cms_csrf_field(); ?>
        <input type="hidden" name="action" value="reset">
    </form>
    <p class="panel-note">Kuisioner wawancara per unit diatur terpisah di halaman <a href="ppdb-materi.php">Materi &amp; kuisioner</a>.</p>
</div>
<?php require 'admin_footer.php'; ?>
