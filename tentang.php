<?php
require_once __DIR__ . '/public_layout.php';

$sections = cms_sections($conn, 'tentang');
$prakata = $sections['prakata'] ?? ['title' => 'Prakata pimpinan', 'subtitle' => '', 'body_html' => '', 'image_path' => ''];
$visiMisi = $sections['visi_misi'] ?? ['title' => 'Visi dan misi', 'subtitle' => '', 'body_html' => '', 'image_path' => ''];
$people = cms_items($conn, 'manajemen');
$facilities = cms_items($conn, 'fasilitas');
$leaderName = cms_setting($conn, 'leader_name', 'Ilawarni');
$leaderRole = cms_setting($conn, 'leader_role', 'Pimpinan Yayasan Wakaf Cendekia Takengon');

$emptyNote = static fn (string $label) => '<div class="empty-note"><strong>'.h($label).' sedang disiapkan.</strong><span>Konten akan tampil setelah diisi oleh pengelola situs.</span></div>';

public_page_start($conn, [
    'title' => 'Tentang yayasan',
    'description' => 'Prakata pimpinan, profil manajemen, visi dan misi, serta fasilitas Yayasan Wakaf Cendekia Takengon.',
    'active' => 'tentang',
    'path' => 'tentang.php',
]);
?>
<section class="page-hero">
    <div class="shell">
        <ol class="breadcrumbs" aria-label="Breadcrumb"><li><a href="index.php">Beranda</a></li><li aria-current="page">Tentang</li></ol>
        <p class="kicker">Tentang yayasan</p>
        <h1>Yayasan Wakaf Cendekia Takengon</h1>
        <p>Yayasan berbadan hukum yang menaungi satuan pendidikan Islam terpadu jenjang KB, TPA, TK, SD, dan SMP di Kecamatan Bebesen, Kabupaten Aceh Tengah.</p>
    </div>
</section>

<nav class="subpage-nav" aria-label="Bagian halaman Tentang" data-spy>
    <ul class="shell">
        <li><a href="#prakata">Prakata pimpinan</a></li>
        <li><a href="#manajemen">Profil manajemen</a></li>
        <li><a href="#visi-misi">Visi dan misi</a></li>
        <li><a href="#fasilitas">Fasilitas</a></li>
        <li><a href="#legalitas">Legalitas</a></li>
    </ul>
</nav>

<section class="section" id="prakata" aria-labelledby="prakata-title">
    <div class="shell welcome">
        <figure class="welcome__photo">
            <?php if ($prakata['image_path']) { ?>
                <img src="<?= h($prakata['image_path']) ?>" alt="Foto <?= h($leaderName) ?>" width="480" height="600" loading="lazy">
            <?php } ?>
            <figcaption class="person">
                <h3><?= h($leaderName) ?></h3>
                <p class="person__role"><?= h($leaderRole) ?></p>
            </figcaption>
        </figure>
        <div>
            <p class="kicker"><?= h($prakata['subtitle'] ?: 'Sambutan') ?></p>
            <h2 id="prakata-title"><?= h($prakata['title']) ?></h2>
            <?= trim((string) $prakata['body_html']) !== '' ? '<div class="prose">'.$prakata['body_html'].'</div>' : $emptyNote('Prakata pimpinan') ?>
        </div>
    </div>
</section>

<section class="section section--tinted" id="manajemen" aria-labelledby="manajemen-title">
    <div class="shell">
        <header class="section-head">
            <h2 id="manajemen-title">Profil manajemen</h2>
            <p>Pengurus dan pengelola yang menjalankan amanah yayasan.</p>
        </header>
        <?php if (!$people) { echo $emptyNote('Profil manajemen'); } else { ?>
            <ul class="people-grid">
                <?php foreach ($people as $person) { ?>
                    <li class="person">
                        <div class="person__photo">
                            <?php if ($person['image_path']) { ?>
                                <img src="<?= h($person['image_path']) ?>" alt="Foto <?= h($person['title']) ?>" width="400" height="400" loading="lazy">
                            <?php } else { ?>
                                <span aria-hidden="true"><?= h(mb_substr($person['title'], 0, 1)) ?></span>
                            <?php } ?>
                        </div>
                        <h3><?= h($person['title']) ?></h3>
                        <?php if ($person['subtitle']) { ?><p class="person__role"><?= h($person['subtitle']) ?></p><?php } ?>
                        <?php if ($person['description']) { ?><p class="person__bio"><?= h($person['description']) ?></p><?php } ?>
                    </li>
                <?php } ?>
            </ul>
        <?php } ?>
    </div>
</section>

<section class="section" id="visi-misi" aria-labelledby="visi-title">
    <div class="shell vision">
        <div>
            <p class="kicker"><?= h($visiMisi['subtitle'] ?: 'Arah yayasan') ?></p>
            <h2 id="visi-title"><?= h($visiMisi['title']) ?></h2>
        </div>
        <div>
            <?= trim((string) $visiMisi['body_html']) !== '' ? '<div class="prose">'.$visiMisi['body_html'].'</div>' : $emptyNote('Visi dan misi') ?>
        </div>
    </div>
</section>

<section class="section section--tinted" id="fasilitas" aria-labelledby="fasilitas-title">
    <div class="shell">
        <header class="section-head">
            <h2 id="fasilitas-title">Fasilitas</h2>
            <p>Sarana yang mendukung kegiatan belajar ananda.</p>
        </header>
        <?php if (!$facilities) { echo $emptyNote('Informasi fasilitas'); } else { ?>
            <div class="facility-grid">
                <?php foreach ($facilities as $facility) { ?>
                    <article class="facility">
                        <div class="facility__media">
                            <?php if ($facility['image_path']) { ?><img src="<?= h($facility['image_path']) ?>" alt="<?= h($facility['title']) ?>" width="800" height="500" loading="lazy"><?php } ?>
                        </div>
                        <div class="facility__body">
                            <h3><?= h($facility['title']) ?></h3>
                            <?php if ($facility['subtitle']) { ?><p class="person__role"><?= h($facility['subtitle']) ?></p><?php } ?>
                            <?php if ($facility['description']) { ?><p><?= h($facility['description']) ?></p><?php } ?>
                        </div>
                    </article>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
</section>

<section class="section" id="legalitas" aria-labelledby="legal-title">
    <div class="shell">
        <header class="section-head">
            <h2 id="legal-title">Legalitas</h2>
        </header>
        <dl class="legal-list">
            <div><dt>Berdiri</dt><dd>5 Januari 2022</dd></div>
            <div><dt>Badan hukum</dt><dd>SK Kemenkumham AHU-0000410.AH.01.12 Tahun 2022</dd></div>
            <div><dt>Nazhir wakaf uang</dt><dd>Badan Wakaf Indonesia, kode 3.3.00369</dd></div>
            <div><dt>Pimpinan yayasan</dt><dd><?= h($leaderName) ?></dd></div>
            <div><dt>Alamat</dt><dd><?= h(cms_setting($conn, 'contact_address')) ?></dd></div>
            <div><dt>Situs yayasan</dt><dd><a class="text-link" href="<?= h(cms_setting($conn, 'contact_website', 'https://cendekiabisa.com')) ?>" rel="noopener"><?= h(preg_replace('#^https?://#', '', cms_setting($conn, 'contact_website', 'https://cendekiabisa.com'))) ?></a></dd></div>
        </dl>
    </div>
</section>
<?php public_page_end($conn); ?>
