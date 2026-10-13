<?php
require_once __DIR__.'/admin_cms.php';

$self = 'cms-berita.php';
$units = cms_units($conn);
$unitCodes = array_column($units, 'kode_unit');
$categories = ['Berita', 'Kegiatan', 'Pengumuman', 'Prestasi'];
$mode = $_GET['aksi'] ?? '';
$editId = (int) ($_GET['id'] ?? 0);

/** Slug unik: tambahkan angka bila sudah dipakai berita lain. */
function cms_unique_news_slug(mysqli $conn, string $slug, int $ignoreId): string
{
    $base = $slug;
    $n = 2;
    $stmt = mysqli_prepare($conn, 'SELECT id FROM tb_cms_news WHERE slug=? AND id<>? LIMIT 1');
    while (true) {
        mysqli_stmt_bind_param($stmt, 'si', $slug, $ignoreId);
        mysqli_stmt_execute($stmt);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
            return $slug;
        }
        $slug = substr($base, 0, 140).'-'.$n++;
    }
}

function cms_find_news(mysqli $conn, int $id): ?array
{
    $stmt = mysqli_prepare($conn, 'SELECT * FROM tb_cms_news WHERE id=?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $back = $id ? $self.'?aksi=ubah&id='.$id : $self.'?aksi=baru';
    cms_require_post_token($back);
    try {
        $current = $id ? cms_find_news($conn, $id) : null;
        if ($id && !$current) {
            throw new RuntimeException('Berita tidak ditemukan.');
        }

        if ($action === 'delete' && $current) {
            $stmt = mysqli_prepare($conn, 'DELETE FROM tb_cms_news WHERE id=?');
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            cms_delete_image($current['cover_path']);
            cms_flash('success', 'Berita "'.$current['title'].'" dihapus.');
            cms_redirect($self);
        }

        if ($action === 'save') {
            $title = cms_post_text('title', 200);
            if ($title === '') {
                throw new RuntimeException('Judul berita wajib diisi.');
            }
            $body = ppdb_sanitize_rich_text((string) ($_POST['body_html'] ?? ''));
            $status = ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft';
            if ($status === 'published' && trim(strip_tags($body)) === '') {
                throw new RuntimeException('Isi berita masih kosong. Simpan sebagai draf atau lengkapi isinya sebelum diterbitkan.');
            }
            $slugInput = cms_post_text('slug', 150);
            $slug = cms_unique_news_slug($conn, cms_slugify($slugInput !== '' ? $slugInput : $title), $id);
            $excerpt = cms_post_text('excerpt', 400);
            $unit = in_array($_POST['kode_unit'] ?? '', $unitCodes, true) ? $_POST['kode_unit'] : null;
            $category = in_array($_POST['category'] ?? '', $categories, true) ? $_POST['category'] : 'Berita';
            $publishedInput = cms_post_text('published_at', 20);
            $publishedAt = $publishedInput !== '' && strtotime($publishedInput) ? date('Y-m-d H:i:s', strtotime($publishedInput)) : null;
            if ($status === 'published' && !$publishedAt) {
                $publishedAt = $current['published_at'] ?? date('Y-m-d H:i:s');
            }
            $cover = cms_process_image_field('cover', $current['cover_path'] ?? null, 'berita', 1600);
            $adminId = (int) $_SESSION['admin_id'];

            if ($current) {
                $stmt = mysqli_prepare($conn, 'UPDATE tb_cms_news SET slug=?,title=?,excerpt=?,body_html=?,cover_path=?,kode_unit=?,category=?,status=?,published_at=? WHERE id=?');
                mysqli_stmt_bind_param($stmt, 'sssssssssi', $slug, $title, $excerpt, $body, $cover, $unit, $category, $status, $publishedAt, $id);
            } else {
                $stmt = mysqli_prepare($conn, 'INSERT INTO tb_cms_news (slug,title,excerpt,body_html,cover_path,kode_unit,category,status,published_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
                mysqli_stmt_bind_param($stmt, 'sssssssssi', $slug, $title, $excerpt, $body, $cover, $unit, $category, $status, $publishedAt, $adminId);
            }
            mysqli_stmt_execute($stmt);
            $savedId = $current ? $id : mysqli_insert_id($conn);
            $scheduled = $status === 'published' && strtotime((string) $publishedAt) > time();
            cms_flash('success', $status === 'draft' ? 'Draf disimpan. Berita belum tampil di situs.' : ($scheduled ? 'Berita dijadwalkan terbit '.ppdb_format_date_id(substr($publishedAt, 0, 10)).'.' : 'Berita diterbitkan dan tampil di situs.'));
            cms_redirect($self.'?aksi=ubah&id='.$savedId);
        }
    } catch (RuntimeException $exception) {
        cms_flash('error', $exception->getMessage());
        cms_redirect($back);
    }
}

$activeAdminPage = 'cms-news';
$adminPageDescription = 'Tulis, jadwalkan, dan terbitkan berita yayasan serta setiap unit.';

// ---------- Form tulis/ubah ----------
if ($mode === 'baru' || ($mode === 'ubah' && $editId)) {
    $news = $mode === 'ubah' ? cms_find_news($conn, $editId) : null;
    if ($mode === 'ubah' && !$news) {
        cms_flash('error', 'Berita tidak ditemukan.');
        cms_redirect($self);
    }
    $adminPageTitle = $news ? 'Ubah berita' : 'Tulis berita';
    require 'admin_header.php';
    $isLive = $news && $news['status'] === 'published' && strtotime((string) $news['published_at']) <= time();
    ?>
    <div class="admin-content cms-page">
        <?php cms_render_flash(); ?>
        <header class="page-heading">
            <div>
                <p class="eyebrow"><a href="<?php echo $self; ?>">Berita</a></p>
                <h1><?php echo $news ? 'Ubah berita' : 'Tulis berita'; ?></h1>
                <?php if ($news) { ?><p>Status: <strong><?php echo $news['status'] === 'draft' ? 'Draf' : ($isLive ? 'Terbit' : 'Terjadwal'); ?></strong> · diperbarui <?php echo h(ppdb_format_date_id(substr((string) $news['updated_at'], 0, 10))); ?></p><?php } ?>
            </div>
            <div class="table-actions">
                <?php if ($isLive) { ?><a class="button-secondary" href="berita.php?baca=<?php echo rawurlencode($news['slug']); ?>" target="_blank" rel="noopener">Lihat di situs <span aria-hidden="true">↗</span></a><?php } ?>
                <a class="button-secondary" href="<?php echo $self; ?>">Kembali ke daftar</a>
            </div>
        </header>

        <form class="cms-editor-layout" method="post" enctype="multipart/form-data" data-loading-form>
            <?php echo cms_csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <?php if ($news) { ?><input type="hidden" name="id" value="<?php echo (int) $news['id']; ?>"><?php } ?>
            <section class="panel">
                <div class="cms-form cms-grid">
                    <div class="cms-field cms-field--wide"><label class="cms-label" for="news-title">Judul <span aria-hidden="true">*</span></label><input id="news-title" class="cms-input--lg" name="title" value="<?php echo h($news['title'] ?? ''); ?>" maxlength="200" required data-slug-source></div>
                    <div class="cms-field cms-field--wide">
                        <label class="cms-label" for="news-slug">Alamat (slug)</label>
                        <div class="cms-prefix"><span>berita.php?baca=</span><input id="news-slug" name="slug" value="<?php echo h($news['slug'] ?? ''); ?>" maxlength="150" pattern="[a-z0-9-]*" data-slug-target placeholder="dibuat otomatis dari judul"></div>
                    </div>
                    <div class="cms-field cms-field--wide"><label class="cms-label" for="news-excerpt">Ringkasan</label><textarea id="news-excerpt" name="excerpt" rows="2" maxlength="400" placeholder="1–2 kalimat untuk kartu berita dan pratinjau media sosial."><?php echo h($news['excerpt'] ?? ''); ?></textarea></div>
                    <?php echo cms_rich_editor('body_html', $news['body_html'] ?? '', 'Isi berita'); ?>
                </div>
            </section>
            <aside class="cms-editor-side">
                <section class="panel">
                    <div class="panel-header"><div><h2>Terbitkan</h2></div></div>
                    <div class="cms-form cms-grid cms-grid--single">
                        <fieldset class="cms-field cms-radio-group">
                            <legend class="cms-label">Status</legend>
                            <label class="cms-check"><input type="radio" name="status" value="draft"<?php echo ($news['status'] ?? 'draft') === 'draft' ? ' checked' : ''; ?>> Draf (belum tampil)</label>
                            <label class="cms-check"><input type="radio" name="status" value="published"<?php echo ($news['status'] ?? '') === 'published' ? ' checked' : ''; ?>> Terbitkan</label>
                        </fieldset>
                        <div class="cms-field"><label class="cms-label" for="news-date">Tanggal terbit</label><input id="news-date" type="datetime-local" name="published_at" value="<?php echo $news && $news['published_at'] ? h(date('Y-m-d\TH:i', strtotime($news['published_at']))) : ''; ?>"><span class="cms-hint">Kosongkan untuk terbit sekarang. Isi tanggal mendatang untuk menjadwalkan.</span></div>
                        <div class="cms-field"><label class="cms-label" for="news-unit">Unit</label>
                            <select id="news-unit" name="kode_unit">
                                <option value="">Yayasan (umum)</option>
                                <?php foreach ($units as $unit) { ?><option value="<?php echo h($unit['kode_unit']); ?>"<?php echo ($news['kode_unit'] ?? '') === $unit['kode_unit'] ? ' selected' : ''; ?>><?php echo h(ppdb_unit_display_name($unit['nama_unit'])); ?></option><?php } ?>
                            </select>
                        </div>
                        <div class="cms-field"><label class="cms-label" for="news-category">Kategori</label>
                            <select id="news-category" name="category">
                                <?php foreach ($categories as $category) { ?><option<?php echo ($news['category'] ?? 'Berita') === $category ? ' selected' : ''; ?>><?php echo h($category); ?></option><?php } ?>
                            </select>
                        </div>
                        <button class="button-primary" type="submit">Simpan</button>
                    </div>
                </section>
                <section class="panel">
                    <div class="cms-form"><?php echo cms_image_field('cover', $news['cover_path'] ?? null, 'Gambar sampul', 'Rasio 16:9 disarankan. JPG, PNG, atau WebP, maks. 6 MB.'); ?></div>
                </section>
            </aside>
        </form>

        <?php if ($news) { ?>
            <form class="cms-danger-zone" method="post" data-confirm="Hapus berita ini secara permanen?">
                <?php echo cms_csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int) $news['id']; ?>">
                <span>Menghapus berita juga menghapus gambar sampulnya.</span>
                <button class="button-danger" type="submit">Hapus berita</button>
            </form>
        <?php } ?>
    </div>
    <?php
    echo cms_admin_scripts();
    require 'admin_footer.php';
    exit;
}

// ---------- Daftar ----------
$search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
$statusFilter = in_array($_GET['status'] ?? '', ['draft', 'published', 'scheduled'], true) ? $_GET['status'] : '';
$unitFilter = in_array($_GET['unit'] ?? '', $unitCodes, true) ? $_GET['unit'] : (($_GET['unit'] ?? '') === 'yayasan' ? 'yayasan' : '');
$where = ['1=1'];
$types = '';
$params = [];
if ($search !== '') {
    $where[] = '(n.title LIKE ? OR n.excerpt LIKE ?)';
    $types .= 'ss';
    $params[] = '%'.$search.'%';
    $params[] = '%'.$search.'%';
}
if ($statusFilter === 'draft') {
    $where[] = "n.status='draft'";
} elseif ($statusFilter === 'published') {
    $where[] = "n.status='published' AND n.published_at<=NOW()";
} elseif ($statusFilter === 'scheduled') {
    $where[] = "n.status='published' AND n.published_at>NOW()";
}
if ($unitFilter === 'yayasan') {
    $where[] = 'n.kode_unit IS NULL';
} elseif ($unitFilter !== '') {
    $where[] = 'n.kode_unit=?';
    $types .= 's';
    $params[] = $unitFilter;
}
$whereSql = implode(' AND ', $where);
$count = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM tb_cms_news n WHERE '.$whereSql);
if ($types !== '') {
    mysqli_stmt_bind_param($count, $types, ...$params);
}
mysqli_stmt_execute($count);
$total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($count))['total'];
$perPage = 20;
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['halaman'] ?? 1)));
$offset = ($page - 1) * $perPage;
$list = mysqli_prepare($conn, 'SELECT n.id,n.slug,n.title,n.status,n.published_at,n.updated_at,n.category,n.cover_path,u.jenjang FROM tb_cms_news n LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=n.kode_unit WHERE '.$whereSql.' ORDER BY COALESCE(n.published_at,n.created_at) DESC,n.id DESC LIMIT '.$perPage.' OFFSET '.$offset);
if ($types !== '') {
    mysqli_stmt_bind_param($list, $types, ...$params);
}
mysqli_stmt_execute($list);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($list), MYSQLI_ASSOC);
$query = static fn (array $overrides) => '?'.http_build_query(array_filter(array_merge(['q' => $search, 'status' => $statusFilter, 'unit' => $unitFilter], $overrides), static fn ($v) => $v !== '' && $v !== 1));

$adminPageTitle = 'Berita';
require 'admin_header.php';
?>
<div class="admin-content cms-page">
    <?php cms_render_flash(); ?>
    <header class="page-heading">
        <div>
            <p class="eyebrow">Situs web</p>
            <h1>Berita</h1>
            <p>Draf tidak tampil di situs. Berita terjadwal tampil otomatis pada tanggal terbitnya.</p>
        </div>
        <a class="button-primary" href="<?php echo $self; ?>?aksi=baru">Tulis berita</a>
    </header>

    <section class="panel">
        <form class="filter-bar" method="get" action="<?php echo $self; ?>">
            <div class="filter-field grow">
                <label for="news-q">Cari judul</label>
                <div class="search-field"><?php echo ppdb_admin_icon('search'); ?><input id="news-q" name="q" type="search" value="<?php echo h($search); ?>"></div>
            </div>
            <div class="filter-field">
                <label for="news-status">Status</label>
                <select id="news-status" name="status">
                    <option value="">Semua status</option>
                    <option value="published"<?php echo $statusFilter === 'published' ? ' selected' : ''; ?>>Terbit</option>
                    <option value="scheduled"<?php echo $statusFilter === 'scheduled' ? ' selected' : ''; ?>>Terjadwal</option>
                    <option value="draft"<?php echo $statusFilter === 'draft' ? ' selected' : ''; ?>>Draf</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="news-unit-filter">Unit</label>
                <select id="news-unit-filter" name="unit">
                    <option value="">Semua</option>
                    <option value="yayasan"<?php echo $unitFilter === 'yayasan' ? ' selected' : ''; ?>>Yayasan (umum)</option>
                    <?php foreach ($units as $unit) { ?><option value="<?php echo h($unit['kode_unit']); ?>"<?php echo $unitFilter === $unit['kode_unit'] ? ' selected' : ''; ?>><?php echo h($unit['jenjang']); ?></option><?php } ?>
                </select>
            </div>
            <div class="filter-actions"><button class="button-primary" type="submit">Terapkan</button></div>
        </form>
        <div class="table-meta"><span><?php echo $total > 0 ? 'Menampilkan <strong>'.($offset + 1).'–'.min($total, $offset + $perPage).'</strong> dari <strong>'.$total.'</strong> berita' : 'Tidak ada berita'; ?></span></div>
        <?php if (!$rows) { ?>
            <div class="empty-state">
                <span class="empty-state__icon"><?php echo ppdb_admin_icon('news'); ?></span>
                <strong><?php echo $search !== '' || $statusFilter !== '' || $unitFilter !== '' ? 'Tidak ada berita yang cocok' : 'Belum ada berita'; ?></strong>
                <p><?php echo $search !== '' || $statusFilter !== '' || $unitFilter !== '' ? 'Ubah kata kunci atau filter.' : 'Tulis berita pertama untuk ditampilkan di beranda.'; ?></p>
            </div>
        <?php } else { ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th scope="col">Judul</th><th scope="col">Unit</th><th scope="col">Status</th><th scope="col">Tanggal terbit</th><th scope="col"><span class="visually-hidden">Aksi</span></th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $row) {
                            $scheduled = $row['status'] === 'published' && strtotime((string) $row['published_at']) > time();
                            [$label, $tone] = $row['status'] === 'draft' ? ['Draf', 'slate'] : ($scheduled ? ['Terjadwal', 'amber'] : ['Terbit', 'green']); ?>
                            <tr>
                                <td class="primary-cell" data-label="Judul">
                                    <a href="<?php echo $self; ?>?aksi=ubah&amp;id=<?php echo (int) $row['id']; ?>"><?php echo h($row['title']); ?></a>
                                    <small class="cell-sub"><?php echo h($row['category']); ?></small>
                                </td>
                                <td data-label="Unit"><?php echo h($row['jenjang'] ?: 'Yayasan'); ?></td>
                                <td data-label="Status"><span class="status-pill tone-<?php echo $tone; ?>"><?php echo $label; ?></span></td>
                                <td data-label="Tanggal terbit"><?php echo $row['published_at'] ? h(ppdb_format_date_id(substr($row['published_at'], 0, 10))) : '<span class="muted-dash">&mdash;</span>'; ?></td>
                                <td data-label="Aksi"><div class="table-actions">
                                    <a class="table-action" href="<?php echo $self; ?>?aksi=ubah&amp;id=<?php echo (int) $row['id']; ?>">Ubah<span class="visually-hidden"> <?php echo h($row['title']); ?></span></a>
                                    <?php if ($label === 'Terbit') { ?><a class="table-action" href="berita.php?baca=<?php echo rawurlencode($row['slug']); ?>" target="_blank" rel="noopener">Lihat</a><?php } ?>
                                </div></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
        <?php if ($pages > 1) { ?>
            <nav class="pagination" aria-label="Halaman berita">
                <?php if ($page > 1) { ?><a class="page-step" href="<?php echo h($query(['halaman' => $page - 1])); ?>" rel="prev">&larr; Sebelumnya</a><?php } else { ?><span class="page-step is-disabled">&larr; Sebelumnya</span><?php } ?>
                <div class="page-numbers">
                    <?php for ($i = 1; $i <= $pages; $i++) { if ($i === 1 || $i === $pages || abs($i - $page) <= 1) { echo '<a class="page-number'.($i === $page ? ' is-current' : '').'" href="'.h($query(['halaman' => $i])).'"'.($i === $page ? ' aria-current="page"' : '').'>'.$i.'</a>'; } elseif (abs($i - $page) === 2) { echo '<span class="page-gap">&hellip;</span>'; } } ?>
                </div>
                <?php if ($page < $pages) { ?><a class="page-step" href="<?php echo h($query(['halaman' => $page + 1])); ?>" rel="next">Berikutnya &rarr;</a><?php } else { ?><span class="page-step is-disabled">Berikutnya &rarr;</span><?php } ?>
            </nav>
        <?php } ?>
    </section>
</div>
<?php require 'admin_footer.php'; ?>
