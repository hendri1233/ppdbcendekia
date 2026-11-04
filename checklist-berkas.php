<?php
/**
 * Halaman centang berkas untuk unit, dibuka dari tautan bertoken di grup WhatsApp.
 * Token dikirim bersama setiap perubahan, jadi halaman tidak bergantung pada sesi login.
 */
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once __DIR__.'/cms_helpers.php';
require_once __DIR__.'/checklist_berkas_helpers.php';

header('X-Robots-Tag: noindex, nofollow');

$token = is_string($_REQUEST['t'] ?? null) ? $_REQUEST['t'] : '';
$unit = checklist_unit_by_token($conn, $token);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $itemCode = is_string($_POST['berkas'] ?? null) ? $_POST['berkas'] : '';
    if (!$unit || !isset(checklist_items()[$itemCode])) {
        http_response_code(404);
        echo json_encode(['ok' => false]);
        exit;
    }
    checklist_set($conn, $unit['kode_unit'], $itemCode, ($_POST['dicentang'] ?? '') === '1');
    echo json_encode(['ok' => true, 'selesai' => checklist_required_done(checklist_checked($conn, $unit['kode_unit']))]);
    exit;
}

if (!$unit) {
    http_response_code(404);
}
$checked = $unit ? checklist_checked($conn, $unit['kode_unit']) : [];
$requiredTotal = count(checklist_required_items());
$requiredDone = checklist_required_done($checked);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Berkas untuk Yayasan · Cendekia Takengon</title>
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicons/favicon-32x32.png">
    <link rel="stylesheet" href="css/tokens.css?v=<?php echo (int) @filemtime(__DIR__.'/css/tokens.css'); ?>">
    <link rel="stylesheet" href="css/checklist-berkas.css?v=<?php echo (int) @filemtime(__DIR__.'/css/checklist-berkas.css'); ?>">
</head>
<body>
<main class="berkas">
    <?php if (!$unit) { ?>
        <h1>Tautan tidak berlaku</h1>
        <p class="berkas-lead">Minta tautan terbaru kepada panitia yayasan.</p>
    <?php } else { ?>
        <header>
            <p class="berkas-unit"><?php echo h(ppdb_unit_display_name($unit['nama_unit'])); ?></p>
            <h1>Berkas untuk yayasan</h1>
            <p class="berkas-lead">Centang setiap berkas yang sudah diserahkan ke yayasan. Centang tersimpan otomatis dan terlihat oleh semua yang membuka tautan ini.</p>
        </header>

        <div class="berkas-progress" role="status">
            <strong><span data-progress-done><?php echo $requiredDone; ?></span> dari <?php echo $requiredTotal; ?> berkas wajib</strong>
            <progress data-progress-bar max="<?php echo $requiredTotal; ?>" value="<?php echo $requiredDone; ?>"></progress>
        </div>

        <noscript><p class="berkas-error">Aktifkan JavaScript agar centang dapat tersimpan.</p></noscript>
        <p class="berkas-error" data-save-error role="alert" hidden>Centang gagal disimpan. Periksa koneksi internet, lalu coba lagi.</p>

        <form data-checklist data-token="<?php echo h($token); ?>">
            <?php foreach (CHECKLIST_BERKAS as $group => $items) { ?>
                <fieldset class="berkas-group">
                    <legend><?php echo h($group); ?></legend>
                    <?php foreach ($items as $code => [$label, $hint]) { ?>
                        <label class="berkas-item">
                            <input type="checkbox" name="<?php echo h($code); ?>" value="1"<?php echo isset($checked[$code]) ? ' checked' : ''; ?>>
                            <span><strong><?php echo h($label); ?></strong><small><?php echo h($hint); ?></small></span>
                        </label>
                    <?php } ?>
                </fieldset>
            <?php } ?>
        </form>
    <?php } ?>
</main>
<?php if ($unit) { ?><script src="js/checklist-berkas.js?v=<?php echo (int) @filemtime(__DIR__.'/js/checklist-berkas.js'); ?>"></script><?php } ?>
</body>
</html>
