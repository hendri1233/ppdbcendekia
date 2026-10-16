<?php
require_once __DIR__.'/public_layout.php';
$programs = cms_showcase_items($conn, 'program');
$categories = ['pendidikan' => 'Pendidikan', 'dakwah' => 'Dakwah', 'sosial' => 'Sosial'];
public_page_start($conn, ['title' => 'Program yayasan', 'description' => 'Program pendidikan, dakwah, dan sosial Yayasan Wakaf Cendekia Takengon.', 'active' => 'inspirasi', 'path' => 'program.php']);
?>
<section class="page-hero"><div class="shell"><ol class="breadcrumbs" aria-label="Breadcrumb"><li><a href="index.php">Beranda</a></li><li aria-current="page">Program yayasan</li></ol><p class="kicker">Shaleh, cerdas, dan terampil</p><h1>Program yayasan</h1><p>Ikhtiar Cendekia dalam pendidikan, dakwah, dan kepedulian sosial di Takengon.</p></div></section>
<?php if (!$programs) { ?><section class="section"><div class="shell"><div class="empty-note"><strong>Informasi program sedang disiapkan.</strong><span>Program resmi yayasan akan ditampilkan di halaman ini.</span></div></div></section><?php } else { foreach ($categories as $key => $label) { $group = array_values(array_filter($programs, static fn (array $item): bool => $item['category'] === $key)); if (!$group) { continue; } ?>
<section class="section<?= $key === 'dakwah' ? ' section--tinted' : '' ?>" aria-labelledby="program-<?= h($key) ?>"><div class="shell"><header class="section-head"><p class="kicker">Program Cendekia</p><h2 id="program-<?= h($key) ?>"><?= h($label) ?></h2></header><div class="showcase-grid">
<?php foreach ($group as $item) { ?><article class="showcase-card"><?php if ($item['image_path']) { ?><img class="showcase-card__image" src="<?= h($item['image_path']) ?>" alt="" loading="lazy"><?php } ?><div class="showcase-card__body"><h3><?= h($item['title']) ?></h3><?php if ($item['subtitle']) { ?><p class="showcase-card__summary"><?= h($item['subtitle']) ?></p><?php } ?><?php if ($item['body_html']) { ?><div class="prose"><?= $item['body_html'] ?></div><?php } ?></div></article><?php } ?>
</div></div></section><?php } } ?>
<?php public_page_end($conn); ?>
