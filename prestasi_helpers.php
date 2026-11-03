<?php
/*
 * Jalur seleksi unit SMP pada registrasi awal: pilihan prestasi/umum dan isian
 * tambahan jalur prestasi, mengikuti Google Form pendaftaran SMP IT Cendekia.
 */

const PRESTASI_JENIS_AKADEMIK = 'Akademik / peringkat raport';
const PRESTASI_JENIS_QURAN = "Al-Qur'an minimal hafalan 1 juz";
const PRESTASI_JENIS_LOMBA = 'Non akademik / perlombaan';
const PRESTASI_PERINGKAT = ['Peringkat 1', 'Peringkat 2', 'Peringkat 3', 'Tidak ada peringkat'];
const PRESTASI_PERINGKAT_BARIS = [
    'peringkat_k5_s1' => 'Kelas 5 · Semester 1',
    'peringkat_k5_s2' => 'Kelas 5 · Semester 2',
    'peringkat_k6_s1' => 'Kelas 6 · Semester 1',
];
const PRESTASI_JUMLAH_HAFALAN = ['1 Juz', '1,5 Juz', '2 Juz', '2,5 Juz', '3 Juz'];
const PRESTASI_TIDAK_ADA = 'Tidak ada';

/** Migrasi 030 sudah dijalankan; bila belum, pendaftaran SMP berjalan seperti unit lain. */
function prestasi_is_ready(mysqli $conn): bool
{
    return ppdb_table_exists($conn, 'tb_prestasi_peserta') && ppdb_column_exists($conn, 'tb_pendaftaran', 'jalur_seleksi');
}

/** Satu kategori prestasi yang wajib diisi bergiliran, dibuka dan ditutup seperti dropdown. */
function prestasi_category_open(int $number, string $title): void
{
    echo '<details class="prestasi-category" data-prestasi-category>';
    echo '<summary><span class="prestasi-category__number" aria-hidden="true">'.$number.'</span><span class="prestasi-category__title">'.h($title).' <span class="required-mark" aria-hidden="true">*</span></span><span class="prestasi-category__status" data-prestasi-status>Belum diisi</span></summary>';
    echo '<div class="prestasi-category__body">';
}

function prestasi_category_close(): void
{
    echo '</div></details>';
}

/** Pertanyaan pembuka setiap kategori: Ada atau Tidak ada. */
function prestasi_render_question(string $name, string $question, string $value): void
{
    echo '<fieldset class="field field-wide"><legend>'.h($question).' <span class="required-mark" aria-hidden="true">*</span></legend><div class="radio-options">';
    foreach (['Ada', PRESTASI_TIDAK_ADA] as $option) {
        echo '<label class="radio-option"><input type="radio" name="'.h($name).'" value="'.h($option).'"'.($value === $option ? ' checked' : '').' data-prestasi-required><span>'.h($option).'</span></label>';
    }
    echo '</div></fieldset>';
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

    echo '<div class="field field-wide prestasi-categories"><p class="prestasi-categories__label">Prestasi akademik <span class="required-mark" aria-hidden="true">*</span></p>';
    echo '<small class="field-hint">Isi ketiga kategori secara bergiliran. Jawab "Ada" atau "Tidak ada" pada setiap kategori; kategori berikutnya terbuka setelah yang sebelumnya selesai.</small>';

    prestasi_category_open(1, PRESTASI_JENIS_AKADEMIK);
    prestasi_render_question('akademik_ada', 'Apakah ananda memiliki peringkat di raport?', $value('akademik_ada'));
    echo '<div class="prestasi-group" data-prestasi-when="akademik_ada" data-prestasi-is="Ada">';
    foreach (PRESTASI_PERINGKAT_BARIS as $name => $label) {
        echo '<fieldset class="field field-wide"><legend>'.h($label).' <span class="required-mark" aria-hidden="true">*</span></legend><div class="radio-options">';
        foreach (PRESTASI_PERINGKAT as $option) {
            echo '<label class="radio-option"><input type="radio" name="'.$name.'" value="'.h($option).'"'.($value($name) === $option ? ' checked' : '').' data-prestasi-required><span>'.h($option).'</span></label>';
        }
        echo '</div></fieldset>';
    }
    echo '</div>';
    prestasi_category_close();

    prestasi_category_open(2, PRESTASI_JENIS_QURAN);
    prestasi_render_question('hafalan_ada', "Apakah ananda memiliki hafalan Al-Qur'an minimal 1 juz?", $value('hafalan_ada'));
    echo '<fieldset class="field field-wide" data-prestasi-when="hafalan_ada" data-prestasi-is="Ada"><legend>Jumlah hafalan ananda <span class="required-mark" aria-hidden="true">*</span></legend><div class="radio-options">';
    foreach ([...PRESTASI_JUMLAH_HAFALAN, 'Lainnya'] as $option) {
        echo '<label class="radio-option"><input type="radio" name="jumlah_hafalan" value="'.h($option).'"'.($value('jumlah_hafalan') === $option ? ' checked' : '').' data-prestasi-required><span>'.h($option).'</span></label>';
    }
    echo '</div><div class="prestasi-other" data-prestasi-when="jumlah_hafalan" data-prestasi-is="Lainnya"><label for="jumlah_hafalan_lain">Tuliskan jumlah hafalan</label><input id="jumlah_hafalan_lain" type="text" name="jumlah_hafalan_lain" maxlength="100" placeholder="Contoh: 1 Juz 4 surah" value="'.h($value('jumlah_hafalan_lain')).'" data-prestasi-required></div></fieldset>';
    prestasi_category_close();

    prestasi_category_open(3, PRESTASI_JENIS_LOMBA);
    prestasi_render_question('riwayat_lomba', 'Apakah ananda pernah memenangkan lomba?', $value('riwayat_lomba'));
    echo '<div class="field field-wide" data-prestasi-when="riwayat_lomba" data-prestasi-is="Ada"><label for="daftar_lomba">Lomba yang pernah dimenangkan <span class="required-mark" aria-hidden="true">*</span></label>';
    echo '<textarea id="daftar_lomba" name="daftar_lomba" rows="4" maxlength="2000" data-prestasi-required>'.h($value('daftar_lomba')).'</textarea>';
    echo '<small class="field-hint">Sebutkan juara, nama lomba, dan tingkatnya (gugus, kecamatan, kabupaten, provinsi, atau nasional). Contoh: Juara 1 lomba baca puisi tingkat kabupaten; Juara 3 lomba bercerita tingkat provinsi.</small></div>';
    prestasi_category_close();
    echo '</div></div>';
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
    // Ketiga kategori wajib diisi; kolom jenis_prestasi hanya terisi pada data lama.
    $data['jenis_prestasi'] = '';
    $data['kategori_hafalan'] = '';
    $data['riwayat_lomba'] = '';
    $hasRank = $text('akademik_ada');
    if (!in_array($hasRank, ['Ada', PRESTASI_TIDAK_ADA], true)) {
        $errors[] = 'Lengkapi kategori akademik: pilih apakah ananda memiliki peringkat di raport.';
    }
    foreach (PRESTASI_PERINGKAT_BARIS as $name => $label) {
        $data[$name] = $hasRank === 'Ada' ? $text($name) : 'Tidak ada peringkat';
        if ($hasRank === 'Ada' && !in_array($data[$name], PRESTASI_PERINGKAT, true)) {
            $errors[] = 'Pilih peringkat '.$label.'.';
        }
    }
    $hasMemorization = $text('hafalan_ada');
    if ($hasMemorization === PRESTASI_TIDAK_ADA) {
        $data['jumlah_hafalan'] = PRESTASI_TIDAK_ADA;
    } elseif ($hasMemorization !== 'Ada') {
        $data['jumlah_hafalan'] = '';
        $errors[] = "Lengkapi kategori Al-Qur'an: pilih apakah ananda memiliki hafalan.";
    } else {
        $amount = $text('jumlah_hafalan');
        $data['jumlah_hafalan'] = $amount === 'Lainnya' ? $text('jumlah_hafalan_lain') : $amount;
        $valid = $amount === 'Lainnya'
            ? $data['jumlah_hafalan'] !== '' && mb_strlen($data['jumlah_hafalan']) <= 100
            : in_array($amount, PRESTASI_JUMLAH_HAFALAN, true);
        if (!$valid) {
            $errors[] = 'Pilih atau tuliskan jumlah hafalan ananda.';
        }
    }
    $competition = $text('riwayat_lomba');
    $data['daftar_lomba'] = $competition === 'Ada' ? $text('daftar_lomba') : PRESTASI_TIDAK_ADA;
    if (!in_array($competition, ['Ada', PRESTASI_TIDAK_ADA], true)) {
        $errors[] = 'Lengkapi kategori non akademik: pilih apakah ananda pernah memenangkan lomba.';
    } elseif ($competition === 'Ada' && ($data['daftar_lomba'] === '' || mb_strlen($data['daftar_lomba']) > 2000)) {
        $errors[] = 'Tuliskan lomba yang pernah dimenangkan ananda (maksimal 2000 karakter).';
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
