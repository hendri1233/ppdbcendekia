<?php
require_once __DIR__ . '/public_layout.php';

$email = cms_setting($conn, 'contact_email', 'wakafcendekiatakengon@gmail.com');
$phone = cms_setting($conn, 'contact_phone', '082181649543');
$waNumber = ppdb_normalize_whatsapp_number($phone);
$updatedAt = '30 Oktober 2026';

public_page_start($conn, [
    'title' => 'Kebijakan Privasi',
    'description' => 'Cara Yayasan Wakaf Cendekia Takengon mengumpulkan, memakai, menyimpan, dan melindungi data pendaftaran peserta didik.',
    'active' => '',
    'path' => 'kebijakan-privasi',
]);
?>
<section class="page-hero">
    <div class="shell">
        <ol class="breadcrumbs" aria-label="Breadcrumb"><li><a href="./">Beranda</a></li><li aria-current="page">Kebijakan Privasi</li></ol>
        <p class="kicker">Privasi</p>
        <h1>Kebijakan Privasi</h1>
        <p>Berlaku untuk situs <?= h(PUBLIC_SITE_NAME) ?> dan layanan SPMB (penerimaan murid baru). Diperbarui <?= h($updatedAt) ?>.</p>
    </div>
</section>

<section class="section">
    <div class="shell">
        <div class="prose privacy-policy">
            <p>Yayasan Wakaf Cendekia Takengon ("yayasan", "kami") menghormati privasi Ayah/Bunda dan ananda. Halaman ini menjelaskan data apa yang kami kumpulkan melalui situs ini, untuk apa data itu dipakai, di mana disimpan, dan hak yang Ayah/Bunda miliki.</p>

            <h2>1. Data yang kami kumpulkan</h2>
            <ul>
                <li><strong>Registrasi awal:</strong> nama, tanggal lahir, dan jenis kelamin ananda; nama pendaftar; nomor WhatsApp pendaftar, ayah, dan ibu; serta unit yang dipilih.</li>
                <li><strong>Formulir lengkap:</strong> data peserta didik sesuai kebutuhan Dapodik (antara lain NIK, NISN, tempat dan tanggal lahir, agama, alamat, dan data kesehatan dasar), data orang tua atau wali (antara lain NIK, pendidikan, pekerjaan, dan penghasilan), serta jawaban kuisioner unit.</li>
                <li><strong>Dokumen:</strong> foto peserta didik, NISN, Kartu Keluarga, Akta Kelahiran, KTP ayah dan ibu, dan bukti transfer biaya pendaftaran.</li>
                <li><strong>Data teknis:</strong> cookie sesi untuk menjaga keamanan formulir dan login admin, serta catatan kunjungan halaman pendaftaran (pengenal pengunjung yang disamarkan dan perkiraan kota dari alamat IP) untuk statistik internal.</li>
            </ul>

            <h2>2. Tujuan penggunaan data</h2>
            <ul>
                <li>Memproses pendaftaran, verifikasi pembayaran, seleksi, dan penerimaan peserta didik baru.</li>
                <li>Menghubungi Ayah/Bunda melalui WhatsApp terkait status pendaftaran.</li>
                <li>Mengisi data pokok pendidikan (Dapodik) dan administrasi sekolah bagi peserta didik yang diterima.</li>
                <li>Menyusun statistik internal penerimaan tanpa menampilkan identitas pribadi.</li>
            </ul>
            <p>Kami tidak menjual data, tidak memakainya untuk iklan, dan tidak membagikannya kepada pihak lain di luar keperluan di atas.</p>

            <h2>3. Penyimpanan dan layanan pihak ketiga</h2>
            <ul>
                <li>Data dan dokumen disimpan di server situs ini; dokumen disimpan di folder privat yang tidak dapat diakses publik.</li>
                <li>Untuk administrasi panitia, rekap data disalin ke <strong>Google Sheets</strong> dan salinan dokumen ke <strong>Google Drive</strong> milik akun yayasan. Berkas tersebut tidak dibagikan secara publik dan hanya dapat dibuka oleh panitia yang diberi akses.</li>
                <li>Pesan status pendaftaran dikirim melalui layanan gateway WhatsApp.</li>
                <li>Asisten tanya jawab di situs meneruskan pertanyaan yang Ayah/Bunda ketik ke penyedia layanan AI untuk menyusun jawaban. Mohon tidak menuliskan data pribadi di asisten tersebut.</li>
            </ul>

            <h2>4. Penggunaan data pengguna Google</h2>
            <p>Fitur salinan dokumen terhubung ke akun Google Drive milik yayasan dengan izin <code>drive.file</code>. Dengan izin ini, aplikasi hanya dapat membuat dan mengelola folder serta berkas yang dibuatnya sendiri; aplikasi tidak dapat membaca berkas lain di Drive yayasan. Izin tersebut hanya dipakai untuk mengunggah dokumen pendaftaran ke folder per unit dan menautkannya di spreadsheet panitia.</p>
            <p>Penggunaan dan pemindahan informasi yang diterima dari Google API mematuhi <a href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener">Kebijakan Data Pengguna Layanan Google API</a>, termasuk ketentuan Penggunaan Terbatas (Limited Use). Data tersebut tidak dipakai untuk iklan dan tidak dibagikan kepada pihak lain. Akses dapat dicabut kapan saja oleh yayasan melalui dasbor admin atau halaman izin akun Google.</p>

            <h2>5. Keamanan</h2>
            <p>Situs memakai koneksi terenkripsi (HTTPS). Panel admin dilindungi kata sandi dan pembatasan percobaan masuk; admin unit hanya dapat melihat pendaftar unitnya sendiri. Tautan formulir dan bukti pembayaran memakai kode unik yang hanya dikirim kepada pendaftar.</p>

            <h2>6. Lama penyimpanan</h2>
            <p>Data peserta didik yang diterima disimpan selama ananda bersekolah di unit yayasan dan sesudahnya sesuai kewajiban arsip sekolah. Data pendaftar yang tidak melanjutkan atau tidak diterima dapat dihapus atas permintaan, atau dihapus panitia setelah proses penerimaan tahun ajaran tersebut selesai.</p>

            <h2>7. Hak Ayah/Bunda</h2>
            <p>Ayah/Bunda dapat meminta salinan, perbaikan, atau penghapusan data pendaftaran dengan menghubungi panitia. Perbaikan data formulir juga dapat diajukan melalui panitia selama masa pendaftaran.</p>

            <h2>8. Kontak</h2>
            <p>Pertanyaan tentang kebijakan ini dapat disampaikan ke email <a href="mailto:<?= h($email) ?>"><?= h($email) ?></a> atau WhatsApp panitia <a href="https://wa.me/<?= h($waNumber ?: '6282181649543') ?>" rel="noopener"><?= h($phone) ?></a>.</p>

            <h2>9. Perubahan kebijakan</h2>
            <p>Kebijakan ini dapat diperbarui sewaktu-waktu. Tanggal pembaruan terakhir tercantum di bagian atas halaman.</p>
        </div>
    </div>
</section>
<?php public_page_end($conn); ?>
