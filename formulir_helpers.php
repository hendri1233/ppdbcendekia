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
 * - 'wajib_jenjang' : pada jenjang ini field selalu tampil dan selalu wajib
 *                     (syarat tampil diabaikan), mis. NISN untuk SD dan SMP.
 */

const FORMULIR_PARENT_ROLES = ['ayah' => 'Ayah', 'ibu' => 'Ibu', 'wali' => 'Wali'];

/** Kolom tb_orang_tua_peserta yang diisi dari formulir. */
const FORMULIR_PARENT_EXTRA_COLUMNS = [
    'status_hidup', 'tempat_lahir', 'gelar', 'nama_panggilan', 'suku', 'golongan_darah', 'status_pekerjaan', 'nama_instansi', 'alamat_instansi',
    'bidang_spesifik', 'pengeluaran_bulanan', 'hubungan_keluarga', 'no_hp', 'tinggal_bersama_ananda', 'alamat', 'dusun',
    'provinsi', 'kabupaten', 'kecamatan', 'kampung', 'ibadah_sering_tertinggal', 'jumlah_buku', 'judul_buku_dibaca', 'hobi',
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

/** Isian pekerjaan dan ekonomi orang tua/wali. */
function formulir_work_fields(callable $ortu, callable $detail, string $role): array
{
    $jobs = [
        'Karyawan Swasta', 'Pedagang Kecil', 'Pedagang Besar', 'Wiraswasta', 'Wirausaha',
        'Buruh', 'Pensiunan', 'Tenaga Kerja Indonesia', 'Sudah Meninggal', 'Karyawan BUMN',
        'TNI', 'PNS', 'POLRI', 'Guru PPPK', 'BUMN', 'Tidak dapat diterapkan', 'IRT', 'PETANI',
    ];
    $jobsWithInstitution = [
        'Karyawan Swasta', 'Buruh', 'Tenaga Kerja Indonesia', 'Karyawan BUMN',
        'TNI', 'PNS', 'POLRI', 'Guru PPPK', 'BUMN',
    ];

    return array_merge([
        ['type' => 'heading', 'label' => 'Pendidikan dan pekerjaan'],
        $detail('pendidikan', ['label' => 'Pendidikan terakhir', 'type' => 'select', 'options' => formulir_options(formulir_education_options())]),
        $ortu('status_pekerjaan', ['label' => 'Status kepegawaian', 'maxlength' => 60, 'placeholder' => 'Contoh: tetap, kontrak, atau honorer']),
        $detail('pekerjaan', ['label' => 'Pekerjaan', 'type' => 'select', 'options' => formulir_options($jobs)]),
    ], formulir_with_condition([
        $ortu('nama_instansi', ['label' => 'Nama instansi tempat bekerja', 'maxlength' => 150]),
        $ortu('alamat_instansi', ['label' => 'Alamat instansi tempat bekerja', 'maxlength' => 255, 'wide' => true]),
        $ortu('bidang_spesifik', ['label' => 'Bidang spesifik yang ditangani', 'maxlength' => 150, 'wide' => true]),
    ], [[$role.'_pekerjaan', $jobsWithInstitution]]), [
        $detail('penghasilan', ['label' => 'Penghasilan bulanan', 'type' => 'select', 'options' => formulir_options(formulir_income_options())]),
        $ortu('pengeluaran_bulanan', ['label' => 'Pengeluaran rata-rata per bulan', 'type' => 'select', 'options' => formulir_options(formulir_income_options())]),
    ]);
}

function formulir_parent_fields(string $role): array
{
    $label = strtolower(FORMULIR_PARENT_ROLES[$role]);
    $ortu = static fn (string $column, array $field) => $field + ['name' => $role.'_'.$column, 'store' => 'ortu', 'peran' => $role, 'column' => $column];
    $detail = static fn (string $column, array $field) => $field + ['name' => $role.'_'.$column, 'store' => 'detail'];
    $bloodTypes = formulir_options(['A', 'B', 'AB', 'O', 'Belum diketahui']);
    $nameHint = 'Nama lengkap sesuai Kartu Keluarga, tanpa gelar akademik.';
    $nik = $detail('nik', ['label' => 'NIK '.$label.' (sesuai KTP)', 'maxlength' => 16, 'inputmode' => 'numeric', 'pattern' => '[0-9]{16}', 'placeholder' => '16 digit angka', 'hint' => 'Masukkan 16 digit NIK yang tercantum pada KTP.']);

    if ($role !== 'wali') {
        $alive = [[$role.'_status_hidup', ['Masih hidup']]];
        return array_merge(
            [
                $detail('nama', ['label' => 'Nama lengkap '.$label, 'maxlength' => 100, 'required' => true, 'hint' => $nameHint]),
                $ortu('status_hidup', ['label' => 'Keadaan '.$label.' kandung', 'type' => 'select', 'locked' => true, 'options' => ['Masih hidup' => 'Masih hidup', 'Meninggal dunia' => 'Meninggal/lainnya']]),
            ],
            formulir_with_condition([
                $ortu('gelar', ['label' => 'Gelar akademik '.$label, 'maxlength' => 80, 'placeholder' => 'Contoh: S.Pd., M.Pd.']),
            ], $alive),
            [
                $ortu('tempat_lahir', ['label' => 'Tempat lahir (sesuai KTP)', 'maxlength' => 50, 'required' => true]),
                $detail('tahun_lahir', ['label' => 'Tahun lahir (sesuai KTP)', 'type' => 'number', 'min' => 1900, 'max' => (int) date('Y'), 'numeric' => true]),
                $nik,
            ],
            formulir_with_condition([
                $ortu('nama_panggilan', ['label' => 'Nama panggilan / sebutan di daerah', 'maxlength' => 50, 'placeholder' => $role === 'ayah' ? 'Contoh: Aman Hafidz' : 'Contoh: Inen Hafidz']),
                $ortu('suku', ['label' => 'Suku', 'maxlength' => 50]),
                $ortu('golongan_darah', ['label' => 'Golongan darah', 'type' => 'select', 'options' => $bloodTypes]),
            ], $alive),
            formulir_with_condition(formulir_work_fields($ortu, $detail, $role), $alive),
        );
    }

    // Wali: identitas bila mempunyai wali; data lengkap bila ananda tinggal bersama wali.
    $livesWithGuardian = [['jenis_tinggal', ['Bersama wali']]];
    return array_merge(
        formulir_with_condition([
            $detail('nama', ['label' => 'Nama lengkap wali', 'maxlength' => 100, 'required' => true, 'hint' => $nameHint]),
        ], []),
        formulir_with_condition([
            $ortu('gelar', ['label' => 'Gelar akademik wali', 'maxlength' => 80, 'placeholder' => 'Contoh: S.Pd., M.Pd.']),
        ], $livesWithGuardian),
        formulir_with_condition([
            $ortu('tempat_lahir', ['label' => 'Tempat lahir (sesuai KTP)', 'maxlength' => 50, 'required' => true]),
            $detail('tahun_lahir', ['label' => 'Tahun lahir (sesuai KTP)', 'type' => 'number', 'min' => 1900, 'max' => (int) date('Y'), 'numeric' => true]),
            $nik,
        ], []),
        formulir_with_condition([
            $ortu('hubungan_keluarga', ['label' => 'Ikatan keluarga dengan wali', 'type' => 'select', 'options' => formulir_options(['Kakek', 'Nenek', 'Paman', 'Bibik', 'Cicik', 'Saudara kandung', 'Lainnya'])]),
            $ortu('suku', ['label' => 'Suku', 'maxlength' => 50]),
            $ortu('no_hp', ['label' => 'Nomor WhatsApp wali', 'type' => 'tel', 'maxlength' => 20, 'inputmode' => 'tel', 'pattern' => '(\+62|62|0)8[0-9]{8,11}', 'placeholder' => '081234567890']),
            $detail('pendidikan', ['label' => 'Pendidikan terakhir', 'type' => 'select', 'options' => formulir_options(formulir_education_options())]),
            $detail('penghasilan', ['label' => 'Penghasilan bulanan', 'type' => 'select', 'options' => formulir_options(formulir_income_options())]),
        ], $livesWithGuardian),
        formulir_with_condition(array_merge(
            [
                ['type' => 'heading', 'label' => 'Alamat wali'],
                $ortu('provinsi', ['label' => 'Provinsi', 'type' => 'region', 'region_level' => 'province', 'region_group' => 'wali', 'placeholder' => 'Pilih provinsi', 'maxlength' => 100]),
                $ortu('kabupaten', ['label' => 'Kabupaten/kota', 'type' => 'region', 'region_level' => 'regency', 'region_group' => 'wali', 'placeholder' => 'Pilih kabupaten/kota', 'maxlength' => 100]),
                $ortu('kecamatan', ['label' => 'Kecamatan', 'type' => 'region', 'region_level' => 'district', 'region_group' => 'wali', 'placeholder' => 'Pilih kecamatan', 'maxlength' => 100]),
                $ortu('kampung', ['label' => 'Desa/kelurahan', 'type' => 'region', 'region_level' => 'village', 'region_group' => 'wali', 'placeholder' => 'Pilih desa/kelurahan', 'maxlength' => 100]),
                $ortu('alamat', ['label' => 'Alamat jalan', 'maxlength' => 200, 'wide' => true]),
                $ortu('dusun', ['label' => 'Dusun', 'maxlength' => 100]),
                $ortu('nama_panggilan', ['label' => 'Nama panggilan / sebutan di daerah', 'maxlength' => 50, 'placeholder' => 'Contoh: Inen/Aman Hafidz']),
                $ortu('golongan_darah', ['label' => 'Golongan darah', 'type' => 'select', 'options' => $bloodTypes]),
            ],
            array_values(array_filter(formulir_work_fields($ortu, $detail, $role), static fn ($field) => !in_array($field['name'] ?? '', ['wali_pendidikan', 'wali_penghasilan'], true))),
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
                // KB, TPA, TK boleh belum memiliki NISN; SD dan SMP wajib mengisi NISN.
                ['name' => 'status_nisn', 'label' => 'NISN ananda', 'type' => 'select', 'locked' => true, 'store' => 'detail', 'jenjang' => ['KB', 'TPA', 'TK'], 'options' => formulir_options(['Sudah ada NISN', 'Belum ada NISN']), 'hint' => 'Jenjang KB, TPA, dan TK boleh belum memiliki NISN.'],
                ['name' => 'NISN', 'label' => 'Nomor NISN', 'required' => true, 'wajib_jenjang' => ['SD', 'SMP'], 'maxlength' => 10, 'inputmode' => 'numeric', 'pattern' => '[0-9]{10}', 'placeholder' => 'Contoh: 3991095271', 'hint' => 'NISN 10 digit dari sekolah sebelumnya. Wajib untuk pendaftar SD dan SMP.', 'store' => 'pendaftaran', 'show_if' => $hasNisn],
                ['name' => 'alasan_tanpa_nisn', 'label' => 'Penjelasan belum ada NISN', 'required' => true, 'maxlength' => 255, 'placeholder' => 'Contoh: belum terbit', 'store' => 'detail', 'jenjang' => ['KB', 'TPA', 'TK'], 'show_if' => [['status_nisn', ['Belum ada NISN']]]],
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
                ['name' => 'kecerdasan_menonjol', 'label' => 'Kecerdasan / gaya belajar yang paling menonjol', 'type' => 'select', 'wide' => true, 'store' => 'detail', 'image' => ['src' => 'img/gaya-belajar.png', 'alt' => 'Ilustrasi tiga gaya belajar: visual, auditori, dan kinestetik', 'width' => 820, 'height' => 509], 'options' => [
                    'Visual' => 'Visual — cepat menangkap warna, bentuk, arah, dan ruang',
                    'Auditori' => 'Auditori — lebih mudah memahami dengan mendengarkan',
                    'Kinestetik' => 'Kinestetik — belajar lewat gerak tubuh dan keterampilan tangan',
                ]],
                ['name' => 'minat_bakat', 'label' => 'Minat dan bakat yang perlu didukung', 'maxlength' => 255, 'wide' => true, 'store' => 'detail'],
                ['name' => 'hobi', 'label' => 'Hobi ananda', 'type' => 'select', 'store' => 'detail', 'options' => formulir_options([
                    'Olah Raga', 'Kesenian', 'Membaca', 'Menulis', 'Traveling', 'Lainnya', 'Fotografi', 'Fitness', 'Belanja',
                    'Menggambar', 'Bermain Musik', 'Mendaki', 'Jogging', 'Bermain Gitar', 'Bermain Bola', 'Bermain Bulu Tangkis',
                    'Bermain Bola Tenis', 'Bermain Biola', 'Bermain Piano', 'Berlari', 'Berkemah', 'Memancing', 'Berselancar',
                    'Bermain Boneka', 'Makan', 'Menjahit', 'Main Puzzle', 'Mewarnai',
                ])],
                ['name' => 'cita_cita', 'label' => 'Cita-cita ananda', 'type' => 'select', 'store' => 'detail', 'options' => formulir_options([
                    'PNS / Pegawai Negeri Sipil', 'TNI/Polri', 'Guru', 'Dokter', 'Politikus', 'Wiraswasta', 'Seni/Lukis/Artis/Sejenis',
                    'Lainnya', 'Penghafal Al-Qur\'an', 'Atlet E-Sport Profesional', 'Atlet', 'Content Creator', 'Vloger', 'Koki',
                    'Pendeta', 'Perawat', 'Pilot', 'Pembalap', 'Atlit Olahraga', 'Pengacara', 'Da\'i / Ustadz',
                    'Entertainer / Pekerja Seni', 'Wartawan', 'Pengusaha / Bisnismen', 'Penulis', 'Penyiar Radio',
                    'Pembawa Acara / Master Ceremony', 'Polisi', 'Pemadam Kebakaran', 'Astronot', 'Masinis Kereta Api',
                    'Perawat / Suster', 'Bidan', 'Presiden', 'Translator', 'Designer', 'Pelaut', 'Arsitek', 'Dosen',
                ])],
            ],
        ],
        'alamat' => [
            'title' => 'Alamat dan data periodik',
            'description' => 'Alamat domisili saat ini serta data tumbuh kembang terbaru.',
            'fields' => [
                ['name' => 'provinsi', 'label' => 'Provinsi', 'type' => 'region', 'region_level' => 'province', 'placeholder' => 'Pilih provinsi', 'locked' => true, 'maxlength' => 100, 'store' => 'detail'],
                ['name' => 'kabupaten', 'label' => 'Kabupaten/kota', 'type' => 'region', 'region_level' => 'regency', 'placeholder' => 'Pilih kabupaten/kota', 'locked' => true, 'maxlength' => 100, 'store' => 'detail'],
                ['name' => 'kecamatan', 'label' => 'Kecamatan', 'type' => 'region', 'region_level' => 'district', 'placeholder' => 'Pilih kecamatan', 'locked' => true, 'maxlength' => 100, 'store' => 'detail'],
                ['name' => 'desa_kelurahan', 'label' => 'Desa/kelurahan', 'type' => 'region', 'region_level' => 'village', 'placeholder' => 'Pilih desa/kelurahan', 'locked' => true, 'maxlength' => 100, 'store' => 'detail'],
                ['name' => 'alamat_jalan', 'label' => 'Alamat jalan', 'locked' => true, 'maxlength' => 200, 'wide' => true, 'store' => 'detail'],
                ['name' => 'dusun', 'label' => 'Dusun', 'maxlength' => 100, 'store' => 'detail'],
                ['name' => 'rt', 'label' => 'RT', 'maxlength' => 5, 'store' => 'detail'],
                ['name' => 'rw', 'label' => 'RW', 'maxlength' => 5, 'store' => 'detail'],
                ['name' => 'kode_pos', 'label' => 'Kode pos', 'maxlength' => 5, 'inputmode' => 'numeric', 'pattern' => '[0-9]{5}', 'store' => 'detail'],
                ['name' => 'email', 'label' => 'Email orang tua', 'type' => 'email', 'maxlength' => 120, 'store' => 'detail'],
                ['name' => 'jenis_tinggal', 'label' => 'Jenis tinggal', 'type' => 'select', 'locked' => true, 'store' => 'detail', 'options' => formulir_options(['Bersama orang tua', 'Bersama wali', 'Kos', 'Asrama', 'Panti asuhan', 'Lainnya'])],
                ['name' => 'alat_transportasi', 'label' => 'Alat transportasi ke sekolah', 'type' => 'select', 'locked' => true, 'store' => 'detail', 'options' => formulir_options(['Jalan kaki', 'Sepeda', 'Sepeda motor', 'Mobil', 'Angkutan umum', 'Antar jemput', 'Lainnya'])],
                ['type' => 'heading', 'label' => 'Data periodik'],
                ['name' => 'jarak_ke_sekolah_km', 'label' => 'Jarak rumah ke sekolah (km)', 'type' => 'number', 'min' => 0, 'max' => 500, 'step' => '0.1', 'numeric' => true, 'store' => 'detail'],
                ['name' => 'waktu_tempuh_menit', 'label' => 'Waktu tempuh (menit)', 'type' => 'number', 'min' => 0, 'max' => 1440, 'numeric' => true, 'store' => 'detail'],
                ['name' => 'tinggi_badan_cm', 'label' => 'Tinggi badan (cm)', 'type' => 'text', 'inputmode' => 'decimal', 'pattern' => '[0-9]+([,.][0-9]{1,2})?', 'decimal' => true, 'min' => 30, 'max' => 250, 'numeric' => true, 'hint' => 'Koma atau titik dapat digunakan untuk angka desimal. Jika belum tahu, dapat diukur di sekolah.', 'store' => 'detail'],
                ['name' => 'berat_badan_kg', 'label' => 'Berat badan (kg)', 'type' => 'text', 'inputmode' => 'decimal', 'pattern' => '[0-9]+([,.][0-9]{1,2})?', 'decimal' => true, 'min' => 1, 'max' => 300, 'numeric' => true, 'hint' => 'Koma atau titik dapat digunakan untuk angka desimal.', 'store' => 'detail'],
                ['name' => 'lingkar_kepala_cm', 'label' => 'Lingkar kepala (cm)', 'type' => 'text', 'inputmode' => 'decimal', 'pattern' => '[0-9]+([,.][0-9]{1,2})?', 'decimal' => true, 'min' => 20, 'max' => 100, 'numeric' => true, 'hint' => 'Koma atau titik dapat digunakan untuk angka desimal.', 'store' => 'detail'],
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
            if ($jenjang !== '' && in_array($jenjang, $field['wajib_jenjang'] ?? [], true)) {
                unset($field['show_if']);
                $field['locked'] = true;
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
    if (!empty($field['image'])) {
        $image = $field['image'];
        echo '<img class="field-image" src="'.h($image['src']).'" alt="'.h($image['alt']).'" width="'.(int) $image['width'].'" height="'.(int) $image['height'].'" loading="lazy">';
    }

    if ($type === 'region') {
        $regionLevel = (string) ($field['region_level'] ?? '');
        $regionGroup = isset($field['region_group']) ? ' data-region-group="'.h((string) $field['region_group']).'"' : '';
        $disabled = $regionLevel === 'province' ? '' : ' disabled';
        echo '<select id="'.h($name).'" name="'.h($name).'" data-region-select="'.h($regionLevel).'"'.$regionGroup.' data-placeholder="'.h($field['placeholder'] ?? 'Pilih wilayah').'"'.$required.$disabled.'>';
        echo '<option value="">'.h($field['placeholder'] ?? 'Pilih wilayah').'</option>';
        if ($value !== '') {
            echo '<option value="'.h($value).'" data-region-saved="1" selected>'.h($value).'</option>';
        }
        echo '</select>';
        echo '<small class="field-hint" data-region-status role="status" aria-live="polite">'.($regionLevel === 'province' ? 'Memuat daftar provinsi…' : 'Pilih wilayah di atas terlebih dahulu.').'</small>';
    } elseif ($type === 'select') {
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
        // Pesan galat format untuk validasi langsung di js/formulir.js.
        if (isset($field['pattern'])) {
            $patternMessage = preg_match('/^\[0-9\]\{(\d+)\}$/', $field['pattern'], $digits)
                ? 'Harus '.$digits[1].' digit angka.'
                : ($type === 'tel' ? 'Gunakan nomor WhatsApp berawalan 08, 10–13 digit.' : 'Format belum sesuai.');
            $attributes .= ' data-pattern-message="'.h($patternMessage).'"';
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
 * tampilnya tidak terpenuhi dilewati. Mengembalikan daftar pesan galat;
 * $fieldErrors diisi [nama isian => pesan] agar isiannya dapat ditandai.
 */
function formulir_validate_fields(array $input, array $sections, ?array &$fieldErrors = null): array
{
    $errors = [];
    $fieldErrors ??= [];
    // Pesan pertama dari isian sebelumnya dicatat sebelum isian berikutnya diperiksa.
    $pending = null;
    $record = static function () use (&$pending, &$errors, &$fieldErrors): void {
        if ($pending !== null && count($errors) > $pending[1]) {
            $fieldErrors[$pending[0]] ??= $errors[$pending[1]];
        }
        $pending = null;
    };
    foreach ($sections as $section) {
        foreach ($section['fields'] as $field) {
            $record();
            $type = $field['type'] ?? 'text';
            if ($type === 'heading' || !formulir_condition_met($field, $input)) {
                continue;
            }
            $label = $section['title'].' · '.$field['label'];
            $pending = [$field['name'], count($errors)];
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
            if (!empty($field['decimal'])) {
                if (!preg_match('/^[0-9]+(?:[,.][0-9]{1,2})?$/D', $value)) {
                    $errors[] = $label.' harus berupa angka; koma atau titik diperbolehkan dengan maksimal dua angka desimal.';
                    continue;
                }
                $numericValue = (float) str_replace(',', '.', $value);
                if ((isset($field['min']) && $numericValue < (float) $field['min']) || (isset($field['max']) && $numericValue > (float) $field['max'])) {
                    $errors[] = $label.' harus berada dalam rentang '.(string) ($field['min'] ?? '0').'–'.(string) ($field['max'] ?? 'tanpa batas').'.';
                }
                continue;
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
    $record();
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
            $value = $row[$column] ?? '';
            // Satukan nilai lama "Lainnya" ke pilihan meninggal/lainnya.
            if ($column === 'status_hidup' && $value === 'Lainnya') {
                $value = 'Meninggal dunia';
            }
            $flat[$role.'_'.$column] = $value;
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

    // Nama isian NIK tiap pemilik, untuk menandai isian yang keliru.
    $nikField = static fn (string $owner): string => $owner === 'ananda' ? 'nik' : $owner.'_nik';
    $fields = [];
    $reject = static function (string $field, string $message) use (&$errors, &$fields): void {
        $errors[] = $message;
        $fields[$field] ??= $message;
    };

    foreach ($niks as $owner => $nik) {
        if ($nik === $familyCard && $nik !== '') {
            $reject($nikField($owner), 'NIK '.$owner.' sama dengan nomor KK. Periksa kembali, keduanya berbeda walau sama-sama 16 digit.');
        }
        if (preg_match('/^[0-9]{16}$/', $nik) && !in_array(substr($nik, 0, 2), FORMULIR_PROVINCE_CODES, true)) {
            $reject($nikField($owner), 'Kode wilayah pada NIK '.$owner.' tidak dikenal. Periksa dua digit pertama NIK.');
        }
    }
    if (preg_match('/^[0-9]{16}$/', $familyCard) && !in_array(substr($familyCard, 0, 2), FORMULIR_PROVINCE_CODES, true)) {
        $reject('nomor_kk', 'Kode wilayah pada nomor KK tidak dikenal. Periksa dua digit pertama nomor KK.');
    }
    $owners = array_keys($niks);
    foreach ($owners as $i => $first) {
        foreach (array_slice($owners, $i + 1) as $second) {
            if ($niks[$first] !== '' && $niks[$first] === $niks[$second]) {
                $reject($nikField($second), 'NIK '.$first.' dan NIK '.$second.' tidak boleh sama.');
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

    // Orang tua/wali: hanya tahun lahir yang dicocokkan; tanggal dan bulan
    // pada NIK tidak diperiksa. Jenis kelamin tetap dibaca dari kode tanggal.
    foreach (['ayah', 'ibu', 'wali'] as $role) {
        if (!isset($niks[$role]) || !preg_match('/^[0-9]{16}$/', $niks[$role])) {
            continue;
        }
        $dayCode = (int) substr($niks[$role], 6, 2);
        $parentParsed = [
            'year2' => (int) substr($niks[$role], 10, 2),
            // Kode tanggal 01-31 laki-laki, 41-71 perempuan; selain itu tidak dinilai.
            'female' => $dayCode >= 41 && $dayCode <= 71 ? true : ($dayCode >= 1 && $dayCode <= 31 ? false : null),
        ];
        $birthYear = $value($role.'_tahun_lahir');
        if (preg_match('/^[0-9]{4}$/', $birthYear) && (int) $birthYear % 100 !== $parentParsed['year2']) {
            $flags[] = sprintf('Tahun lahir %s (%s) berbeda dengan tahun pada NIK (%02d).', $role, $birthYear, $parentParsed['year2']);
        }
        if ($role === 'ayah' && $parentParsed['female'] === true) {
            $flags[] = 'NIK ayah terbaca sebagai NIK perempuan.';
        } elseif ($role === 'ibu' && $parentParsed['female'] === false) {
            $flags[] = 'NIK ibu terbaca sebagai NIK laki-laki.';
        }
    }

    return ['errors' => $errors, 'fields' => $fields, 'flags' => $flags];
}

/**
 * Isian formulir yang pernah dikirim, dalam bentuk nama_field => nilai.
 * Mengembalikan array kosong bila formulir belum pernah dikirim.
 */
function formulir_saved_values(mysqli $conn, string $registrationId): array
{
    $query = mysqli_prepare($conn, 'SELECT p.NISN,p.tmp_lahir,p.agama,p.asal_sekolah,p.sumber_informasi,d.* FROM tb_pendaftaran p JOIN tb_detail_dapodik d ON d.id_pendaftaran=p.id_pendaftaran WHERE p.id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($query, 's', $registrationId);
    mysqli_stmt_execute($query);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($query)) ?: [];
    mysqli_stmt_close($query);
    if (trim((string) ($row['nik'] ?? '')) === '') {
        return [];
    }
    $row += formulir_flatten_parent_profiles(formulir_parent_profiles($conn, $registrationId));
    $values = [];
    foreach (formulir_input_fields() as $name => $_field) {
        $values[$name] = (string) ($row[$name] ?? '');
    }
    return $values;
}

/** Samakan bentuk nilai agar 95.50 dan 95.5 tidak dianggap berubah. */
function formulir_normalize_value(string $value, array $field): string
{
    $value = trim($value);
    if (!empty($field['numeric']) && $value !== '' && is_numeric($value)) {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
    return $value;
}

/**
 * Daftar perubahan antara isian lama dan baru: [[bagian, label, lama, baru], ...].
 * Nilai pilihan ditampilkan dengan labelnya.
 */
function formulir_diff_values(array $old, array $new, array $sections): array
{
    $changes = [];
    foreach ($sections as $section) {
        foreach ($section['fields'] as $field) {
            if (($field['type'] ?? 'text') === 'heading') {
                continue;
            }
            $name = $field['name'];
            $before = formulir_normalize_value((string) ($old[$name] ?? ''), $field);
            $after = formulir_normalize_value((string) ($new[$name] ?? ''), $field);
            if ($before === $after) {
                continue;
            }
            $label = static fn (string $value): string => ($field['type'] ?? '') === 'select' && isset($field['options'][$value]) ? (string) $field['options'][$value] : $value;
            $changes[] = [$section['title'], $field['label'], $label($before), $label($after)];
        }
    }
    return $changes;
}
