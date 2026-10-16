<?php
require_once __DIR__.'/public_layout.php';

$units = cms_units($conn);
$jenjangPilihan = ['KB', 'TPA', 'TK', 'SD', 'SMP'];
$jenjang = is_string($_GET['jenjang'] ?? null) && in_array($_GET['jenjang'], $jenjangPilihan, true) ? $_GET['jenjang'] : '';
$query = is_string($_GET['q'] ?? null) ? trim(mb_substr($_GET['q'], 0, 80)) : '';
$filteredUnits = array_values(array_filter($units, static function (array $unit) use ($jenjang, $query): bool {
    if ($jenjang !== '' && $unit['jenjang'] !== $jenjang) {
        return false;
    }
    if ($query === '') {
        return true;
    }
    $haystack = implode(' ', array_map('strval', [$unit['nama_unit'], $unit['jenjang'], $unit['npsn'], $unit['tagline'], $unit['alamat']]));
    return mb_stripos($haystack, $query) !== false;
}));

public_page_start($conn, [
    'title' => 'Direktori unit pendidikan',
    'description' => 'Temukan unit KB, TPA, TK, SD, dan SMP Islam terpadu Yayasan Wakaf Cendekia Takengon.',
    'active' => 'unit',
    'path' => 'direktori-unit.php',
]);
?>
<section class="page-hero">
    <div class="shell">
        <ol class="breadcrumbs" aria-label="Breadcrumb"><li><a href="index.php">Beranda</a></li><li aria-current="page">Direktori unit</li></ol>
        <p class="kicker">Belajar dan bertumbuh bersama</p>
        <h1>Direktori unit pendidikan</h1>
        <p>Kenali satuan pendidikan Cendekia dari usia dini hingga jenjang SMP.</p>
    </div>
</section>
<section class="section">
    <div class="shell">
        <form class="unit-directory-filter" method="get" action="direktori-unit.php">
            <div class="unit-directory-filter__field"><label for="unit-q">Cari nama, lokasi, atau NPSN</label><input id="unit-q" name="q" type="search" maxlength="80" value="<?= h($query) ?>" placeholder="Contoh: SD Cendekia"></div>
            <div class="unit-directory-filter__field"><label for="unit-jenjang">Jenjang</label><select id="unit-jenjang" name="jenjang"><option value="">Semua jenjang</option><?php foreach ($jenjangPilihan as $pilihan) { ?><option value="<?= h($pilihan) ?>"<?= $jenjang === $pilihan ? ' selected' : '' ?>><?= h(ppdb_jenjang_label($pilihan)) ?></option><?php } ?></select></div>
            <div class="unit-directory-filter__actions"><button class="button button--primary" type="submit">Cari unit</button><a class="button button--secondary" href="direktori-unit.php">Reset</a></div>
        </form>
        <header class="section-head section-head--split unit-directory-heading"><div><h2>Unit Cendekia</h2><p><?= count($filteredUnits) ?> dari <?= count($units) ?> unit</p></div><a class="text-link" href="ppdb.php#unit">Lihat informasi PPDB <?= h(PUBLIC_PPDB_YEAR) ?> <?= public_icon('arrow') ?></a></header>
        <?php if (!$filteredUnits) { ?><div class="empty-note"><strong>Unit tidak ditemukan.</strong><span>Coba kata kunci atau jenjang yang berbeda.</span></div><?php } else { ?>
            <div class="unit-grid">
                <?php foreach ($filteredUnits as $unit) { ?>
                    <a class="unit-card" href="unit.php?kode=<?= rawurlencode($unit['kode_unit']) ?>">
                        <span class="unit-card__media"><?php if ($unit['photo_path']) { ?><img src="<?= h($unit['photo_path']) ?>" alt="" width="640" height="480" loading="lazy"><?php } else { ?><img class="unit-card__logo" src="img/logo-display.png" alt="" width="120" height="120" loading="lazy"><?php } ?><span class="unit-card__code"><?= h($unit['jenjang']) ?></span></span>
                        <span class="unit-card__body"><h3><?= h(ppdb_unit_display_name($unit['nama_unit'])) ?></h3><p><?= h($unit['tagline'] ?: ppdb_jenjang_label($unit['jenjang'])) ?></p></span>
                    </a>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
</section>
<?php public_page_end($conn); ?>
