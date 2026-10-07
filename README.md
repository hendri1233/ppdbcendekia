# PPDB Yayasan Wakaf Cendekia Takengon

Situs Penerimaan Peserta Didik Baru untuk unit KB, TPA, TK, SD, dan SMP Cendekia di bawah Yayasan Wakaf Cendekia Takengon.

## Unit Pendidikan

| Unit | NPSN | Lokasi |
|---|---:|---|
| SMP IT CENDEKIA TAKENGON | 69990330 | Kecamatan Bebesen, Kabupaten Aceh Tengah |
| SD IT CENDEKIA TAKENGON | 69862386 | Dusun Pediwi, Kampung Kebet, Kecamatan Bebesen |
| TK SWASTA ISLAM TERPADU CENDEKIA | 69934833 | Kampung Lemah Burbana, Kecamatan Bebesen |
| KB IT CENDEKIA | 70037526 | Belum dikonfirmasi |
| TPA IT CENDEKIA | 70037536 | Belum dikonfirmasi |

Alamat yayasan: Jalan Pertamina–Kebet, Kampung Lemah Burbana, Kecamatan Bebesen, Kabupaten Aceh Tengah, Aceh 24552.

Kontak yayasan: 0821-8164-9543, wakafcendekiatakengon@gmail.com, [cendekiabisa.com](https://cendekiabisa.com).

## Teknologi

- PHP
- MySQL/MariaDB
- HTML and CSS
- Dompdf for downloadable PDF receipts
- Google API PHP client for Sheets synchronization

## Menjalankan Secara Lokal

1. Pastikan Apache, PHP, dan MySQL/MariaDB tersedia, misalnya melalui XAMPP.
2. Jalankan `composer install` untuk memasang Dompdf dan Google API PHP client.
3. Buat database bernama `ppdb_cendekia`.
4. Untuk instalasi baru, impor `ppdb_cendekia.sql`.
5. Untuk database lama, jalankan migrasi `001_multi_unit_ppdb.sql` sampai `011_ppdb_materials_and_questionnaires.sql` satu kali secara berurutan. Migrasi 007 memisahkan kuota, 008 menambahkan token internal sekali pakai, 009 menambahkan unit KB/TPA serta konfigurasi mulai tahun ajaran 2027/2028, 010 mengizinkan penggunaan ulang token setelah persetujuan admin dan mencatat riwayatnya, dan 011 menambahkan materi PPDB serta kuisioner per unit/tahun. Migrasi 009 menutup penerimaan dan mencabut token yang belum digunakan untuk tahun sebelum 2027/2028, tanpa menghapus data historis. Jangan impor dump instalasi baru ke database yang sedang digunakan.
6. Sesuaikan host, username, password, dan nama database pada `koneksi.php`.
7. Buka `http://localhost/ppdb-web`.
8. Buat akun admin pertama melalui `register.php` hanya jika tabel admin masih kosong.

Formulir mengumpulkan identitas, alamat, data ayah/ibu/wali, dan data periodik yang diperlukan untuk pencatatan bergaya Dapodik. Umur peserta dihitung terhadap 30 Juni pada tahun awal ajaran (misalnya, 30/06/2027 untuk 2027/2028), ditampilkan saat tanggal lahir diisi, lalu dicantumkan di halaman berhasil dan detail peserta admin. NIK, nomor KK, dan informasi keluarga hanya tersedia bagi admin yang telah masuk. Bukti PDF untuk orang tua hanya berisi kode pendaftaran, nama, unit, tahun ajaran, dan tanggal daftar.

## Kuota dan Pembayaran

Admin mengatur kuota internal dan eksternal secara terpisah, biaya, rekening tujuan, masa pembayaran, dan status buka/tutup tiap jalur per unit serta tahun ajaran melalui menu **Kuota PPDB**. Tahun ajaran yang dapat dikonfigurasi dimulai dari 2027/2028. Formulir pendaftaran eksternal langsung menampilkan registrasi awal; pendaftar memilih pasangan unit/tahun ajaran yang kuotanya masih terbuka. Jalur internal memakai satu URL bersama (`daftar.php?internal=1`); admin membuat hingga 150 token acak sepanjang 9 karakter per unit/tahun. Setiap token mengandung huruf besar, huruf kecil, dan angka, serta dibagikan kepada satu SDM terverifikasi. Dari menu Kuota PPDB, admin dapat mengunduh PDF token aktif secara terpisah untuk tiap unit/tahun agar dibagikan kepada unit terkait. PDF berisi informasi rahasia dan tidak boleh dibagikan kepada publik. Token yang dicabut bisa diaktifkan kembali dengan persetujuan admin; token yang telah digunakan juga dapat disetujui untuk digunakan kembali, dan persetujuannya dicatat beserta admin serta waktunya. Token menentukan unit/tahun di server, berlaku satu kali per siklus aktif, dan ditandai terpakai dalam transaksi pendaftaran. Percobaan kode yang salah dibatasi 10 kali per alamat IP dalam 15 menit. Nilai awal dibuat tertutup dengan kuota dan biaya nol; isi sesuai keputusan resmi yayasan sebelum membuka pendaftaran. Sistem memberi nomor antrean secara transaksional dan memeriksa kuota sesuai jalur. Registrasi awal hanya meminta tujuh isian dasar: nama lengkap ananda, tanggal lahir, jenis kelamin, siapa yang mendaftarkan, serta nomor WhatsApp pendaftar, ayah, dan ibu.

Admin memeriksa data awal lalu memilih **Siapkan tautan pembayaran**; sistem membuat tautan ber-token dan menampilkan satu tombol WhatsApp untuk setiap nomor pendaftar. Admin menekan tombol tersebut, dan pesan pembayaran yang sudah terisi otomatis muncul di WhatsApp untuk dikirim secara manual. Tidak ada layanan pihak ketiga yang dipanggil: seluruh tautan dibangun di server dan dibuka lewat alamat `wa.me` bawaan WhatsApp. Tautan memakai token tersendiri sehingga tautan awal pendaftaran tetap berlaku. Peserta mengunggah bukti transfer melalui halaman status. Setelah pembayaran disetujui, admin memilih **Siapkan tautan formulir lengkap** dengan cara yang sama. Status **Diterima** hanya diberikan setelah formulir lengkap diperiksa dan kuota tersedia. Pendaftar di luar kuota berada pada daftar tunggu.

Nomor WhatsApp dinormalisasi ke format `62xxx`, dideduplikasi per peran, dan hanya nomor tersebut yang dijadikan tujuan tautan `wa.me`. Tidak ada token API, secret key, atau kredensial layanan pihak ketiga yang perlu disimpan.

Jika situs berada di balik reverse proxy atau domain publiknya berbeda dari alamat permintaan, atur environment variable `PPDB_BASE_URL` ke alamat dasar publik, misalnya `https://ppdb.example.org`.

Untuk uji lokal, `PPDB_BASE_URL` dapat diarahkan ke alamat XAMPP agar tautan yang dihasilkan tetap dapat dibuka. Hapus override tersebut sebelum deploy supaya tautan mengarah ke domain publik yang benar.

Bukti transfer disimpan di luar direktori web pada `C:\xampp\private\ppdb-payment-proofs`. Jika lokasi berbeda, atur environment variable `PPDB_PAYMENT_STORAGE` untuk Apache.

## Materi dan Kuisioner PPDB

Di menu admin **Materi & Kuisioner**, siapkan brosur, rincian biaya, SOP, dan sedikitnya satu kuisioner untuk setiap pasangan unit/tahun ajaran. Materi dapat disimpan lebih dahulu secara terpisah dari kuisioner; formulir pendaftar baru dapat dilanjutkan setelah ketiga materi dan kuisioner untuk unit/tahun tersebut tersedia. Setelah mengonfirmasi data awal, pendaftar membaca ketiga materi, mengisi kuisioner, lalu melengkapi formulir. Jawaban kuisioner tersimpan bersama pendaftaran dan dapat dilihat pada detail peserta admin.

Materi dapat diketik dengan editor format teks atau diunggah sebagai PDF, JPG, PNG, DOC, DOCX, atau PSD. File asli disimpan privat di luar direktori web. Berkas DOC/DOCX/PSD memerlukan file pratinjau PDF/JPG/PNG terpisah karena server tidak mengonversinya; file pratinjau PDF atau gambar ditampilkan langsung di halaman pendaftar. PDF pada layar HP dirender langsung di halaman menggunakan PDF.js lokal, sedangkan desktop tetap menggunakan pratinjau tertanam; aset PDF.js beserta lisensinya tersedia di `assets/pdfjs/`.

Kuisioner dapat dibuat manual atau diimpor dari XLSX/CSV dengan kolom `Pertanyaan`, `Tipe`, `Pilihan`, dan opsional `Wajib`. Tipe yang didukung: `Teks`, `Pilihan tunggal`, dan `Pilihan ganda`; pisahkan opsi jawaban dengan titik koma, misalnya `Ya; Tidak`. File `.xls` lama tidak didukung. Impor XLSX memerlukan ekstensi PHP `ZipArchive` dan `SimpleXML`.

## Google Sheets

Dashboard admin mengarah ke spreadsheet milik `yayasanwakafcendikia@gmail.com`. Pendaftaran baru otomatis masuk antrean sinkron dan dicoba setelah tersimpan. Gunakan tombol **Sinkronkan antrean** untuk retry atau backfill. Sistem membuat tab `Data Peserta Didik` jika belum ada dan tidak menimpa tab lain.

Untuk mengaktifkan API:

1. Di Google Cloud, aktifkan Google Sheets API dan buat service account.
2. Buat key JSON service account dan simpan di luar direktori web, misalnya `C:\xampp\private\ppdb-sheets-service-account.json`.
3. Atur environment variable Windows `GOOGLE_APPLICATION_CREDENTIALS` ke path JSON tersebut, lalu restart Apache.
4. Bagikan spreadsheet kepada alamat email service account sebagai Editor. Spreadsheet tetap dimiliki akun yayasan.
5. Buka dashboard admin dan jalankan sinkronisasi antrean. Pendaftar lama yang masuk antrean akan ikut di-backfill.

Sheet berisi NIK, nomor KK, NISN, alamat, dan data keluarga. Batasi akses spreadsheet hanya untuk pihak berwenang. Jangan unggah key service account ke repository atau direktori publik.

Dump SQL berisi struktur tabel dan referensi tiga unit pendidikan, tanpa akun contoh atau data pendaftar. Migrasi mempertahankan semua pendaftar/admin, mengarsipkan data nilai lama, dan menandai pendaftar lama sebagai belum dipetakan ke unit. Data jadwal, kuota, biaya, dan persyaratan PPDB belum tersedia pada profil; konfirmasikan kepada panitia sebelum pendaftaran dipublikasikan.

Simpan cadangan database secara aman karena berisi data kependudukan dan keluarga. Jangan menaruh cadangan database di dalam direktori web publik.

## Catatan Profil

Gunakan `PROFIL_YAYASAN_WAKAF_CENDEKIA_TAKENGON.md` sebagai rujukan identitas dan alamat. Data yang ditandai perlu verifikasi pada profil tidak ditampilkan sebagai fakta pada situs.