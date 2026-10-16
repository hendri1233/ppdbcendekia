<?php

/**
 * Definisi isian formulir lengkap PPDB, dipakai bersama oleh formulir.php,
 * detail-peserta.php, pengaturan-formulir.php, dan sinkronisasi Google Sheets.
 *
 * Setiap field menyimpan ke salah satu tempat:
 * - 'detail'      : kolom tb_detail_dapodik dengan nama yang sama.
 * - 'pendaftaran' : kolom tb_pendaftaran dengan nama yang sama.
 * - 'ortu'        : kolom 'column' pada tb_orang_tua_peserta untuk 'peran'.
 *
 * Kunci tambahan:
 * - 'required' : nilai bawaan wajib/opsional; super admin dapat mengubahnya.
 * - 'locked'   : selalu wajib (pengendali percabangan atau data inti DAPODIK).
 * - 'show_if'  : daftar syarat [[nama_field, [nilai...]], ...]; semua harus terpenuhi.
 * - 'jenjang'  : hanya tampil untuk jenjang unit tertentu, mis. ['TK'].
 */

const FORMULIR_PARENT_ROLES = ['ayah' => 'Ayah', 'ibu' => 'Ibu', 'wali' => 'Wali'];

/** Kolom tb_orang_tua_peserta yang diisi dari formulir. */
const FORMULIR_PARENT_EXTRA_COLUMNS = [
    'status_hidup', 'tempat_lahir', 'nama_panggilan', 'suku', 'golongan_darah', 'status_pekerjaan', 'nama_instansi', 'alamat_instansi',
    'bidang_spesifik', 'pengeluaran_bulanan', 'hubungan_keluarga', 'no_hp', 'tinggal_bersama_ananda', 'alamat', 'dusun',
    'kampung', 'kecamatan', 'kabupaten', 'ibadah_sering_tertinggal', 'jumlah_buku', 'judul_buku_dibaca', 'hobi',
    'tokoh_dikagumi', 'riwayat_penyakit', 'nama_penyakit', 'perilaku_positif', 'perilaku_negatif', 'sosok_terdekat',
];

/** Pilihan pendidikan sesuai referensi DAPODIK (sama dengan Google Form SPMB). */
function formulir_education_options(): array
{
    return [
        'Tidak sekolah', 'PAUD', 'TK/sederajat', 'Putus SD', 'SD/sederajat', 'SMP/sederajat', 'SMA/sederajat', 'Paket A', 'Paket B', 'Paket C',
        'D1', 'D2', 'D3', 'D4', 'S1', 'Profesi', 'Sp-1', 'S2', 'S2 Terapan', 'Sp-2', 'S3', 'S3 Terapan', 'Non formal', 'Informal', 'Lainnya',
    ];
}

function formulir_income_options(): array
{
    return ['Tidak berpenghasilan', 'Kurang dari Rp500.000', 'Rp500.000 - Rp999.999', 'Rp1.000.000 - Rp1.999.999', 'Rp2.000.000 - Rp4.999.999', 'Rp5.000.000 - Rp20.000.000', 'Lebih dari Rp20.000.000'];
}

function formulir_options(array $values): array
{
    return array_combine($values, $values);
}

/** Tambahkan syarat tampil ke sekumpulan field (termasuk judul kecil). */
function formulir_with_condition(array $fields, array $conditions): array
{
    foreach ($fields as $index => $field) {
        $fields[$index]['show_if'] = array_merge($conditions, $field['show_if'] ?? []);
    }
    return $fields;
}

/** Profil pribadi orang tua/wali; seluruhnya opsional secara bawaan. */
function formulir_profile_fields(string $role, callable $ortu): array
{
    return [
        ['type' => 'heading', 'label' => 'Profil pribadi', 'hint' => 'Hanya dibaca panitia untuk mengenal keluarga ananda.'],
        $ortu('ibadah_sering_tertinggal', ['label' => 'Ibadah wajib yang masih sering tertinggal', 'maxlength' => 255, 'wide' => true]),
        $ortu('jumlah_buku', ['label' => 'Jumlah buku yang dimiliki di rumah', 'maxlength' => 50]),
        $ortu('hobi', ['label' => 'Hobi yang masih sering dilakukan', 'maxlength' => 150]),
        $ortu('judul_buku_dibaca', ['label' => 'Beberapa judul buku yang pernah dibaca', 'type' => 'textarea', 'maxlength' => 1000]),
        $ortu('tokoh_dikagumi', ['label' => 'Tokoh agama, politik, atau publik figur yang dikagumi', 'maxlength' => 255, 'wide' => true]),
        $ortu('riwayat_penyakit', ['label' => 'Riwayat penyakit yang pernah diderita', 'type' => 'select', 'options' => formulir_options(['Tidak ada', 'Ada'])]),
        $ortu('nama_penyakit', ['label' => 'Sebutkan penyakitnya', 'maxlength' => 255, 'show_if' => [[$role.'_riwayat_penyakit', ['Ada']]]]),
        $ortu('perilaku_positif', ['label' => 'Perilaku positif yang sering muncul', 'type' => 'textarea', 'maxlength' => 1000]),
        $ortu('perilaku_negatif', ['label' => 'Perilaku negatif yang sering muncul', 'type' => 'textarea', 'maxlength' => 1000]),
        $ortu('sosok_terdekat', ['label' => 'Sosok yang paling dekat secara emosional', 'maxlength' => 150, 'placeholder' => 'Sahabat, orang tua, atau pasangan', 'wide' => true]),
    ];
}

/** Isian pekerjaan dan ekonomi orang tua/wali. */
function formulir_work_fields(callable $ortu, callable $detail): array
{
    return [
        ['type' => 'heading', 'label' => 'Pendidikan dan pekerjaan'],
        $detail('pendidikan', ['label' => 'Pendidikan terakhir', 'type' => 'select', 'options' => formulir_options(formulir_education_options())]),
        $ortu('status_pekerjaan', ['label' => 'Status pekerjaan', 'maxlength' => 60, 'placeholder' => 'Contoh: PNS, wiraswasta, IRT']),
        $detail('pekerjaan', ['label' => 'Pekerjaan', 'maxlength' => 100]),
        $ortu('nama_instansi', ['label' => 'Nama instansi tempat bekerja', 'maxlength' => 150]),
        $ortu('alamat_instansi', ['label' => 'Alamat instansi tempat bekerja', 'maxlength' => 255, 'wide' => true]),
        $ortu('bidang_spesifik', ['label' => 'Bidang spesifik yang ditangani', 'maxlength' => 150, 'wide' => true]),
        $detail('penghasilan', ['label' => 'Penghasilan bulanan', 'type' => 'select', 'options' => formulir_options(formulir_income_options())]),
        $ortu('pengeluaran_bulanan', ['label' => 'Pengeluaran rata-rata per bulan', 'type' => 'select', 'options' => formulir_options(formulir_income_options())]),
    ];
}

function formulir_parent_fields(string $role): array
{
    $label = strtolower(FORMULIR_PARENT_ROLES[$role]);
    $ortu = static fn (string $column, array $field) => $field + ['name' => $role.'_'.$column, 'store' => 'ortu', 'peran' => $role, 'column' => $column];
    $detail = static fn (string $column, array $field) => $field + ['name' => $role.'_'.$column, 'store' => 'detail'];
    $bloodTypes = formulir_options(['A', 'B', 'AB', 'O', 'Belum diketahui']);
    $nameHint = 'Huruf kapital tanpa gelar (Alm., Dr., Drs., S.Pd, H.), sesuai Kartu Keluarga.';
    $nik = $detail('nik', ['label' => 'NIK '.$label, 'maxlength' => 16, 'inputmode' => 'numeric', 'pattern' => '[0-9]{16}', 'placeholder' => '16 digit angka', 'hint' => 'Pastikan tidak tertukar dengan nomor KK.']);

    if ($role !== 'wali') {
        // Ayah/ibu kandung: identitas selalu diisi; selebihnya hanya bila masih hidup.
        $alive = [[$role.'_status_hidup', ['Masih hidup']]];
        return array_merge(
            [
                $ortu('status_hidup', ['label' => 'Keadaan '.$label.' kandung', 'type' => 'select', 'locked' => true, 'options' => ['Masih hidup' => 'Masih hidup', 'Meninggal' => 'Meninggal dunia', 'Lainnya' => 'Lainnya (hilang, bercerai, dan sebagainya)'], 'hint' => 'Selain "Masih hidup", cukup isi nama, tempat lahir, tahun lahir, dan NIK sesuai KK.']),
                $detail('nama', ['label' => 'Nama lengkap '.$label, 'maxlength' => 100, 'required' => true, 'hint' => $nameHint]),
                $ortu('tempat_lahir', ['label' => 'Tempat lahir', 'maxlength' => 50, 'required' => true]),
                $detail('tahun_lahir', ['label' => 'Tahun lahir', 'type' => 'number', 'min' => 1900, 'max' => (int) date('Y'), 'numeric' => true]),
                $nik,
            ],
            formulir_with_condition(array_merge(
                [
                    $ortu('nama_panggilan', ['label' => 'Nama panggilan / sebutan di daerah', 'maxlength' => 50, 'placeholder' => $role === 'ayah' ? 'Contoh: Aman Hafidz' : 'Contoh: Inen Hafidz']),
                    $ortu('suku', ['label' => 'Suku', 'maxlength' => 50]),
                    $ortu('golongan_darah', ['label' => 'Golongan darah', 'type' => 'select', 'options' => $bloodTypes]),
                ],
                formulir_work_fields($ortu, $detail),
                formulir_profile_fields($role, $ortu)
            ), $alive)
        );
    }

    // Wali: identitas bila mempunyai wali; data lengkap bila ananda tinggal bersama wali.
    $hasGuardian = [['mempunyai_wali', ['Ya']]];
    $livesWithGuardian = [['mempunyai_wali', ['Ya']], ['wali_tinggal_bersama_ananda', ['Ya']]];
    return array_merge(
        [
            ['name' => 'mempunyai_wali', 'label' => 'Ananda mempunyai wali', 'type' => 'select', 'locked' => true, 'store' => 'detail', 'options' => formulir_options(['Tidak', 'Ya']), 'hint' => 'Wali adalah anggota keluarga terdekat yang ikut mengasuh, misalnya kakek, nenek, paman, atau bibi.'],
        ],
        formulir_with_condition([
            $ortu('tinggal_bersama_ananda', ['label' => 'Ananda sekarang tinggal bersama wali', 'type' => 'select', 'locked' => true, 'options' => formulir_options(['Ya', 'Tidak']), 'hint' => 'Jika "Ya", lengkapi seluruh data wali. Jika "Tidak", cukup identitas dan kontak.']),
            $ortu('hubungan_keluarga', ['label' => 'Ikatan keluarga dengan wali', 'type' => 'select', 'options' => formulir_options(['Kakek', 'Nenek', 'Paman', 'Bibik', 'Cicik', 'Saudara kandung', 'Lainnya'])]),
            $detail('nama', ['label' => 'Nama lengkap wali', 'maxlength' => 100, 'hint' => $nameHint]),
            $nik,
            $ortu('tempat_lahir', ['label' => 'Tempat lahir', 'maxlength' => 50]),
            $detail('tahun_lahir', ['label' => 'Tahun lahir', 'type' => 'number', 'min' => 1900, 'max' => (int) date('Y'), 'numeric' => true]),
            $ortu('suku', ['label' => 'Suku', 'maxlength' => 50]),
            $ortu('no_hp', ['label' => 'Nomor WhatsApp wali', 'type' => 'tel', 'maxlength' => 20, 'inputmode' => 'tel', 'pattern' => '(\+62|62|0)8[0-9]{8,11}', 'placeholder' => '081234567890']),
            $detail('pendidikan', ['label' => 'Pendidikan terakhir', 'type' => 'select', 'options' => formulir_options(formulir_education_options())]),
            $detail('penghasilan', ['label' => 'Penghasilan bulanan', 'type' => 'select', 'options' => formulir_options(formulir_income_options())]),
        ], $hasGuardian),
        formulir_with_condition(array_merge(
            [
                ['type' => 'heading', 'label' => 'Alamat wali'],
                $ortu('alamat', ['label' => 'Alamat jalan', 'maxlength' => 200, 'wide' => true]),
                $ortu('dusun', ['label' => 'Dusun', 'maxlength' => 100]),
                $ortu('kampung', ['label' => 'Kampung/desa', 'maxlength' => 100]),
                $ortu('kecamatan', ['label' => 'Kecamatan', 'maxlength' => 100]),
                $ortu('kabupaten', ['label' => 'Kabupaten/kota', 'maxlength' => 100]),
                $ortu('nama_panggilan', ['label' => 'Nama panggilan / sebutan di daerah', 'maxlength' => 50, 'placeholder' => 'Contoh: Inen/Aman Hafidz']),
                $ortu('golongan_darah', ['label' => 'Golongan darah', 'type' => 'select', 'options' => $bloodTypes]),
            ],
            array_values(array_filter(formulir_work_fields($ortu, $detail), static fn ($field) => !in_array($field['name'] ?? '', ['wali_pendidikan', 'wali_penghasilan'], true))),
            formulir_profile_fields($role, $ortu)
        ), $livesWithGuardian)
    );
}

/**
 * Bagian formulir data (tanpa wawancara dan dokumen), berurutan sesuai langkah.
 * Nilai 'required' di sini adalah bawaan; gunakan formulir_sections_for() untuk
 * mendapatkan aturan yang sudah disesuaikan super admin dan jenjang unit.
 */
function formulir_data_sections(): array
{
    $hasNisn = [['status_nisn', ['Sudah ada NISN']]];
    return [
        'ananda' => [
            'title' => 'Data ananda',
            'description' => 'Gunakan ejaan dan angka sesuai Kartu Keluarga dan akta kelahiran.',
            'fields' => [
                ['name' => 'kelompok_tk', 'label' => 'Ananda mendaftar ke kelompok', 'type' => 'select', 'required' => true, 'store' => 'detail', 'jenjang' => ['TK'], 'options' => ['TK A' => 'TK A (genap 4 tahun per 1 Juli)', 'TK B' => 'TK B (genap 5 tahun per 1 Juli)']],
                ['name' => 'nik', 'label' => 'NIK ananda', 'locked' => true, 'maxlength' => 16, 'inputmode' => 'numeric', 'pattern' => '[0-9]{16}', 'placeholder' => '16 digit angka', 'hint' => 'Pastikan tidak tertukar dengan nomor KK.', 'store' => 'detail'],
                ['name' => 'nomor_kk', 'label' => 'Nomor Kartu Keluarga', 'locked' => true, 'maxlength' => 16, 'inputmode' => 'numeric', 'pattern' => '[0-9]{16}', 'placeholder' => '16 digit angka', 'store' => 'detail'],
                ['name' => 'nama_panggilan', 'label' => 'Nama panggilan ananda', 'maxlength' => 50, 'store' => 'detail'],
                ['name' => 'tmp_lahir', 'label' => 'Tempat lahir', 'locked' => true, 'maxlength' => 50, 'hint' => 'Sesuai akta kelahiran.', 'store' => 'pendaftaran'],
                ['name' => 'nomor_registrasi_akta', 'label' => 'Nomor registrasi akta kelahiran', 'maxlength' => 100, 'wide' => true, 'placeholder' => 'Contoh: 1104-LT-14082017-0011 atau 1.337/Ist/2002', 'store' => 'detail'],
                ['name' => 'status_nisn', 'label' => 'NISN ananda', 'type' => 'select', 'locked' => true, 'store' => 'detail', 'options' => formulir_options(['Sudah ada NISN', 'Belum ada NISN']), 'hint' => 'NISN wajib dimiliki ananda yang naik dari TK ke jenjang berikutnya.'],
                ['name' => 'NISN', 'label' => 'Nomor NISN', 'required' => true, 'maxlength' => 10, 'inputmode' => 'numeric', 'pattern' => '[0-9]{10}', 'placeholder' => 'Contoh: 3991095271', 'store' => 'pendaftaran', 'show_if' => $hasNisn],
                ['name' => 'alasan_tanpa_nisn', 'label' => 'Penjelasan belum ada NISN', 'required' => true, 'maxlength' => 255, 'placeholder' => 'Contoh: belum terbit', 'store' => 'detail', 'show_if' => [['status_nisn', ['Belum ada NISN']]]],
                ['name' => 'agama', 'label' => 'Agama/kepercayaan', 'type' => 'select', 'locked' => true, 'store' => 'pendaftaran', 'options' => ['Islam' => 'Islam', 'Kristen' => 'Kristen', 'Katolik' => 'Katolik', 'Hindu' => 'Hindu', 'Buddha' => 'Buddha', 'Khonghucu' => 'Khonghucu', 'Kepercayaan' => 'Kepercayaan lainnya']],
                ['name' => 'kewarganegaraan', 'label' => 'Kewarganegaraan', 'type' => 'select', 'locked' => true, 'store' => 'detail', 'options' => formulir_options(['WNI', 'WNA'])],
                ['name' => 'golongan_darah', 'label' => 'Golongan darah', 'type' => 'select', 'store' => 'detail', 'options' => formulir_options(['A', 'B', 'AB', 'O', 'Belum diketahui'])],
                ['name' => 'anak_ke', 'label' => 'Anak ke- (sesuai KK)', 'type' => 'number', 'min' => 1, 'max' => 30, 'numeric' => true, 'store' => 'detail'],
                ['name' => 'jumlah_saudara_kandung', 'label' => 'Jumlah saudara kandung', 'type' => 'number', 'min' => 0, 'max' => 30, 'numeric' => true, 'hint' => 'Tidak termasuk ananda sendiri.', 'store' => 'detail'],
                ['name' => 'kebutuhan_khusus', 'label' => 'Berkebutuhan khusus', 'maxlength' => 120, 'placeholder' => 'Tidak ada jika tidak memiliki', 'wide' => true, 'store' => 'detail'],
                ['type' => 'heading', 'label' => 'Riwayat sekolah'],
                ['name' => 'jenis_pendaftaran', 'label' => 'Jenis pendaftaran', 'type' => 'select', 'locked' => true, 'store' => 'detail', 'options' => ['Siswa baru' => 'Siswa baru', 'Pindahan' => 'Pindahan / mutasi', 'Kembali aktif' => 'Kembali aktif']],
                ['name' => 'pernah_paud_tk', 'label' => 'Pernah mengikuti PAUD/TK', 'type' => 'select', 'locked' => true, 'store' => 'detail', 'options' => formulir_options(['Ya', 'Tidak', 'Belum diketahui'])],
                ['name' => 'jenjang_sekolah_asal', 'label' => 'Sekolah ananda sebelumnya', 'type' => 'select', 'store' => 'detail', 'options' => formulir_options(['Belum sekolah', 'KB IT Cendekia', 'TPA IT Cendekia', 'TK IT Cendekia', 'SD IT Cendekia', 'Sekolah lain'])],
                ['name' => 'asal_sekolah', 'label' => 'Nama sekolah sebelumnya', 'maxlength' => 100, 'store' => 'pendaftaran', 'show_if' => [['jenjang_sekolah_asal', ['Sekolah lain']]]],
                ['name' => 'npsn_sekolah_asal', 'label' => 'NPSN sekolah sebelumnya', 'maxlength' => 8, 'inputmode' => 'numeric', 'pattern' => '[0-9]{8}', 'placeholder' => '8 digit angka', 'hint' => 'TPA 70037536 · KB 70037526 · TK 69934833 · SD 69862386 · SMP 69990330', 'store' => 'detail', 'show_if' => [['jenjang_sekolah_asal', ['KB IT Cendekia', 'TPA IT Cendekia', 'TK IT Cendekia', 'SD IT Cendekia', 'Sekolah lain']]]],
            ],
        ],
        'profil' => [
            'title' => 'Kesehatan dan profil ananda',
            'description' => 'Membantu guru mengenal kebutuhan dan potensi ananda sejak awal.',
            'fields' => [
                ['name' => 'riwayat_penyakit', 'label' => 'Riwayat penyakit yang pernah diderita', 'type' => 'select', 'store' => 'detail', 'options' => formulir_options(['Tidak ada', 'Ada'])],
                ['name' => 'nama_penyakit', 'label' => 'Penyakit yang pernah diderita', 'maxlength' => 255, 'store' => 'detail', 'show_if' => [['riwayat_penyakit', ['Ada']]]],
                ['name' => 'pantangan_penyakit', 'label' => 'Pantangan / hal yang harus dihindari', 'maxlength' => 255, 'wide' => true, 'store' => 'detail', 'show_if' => [['riwayat_penyakit', ['Ada']]]],
                ['name' => 'karakter_menonjol', 'label' => 'Karakter ananda yang paling menonjol', 'maxlength' => 255, 'wide' => true, 'store' => 'detail'],
                ['name' => 'kecerdasan_menonjol', 'label' => 'Kecerdasan / gaya belajar yang paling menonjol', 'type' => 'select', 'wide' => true, 'store' => 'detail', 'options' => [
                    'Visual' => 'Visual — cepat menangkap warna, bentuk, arah, dan ruang',
                    'Auditori' => 'Auditori — lebih mudah memahami dengan mendengarkan',
                    'Kinestetik' => 'Kinestetik — belajar lewat gerak tubuh dan keterampilan tangan',
                ]],
                ['name' => 'minat_bakat', 'label' => 'Minat dan bakat yang perlu didukung', 'maxlength' => 255, 'wide' => true, 'store' => 'detail'],
                ['name' => 'hobi', 'label' => 'Hobi ananda', 'maxlength' => 150, 'store' => 'detail'],
                ['name' => 'cita_cita', 'label' => 'Cita-cita ananda', 'maxlength' => 150, 'store' => 'detail'],
            ],
        ],
        'alamat' => [
            'title' => 'Alamat dan data periodik',
            'description' => 'Alamat domisili saat ini serta data tumbuh kembang terbaru.',
            'fields' => [
                ['name' => 'alamat_jalan', 'label' => 'Alamat jalan', 'locked' => true, 'maxlength' => 200, 'wide' => true, 'store' => 'detail'],
                ['name' => 'dusun', 'label' => 'Dusun', 'maxlength' => 100, 'store' => 'detail'],
                ['name' => 'rt', 'label' => 'RT', 'maxlength' => 5, 'store' => 'detail'],
                ['name' => 'rw', 'label' => 'RW', 'maxlength' => 5, 'store' => 'detail'],
                ['name' => 'desa_kelurahan', 'label' => 'Desa/kelurahan', 'locked' => true, 'maxlength' => 100, 'store' => 'detail'],
                ['name' => 'kecamatan', 'label' => 'Kecamatan', 'locked' => true, 'maxlength' => 100, 'store' => 'detail'],
                ['name' => 'kabupaten', 'label' => 'Kabupaten/kota', 'locked' => true, 'maxlength' => 100, 'store' => 'detail'],
                ['name' => 'provinsi', 'label' => 'Provinsi', 'locked' => true, 'maxlength' => 100, 'store' => 'detail'],
                ['name' => 'kode_pos', 'label' => 'Kode pos', 'maxlength' => 5, 'inputmode' => 'numeric', 'pattern' => '[0-9]{5}', 'store' => 'detail'],
                ['name' => 'email', 'label' => 'Email orang tua', 'type' => 'email', 'maxlength' => 120, 'store' => 'detail'],
                ['name' => 'jenis_tinggal', 'label' => 'Jenis tinggal', 'type' => 'select', 'locked' => true, 'store' => 'detail', 'options' => formulir_options(['Bersama orang tua', 'Bersama wali', 'Kos', 'Asrama', 'Panti asuhan', 'Lainnya'])],
                ['name' => 'alat_transportasi', 'label' => 'Alat transportasi ke sekolah', 'type' => 'select', 'locked' => true, 'store' => 'detail', 'options' => formulir_options(['Jalan kaki', 'Sepeda', 'Sepeda motor', 'Mobil', 'Angkutan umum', 'Antar jemput', 'Lainnya'])],
                ['type' => 'heading', 'label' => 'Data periodik'],
                ['name' => 'jarak_ke_sekolah_km', 'label' => 'Jarak rumah ke sekolah (km)', 'type' => 'number', 'min' => 0, 'max' => 500, 'step' => '0.1', 'numeric' => true, 'store' => 'detail'],
                ['name' => 'waktu_tempuh_menit', 'label' => 'Waktu tempuh (menit)', 'type' => 'number', 'min' => 0, 'max' => 1440, 'numeric' => true, 'store' => 'detail'],
                ['name' => 'tinggi_badan_cm', 'label' => 'Tinggi badan (cm)', 'type' => 'number', 'min' => 30, 'max' => 250, 'step' => '0.1', 'numeric' => true, 'hint' => 'Jika belum tahu, dapat diukur di sekolah.', 'store' => 'detail'],
                ['name' => 'berat_badan_kg', 'label' => 'Berat badan (kg)', 'type' => 'number', 'min' => 1, 'max' => 300, 'step' => '0.1', 'numeric' => true, 'store' => 'detail'],
                ['name' => 'lingkar_kepala_cm', 'label' => 'Lingkar kepala (cm)', 'type' => 'number', 'min' => 20, 'max' => 100, 'step' => '0.1', 'numeric' => true, 'store' => 'detail'],
                ['name' => 'sumber_informasi', 'label' => 'Mengetahui PPDB dari', 'type' => 'select', 'wide' => true, 'store' => 'pendaftaran', 'options' => formulir_options(['Keluarga atau alumni', 'Guru atau karyawan', 'Brosur', 'Media sosial', 'Website', 'Lainnya'])],
            ],
        ],
        'ayah' => [
            'title' => 'Data ayah',
            'description' => 'Data ayah kandung sesuai Kartu Keluarga.',
            'fields' => formulir_parent_fields('ayah'),
        ],
        'ibu' => [
            'title' => 'Data ibu',
            'description' => 'Data ibu kandung sesuai Kartu Keluarga.',
            'fields' => formulir_parent_fields('ibu'),
        ],
        'wali' => [
            'title' => 'Data wali',
            'description' => 'Pilih "Tidak" jika ananda tidak mempunyai wali, lalu lanjutkan.',
            'fields' => formulir_parent_fields('wali'),
        ],
    ];
}

/** Aturan wajib dari super admin: [nama_field => bool]. */
function formulir_field_rules(mysqli $conn): array
{
    $rules = [];
    $result = mysqli_query($conn, 'SELECT nama_isian,wajib FROM tb_formulir_aturan_isian');
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rules[$row['nama_isian']] = (bool) $row['wajib'];
        }
    }
    return $rules;
}

/**
 * Bagian formulir untuk satu jenjang dengan aturan wajib yang berlaku.
 * Jenjang kosong berarti semua field (dipakai laporan dan pengaturan).
 */
function formulir_sections_for(?mysqli $conn, string $jenjang = ''): array
{
    $rules = $conn ? formulir_field_rules($conn) : [];
    $sections = formulir_data_sections();
    foreach ($sections as $sectionKey => $section) {
        $fields = [];
        foreach ($section['fields'] as $field) {
            if ($jenjang !== '' && isset($field['jenjang']) && !in_array($jenjang, $field['jenjang'], true)) {
                continue;
            }
            if (($field['type'] ?? 'text') !== 'heading') {
                $field['required'] = !empty($field['locked']) || ($rules[$field['name']] ?? !empty($field['required']));
            }
            $fields[] = $field;
        }
        $sections[$sectionKey]['fields'] = $fields;
    }
    return $sections;
}

/** Semua field input (tanpa judul kecil), diindeks berdasarkan nama. */
function formulir_input_fields(?array $sections = null): array
{
    $fields = [];
    foreach ($sections ?? formulir_data_sections() as $section) {
        foreach ($section['fields'] as $field) {
            if (($field['type'] ?? 'text') !== 'heading') {
                $fields[$field['name']] = $field;
            }
        }
    }
    return $fields;
}

/** Apakah semua syarat tampil field terpenuhi oleh isian $input. */
function formulir_condition_met(array $field, array $input): bool
{
    foreach ($field['show_if'] ?? [] as [$controller, $values]) {
        $value = $input[$controller] ?? '';
        if (!is_string($value) || !in_array(trim($value), $values, true)) {
            return false;
        }
    }
    return true;
}

function formulir_render_field(array $field, array $values): void
{
    $showIf = '';
    if (!empty($field['show_if'])) {
        $showIf = ' data-show-if="'.h(json_encode($field['show_if'], JSON_UNESCAPED_UNICODE)).'"';
    }
    $type = $field['type'] ?? 'text';
    if ($type === 'heading') {
        echo '<div class="field-subheading field-wide"'.$showIf.'><h3>'.h($field['label']).'</h3>';
        if (!empty($field['hint'])) {
            echo '<p>'.h($field['hint']).'</p>';
        }
        echo '</div>';
        return;
    }

    $name = $field['name'];
    $value = $values[$name] ?? '';
    $value = is_scalar($value) ? (string) $value : '';
    $wideClass = !empty($field['wide']) || $type === 'textarea' ? ' field-wide' : '';
    $required = !empty($field['required']) ? ' required' : '';
    echo '<div class="field'.$wideClass.'" data-field="'.h($name).'"'.$showIf.'>';
    echo '<label for="'.h($name).'">'.h($field['label']);
    echo $required !== '' ? ' <span class="required-mark" aria-hidden="true">*</span>' : ' <span class="optional-mark">Opsional</span>';
    echo '</label>';

    if ($type === 'select') {
        echo '<select id="'.h($name).'" name="'.h($name).'"'.$required.'>';
        echo '<option value="">'.h($field['placeholder'] ?? 'Pilih salah satu').'</option>';
        foreach ($field['options'] as $optionValue => $optionLabel) {
            echo '<option value="'.h($optionValue).'"'.($value === (string) $optionValue ? ' selected' : '').'>'.h($optionLabel).'</option>';
        }
        echo '</select>';
    } elseif ($type === 'textarea') {
        $maxlength = isset($field['maxlength']) ? ' maxlength="'.(int) $field['maxlength'].'"' : '';
        echo '<textarea id="'.h($name).'" name="'.h($name).'" rows="3"'.$maxlength.$required.'>'.h($value).'</textarea>';
    } else {
        $attributes = '';
        foreach (['maxlength', 'min', 'max', 'step', 'inputmode', 'pattern', 'placeholder'] as $attribute) {
            if (isset($field[$attribute])) {
                $attributes .= ' '.$attribute.'="'.h((string) $field[$attribute]).'"';
            }
        }
        echo '<input id="'.h($name).'" type="'.h($type).'" name="'.h($name).'" value="'.h($value).'"'.$attributes.$required.'>';
    }
    if (!empty($field['hint'])) {
        echo '<small class="field-hint">'.h($field['hint']).'</small>';
    }
    echo '</div>';
}

/**
 * Validasi seluruh field data berdasarkan definisinya. Field yang syarat
 * tampilnya tidak terpenuhi dilewati. Mengembalikan daftar pesan galat.
 */
function formulir_validate_fields(array $input, array $sections): array
{
    $errors = [];
    foreach ($sections as $section) {
        foreach ($section['fields'] as $field) {
            $type = $field['type'] ?? 'text';
            if ($type === 'heading' || !formulir_condition_met($field, $input)) {
                continue;
            }
            $label = $section['title'].' · '.$field['label'];
            $value = $input[$field['name']] ?? '';
            $value = is_string($value) ? trim($value) : '';
            if ($value === '') {
                if (!empty($field['required'])) {
                    $errors[] = $label.' wajib diisi.';
                }
                continue;
            }
            if ($type === 'select') {
                if (!array_key_exists($value, $field['options'])) {
                    $errors[] = 'Pilih nilai yang tersedia untuk '.$label.'.';
                }
                continue;
            }
            if (isset($field['maxlength']) && mb_strlen($value) > (int) $field['maxlength']) {
                $errors[] = $label.' maksimal '.(int) $field['maxlength'].' karakter.';
            }
            if (isset($field['pattern']) && !preg_match('/^(?:'.$field['pattern'].')$/', $value)) {
                $errors[] = 'Periksa kembali format '.$label.'.';
            }
            if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Periksa kembali alamat email.';
            }
            if ($type === 'number' && (!is_numeric($value) || (isset($field['min']) && (float) $value < $field['min']) || (isset($field['max']) && (float) $value > $field['max']))) {
                $errors[] = 'Periksa kembali nilai '.$label.'.';
            }
        }
    }
    return $errors;
}

/** Ambil isian data orang tua/wali tambahan, diindeks peran lalu kolom. */
function formulir_parent_profiles(mysqli $conn, string $registrationId): array
{
    $profiles = [];
    $stmt = mysqli_prepare($conn, 'SELECT * FROM tb_orang_tua_peserta WHERE id_pendaftaran=?');
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $profiles[$row['peran']] = $row;
    }
    mysqli_stmt_close($stmt);
    return $profiles;
}

/** Ratakan profil orang tua menjadi kunci peran_kolom, misalnya ayah_suku. */
function formulir_flatten_parent_profiles(array $profiles): array
{
    $flat = [];
    foreach ($profiles as $role => $row) {
        foreach (FORMULIR_PARENT_EXTRA_COLUMNS as $column) {
            $flat[$role.'_'.$column] = $row[$column] ?? '';
        }
    }
    return $flat;
}

/** Kode provinsi Kemendagri yang sah sebagai dua digit awal NIK dan nomor KK. */
const FORMULIR_PROVINCE_CODES = [
    '11', '12', '13', '14', '15', '16', '17', '18', '19', '21', '31', '32', '33', '34', '35', '36', '51', '52', '53',
    '61', '62', '63', '64', '65', '71', '72', '73', '74', '75', '76', '81', '82', '91', '92', '93', '94', '95', '96',
];

/**
 * Baca tanggal lahir dan jenis kelamin yang tersandi di NIK.
 * Digit 7-8 tanggal (perempuan ditambah 40), 9-10 bulan, 11-12 tahun dua digit.
 * Mengembalikan null bila bagian tanggalnya tidak masuk akal.
 */
function formulir_nik_parse(string $nik): ?array
{
    if (!preg_match('/^[0-9]{16}$/', $nik)) {
        return null;
    }
    $day = (int) substr($nik, 6, 2);
    $female = $day > 40;
    $day = $female ? $day - 40 : $day;
    $month = (int) substr($nik, 8, 2);
    if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
        return null;
    }
    return ['day' => $day, 'month' => $month, 'year2' => (int) substr($nik, 10, 2), 'female' => $female];
}

/**
 * Pemeriksaan NIK keluarga terhadap data registrasi dan isian formulir.
 * 'errors' menolak pengiriman (pasti keliru); 'flags' hanya menandai
 * formulir agar diperiksa panitia (bisa jadi data registrasi yang keliru).
 */
function formulir_identity_checks(array $input, array $registration, array $inputFields): array
{
    $errors = [];
    $flags = [];
    $value = static fn (string $name): string => is_string($input[$name] ?? null) ? trim($input[$name]) : '';
    $visible = static fn (string $name): bool => isset($inputFields[$name]) && formulir_condition_met($inputFields[$name], $input);

    $childNik = $value('nik');
    $familyCard = $value('nomor_kk');
    $niks = ['ananda' => $childNik];
    foreach (['ayah', 'ibu', 'wali'] as $role) {
        if ($visible($role.'_nik') && $value($role.'_nik') !== '') {
            $niks[$role] = $value($role.'_nik');
        }
    }

    foreach ($niks as $owner => $nik) {
        if ($nik === $familyCard && $nik !== '') {
            $errors[] = 'NIK '.$owner.' sama dengan nomor KK. Periksa kembali, keduanya berbeda walau sama-sama 16 digit.';
        }
        if (preg_match('/^[0-9]{16}$/', $nik) && !in_array(substr($nik, 0, 2), FORMULIR_PROVINCE_CODES, true)) {
            $errors[] = 'Kode wilayah pada NIK '.$owner.' tidak dikenal. Periksa dua digit pertama NIK.';
        }
    }
    if (preg_match('/^[0-9]{16}$/', $familyCard) && !in_array(substr($familyCard, 0, 2), FORMULIR_PROVINCE_CODES, true)) {
        $errors[] = 'Kode wilayah pada nomor KK tidak dikenal. Periksa dua digit pertama nomor KK.';
    }
    $owners = array_keys($niks);
    foreach ($owners as $i => $first) {
        foreach (array_slice($owners, $i + 1) as $second) {
            if ($niks[$first] !== '' && $niks[$first] === $niks[$second]) {
                $errors[] = 'NIK '.$first.' dan NIK '.$second.' tidak boleh sama.';
            }
        }
    }

    // Ananda: tanggal lahir dan jenis kelamin dari registrasi awal.
    $parsed = formulir_nik_parse($childNik);
    if (preg_match('/^[0-9]{16}$/', $childNik)) {
        $birth = !empty($registration['tgl_lahir']) ? strtotime((string) $registration['tgl_lahir']) : false;
        if ($parsed === null) {
            $flags[] = 'Bagian tanggal lahir pada NIK ananda tidak terbaca sebagai tanggal yang sah.';
        } elseif ($birth) {
            if ($parsed['day'] !== (int) date('j', $birth) || $parsed['month'] !== (int) date('n', $birth) || $parsed['year2'] !== (int) date('y', $birth)) {
                $flags[] = sprintf('Tanggal lahir pada NIK ananda (%02d-%02d-%02d) berbeda dengan tanggal lahir registrasi (%s).', $parsed['day'], $parsed['month'], $parsed['year2'], date('d-m-Y', $birth));
            }
            $registeredFemale = ($registration['jenis_kelamin'] ?? '') === 'perempuan';
            if (in_array($registration['jenis_kelamin'] ?? '', ['laki-laki', 'perempuan'], true) && $parsed['female'] !== $registeredFemale) {
                $flags[] = 'NIK ananda terbaca '.($parsed['female'] ? 'perempuan' : 'laki-laki').', sedangkan registrasi awal '.$registration['jenis_kelamin'].'.';
            }
        }
    }

    // Orang tua/wali: tahun lahir isian dan jenis kelamin yang wajar.
    foreach (['ayah', 'ibu', 'wali'] as $role) {
        if (!isset($niks[$role]) || !preg_match('/^[0-9]{16}$/', $niks[$role])) {
            continue;
        }
        $parentParsed = formulir_nik_parse($niks[$role]);
        if ($parentParsed === null) {
            $flags[] = 'Bagian tanggal lahir pada NIK '.$role.' tidak terbaca sebagai tanggal yang sah.';
            continue;
        }
        $birthYear = $value($role.'_tahun_lahir');
        if (preg_match('/^[0-9]{4}$/', $birthYear) && (int) $birthYear % 100 !== $parentParsed['year2']) {
            $flags[] = sprintf('Tahun lahir %s (%s) berbeda dengan tahun pada NIK (%02d).', $role, $birthYear, $parentParsed['year2']);
        }
        if ($role === 'ayah' && $parentParsed['female']) {
            $flags[] = 'NIK ayah terbaca sebagai NIK perempuan.';
        } elseif ($role === 'ibu' && !$parentParsed['female']) {
            $flags[] = 'NIK ibu terbaca sebagai NIK laki-laki.';
        }
    }

    return ['errors' => $errors, 'flags' => $flags];
}
