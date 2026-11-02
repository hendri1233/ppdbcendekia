<?php
/*
 * Jalur seleksi unit SMP pada registrasi awal: pilihan prestasi/umum dan isian
 * tambahan jalur prestasi, mengikuti Google Form pendaftaran SMP IT Cendekia.
 */

const PRESTASI_JENIS = ['Prestasi peringkat 1, 2 dan 3 di kelas', "Prestasi (Al-Qur'an/akademik/nonakademik)"];
const PRESTASI_PERINGKAT = ['Peringkat 1', 'Peringkat 2', 'Peringkat 3', 'Tidak ada peringkat'];
const PRESTASI_PERINGKAT_BARIS = [
    'peringkat_k5_s1' => 'Kelas 5 · Semester 1',
    'peringkat_k5_s2' => 'Kelas 5 · Semester 2',
    'peringkat_k6_s1' => 'Kelas 6 · Semester 1',
];
const PRESTASI_KATEGORI_HAFALAN = "Prestasi hafalan Al-Qur'an";
const PRESTASI_KATEGORI = [PRESTASI_KATEGORI_HAFALAN, 'Prestasi dibidang akademik non akademik'];
const PRESTASI_JUMLAH_HAFALAN = ['1 Juz', '1,5 Juz', '2 Juz', '2,5 Juz', '3 Juz'];
const PRESTASI_LOMBA_PERNAH = 'Pernah memenangkan lomba tingkat Kecamatan, Kabupaten, Provinsi, dan Nasional.';
const PRESTASI_LOMBA = [PRESTASI_LOMBA_PERNAH, 'Tidak memiliki prestasi'];

/** Migrasi 030 sudah dijalankan; bila belum, pendaftaran SMP berjalan seperti unit lain. */
function prestasi_is_ready(mysqli $conn): bool
{
    return ppdb_table_exists($conn, 'tb_prestasi_peserta') && ppdb_column_exists($conn, 'tb_pendaftaran', 'jalur_seleksi');
}

function prestasi_render_radios(string $name, array $options, string $value, bool $required = true): void
{
    echo '<div class="radio-options radio-options--stack">';
    foreach ($options as $option) {
        echo '<label class="radio-option"><input type="radio" name="'.h($name).'" value="'.h($option).'"'.($value === $option ? ' checked' : '').($required ? ' data-prestasi-required' : '').'><span>'.h($option).'</span></label>';
    }
    echo '</div>';
}

/** Pilihan jalur, tampil setelah unit dan sebelum isian data awal. */
function prestasi_render_jalur_field(string $value, bool $visible): void
{
    echo '<fieldset class="field field-wide jalur-field" data-jalur-field'.($visible ? '' : ' hidden').'>';
    echo '<legend>Jalur pendaftaran SMP <span class="required-mark" aria-hidden="true">*</span></legend>';
    echo '<div class="radio-options">';
    foreach (['prestasi' => 'Prestasi', 'umum' => 'Umum'] as $key => $label) {
        echo '<label class="radio-option"><input type="radio" name="jalur_seleksi" value="'.$key.'"'.($value === $key ? ' checked' : '').($visible ? ' required' : ' disabled').'><span>'.$label.'</span></label>';
    }
    echo '</div><small class="field-hint">Pilih jalur terlebih dahulu untuk melanjutkan pengisian data.</small></fieldset>';
}

/** Isian tambahan jalur prestasi. Atribut required dipasang js/daftar.js sesuai isian yang tampil. */
function prestasi_render_section(array $values, bool $visible): void
{
    $value = static fn (string $name): string => is_string($values[$name] ?? null) ? $values[$name] : '';
    echo '<div class="prestasi-section" data-prestasi-section'.($visible ? '' : ' hidden').'>';
    echo '<h3>Data jalur prestasi</h3>';

    echo '<div class="field field-wide"><label for="surat_prestasi">Surat pengantar prestasi <span class="required-mark" aria-hidden="true">*</span></label>';
    echo '<input id="surat_prestasi" type="file" name="surat_prestasi" accept="image/jpeg,image/png" data-prestasi-required>';
    echo '<p class="prestasi-template">Belum punya suratnya? Unduh template, isi di sekolah, lalu minta tanda tangan dan stempel kepala sekolah: <a href="assets/template/surat-keterangan-prestasi.docx" download>Unduh Word</a> <a href="assets/template/surat-keterangan-prestasi.pdf" download>Unduh PDF</a></p>';
    echo '<small class="field-hint">Foto surat pengantar prestasi yang ditandatangani kepala sekolah, posisi berdiri dan dapat dibaca. Format JPG atau PNG, maksimal 5 MB. Bila ada isian yang keliru saat dikirim, pilih ulang fotonya.</small></div>';

    echo '<fieldset class="field field-wide"><legend>Prestasi peringkat di kelas <span class="required-mark" aria-hidden="true">*</span></legend>';
    prestasi_render_radios('jenis_prestasi', PRESTASI_JENIS, $value('jenis_prestasi'));
    echo '</fieldset>';

    foreach (PRESTASI_PERINGKAT_BARIS as $name => $label) {
        echo '<fieldset class="field field-wide"><legend>'.h($label).' <span class="required-mark" aria-hidden="true">*</span></legend><div class="radio-options">';
        foreach (PRESTASI_PERINGKAT as $option) {
            echo '<label class="radio-option"><input type="radio" name="'.$name.'" value="'.h($option).'"'.($value($name) === $option ? ' checked' : '').' data-prestasi-required><span>'.h($option).'</span></label>';
        }
        echo '</div></fieldset>';
    }

    echo '<fieldset class="field field-wide"><legend>Prestasi hafalan Al-Qur\'an minimal 1 juz <span class="required-mark" aria-hidden="true">*</span></legend>';
    prestasi_render_radios('kategori_hafalan', PRESTASI_KATEGORI, $value('kategori_hafalan'));
    echo '</fieldset>';

    echo '<fieldset class="field field-wide" data-prestasi-when="kategori_hafalan" data-prestasi-is="'.h(PRESTASI_KATEGORI_HAFALAN).'"><legend>Jumlah hafalan ananda <span class="required-mark" aria-hidden="true">*</span></legend><div class="radio-options">';
    foreach ([...PRESTASI_JUMLAH_HAFALAN, 'Lainnya'] as $option) {
        echo '<label class="radio-option"><input type="radio" name="jumlah_hafalan" value="'.h($option).'"'.($value('jumlah_hafalan') === $option ? ' checked' : '').' data-prestasi-required><span>'.h($option).'</span></label>';
    }
    echo '</div><div class="prestasi-other" data-prestasi-when="jumlah_hafalan" data-prestasi-is="Lainnya"><label for="jumlah_hafalan_lain">Tuliskan jumlah hafalan</label><input id="jumlah_hafalan_lain" type="text" name="jumlah_hafalan_lain" maxlength="100" placeholder="Contoh: 1 Juz 4 surah" value="'.h($value('jumlah_hafalan_lain')).'" data-prestasi-required></div></fieldset>';

    echo '<fieldset class="field field-wide"><legend>Pernah memenangkan lomba tingkat Gugus, Kecamatan, Kabupaten, Provinsi, dan Nasional <span class="required-mark" aria-hidden="true">*</span></legend>';
    prestasi_render_radios('riwayat_lomba', PRESTASI_LOMBA, $value('riwayat_lomba'));
    echo '</fieldset>';

    echo '<div class="field field-wide" data-prestasi-when="riwayat_lomba" data-prestasi-is="'.h(PRESTASI_LOMBA_PERNAH).'"><label for="daftar_lomba">Lomba yang pernah dimenangkan <span class="required-mark" aria-hidden="true">*</span></label>';
    echo '<textarea id="daftar_lomba" name="daftar_lomba" rows="4" maxlength="2000" data-prestasi-required>'.h($value('daftar_lomba')).'</textarea>';
    echo '<small class="field-hint">Sebutkan juara, nama lomba, dan tingkatnya. Contoh: Juara 1 lomba baca puisi tingkat kabupaten; Juara 3 lomba bercerita tingkat provinsi.</small></div>';
    echo '</div>';
}

/** Periksa isian jalur prestasi; mengembalikan [data untuk disimpan, daftar galat]. */
function prestasi_validate(array $input, array $files): array
{
    $text = static fn (string $name): string => is_string($input[$name] ?? null) ? trim($input[$name]) : '';
    $errors = [];
    $data = [];
    if (($files['surat_prestasi']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Unggah foto surat pengantar prestasi (JPG atau PNG, maksimal 5 MB).';
    }
    $data['jenis_prestasi'] = $text('jenis_prestasi');
    if (!in_array($data['jenis_prestasi'], PRESTASI_JENIS, true)) {
        $errors[] = 'Pilih prestasi peringkat di kelas.';
    }
    foreach (PRESTASI_PERINGKAT_BARIS as $name => $label) {
        $data[$name] = $text($name);
        if (!in_array($data[$name], PRESTASI_PERINGKAT, true)) {
            $errors[] = 'Pilih peringkat '.$label.'.';
        }
    }
    $data['kategori_hafalan'] = $text('kategori_hafalan');
    if (!in_array($data['kategori_hafalan'], PRESTASI_KATEGORI, true)) {
        $errors[] = "Pilih prestasi hafalan Al-Qur'an atau akademik/nonakademik.";
    }
    $data['jumlah_hafalan'] = '';
    if ($data['kategori_hafalan'] === PRESTASI_KATEGORI_HAFALAN) {
        $amount = $text('jumlah_hafalan');
        $data['jumlah_hafalan'] = $amount === 'Lainnya' ? $text('jumlah_hafalan_lain') : $amount;
        $valid = $amount === 'Lainnya'
            ? $data['jumlah_hafalan'] !== '' && mb_strlen($data['jumlah_hafalan']) <= 100
            : in_array($amount, PRESTASI_JUMLAH_HAFALAN, true);
        if (!$valid) {
            $errors[] = 'Pilih atau tuliskan jumlah hafalan ananda.';
        }
    }
    $data['riwayat_lomba'] = $text('riwayat_lomba');
    if (!in_array($data['riwayat_lomba'], PRESTASI_LOMBA, true)) {
        $errors[] = 'Pilih riwayat lomba ananda.';
    }
    $data['daftar_lomba'] = '';
    if ($data['riwayat_lomba'] === PRESTASI_LOMBA_PERNAH) {
        $data['daftar_lomba'] = $text('daftar_lomba');
        if ($data['daftar_lomba'] === '' || mb_strlen($data['daftar_lomba']) > 2000) {
            $errors[] = 'Tuliskan lomba yang pernah dimenangkan ananda (maksimal 2000 karakter).';
        }
    }
    return [$data, $errors];
}

function prestasi_save(mysqli $conn, string $registrationId, array $data): void
{
    $stmt = mysqli_prepare($conn, 'INSERT INTO tb_prestasi_peserta (id_pendaftaran,jenis_prestasi,peringkat_k5_s1,peringkat_k5_s2,peringkat_k6_s1,kategori_hafalan,jumlah_hafalan,riwayat_lomba,daftar_lomba) VALUES (?,?,?,?,?,?,?,?,?)');
    mysqli_stmt_bind_param($stmt, 'sssssssss', $registrationId, $data['jenis_prestasi'], $data['peringkat_k5_s1'], $data['peringkat_k5_s2'], $data['peringkat_k6_s1'], $data['kategori_hafalan'], $data['jumlah_hafalan'], $data['riwayat_lomba'], $data['daftar_lomba']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/** Simpan surat pengantar sebagai dokumen peserta; mengembalikan path berkas. */
function prestasi_store_letter(mysqli $conn, string $registrationId, array $file): string
{
    $stored = ppdb_store_private_upload($file, 'participant-documents/'.$registrationId, ['image/jpeg' => 'jpg', 'image/png' => 'png'], 5 * 1024 * 1024);
    $slot = 'surat_prestasi';
    $name = (string) $stored['original_name'];
    $path = (string) $stored['path'];
    $mime = (string) $stored['mime'];
    $size = (int) $stored['size'];
    $stmt = mysqli_prepare($conn, 'INSERT INTO tb_dokumen_peserta (id_pendaftaran,jenis_dokumen,nama_file_asli,path_file,mime_type,ukuran_byte) VALUES (?,?,?,?,?,?)');
    mysqli_stmt_bind_param($stmt, 'sssssi', $registrationId, $slot, $name, $path, $mime, $size);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $path;
}

/** Isian jalur peserta untuk panel admin; kosong bila peserta tidak memilih jalur. */
function prestasi_load(mysqli $conn, string $registrationId): array
{
    if (!prestasi_is_ready($conn)) {
        return [];
    }
    $stmt = mysqli_prepare($conn, 'SELECT p.jalur_seleksi,x.* FROM tb_pendaftaran p LEFT JOIN tb_prestasi_peserta x ON x.id_pendaftaran=p.id_pendaftaran WHERE p.id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row && $row['jalur_seleksi'] ? $row : [];
}

function prestasi_jalur_label(?string $value): string
{
    return ['prestasi' => 'Prestasi', 'umum' => 'Umum'][$value] ?? '';
}
