# Penjelasan Alur Kuota Internal dan Eksternal PPDB

## Tujuan

Kuota dipisahkan agar anak dari SDM Yayasan Wakaf Cendekia memiliki alokasi penerimaan khusus, sementara pendaftar dari masyarakat umum menggunakan alokasi eksternal. Pengaturan dilakukan untuk setiap unit pendidikan dan tahun ajaran.

- **Kuota internal**: alokasi yang dikhususkan bagi anak SDM yayasan yang memenuhi kriteria.
- **Kuota eksternal**: alokasi untuk pendaftar dari masyarakat umum yang tidak menggunakan jalur internal.
- **Total daya tampung**: kuota internal ditambah kuota eksternal untuk unit dan tahun ajaran yang sama.

Kuota khusus merupakan jalur/alokasi tempat, bukan otomatis pembebasan biaya. Biaya, diskon, atau fasilitas lain perlu ditetapkan dan dikonfigurasi sebagai kebijakan terpisah.

## Alur Pengaturan oleh Admin

1. Admin memilih unit pendidikan dan tahun ajaran (mulai 2027/2028).
2. Admin mengisi jumlah kuota internal dan kuota eksternal secara terpisah.
3. Admin mengisi biaya serta rekening pembayaran yang berlaku. Jika kebijakan biaya untuk jalur internal berbeda, perbedaan tersebut perlu ditentukan tersendiri.
4. Admin memeriksa kembali jumlah kuota dan membuka pendaftaran untuk jalur yang siap menerima pendaftar.
5. Admin dapat menutup atau memperbarui jalur tertentu tanpa mengubah pengaturan unit/tahun ajaran yang lain.
6. Dashboard menampilkan penggunaan kuota internal dan eksternal secara terpisah, termasuk jumlah pendaftar dan peserta yang sudah diterima.

## Alur Pendaftaran Internal

1. Admin memverifikasi pegawai, lalu membuat hingga 150 kode 9 karakter untuk setiap unit dan tahun ajaran yang memiliki kuota internal. Setiap kode berisi huruf besar, huruf kecil, dan angka. Formulir admin tidak meminta nama SDM atau unit kerja.
2. Panitia membagikan satu kode kepada setiap SDM yang berhak. Semua SDM menggunakan satu URL bersama (`daftar.php?internal=1`), lalu memasukkan kode masing-masing. Kode berlaku satu kali dan tidak menampilkan pilihan jalur kepada orang tua/wali.
3. Server menentukan jalur internal, unit, dan tahun ajaran dari kode yang cocok di basis data; nilai-nilai ini tidak dipercaya dari isian formulir atau parameter yang dapat diubah oleh pengguna.
4. Orang tua/wali mengisi data peserta dan kontak. Bila perlu, panitia meminta data pendukung hubungan peserta dengan pegawai.
5. Setelah panitia memastikan kelayakan, pendaftaran memakai kuota internal untuk unit dan tahun ajaran yang sudah ditentukan pada undangan.
6. Jika undangan tidak valid/kedaluwarsa atau pegawai tidak memenuhi syarat, pendaftaran internal tidak dibuat. Panitia dapat memberi tautan pendaftaran eksternal bila keluarga ingin mendaftar sebagai pendaftar umum.
7. Pendaftar mengikuti tahapan lanjutan PPDB yang berlaku: komunikasi panitia, pembayaran (jika dikenakan), pengisian formulir lengkap, pemeriksaan data, dan keputusan penerimaan.

## Alur Pendaftaran Eksternal

1. Situs menampilkan tautan atau tombol **Daftar** tersendiri untuk setiap unit dan tahun ajaran yang sedang dibuka. Setiap tautan sudah terikat ke unit, tahun ajaran, dan kuota eksternal; tidak ada pilihan jalur internal/eksternal di formulir.
2. Orang tua/wali membuka tautan untuk unit dan tahun ajaran yang diinginkan, lalu mengisi data dasar peserta dan kontak.
3. Server menetapkan pendaftaran sebagai eksternal berdasarkan rute publik yang dipilih dan memeriksa ketersediaan kuota eksternal saat pendaftaran disimpan.
4. Pendaftar mengikuti tahapan lanjutan PPDB yang berlaku: komunikasi panitia, pembayaran, pengisian formulir lengkap, pemeriksaan data, dan keputusan penerimaan.

## Pencegahan Salah Jalur dan Penyalahgunaan

- Jangan menyediakan dropdown atau checkbox jalur internal/eksternal di formulir orang tua/wali. Jalur ditetapkan oleh server berdasarkan tautan masuk, bukan pilihan atau nilai POST dari browser.
- Tautan eksternal harus mengarah hanya ke kuota eksternal. Tautan internal diterbitkan setelah verifikasi SDM dan harus diikat ke pegawai serta unit/tahun ajaran yang disetujui.
- Buat token 9 karakter secara acak dan unik, dengan huruf besar, huruf kecil, dan angka; bandingkan token secara peka huruf besar/kecil, batasi percobaan kode salah, serta tandai kode sudah digunakan dalam transaksi yang sama dengan pembuatan pendaftaran.
- Jangan membagikan kode yang sama kepada beberapa pegawai. Setiap token hanya berlaku untuk satu pendaftaran dan terikat pada unit serta tahun ajaran.
- Tampilkan jalur, unit, dan tahun ajaran sebagai informasi saja jika perlu; jangan menerima perubahan nilai tersebut dari form. Setiap perubahan oleh panitia harus melalui aksi admin yang berwenang dan tercatat di audit log.
- Jika keluarga tidak memenuhi syarat internal, jangan otomatis memindahkannya ke kuota eksternal. Beri penjelasan dan tautan eksternal; keluarga mengajukan pendaftaran eksternal secara sadar.

## Aturan Penghitungan dan Kelebihan Pendaftar

- Kuota setiap jalur dihitung terpisah. Pendaftar internal tidak mengurangi kuota eksternal, dan sebaliknya.
- Pemeriksaan sisa kuota harus dilakukan kembali saat pendaftaran disimpan, bukan hanya saat halaman formulir dibuka, agar pendaftar yang masuk bersamaan tidak melebihi kuota.
- Jika kuota suatu jalur terpenuhi, halaman pendaftaran memberi tahu bahwa jalur tersebut penuh. Pendaftar tidak boleh diam-diam dibebankan ke kuota jalur yang lain.
- Pendaftar yang tidak mendapat tempat dapat masuk daftar tunggu jika kebijakan panitia mengaktifkan daftar tunggu untuk jalur tersebut.
- Sisa kuota internal tidak otomatis dialihkan ke eksternal. Pengalihan hanya dilakukan oleh admin sesuai keputusan resmi, dicatat, dan diinformasikan sebelum kuota eksternal dibuka atau diperbarui.
- Penolakan, pembatalan, atau pendaftar yang tidak menyelesaikan tahapan hanya mengembalikan tempat ke kuota apabila kebijakan dan status pendaftar memang mengizinkan tempat tersebut dibuka kembali.

## Informasi yang Perlu Disepakati Yayasan

Sebelum alur diterapkan, tetapkan secara tertulis:

1. Siapa yang memenuhi kriteria SDM internal (misalnya pegawai aktif saja atau juga kategori lain).
2. Apakah jalur internal hanya berlaku untuk anak kandung atau juga anak/wali lain yang menjadi tanggungan.
3. Data atau bukti apa yang digunakan panitia untuk memverifikasi kelayakan, serta siapa yang berwenang memeriksanya.
4. Apakah jalur internal memiliki biaya, potongan, atau tenggat pembayaran yang berbeda.
5. Apakah pendaftar internal yang tidak lolos verifikasi boleh memilih jalur eksternal.
6. Kapan dan dengan persetujuan siapa sisa kuota internal boleh dialihkan ke kuota eksternal.
7. Apakah daftar tunggu dipisahkan per jalur atau digabung, dan bagaimana urutan prioritasnya.

## Kondisi Implementasi Saat Ini

Implementasi aplikasi menyediakan kuota terpisah per jalur, tautan pendaftaran eksternal per unit/tahun, dan satu URL internal bersama dengan token 9 karakter sekali pakai. Token memuat huruf besar, huruf kecil, dan angka. Admin membuat maksimal 150 token untuk setiap unit/tahun dan bertanggung jawab memverifikasi SDM serta membagikan kode secara individual; aplikasi belum terhubung ke sistem kepegawaian atau mengautentikasi pegawai secara otomatis. Konfigurasi baru dimulai pada tahun ajaran 2027/2028; penerimaan 2026/2027 ditutup tanpa menghapus riwayatnya. Kode yang salah dibatasi hingga 10 percobaan per alamat IP selama 15 menit.
