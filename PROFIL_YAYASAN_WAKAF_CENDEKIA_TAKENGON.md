# Profil Yayasan Wakaf Cendekia Takengon

Dokumen ini disusun untuk kebutuhan repositori dan konfigurasi sistem PPDB. Data yang belum terverifikasi secara resmi ditandai untuk dikonfirmasi oleh pengelola yayasan/sekolah sebelum dipublikasikan.

## Identitas Yayasan

| Field | Nilai |
|---|---|
| Nama yayasan | YAYASAN WAKAF CENDEKIA TAKENGON |
| Alamat | Jalan Pertamina–Kebet, Kampung Lemah Burbana, Kecamatan Bebesen, Kabupaten Aceh Tengah, Provinsi Aceh |
| Kode pos | 24552 |
| Pimpinan yayasan | ILAWARNI |
| Operator yayasan | HARDI SYAH HENDRA |
| Telepon/WhatsApp | 082181649543 |
| Email | wakafcendekiatakengon@gmail.com |
| Website | https://cendekiabisa.com |
| Nomor SK pendirian | 03 |
| Tanggal pendirian | 5 Januari 2022 |
| SK pengesahan badan hukum | AHU-0000410.AH.01.12 Tahun 2022 |
| Tanggal SK badan hukum | 5 Januari 2022 |
| Status nazhir wakaf uang | Terdaftar pada daftar nazhir wakaf uang BWI; kode 3.3.00369 |

## Satuan Pendidikan

Yayasan Wakaf Cendekia Takengon menaungi satuan pendidikan KB, TPA, TK, SD, dan SMP.
KB adalah Kelompok Bermain; TPA adalah Taman Penitipan Anak.

### SMP IT Cendekia Takengon

| Field | Nilai |
|---|---|
| Nama sekolah | SMP IT CENDEKIA TAKENGON |
| NPSN | 69990330 |
| Jenjang | SMP |
| Status | Swasta |
| Alamat utama | Jalan Pertamina–Kebet, Kecamatan Bebesen, Kabupaten Aceh Tengah, Aceh |
| Desa/kelurahan | Kebet (perlu diseragamkan dengan dokumen sekolah) |
| Kecamatan | Bebesen |
| Kabupaten | Aceh Tengah |
| Provinsi | Aceh |
| Kode pos | 24552 |
| Email | smpitcendekiatkn@gmail.com |
| Website yang pernah digunakan | http://smpitcendekia.blogspot.com |
| Jumlah peserta didik (indikatif) | 324 siswa; perlu pembaruan dari Dapodik sebelum publikasi |
| Akreditasi | Perlu verifikasi resmi di BAN-PDM/BAN-SM |
| Nomor telepon | Perlu konfirmasi sekolah |
| SK pendirian dan operasional | Perlu konfirmasi sekolah |

### SD IT Cendekia Takengon

| Field | Nilai |
|---|---|
| Nama sekolah | SD IT CENDEKIA TAKENGON |
| NPSN | 69862386 |
| Jenjang | SD |
| Status | Swasta |
| Alamat | Jalan Pertamina–Kebet, Dusun Pediwi, Kampung Kebet, Kecamatan Bebesen, Kabupaten Aceh Tengah, Aceh |
| Desa/kelurahan | Kebet |
| Kecamatan | Bebesen |
| Kabupaten | Aceh Tengah |
| Provinsi | Aceh |
| Kode pos | 24552 |
| Email | sditcendekia.takengon@gmail.com |
| Website yang pernah digunakan | http://sditcendekiatakengon.blogspot.com |
| Akreditasi | Perlu verifikasi resmi di BAN-PDM/BAN-SM |
| Nomor telepon | Perlu konfirmasi sekolah |
| SK pendirian dan operasional | Perlu konfirmasi sekolah |

### TK Swasta Islam Terpadu Cendekia

| Field | Nilai |
|---|---|
| Nama sekolah | TK SWASTA ISLAM TERPADU CENDEKIA |
| NPSN | 69934833 |
| Jenjang | TK |
| Status | Swasta |
| Alamat | Jalan Pertamina–Kebet, Kampung Lemah Burbana, Kecamatan Bebesen, Kabupaten Aceh Tengah, Aceh |
| Desa/kelurahan | Lemah Burbana |
| Kecamatan | Bebesen |
| Kabupaten | Aceh Tengah |
| Provinsi | Aceh |
| Kode pos | 24552 |
| Email | tksit.cendekia@gmail.com |
| Operator sekolah | SYAFARI |
| SK pendirian | 421.1/001/Disdik/2014, 7 Agustus 2014 |
| SK operasional terbaru | 421.1/4.3/015/DISDIKBUD/2023 |
| Akreditasi | B |
| Luas tanah | 2.000 m² |
| Tahun mulai operasi | 14 Juli 2014 |

### KB dan TPA IT Cendekia

| Unit | NPSN | Jenjang | Alamat, email, dan data lain |
|---|---:|---|---|
| KB IT CENDEKIA | 70037526 | KB | Perlu konfirmasi |
| TPA IT CENDEKIA | 70037536 | TPA | Perlu konfirmasi |

## Struktur Multi-Sekolah

```text
Yayasan Wakaf Cendekia Takengon
├── KB IT Cendekia (NPSN: 70037526)
├── TPA IT Cendekia (NPSN: 70037536)
├── TK Swasta Islam Terpadu Cendekia (NPSN: 69934833)
├── SD IT Cendekia Takengon (NPSN: 69862386)
└── SMP IT Cendekia Takengon (NPSN: 69990330)
```

## Kontak PPDB

| Unit | Email | Telepon |
|---|---|---|
| Pusat yayasan | wakafcendekiatakengon@gmail.com | 082181649543 |
| TK Swasta Islam Terpadu Cendekia | tksit.cendekia@gmail.com | Perlu konfirmasi |
| SD IT Cendekia Takengon | sditcendekia.takengon@gmail.com | Perlu konfirmasi |
| SMP IT Cendekia Takengon | smpitcendekiatkn@gmail.com | Perlu konfirmasi |
| KB IT Cendekia | Perlu konfirmasi | Perlu konfirmasi |
| TPA IT Cendekia | Perlu konfirmasi | Perlu konfirmasi |

## Rekomendasi Konfigurasi PPDB

- Kode internal sekolah: `KB-CEN`, `TPA-CEN`, `TK-CEN`, `SD-CEN`, dan `SMP-CEN`.
- Konfirmasikan kuota, biaya, jadwal, nomor WhatsApp, rekening pembayaran, kepala sekolah, dan dokumen persyaratan sebelum pendaftaran dibuka.
- Gunakan kontak sekolah yang telah diverifikasi untuk notifikasi pendaftar.
- Pastikan alamat SMP diseragamkan berdasarkan data Dapodik dan dokumen operasional yang berlaku.

## Sumber Verifikasi

- Data referensi Kemendikdasmen: NPSN 69934833, 69862386, dan 69990330.
- Verifikasi dan Validasi Nomor Pokok Yayasan Nasional Kemendikbud.
- Daftar Nazhir Wakaf Uang Badan Wakaf Indonesia.
- Website Yayasan Wakaf Cendekia Takengon: https://cendekiabisa.com.

## Catatan Pembaruan

Dokumen dibuat untuk kebutuhan awal repositori PPDB. Data identitas lembaga sebaiknya diverifikasi ulang oleh administrator sebelum produksi, khususnya nomor telepon sekolah, akreditasi, SK sekolah, nama kepala sekolah, kapasitas penerimaan, biaya, jadwal, dan ketentuan jalur pendaftaran.
