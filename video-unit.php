<?php
/**
 * Video pendek unggulan di halaman unit. Admin unit hanya mengelola unitnya
 * sendiri; super admin dapat memilih semua unit.
 */
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_admin();
require_once __DIR__.'/admin-partials.php';
require_once __DIR__.'/cms_helpers.php';

$adminUnitCode = ppdb_is_super_admin() ? '' : (string) $_SESSION['admin_unit_code'];
$units = array_values(array_filter(
    cms_units($conn),
    static fn (array $unit): bool => $adminUnitCode === '' || $unit['kode_unit'] === $adminUnitCode
));
$codes = array_column($units, 'kode_unit');
$selected = in_array($_GET['kode'] ?? '', $codes, true) ? $_GET['kode'] : ($codes[0] ?? '');
$self = 'video-unit.php?kode='.rawurlencode($selected);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = is_string($_POST['kode_unit'] ?? null) ? $_POST['kode_unit'] : '';
    $error = '';
    $links = [];
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi formulir kedaluwarsa. Muat ulang halaman dan coba kembali.';
    } elseif (!in_array($code, $codes, true)) {
        $error = 'Unit yang dipilih tidak valid atau Anda tidak memiliki akses.';
    } else {
        foreach (CMS_UNIT_SOCIALS as $network => [$label]) {
            $url = is_string($_POST['video_'.$network] ?? null) ? trim($_POST['video_'.$network]) : '';
            if (strlen($url) > 255) {
                $error = 'Tautan '.$label.' maksimal 255 karakter.';
                break;
            }
            if ($url !== '' && (!preg_match('#^https://[^\s<>"]+$#i', $url) || cms_unit_video_embed($network, $url) === null)) {
                $error = 'Tautan '.$label.' tidak dikenali. Gunakan tautan satu video dari platform tersebut, diawali https://.';
                break;
            }
            $links[$network] = $url;
        }
    }
    $igToken = is_string($_POST['ig_token'] ?? null) ? trim($_POST['ig_token']) : '';
    $igUser = null;
    if ($error === '' && $igToken !== '') {
        if (strlen($igToken) > 1000 || !preg_match('/^[A-Za-z0-9_|.-]+$/', $igToken)) {
            $error = 'Token Instagram tidak valid. Salin ulang token akses dari dasbor Meta for Developers.';
        } elseif (($igUser = cms_instagram_username($igToken)) === null) {
            $error = 'Token Instagram ditolak Instagram. Pastikan token masih berlaku dan akun berjenis Bisnis atau Kreator.';
        }
    }
    if ($error === '') {
        foreach ($links as $network => $url) {
            cms_save_setting($conn, cms_unit_social_key($code, $network), $url);
        }
        cms_save_setting($conn, 'unit_ig_cache_'.$code, '');
        if ($igToken !== '') {
            cms_unit_ig_save_token($conn, $code, $igToken);
            cms_save_setting($conn, 'unit_ig_user_'.$code, $igUser);
        } elseif (!empty($_POST['ig_disconnect'])) {
            cms_unit_ig_save_token($conn, $code, '');
            cms_save_setting($conn, 'unit_ig_user_'.$code, '');
        }
        $_SESSION['video_unit_flash'] = ['success', 'Video unit disimpan.'];
    } else {
        $_SESSION['video_unit_flash'] = ['error', $error];
    }
    header('Location: video-unit.php?kode='.rawurlencode($code !== '' && in_array($code, $codes, true) ? $code : $selected));
    exit;
}

$flash = $_SESSION['video_unit_flash'] ?? null;
unset($_SESSION['video_unit_flash']);
$unit = null;
foreach ($units as $row) {
    if ($row['kode_unit'] === $selected) {
        $unit = $row;
    }
}
$examples = [
    'facebook' => 'https://www.facebook.com/reel/1234567890',
    'instagram' => 'https://www.instagram.com/reel/AbCdEfGh123/',
    'youtube' => 'https://www.youtube.com/shorts/AbCdEfGh123',
    'tiktok' => 'https://www.tiktok.com/@akunsekolah/video/1234567890123456789',
];

$activeAdminPage = 'unit-videos';
$adminPageTitle = 'Video unit';
$adminPageDescription = 'Empat video pendek yang diputar otomatis di halaman unit.';
require 'admin_header.php';
?>
<div class="admin-content cms-page">
    <?php if ($flash) { ?><div class="<?php echo $flash[0] === 'error' ? 'alert-error' : 'alert-success'; ?>" role="status"><?php echo h($flash[1]); ?></div><?php } ?>
    <header class="page-heading">
        <div>
            <p class="eyebrow">Situs web</p>
            <h1>Video unit</h1>
            <p>Tempel tautan satu video dari akun unit. Video tampil berderet di halaman unit, di atas berita, dan diputar otomatis tanpa suara.</p>
        </div>
        <?php if ($unit) { ?><a class="button-secondary" href="<?php echo h(ppdb_unit_public_path($unit)); ?>" target="_blank" rel="noopener">Lihat halaman unit <span aria-hidden="true">↗</span></a><?php } ?>
    </header>

    <?php if (count($units) > 1) { ?>
        <nav class="cms-tabs" aria-label="Pilih unit">
            <?php foreach ($units as $row) { $filled = cms_unit_socials($conn, $row['kode_unit']) !== []; ?>
                <a href="video-unit.php?kode=<?php echo rawurlencode($row['kode_unit']); ?>"<?php echo $row['kode_unit'] === $selected ? ' aria-current="page"' : ''; ?>><?php echo h($row['jenjang']); ?><span class="cms-tabs__dot<?php echo $filled ? ' is-filled' : ''; ?>" title="<?php echo $filled ? 'Video terisi' : 'Video belum diisi'; ?>"></span></a>
            <?php } ?>
        </nav>
    <?php } ?>

    <?php if ($unit) { ?>
        <section class="panel">
            <div class="panel-header"><div><h2><?php echo h(ppdb_unit_display_name($unit['nama_unit'])); ?></h2><span class="panel-subtitle">Kosongkan kolom bila tidak ingin menampilkan video dari platform tersebut.</span></div></div>
            <form class="cms-form" method="post" data-loading-form>
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="kode_unit" value="<?php echo h($unit['kode_unit']); ?>">
                <div class="cms-grid">
                    <?php foreach (CMS_UNIT_SOCIALS as $network => [$label]) { $key = 'video_'.$network; ?>
                        <div class="cms-field"><label class="cms-label" for="<?php echo $key; ?>"><?php echo h($label); ?></label><input id="<?php echo $key; ?>" name="<?php echo $key; ?>" type="url" value="<?php echo h(cms_setting($conn, cms_unit_social_key($unit['kode_unit'], $network))); ?>" maxlength="255" placeholder="https://"><span class="cms-hint">Contoh: <?php echo h($examples[$network]); ?></span></div>
                    <?php } ?>
                </div>
                <?php $igUser = cms_setting($conn, 'unit_ig_user_'.$unit['kode_unit']); $igLinked = cms_setting($conn, cms_unit_ig_token_key($unit['kode_unit'])) !== ''; ?>
                <fieldset class="cms-field cms-field--wide ig-connect">
                    <legend class="cms-label">Akun Instagram unit (agar Reels diputar otomatis)</legend>
                    <p class="cms-hint">
                        <?php if ($igLinked && time() - (int) cms_setting($conn, 'unit_ig_token_at_'.$unit['kode_unit'], '0') > 60 * 86400) { ?>
                            <strong>Token kedaluwarsa.</strong> Halaman unit tidak dikunjungi cukup lama sehingga token tidak sempat diperbarui. Buat token baru lalu tempel di bawah.
                        <?php } elseif ($igLinked) { ?>
                            <strong>Terhubung<?php echo $igUser !== '' ? ' ke @'.h($igUser) : ''; ?>.</strong> Reels sesuai tautan di atas diputar otomatis; bila tautan dikosongkan, reels terbaru akun yang tampil. Token diperbarui otomatis.
                        <?php } else { ?>
                            Belum terhubung. Tanpa token, Reels Instagram hanya dapat diputar setelah pengunjung menekan tombol putar.
                        <?php } ?>
                    </p>
                    <label class="cms-label" for="ig_token"><?php echo $igLinked ? 'Ganti token akses' : 'Token akses Instagram'; ?></label>
                    <input id="ig_token" name="ig_token" type="password" autocomplete="off" maxlength="1000" placeholder="<?php echo $igLinked ? 'Kosongkan bila tidak diganti' : 'IGAA...'; ?>">
                    <span class="cms-hint">Token dari Meta for Developers &rarr; aplikasi &rarr; Instagram &rarr; <em>API setup with Instagram login</em> &rarr; <em>Generate token</em>. Akun harus berjenis Bisnis atau Kreator.</span>
                    <?php if ($igLinked) { ?><label class="ig-connect__remove"><input type="checkbox" name="ig_disconnect" value="1"> Putuskan akun Instagram</label><?php } ?>
                </fieldset>
                <div class="cms-actions"><button class="button-primary" type="submit">Simpan video</button></div>
            </form>
        </section>
    <?php } ?>
</div>
<?php require 'admin_footer.php'; ?>
