<?php
require_once __DIR__.'/admin_cms.php';

$self = 'cms-pengaturan.php';
$fields = [
    'site_tagline' => ['Semboyan', 120],
    'contact_address' => ['Alamat', 300],
    'contact_phone' => ['WhatsApp panitia', 30],
    'contact_email' => ['Email', 120],
    'contact_website' => ['Situs', 200],
    'contact_hours' => ['Jam layanan', 160],
    'contact_map_query' => ['Kata kunci peta', 200],
    'social_instagram' => ['Instagram', 255],
    'social_facebook' => ['Facebook', 255],
    'social_youtube' => ['Kanal YouTube', 255],
    'social_tiktok' => ['TikTok', 255],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cms_require_post_token($self);
    try {
        $values = [];
        foreach ($fields as $key => [$label, $max]) {
            $values[$key] = cms_post_text($key, $max);
        }
        if ($values['contact_email'] !== '' && !filter_var($values['contact_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Format email tidak valid.');
        }
        if ($values['contact_phone'] !== '' && ppdb_normalize_whatsapp_number($values['contact_phone']) === '') {
            throw new RuntimeException('Nomor WhatsApp tidak valid. Gunakan format 08xx atau 628xx.');
        }
        foreach (['contact_website', 'social_instagram', 'social_facebook', 'social_youtube', 'social_tiktok'] as $key) {
            if (!cms_valid_url($values[$key])) {
                throw new RuntimeException($fields[$key][0].' harus berupa tautan lengkap yang diawali https://.');
            }
        }
        foreach ($values as $key => $value) {
            cms_save_setting($conn, $key, $value);
        }
        cms_save_setting($conn, 'ai_enabled', !empty($_POST['ai_enabled']) ? '1' : '0');
        cms_flash('success', 'Pengaturan situs disimpan.');
        cms_redirect($self);
    } catch (RuntimeException $exception) {
        cms_flash('error', $exception->getMessage());
        cms_redirect($self);
    }
}

$aiKeyReady = trim((string) getenv('ANTHROPIC_API_KEY')) !== '' || trim((string) getenv('ANTHROPIC_AUTH_TOKEN')) !== '';
$aiEnabled = cms_setting($conn, 'ai_enabled', '0') === '1';
$value = static fn (string $key) => h(cms_setting($conn, $key));

$activeAdminPage = 'cms-settings';
$adminPageTitle = 'Kontak & pengaturan';
$adminPageDescription = 'Kontak resmi, media sosial, dan asisten AI situs yayasan.';
require 'admin_header.php';
?>
<div class="admin-content cms-page">
    <?php cms_render_flash(); ?>
    <header class="page-heading">
        <div>
            <p class="eyebrow">Situs web</p>
            <h1>Kontak &amp; pengaturan</h1>
            <p>Kontak ini tampil di footer, halaman Kontak, dan menjadi rujukan asisten AI.</p>
        </div>
    </header>

    <form method="post" data-loading-form>
        <?php echo cms_csrf_field(); ?>
        <section class="panel">
            <div class="panel-header"><div><h2>Identitas dan kontak</h2></div></div>
            <div class="cms-form cms-grid">
                <div class="cms-field cms-field--wide"><label class="cms-label" for="site_tagline">Semboyan</label><input id="site_tagline" name="site_tagline" value="<?php echo $value('site_tagline'); ?>" maxlength="120"></div>
                <div class="cms-field cms-field--wide"><label class="cms-label" for="contact_address">Alamat</label><textarea id="contact_address" name="contact_address" rows="2" maxlength="300"><?php echo $value('contact_address'); ?></textarea></div>
                <div class="cms-field"><label class="cms-label" for="contact_phone">WhatsApp panitia</label><input id="contact_phone" name="contact_phone" value="<?php echo $value('contact_phone'); ?>" maxlength="30" inputmode="tel"></div>
                <div class="cms-field"><label class="cms-label" for="contact_email">Email</label><input id="contact_email" name="contact_email" type="email" value="<?php echo $value('contact_email'); ?>" maxlength="120"></div>
                <div class="cms-field"><label class="cms-label" for="contact_website">Situs</label><input id="contact_website" name="contact_website" value="<?php echo $value('contact_website'); ?>" maxlength="200" placeholder="https://"></div>
                <div class="cms-field"><label class="cms-label" for="contact_hours">Jam layanan</label><input id="contact_hours" name="contact_hours" value="<?php echo $value('contact_hours'); ?>" maxlength="160" placeholder="Senin–Jumat, 08.00–15.00 WIB"></div>
                <div class="cms-field cms-field--wide"><label class="cms-label" for="contact_map_query">Kata kunci peta</label><input id="contact_map_query" name="contact_map_query" value="<?php echo $value('contact_map_query'); ?>" maxlength="200" placeholder="Nama tempat atau koordinat, misalnya 4.6260,96.8420"><span class="cms-hint">Dipakai untuk peta Google di halaman Kontak. Koordinat paling akurat.</span></div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header"><div><h2>Media sosial</h2><span class="panel-subtitle">Hanya akun yang diisi yang tampil di footer.</span></div></div>
            <div class="cms-form cms-grid">
                <?php foreach (['social_instagram', 'social_facebook', 'social_youtube', 'social_tiktok'] as $key) { ?>
                    <div class="cms-field"><label class="cms-label" for="<?php echo $key; ?>"><?php echo h($fields[$key][0]); ?></label><input id="<?php echo $key; ?>" name="<?php echo $key; ?>" value="<?php echo $value($key); ?>" maxlength="255" placeholder="https://"></div>
                <?php } ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div><h2>Asisten AI tanya jawab</h2><span class="panel-subtitle">Menjawab pertanyaan pengunjung hanya dari konten situs ini.</span></div>
                <span class="status-pill <?php echo $aiEnabled && $aiKeyReady ? 'is-open' : 'is-closed'; ?>"><?php echo $aiEnabled && $aiKeyReady ? 'Aktif' : 'Nonaktif'; ?></span>
            </div>
            <div class="cms-form cms-grid">
                <label class="cms-check cms-field--wide"><input type="checkbox" name="ai_enabled" value="1"<?php echo $aiEnabled ? ' checked' : ''; ?>> Tampilkan tombol "Tanya asisten" di situs publik</label>
                <?php if (!$aiKeyReady) { ?>
                    <p class="alert-warning cms-field--wide">Kunci API belum dikonfigurasi di server. Tambahkan <code>ANTHROPIC_API_KEY</code> pada berkas <code>.env</code> atau environment Apache, lalu muat ulang halaman. Sampai saat itu tombol asisten tidak tampil.</p>
                <?php } ?>
            </div>
        </section>

        <div class="cms-actions cms-actions--sticky"><button class="button-primary" type="submit">Simpan pengaturan</button></div>
    </form>
</div>
<?php echo cms_admin_scripts(); ?>
<?php require 'admin_footer.php'; ?>
