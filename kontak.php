<?php
require_once __DIR__ . '/public_layout.php';

$units = cms_units($conn);
$phone = cms_setting($conn, 'contact_phone', '082181649543');
$waNumber = ppdb_normalize_whatsapp_number($phone);
$email = cms_setting($conn, 'contact_email', 'wakafcendekiatakengon@gmail.com');
$address = cms_setting($conn, 'contact_address');
$website = cms_setting($conn, 'contact_website', 'https://cendekiabisa.com');
$hours = cms_setting($conn, 'contact_hours');
$mapQuery = cms_setting($conn, 'contact_map_query', 'Yayasan Wakaf Cendekia Takengon, Bebesen, Aceh Tengah');

public_page_start($conn, [
    'title' => 'Kontak',
    'description' => 'Alamat, WhatsApp, email, dan kontak setiap unit Yayasan Wakaf Cendekia Takengon.',
    'active' => 'kontak',
    'path' => 'kontak.php',
]);
?>
<section class="page-hero">
    <div class="shell">
        <ol class="breadcrumbs" aria-label="Breadcrumb"><li><a href="index.php">Beranda</a></li><li aria-current="page">Kontak</li></ol>
        <p class="kicker">Kontak</p>
        <h1>Hubungi yayasan</h1>
        <p>Untuk pertanyaan pendaftaran, layanan, atau kunjungan, hubungi panitia melalui kontak resmi berikut.</p>
    </div>
</section>

<section class="section">
    <div class="shell contact-grid">
        <ul class="contact-list">
            <?php if ($address !== '') { ?><li><?= public_icon('pin') ?><div><strong>Alamat</strong><span><?= h($address) ?></span></div></li><?php } ?>
            <li><?= public_icon('phone') ?><div><strong>WhatsApp panitia</strong><a href="https://wa.me/<?= h($waNumber ?: '6282181649543') ?>" rel="noopener"><?= h($phone) ?></a></div></li>
            <li><?= public_icon('mail') ?><div><strong>Email</strong><a href="mailto:<?= h($email) ?>"><?= h($email) ?></a></div></li>
            <?php if ($website !== '') { ?><li><?= public_icon('globe') ?><div><strong>Situs</strong><a href="<?= h($website) ?>" rel="noopener"><?= h(preg_replace('#^https?://#', '', $website)) ?></a></div></li><?php } ?>
            <?php if ($hours !== '') { ?><li><?= public_icon('calendar') ?><div><strong>Jam layanan</strong><span><?= h($hours) ?></span></div></li><?php } ?>
        </ul>
        <div class="map-frame">
            <iframe src="https://maps.google.com/maps?q=<?= rawurlencode($mapQuery) ?>&amp;z=15&amp;output=embed" title="Peta lokasi Yayasan Wakaf Cendekia Takengon" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
        </div>
    </div>
</section>

<section class="section section--tinted" aria-labelledby="unit-contact-title">
    <div class="shell">
        <header class="section-head"><h2 id="unit-contact-title">Kontak setiap unit</h2></header>
        <div class="table-scroll">
            <table class="data-list">
                <thead><tr><th scope="col">Unit</th><th scope="col">NPSN</th><th scope="col">Email</th><th scope="col">Telepon</th></tr></thead>
                <tbody>
                    <?php foreach ($units as $unit) { ?>
                        <tr>
                            <th scope="row"><a href="unit.php?kode=<?= rawurlencode($unit['kode_unit']) ?>"><?= h(ppdb_unit_display_name($unit['nama_unit'])) ?></a></th>
                            <td><?= h($unit['npsn'] ?: '–') ?></td>
                            <td><?= $unit['email'] ? '<a href="mailto:'.h($unit['email']).'">'.h($unit['email']).'</a>' : '<span>Melalui panitia yayasan</span>' ?></td>
                            <td><?= $unit['phone'] ? h($unit['phone']) : '<span>Melalui panitia yayasan</span>' ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php public_page_end($conn); ?>
