<?php
require_once __DIR__.'/app_helpers.php';
require_once __DIR__.'/prestasi_helpers.php';
require_once __DIR__.'/formulir_helpers.php';

/*
 * Isi cetakan peserta, dipakai bersama halaman cetak browser (cetak-registrasi.php)
 * dan salinan PDF di Google Drive (cetak_drive.php) agar keduanya selalu sama.
 * Dua jenis: registrasi (data registrasi, jalur prestasi, bukti transfer) dan
 * lengkap (seluruh isian formulir serta dokumen peserta yang sudah diterima).
 */

/** Data peserta beserta nama unit dan jenjang, atau null. */
function cetak_registrasi_participant(mysqli $conn, string $registrationId): ?array
{
    $stmt = mysqli_prepare($conn, 'SELECT p.*,u.nama_unit,u.jenjang FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit WHERE p.id_pendaftaran=? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $registrationId);
    mysqli_stmt_execute($stmt);
    $participant = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $participant;
}

function cetak_registrasi_is_empty(mixed $value): bool
{
    return in_array(trim((string) $value), ['', '-'], true);
}

/**
 * Bagian data, lampiran, dan jumlah isian kosong untuk satu peserta.
 * Lampiran memuat id, tabel asal, path berkas di server, dan tipe MIME.
 */
function cetak_registrasi_build(mysqli $conn, array $participant, bool $isCompleteFile): array
{
    $registrationId = (string) $participant['id_pendaftaran'];
    $birthPlace = trim((string) $participant['tmp_lahir']);
    $registrationRows = [
        'Tanggal pendaftaran' => ppdb_format_date_id($participant['tgl_daftar']),
        'Unit pendidikan' => $participant['nama_unit'],
        ...(!empty($participant['tingkat_kelas']) ? ['Daftar ke jenjang' => $participant['tingkat_kelas']] : []),
        'Jalur pendaftaran' => ppdb_jalur_label($participant),
        'Nomor antrean' => $participant['nomor_antrian'] ? '#'.(int) $participant['nomor_antrian'] : '',
        'Nama ananda' => $participant['nm_peserta'],
        'Tempat, tanggal lahir' => ($birthPlace !== '' ? $birthPlace.', ' : '').ppdb_format_date_id($participant['tgl_lahir']),
        'Umur (Dapodik)' => ppdb_dapodik_age_label(ppdb_dapodik_age($participant['tgl_lahir'], $participant['th_ajaran'])),
        'Jenis kelamin' => ppdb_jenis_kelamin_label($participant['jenis_kelamin']),
        'Yang mendaftarkan' => ppdb_pendaftar_label($participant['pendaftar']),
        'WhatsApp pendaftar' => ppdb_display_phone((string) $participant['no_hp_pendaftar']),
        'WhatsApp ayah' => ppdb_display_phone((string) ($participant['no_hp_ayah'] ?? '')),
        'WhatsApp ibu' => ppdb_display_phone((string) ($participant['no_hp_ibu'] ?? '')),
    ];

    $selectionData = prestasi_load($conn, $registrationId);
    $isAchievementRoute = ($selectionData['jalur_seleksi'] ?? '') === 'prestasi';
    $achievementRows = [];
    if ($selectionData) {
        $achievementRows[] = ['Jalur seleksi', prestasi_jalur_label($selectionData['jalur_seleksi'])];
    }
    if ($isAchievementRoute) {
        foreach ([
            'Prestasi akademik' => 'jenis_prestasi',
            'Kelas 4 semester 1' => 'peringkat_k4_s1',
            'Kelas 4 semester 2' => 'peringkat_k4_s2',
            'Kelas 5 semester 1' => 'peringkat_k5_s1',
            'Kelas 5 semester 2' => 'peringkat_k5_s2',
            'Kelas 6 semester 1' => 'peringkat_k6_s1',
            'Kategori prestasi' => 'kategori_hafalan',
            'Jumlah hafalan' => 'jumlah_hafalan',
            'Riwayat lomba' => 'riwayat_lomba',
            'Lomba yang dimenangkan' => 'daftar_lomba',
        ] as $label => $column) {
            if (trim((string) ($selectionData[$column] ?? '')) !== '') {
                $achievementRows[] = [$label, $selectionData[$column]];
            }
        }
    }

    $documentLabels = $isCompleteFile
        ? ['foto' => 'Foto peserta didik', 'kk' => 'Scan Kartu Keluarga', 'akta_lahir' => 'Scan Akta Kelahiran', 'ktp_ayah' => 'Scan KTP Ayah', 'ktp_ibu' => 'Scan KTP Ibu']
        : ($isAchievementRoute ? ['surat_prestasi' => 'Surat pengantar prestasi'] : []);
    $documents = [];
    $documentQuery = mysqli_prepare($conn, 'SELECT id,jenis_dokumen,mime_type,path_file FROM tb_dokumen_peserta WHERE id_pendaftaran=?');
    mysqli_stmt_bind_param($documentQuery, 's', $registrationId);
    mysqli_stmt_execute($documentQuery);
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($documentQuery), MYSQLI_ASSOC) as $document) {
        $documents[$document['jenis_dokumen']] = $document;
    }
    mysqli_stmt_close($documentQuery);

    $attachments = [];
    foreach ($documentLabels as $slot => $label) {
        if (isset($documents[$slot])) {
            $attachments[] = [
                'label' => $label,
                'url' => 'dokumen-admin.php?id='.(int) $documents[$slot]['id'],
                'mime' => $documents[$slot]['mime_type'],
                'subdir' => 'participant-documents/'.$registrationId,
                'path' => (string) $documents[$slot]['path_file'],
            ];
        }
    }
    if (!$isCompleteFile) {
        $paymentQuery = mysqli_prepare($conn, 'SELECT id,mime_type,path_file FROM tb_bukti_pembayaran WHERE id_pendaftaran=? ORDER BY id DESC LIMIT 1');
        mysqli_stmt_bind_param($paymentQuery, 's', $registrationId);
        mysqli_stmt_execute($paymentQuery);
        $latestPayment = mysqli_fetch_assoc(mysqli_stmt_get_result($paymentQuery));
        mysqli_stmt_close($paymentQuery);
        if ($latestPayment) {
            $attachments[] = [
                'label' => 'Bukti transfer',
                'url' => 'bukti-pembayaran-admin.php?id='.(int) $latestPayment['id'],
                'mime' => $latestPayment['mime_type'],
                'subdir' => 'payment-proofs',
                'path' => (string) $latestPayment['path_file'],
            ];
        }
    }

    $sections = [['Data registrasi', array_map(null, array_keys($registrationRows), $registrationRows)]];
    if ($achievementRows) {
        $sections[] = ['Jalur dan prestasi', $achievementRows];
    }
    if ($isCompleteFile) {
        $savedValues = formulir_saved_values($conn, $registrationId);
        foreach (formulir_sections_for(null, (string) ($participant['jenjang'] ?? '')) as $section) {
            $rows = [];
            foreach ($section['fields'] as $field) {
                if (($field['type'] ?? 'text') === 'heading' || !formulir_condition_met($field, $savedValues)) {
                    continue;
                }
                $value = formulir_normalize_value($savedValues[$field['name']] ?? '', $field);
                if (($field['type'] ?? '') === 'select' && $value !== '') {
                    $value = (string) ($field['options'][$value] ?? $value);
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    $value = ppdb_format_date_id($value);
                }
                $rows[] = [$field['label'], $value];
            }
            if ($rows) {
                $sections[] = [$section['title'], $rows];
            }
        }
    }
    $emptyCount = 0;
    foreach ($sections as [, $sectionRows]) {
        $emptyCount += count(array_filter($sectionRows, static fn (array $row): bool => cetak_registrasi_is_empty($row[1])));
    }

    return [
        'title' => $isCompleteFile ? 'DATA LENGKAP PESERTA DIDIK' : 'DATA REGISTRASI PESERTA DIDIK',
        'sections' => $sections,
        'attachments' => $attachments,
        'empty_count' => $emptyCount,
    ];
}
