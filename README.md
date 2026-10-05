# PPDB Yayasan Wakaf Cendekia Takengon

Situs Penerimaan Peserta Didik Baru untuk unit TK, SD, dan SMP Islam Terpadu Cendekia di bawah Yayasan Wakaf Cendekia Takengon.

## Unit Pendidikan

| Unit | NPSN | Lokasi |
|---|---:|---|
| TK Swasta Islam Terpadu Cendekia | 69934833 | Kampung Lemah Burbana, Kecamatan Bebesen |
| SD IT Cendekia Takengon | 69862386 | Dusun Pediwi, Kampung Kebet, Kecamatan Bebesen |
| SMP IT Cendekia Takengon | 69990330 | Kecamatan Bebesen, Kabupaten Aceh Tengah |

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
5. Untuk database lama, jalankan `migrations/001_multi_unit_ppdb.sql`, `migrations/002_dapodik_data_and_receipt_tokens.sql`, `migrations/003_google_sheets_outbox.sql`, `migrations/004_quota_payment_workflow.sql`, lalu `migrations/005_whatsapp_status_tokens.sql`, dan `migrations/006_registration_initial_data_and_documents.sql` satu kali secara berurutan. Jangan impor dump instalasi baru ke database yang sedang digunakan.
6. Sesuaikan host, username, password, dan nama database pada `koneksi.php`.
7. Buka `http://localhost/ppdb-web`.
8. Buat akun admin pertama melalui `register.php` hanya jika tabel admin masih kosong.

Formulir mengumpulkan identitas, alamat, data ayah/ibu/wali, dan data periodik yang diperlukan untuk pencatatan bergaya Dapodik. NIK, nomor KK, dan informasi keluarga hanya tersedia bagi admin yang telah masuk. Bukti PDF untuk orang tua hanya berisi kode pendaftaran, nama, unit, tahun ajaran, dan tanggal daftar.

## Kuota dan Pembayaran

Admin mengatur kuota, biaya, rekening tujuan, masa pembayaran, dan status buka/tutup per unit serta tahun ajaran melalui menu **Kuota PPDB**. Nilai awal dibuat tertutup dengan kuota dan biaya nol; isi sesuai keputusan resmi yayasan sebelum membuka pendaftaran. Sistem memberi nomor antrean secara transaksional. Registrasi awal hanya meminta tujuh isian dasar: nama lengkap ananda, tanggal lahir, jenis kelamin, siapa yang mendaftarkan, serta nomor WhatsApp pendaftar, ayah, dan ibu.

Admin memeriksa data awal lalu memilih **Siapkan tautan pembayaran**; sistem membuat tautan ber-token dan menampilkan satu tombol WhatsApp untuk setiap nomor pendaftar. Admin menekan tombol tersebut, dan pesan pembayaran yang sudah terisi otomatis muncul di WhatsApp untuk dikirim secara manual. Tidak ada layanan pihak ketiga yang dipanggil: seluruh tautan dibangun di server dan dibuka lewat alamat `wa.me` bawaan WhatsApp. Tautan memakai token tersendiri sehingga tautan awal pendaftaran tetap berlaku. Peserta mengunggah bukti transfer melalui halaman status. Setelah pembayaran disetujui, admin memilih **Siapkan tautan formulir lengkap** dengan cara yang sama. Status **Diterima** hanya diberikan setelah formulir lengkap diperiksa dan kuota tersedia. Pendaftar di luar kuota berada pada daftar tunggu.

Nomor WhatsApp dinormalisasi ke format `62xxx`, dideduplikasi per peran, dan hanya nomor tersebut yang dijadikan tujuan tautan `wa.me`. Tidak ada token API, secret key, atau kredensial layanan pihak ketiga yang perlu disimpan.

Jika situs berada di balik reverse proxy atau domain publiknya berbeda dari alamat permintaan, atur environment variable `PPDB_BASE_URL` ke alamat dasar publik, misalnya `https://ppdb.example.org`.

Untuk uji lokal, `PPDB_BASE_URL` dapat diarahkan ke alamat XAMPP agar tautan yang dihasilkan tetap dapat dibuka. Hapus override tersebut sebelum deploy supaya tautan mengarah ke domain publik yang benar.

Bukti transfer disimpan di luar direktori web pada `C:\xampp\private\ppdb-payment-proofs`. Jika lokasi berbeda, atur environment variable `PPDB_PAYMENT_STORAGE` untuk Apache.

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