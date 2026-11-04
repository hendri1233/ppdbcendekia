<?php
/**
 * Daftar berkas yang dikumpulkan unit ke yayasan. Setiap unit mencentang lewat
 * tautan bertoken, tanpa login, sehingga tautan cukup dibagikan di grup WhatsApp unit.
 */

const CHECKLIST_BERKAS = [
    'File wajib' => [
        'brosur' => ['Brosur SPMB', 'PDF, JPG, PNG, DOC, DOCX, atau PSD; maksimal 20 MB', true],
        'rincian_biaya' => ['Rincian biaya', 'PDF, JPG, PNG, DOC, DOCX, atau PSD; maksimal 20 MB', true],
        'sop' => ['SOP pendaftaran', 'PDF, JPG, PNG, DOC, DOCX, atau PSD; maksimal 20 MB', true],
        'kuisioner' => ['Kuisioner', 'XLSX atau CSV; maksimal 5 MB', true],
    ],
    'Data wajib' => [
        'kuota' => ['Kuota penerimaan', 'Kuota internal SDM dan kuota eksternal umum', true],
        'biaya_pendaftaran' => ['Biaya pendaftaran', 'Nominal biaya dan batas pembayaran (hari)', true],
        'rekening' => ['Rekening pembayaran', 'Nama bank atau e-wallet, nomor rekening, dan nama pemilik', true],
        'jadwal' => ['Jadwal pendaftaran', 'Tanggal dan jam buka serta tutup (WIB)', true],
        'calon_admin' => ['Calon admin unit', 'Nama dan email', true],
        'daftar_sdm' => ['Daftar SDM penerima token internal', 'Nama SDM yang berhak mendapat token', true],
    ],
    'Pelengkap' => [
        'pratinjau' => ['Pratinjau materi', 'PDF, JPG, atau PNG; hanya bila materi berformat DOC, DOCX, atau PSD', false],
        'foto_unit' => ['Foto unit', 'Gambar dengan rasio 16:9', false],
        'profil_unit' => ['Profil unit', 'Ringkasan satu kalimat, uraian, dan telepon unit', false],
    ],
];

/** Kode berkas => [nama, keterangan, wajib], tanpa pengelompokan. */
function checklist_items(): array
{
    return array_merge(...array_values(CHECKLIST_BERKAS));
}

function checklist_required_items(): array
{
    return array_filter(checklist_items(), static fn (array $item): bool => $item[2]);
}

/** Token tautan unit; dibuat saat pertama kali diminta. */
function checklist_unit_token(mysqli $conn, string $unitCode): string
{
    $key = 'checklist_token_'.$unitCode;
    $token = cms_setting($conn, $key);
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        cms_save_setting($conn, $key, $token);
    }
    return $token;
}

function checklist_unit_by_token(mysqli $conn, string $token): ?array
{
    if ($token === '') {
        return null;
    }
    foreach (cms_units($conn) as $unit) {
        $saved = cms_setting($conn, 'checklist_token_'.$unit['kode_unit']);
        if ($saved !== '' && hash_equals($saved, $token)) {
            return $unit;
        }
    }
    return null;
}

/** Berkas yang sudah dicentang unit: kode berkas => waktu centang. */
function checklist_checked(mysqli $conn, string $unitCode): array
{
    $stmt = mysqli_prepare($conn, 'SELECT kode_berkas,dicentang_pada FROM tb_checklist_berkas WHERE kode_unit=?');
    mysqli_stmt_bind_param($stmt, 's', $unitCode);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    return array_intersect_key(array_column($rows, 'dicentang_pada', 'kode_berkas'), checklist_items());
}

function checklist_required_done(array $checked): int
{
    return count(array_intersect_key(checklist_required_items(), $checked));
}

function checklist_set(mysqli $conn, string $unitCode, string $itemCode, bool $checked): void
{
    $sql = $checked
        ? 'INSERT IGNORE INTO tb_checklist_berkas (kode_unit,kode_berkas) VALUES (?,?)'
        : 'DELETE FROM tb_checklist_berkas WHERE kode_unit=? AND kode_berkas=?';
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $unitCode, $itemCode);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function checklist_reset(mysqli $conn, string $unitCode): void
{
    $stmt = mysqli_prepare($conn, 'DELETE FROM tb_checklist_berkas WHERE kode_unit=?');
    mysqli_stmt_bind_param($stmt, 's', $unitCode);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
