<?php
require_once 'app_helpers.php';
require_once 'ppdb_material_helpers.php';
require_once 'koneksi.php';
require_admin();

$materialTypes = [
    'brosur' => 'Brosur PPDB',
    'rincian_biaya' => 'Rincian biaya',
    'sop' => 'SOP pendaftaran',
];
$questions = [];
$materials = [];
$errors = [];
$notice = '';
$unitRows = mysqli_query($conn, "SELECT kode_unit,nama_unit FROM tb_unit_pendidikan ORDER BY FIELD(jenjang,'KB','TPA','TK','SD','SMP')");
$units = [];
while ($unit = mysqli_fetch_assoc($unitRows)) {
    $units[$unit['kode_unit']] = $unit['nama_unit'];
}
$yearRows = mysqli_query($conn, 'SELECT DISTINCT th_ajaran FROM tb_pengaturan_ppdb ORDER BY th_ajaran DESC');
$years = [];
while ($year = mysqli_fetch_assoc($yearRows)) {
    $years[] = $year['th_ajaran'];
}
$selectedUnit = trim((string) ($_POST['kode_unit'] ?? $_GET['unit'] ?? array_key_first($units) ?? ''));
$selectedYear = trim((string) ($_POST['th_ajaran'] ?? $_GET['tahun'] ?? ($years[0] ?? '')));
if (!isset($units[$selectedUnit])) {
    $selectedUnit = (string) (array_key_first($units) ?? '');
}
if (!in_array($selectedYear, $years, true)) {
    $selectedYear = (string) ($years[0] ?? '');
}

$unitYearSummary = [];
$summaryRows = mysqli_query($conn, 'SELECT s.kode_unit,s.th_ajaran,u.nama_unit FROM tb_pengaturan_ppdb s INNER JOIN tb_unit_pendidikan u ON u.kode_unit=s.kode_unit ORDER BY s.th_ajaran DESC,FIELD(u.jenjang,"KB","TPA","TK","SD","SMP"),u.nama_unit');
while ($summary = mysqli_fetch_assoc($summaryRows)) {
    $summaryKey = $summary['kode_unit'].'|'.$summary['th_ajaran'];
    $unitYearSummary[$summaryKey] = [
        'kode_unit' => $summary['kode_unit'],
        'th_ajaran' => $summary['th_ajaran'],
        'nama_unit' => $summary['nama_unit'],
        'materials' => [],
        'question_count' => 0,
    ];
}
function ppdb_material_upload(array $file, string $directory, string $role): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 20 * 1024 * 1024) {
        throw new RuntimeException('File materi berukuran maksimal 20 MB dan harus berhasil diunggah.');
    }
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($file['tmp_name']);
    if ($role === 'preview') {
        $allowed = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        if (!isset($allowed[$extension]) || $mime !== $allowed[$extension]) {
            throw new RuntimeException('File pratinjau harus PDF, JPG, atau PNG yang valid.');
        }
    } else {
        $valid = false;
        if (in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            $valid = ($extension === 'pdf' && $mime === 'application/pdf')
                || (in_array($extension, ['jpg', 'jpeg'], true) && $mime === 'image/jpeg')
                || ($extension === 'png' && $mime === 'image/png');
        } elseif ($extension === 'psd') {
            $handle = fopen($file['tmp_name'], 'rb');
            $signature = $handle ? fread($handle, 4) : '';
            if (is_resource($handle)) {
                fclose($handle);
            }
            $valid = $signature === '8BPS' && in_array($mime, ['image/vnd.adobe.photoshop', 'application/octet-stream'], true);
        } elseif ($extension === 'docx') {
            $zip = new ZipArchive();
            if ($zip->open($file['tmp_name']) === true) {
                $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false;
                $zip->close();
            }
        } elseif ($extension === 'doc') {
            $handle = fopen($file['tmp_name'], 'rb');
            $signature = $handle ? fread($handle, 8) : '';
            if (is_resource($handle)) {
                fclose($handle);
            }
            $valid = str_starts_with($signature, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
        }
        if (!$valid) {
            throw new RuntimeException('File materi harus PDF, JPG, PNG, DOC, DOCX, atau PSD yang valid.');
        }
        $mime = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'psd' => 'image/vnd.adobe.photoshop',
        };
    }

    $root = getenv('PPDB_PAYMENT_STORAGE') ?: 'C:/xampp/private/ppdb-payment-proofs';
    $target = rtrim(str_replace('\\', '/', $root), '/').'/'.$directory;
    if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
        throw new RuntimeException('Folder privat materi belum dapat dibuat.');
    }
    $realDirectory = realpath($target);
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    if (!$realDirectory || ($documentRoot && strpos(strtolower($realDirectory), strtolower($documentRoot.DIRECTORY_SEPARATOR)) === 0)) {
        throw new RuntimeException('Materi harus disimpan di luar direktori publik situs.');
    }
    $storedPath = $realDirectory.DIRECTORY_SEPARATOR.bin2hex(random_bytes(24)).'.'.$extension;
    if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
        throw new RuntimeException('File materi tidak dapat disimpan.');
    }
    return [
        'name' => basename((string) $file['name']),
        'path' => $storedPath,
        'mime' => $mime,
        'size' => (int) $file['size'],
        'extension' => $extension,
    ];
}

function ppdb_admin_read_question_rows(array $input): array
{
    $rows = [];
    foreach ($input as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $rows[$index] = [
            'key' => (string) ($row['key'] ?? ''),
            'prompt' => (string) ($row['prompt'] ?? ''),
            'type' => (string) ($row['type'] ?? 'text'),
            'choices' => (string) ($row['choices'] ?? ''),
            'required' => !empty($row['required']) ? '1' : '',
        ];
    }
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba kembali.';
    } elseif (!isset($units[$selectedUnit]) || !in_array($selectedYear, $years, true)) {
        $errors[] = 'Pilih unit dan tahun ajaran yang valid.';
    } elseif (($_POST['action'] ?? '') === 'import_questions') {
        try {
            $questions = ppdb_read_questionnaire_spreadsheet($_FILES['questionnaire_file'] ?? []);
            $notice = count($questions).' pertanyaan berhasil dibaca. Tinjau dan simpan kuisioner untuk menerbitkannya.';
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        }
    } elseif (($_POST['action'] ?? '') === 'delete_material_file') {
        $materialType = (string) ($_POST['jenis_materi'] ?? '');
        $transactionStarted = false;
        try {
            if (!isset($materialTypes[$materialType])) {
                throw new RuntimeException('Jenis materi tidak valid.');
            }

            mysqli_begin_transaction($conn);
            $transactionStarted = true;
            $existingStatement = mysqli_prepare($conn, 'SELECT id,isi_html,path_file_asli,path_file_pratinjau FROM tb_ppdb_materials WHERE kode_unit=? AND th_ajaran=? AND jenis_materi=? FOR UPDATE');
            mysqli_stmt_bind_param($existingStatement, 'sss', $selectedUnit, $selectedYear, $materialType);
            mysqli_stmt_execute($existingStatement);
            $existingMaterial = mysqli_fetch_assoc(mysqli_stmt_get_result($existingStatement));
            mysqli_stmt_close($existingStatement);
            if (!$existingMaterial || (empty($existingMaterial['path_file_asli']) && empty($existingMaterial['path_file_pratinjau']))) {
                throw new RuntimeException('Tidak ada file tersimpan untuk dihapus pada materi ini.');
            }

            $filePaths = array_values(array_unique(array_filter([
                $existingMaterial['path_file_asli'],
                $existingMaterial['path_file_pratinjau'],
            ])));
            if (trim((string) $existingMaterial['isi_html']) === '') {
                $deleteStatement = mysqli_prepare($conn, 'DELETE FROM tb_ppdb_materials WHERE id=?');
                mysqli_stmt_bind_param($deleteStatement, 'i', $existingMaterial['id']);
            } else {
                $adminId = (int) $_SESSION['admin_id'];
                $deleteStatement = mysqli_prepare($conn, 'UPDATE tb_ppdb_materials SET nama_file_asli=NULL,path_file_asli=NULL,mime_file_asli=NULL,ukuran_file_asli=NULL,nama_file_pratinjau=NULL,path_file_pratinjau=NULL,mime_file_pratinjau=NULL,ukuran_file_pratinjau=NULL,updated_by=? WHERE id=?');
                mysqli_stmt_bind_param($deleteStatement, 'ii', $adminId, $existingMaterial['id']);
            }
            mysqli_stmt_execute($deleteStatement);
            mysqli_stmt_close($deleteStatement);
            mysqli_commit($conn);
            $transactionStarted = false;

            $failedCleanup = [];
            foreach ($filePaths as $storedPath) {
                $filePath = ppdb_resolve_stored_file('ppdb-materials', (string) $storedPath);
                if ($filePath && is_file($filePath) && !unlink($filePath)) {
                    $failedCleanup[] = basename($filePath);
                }
            }
            $notice = 'File '.$materialTypes[$materialType].' untuk '.$units[$selectedUnit].' · '.$selectedYear.' berhasil dihapus. Isi materi dan kuisioner tetap tersimpan.';
            if ($failedCleanup) {
                error_log('PPDB material file cleanup failed after record deletion: '.implode(', ', $failedCleanup));
                $errors[] = 'Data file sudah dihapus dari sistem, tetapi file fisik berikut belum dapat dibersihkan: '.implode(', ', $failedCleanup).'.';
            }
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                mysqli_rollback($conn);
            }
            if ($exception instanceof RuntimeException) {
                $errors[] = $exception->getMessage();
            } else {
                error_log('PPDB material file deletion failed: '.$exception->getMessage());
                $errors[] = 'File belum dapat dihapus. Periksa status penyimpanan dan coba kembali.';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'save') {
        $questions = ppdb_admin_read_question_rows($_POST['questions'] ?? []);
        $newFiles = [];
        $oldFiles = [];
        $transactionStarted = false;
        try {
            $questionRows = is_array($_POST['questions'] ?? null) ? $_POST['questions'] : [];
            $validQuestionnaire = ppdb_validate_optional_questions($questionRows);
            $existingStatement = mysqli_prepare($conn, 'SELECT * FROM tb_ppdb_materials WHERE kode_unit=? AND th_ajaran=?');
            mysqli_stmt_bind_param($existingStatement, 'ss', $selectedUnit, $selectedYear);
            mysqli_stmt_execute($existingStatement);
            $existingResult = mysqli_stmt_get_result($existingStatement);
            while ($existing = mysqli_fetch_assoc($existingResult)) {
                $materials[$existing['jenis_materi']] = $existing;
            }
            mysqli_stmt_close($existingStatement);

            mysqli_begin_transaction($conn);
            $transactionStarted = true;
            $settingStatement = mysqli_prepare($conn, 'SELECT kode_unit FROM tb_pengaturan_ppdb WHERE kode_unit=? AND th_ajaran=? FOR UPDATE');
            mysqli_stmt_bind_param($settingStatement, 'ss', $selectedUnit, $selectedYear);
            mysqli_stmt_execute($settingStatement);
            $settingExists = mysqli_fetch_assoc(mysqli_stmt_get_result($settingStatement));
            mysqli_stmt_close($settingStatement);
            if (!$settingExists) {
                throw new RuntimeException('Konfigurasi PPDB untuk unit/tahun ini belum tersedia.');
            }
            $adminId = (int) $_SESSION['admin_id'];
            foreach ($materialTypes as $type => $label) {
                $existing = $materials[$type] ?? null;
                $body = ppdb_sanitize_rich_text((string) ($_POST['isi_html'][$type] ?? ''));
                $original = null;
                $preview = null;
                $originalFile = ppdb_material_uploaded_file($_FILES['materi_asli'] ?? [], $type);
                $previewFile = ppdb_material_uploaded_file($_FILES['materi_pratinjau'] ?? [], $type);
                $hasOriginal = is_array($originalFile) && ($originalFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                $hasPreview = is_array($previewFile) && ($previewFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                $directory = 'ppdb-materials/'.$selectedUnit.'/'.str_replace('/', '-', $selectedYear).'/'.$type;
                if ($hasOriginal) {
                    $original = ppdb_material_upload($originalFile, $directory, 'original');
                    $newFiles[] = $original['path'];
                    if (in_array($original['extension'], ['pdf', 'jpg', 'jpeg', 'png'], true)) {
                        $preview = $original;
                    } elseif (!$hasPreview) {
                        throw new RuntimeException($label.': unggah PDF/JPG/PNG pratinjau untuk file Word atau PSD.');
                    }
                }
                if ($hasPreview) {
                    $preview = ppdb_material_upload($previewFile, $directory, 'preview');
                    $newFiles[] = $preview['path'];
                }
                $originalName = $original['name'] ?? ($existing['nama_file_asli'] ?? null);
                $originalPath = $original['path'] ?? ($existing['path_file_asli'] ?? null);
                $originalMime = $original['mime'] ?? ($existing['mime_file_asli'] ?? null);
                $originalSize = $original['size'] ?? ($existing['ukuran_file_asli'] ?? null);
                $previewName = $preview['name'] ?? ($existing['nama_file_pratinjau'] ?? null);
                $previewPath = $preview['path'] ?? ($existing['path_file_pratinjau'] ?? null);
                $previewMime = $preview['mime'] ?? ($existing['mime_file_pratinjau'] ?? null);
                $previewSize = $preview['size'] ?? ($existing['ukuran_file_pratinjau'] ?? null);
                if ($original && !$hasPreview && !in_array($original['extension'], ['pdf', 'jpg', 'jpeg', 'png'], true)) {
                    throw new RuntimeException($label.': pratinjau wajib disertakan.');
                }
                if (!$body && !$originalPath) {
                    if ($existing) {
                        foreach (['path_file_asli', 'path_file_pratinjau'] as $pathKey) {
                            if (!empty($existing[$pathKey])) {
                                $oldFiles[] = $existing[$pathKey];
                            }
                        }
                        $delete = mysqli_prepare($conn, 'DELETE FROM tb_ppdb_materials WHERE kode_unit=? AND th_ajaran=? AND jenis_materi=?');
                        mysqli_stmt_bind_param($delete, 'sss', $selectedUnit, $selectedYear, $type);
                        mysqli_stmt_execute($delete);
                        mysqli_stmt_close($delete);
                    }
                    $materials[$type] = null;
                    continue;
                }
                if ($existing) {
                    foreach (['path_file_asli' => $originalPath, 'path_file_pratinjau' => $previewPath] as $key => $newPath) {
                        if (!empty($existing[$key]) && $existing[$key] !== $newPath) {
                            $oldFiles[] = $existing[$key];
                        }
                    }
                }
                $upsert = mysqli_prepare($conn, 'INSERT INTO tb_ppdb_materials (kode_unit,th_ajaran,jenis_materi,isi_html,nama_file_asli,path_file_asli,mime_file_asli,ukuran_file_asli,nama_file_pratinjau,path_file_pratinjau,mime_file_pratinjau,ukuran_file_pratinjau,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE isi_html=VALUES(isi_html),nama_file_asli=VALUES(nama_file_asli),path_file_asli=VALUES(path_file_asli),mime_file_asli=VALUES(mime_file_asli),ukuran_file_asli=VALUES(ukuran_file_asli),nama_file_pratinjau=VALUES(nama_file_pratinjau),path_file_pratinjau=VALUES(path_file_pratinjau),mime_file_pratinjau=VALUES(mime_file_pratinjau),ukuran_file_pratinjau=VALUES(ukuran_file_pratinjau),updated_by=VALUES(updated_by)');
                mysqli_stmt_bind_param($upsert, 'sssssssisssii', $selectedUnit, $selectedYear, $type, $body, $originalName, $originalPath, $originalMime, $originalSize, $previewName, $previewPath, $previewMime, $previewSize, $adminId);
                mysqli_stmt_execute($upsert);
                mysqli_stmt_close($upsert);
            }

            if ($validQuestionnaire) {
                $questionsJson = json_encode($validQuestionnaire, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $questionnaire = mysqli_prepare($conn, 'INSERT INTO tb_ppdb_questionnaires (kode_unit,th_ajaran,questions_json,updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE questions_json=VALUES(questions_json),updated_by=VALUES(updated_by)');
                mysqli_stmt_bind_param($questionnaire, 'sssi', $selectedUnit, $selectedYear, $questionsJson, $adminId);
                mysqli_stmt_execute($questionnaire);
                mysqli_stmt_close($questionnaire);
            }
            mysqli_commit($conn);
            $transactionStarted = false;
            foreach (array_unique($oldFiles) as $oldFile) {
                if (is_file($oldFile)) {
                    unlink($oldFile);
                }
            }
            $notice = $validQuestionnaire
                ? 'Materi dan kuisioner berhasil disimpan untuk '.$units[$selectedUnit].' · '.$selectedYear.'.'
                : 'Materi berhasil disimpan untuk '.$units[$selectedUnit].' · '.$selectedYear.'. Kuisioner sebelumnya tidak diubah.';
            $questions = array_map(static fn ($question) => [
                'key' => $question['key'],
                'prompt' => $question['prompt'],
                'type' => $question['type'],
                'choices' => implode('; ', $question['choices']),
                'required' => $question['required'] ? '1' : '',
            ], $validQuestionnaire);
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                mysqli_rollback($conn);
            }
            foreach ($newFiles as $newFile) {
                if (is_file($newFile)) {
                    unlink($newFile);
                }
            }
            if ($exception instanceof RuntimeException) {
                $errors[] = $exception->getMessage();
            } else {
                error_log('PPDB material save failed: '.$exception->getMessage());
                $errors[] = 'Materi belum dapat disimpan. Periksa file dan coba lagi.';
            }
        }
    } else {
        $errors[] = 'Aksi tidak dikenal.';
    }
}

{
    $materialStatement = mysqli_prepare($conn, 'SELECT * FROM tb_ppdb_materials WHERE kode_unit=? AND th_ajaran=?');
    mysqli_stmt_bind_param($materialStatement, 'ss', $selectedUnit, $selectedYear);
    mysqli_stmt_execute($materialStatement);
    $materialResult = mysqli_stmt_get_result($materialStatement);
    while ($row = mysqli_fetch_assoc($materialResult)) {
        $materials[$row['jenis_materi']] = $row;
    }
    mysqli_stmt_close($materialStatement);
    if (!$questions && ($_POST['action'] ?? '') !== 'import_questions') {
        $questionStatement = mysqli_prepare($conn, 'SELECT questions_json FROM tb_ppdb_questionnaires WHERE kode_unit=? AND th_ajaran=? LIMIT 1');
        mysqli_stmt_bind_param($questionStatement, 'ss', $selectedUnit, $selectedYear);
        mysqli_stmt_execute($questionStatement);
        $savedQuestionnaire = mysqli_fetch_assoc(mysqli_stmt_get_result($questionStatement));
        mysqli_stmt_close($questionStatement);
        if ($savedQuestionnaire) {
            $savedQuestions = json_decode($savedQuestionnaire['questions_json'], true);
            if (is_array($savedQuestions)) {
                foreach ($savedQuestions as $question) {
                    $question['choices'] = implode('; ', $question['choices'] ?? []);
                    $question['required'] = !empty($question['required']) ? '1' : '';
                    $questions[] = $question;
                }
            }
        }
    }
}

$summaryMaterialRows = mysqli_query($conn, 'SELECT kode_unit,th_ajaran,jenis_materi,isi_html,nama_file_asli,path_file_pratinjau,mime_file_pratinjau FROM tb_ppdb_materials');
while ($summaryMaterial = mysqli_fetch_assoc($summaryMaterialRows)) {
    $summaryKey = $summaryMaterial['kode_unit'].'|'.$summaryMaterial['th_ajaran'];
    if (!isset($unitYearSummary[$summaryKey])) {
        continue;
    }
    $unitYearSummary[$summaryKey]['materials'][$summaryMaterial['jenis_materi']] = [
        'ready' => trim((string) $summaryMaterial['isi_html']) !== '' || ppdb_material_has_preview($summaryMaterial),
        'file_name' => (string) ($summaryMaterial['nama_file_asli'] ?? ''),
    ];
}
$summaryQuestionRows = mysqli_query($conn, 'SELECT kode_unit,th_ajaran,questions_json FROM tb_ppdb_questionnaires');
while ($summaryQuestion = mysqli_fetch_assoc($summaryQuestionRows)) {
    $summaryKey = $summaryQuestion['kode_unit'].'|'.$summaryQuestion['th_ajaran'];
    $decodedSummaryQuestions = json_decode($summaryQuestion['questions_json'], true);
    if (isset($unitYearSummary[$summaryKey]) && is_array($decodedSummaryQuestions)) {
        $unitYearSummary[$summaryKey]['question_count'] = count($decodedSummaryQuestions);
    }
}

$savedMaterialCount = 0;
foreach ($materialTypes as $type => $_label) {
    $material = $materials[$type] ?? null;
    if ($material && (trim((string) $material['isi_html']) !== '' || ppdb_material_has_preview($material))) {
        $savedMaterialCount++;
    }
}
$selectedSummaryKey = $selectedUnit.'|'.$selectedYear;
$selectedSummary = $unitYearSummary[$selectedSummaryKey] ?? [
    'kode_unit' => $selectedUnit,
    'th_ajaran' => $selectedYear,
    'nama_unit' => $units[$selectedUnit] ?? '',
    'materials' => [],
    'question_count' => 0,
];

if (!$questions) {
    $questions[] = ['key' => 'q0', 'prompt' => '', 'type' => 'text', 'choices' => '', 'required' => '1'];
}
$activeAdminPage = 'materials';
$adminPageTitle = 'Materi & kuisioner';
$adminPageDescription = 'Kelola brosur, rincian biaya, SOP, dan kuisioner per unit dan tahun ajaran.';
require 'admin_header.php';
?>
<div class="admin-content materials-admin">
    <header class="page-heading">
        <div><p class="eyebrow">INFORMASI PENDAFTARAN</p><h1>Materi &amp; kuisioner</h1><p>Kelola materi yang dilihat pendaftar setelah konfirmasi data awal, serta kuisioner sebelum formulir lengkap.</p></div>
    </header>
    <?php foreach ($errors as $error) { ?><div class="alert-error" role="alert"><?php echo h($error); ?></div><?php } ?>
    <?php if ($notice !== '') { ?><div class="alert-success" role="status"><?php echo h($notice); ?></div><?php } ?>

    <section class="panel materials-overview">
        <div class="materials-overview__heading">
            <div>
                <p class="eyebrow">STATUS PUBLIKASI</p>
                <h2>Materi per unit dan tahun ajaran</h2>
                <p>Pilih satu unit untuk meninjau berkas dan mengubah konten. Materi hanya ditampilkan kepada pendaftar pada unit dan tahun ajaran yang sama.</p>
            </div>
            <span class="materials-overview__selected"><?php echo h($units[$selectedUnit] ?? 'Unit belum dipilih'); ?><br><strong><?php echo h($selectedYear); ?></strong></span>
        </div>
        <form method="get" class="filter-bar materials-selector">
            <div class="filter-field"><label for="unit">Unit pendidikan</label><select id="unit" name="unit"><?php foreach ($units as $code => $name) { ?><option value="<?php echo h($code); ?>"<?php echo $selectedUnit === $code ? ' selected' : ''; ?>><?php echo h($name); ?></option><?php } ?></select></div>
            <div class="filter-field"><label for="tahun">Tahun ajaran</label><select id="tahun" name="tahun"><?php foreach ($years as $year) { ?><option value="<?php echo h($year); ?>"<?php echo $selectedYear === $year ? ' selected' : ''; ?>><?php echo h($year); ?></option><?php } ?></select></div>
            <button class="button-primary" type="submit">Tampilkan unit</button>
        </form>
        <div class="materials-unit-grid">
            <?php foreach ($unitYearSummary as $unitSummary) {
                $materialStates = [];
                foreach ($materialTypes as $type => $label) {
                    $materialStates[$type] = !empty($unitSummary['materials'][$type]['ready']);
                }
                $publishedCount = count(array_filter($materialStates));
                $selectedCard = $unitSummary['kode_unit'] === $selectedUnit && $unitSummary['th_ajaran'] === $selectedYear;
                $isReady = $publishedCount === count($materialTypes) && $unitSummary['question_count'] > 0;
                $summaryUrl = 'ppdb-materi.php?'.http_build_query(['unit' => $unitSummary['kode_unit'], 'tahun' => $unitSummary['th_ajaran']]);
            ?>
                <a class="materials-unit-card<?php echo $selectedCard ? ' is-selected' : ''; ?>" href="<?php echo h($summaryUrl); ?>">
                    <span class="materials-unit-card__topline">
                        <span class="materials-unit-card__status<?php echo $isReady ? ' is-ready' : ''; ?>"><?php echo $isReady ? 'Siap ditampilkan' : 'Belum lengkap'; ?></span>
                        <span class="materials-unit-card__year"><?php echo h($unitSummary['th_ajaran']); ?></span>
                    </span>
                    <strong class="materials-unit-card__name"><?php echo h($unitSummary['nama_unit']); ?></strong>
                    <span class="materials-unit-card__counts"><?php echo $publishedCount; ?>/<?php echo count($materialTypes); ?> materi · <?php echo (int) $unitSummary['question_count']; ?> pertanyaan</span>
                    <span class="materials-unit-card__checklist">
                        <?php foreach ($materialTypes as $type => $label) { ?>
                            <span class="<?php echo $materialStates[$type] ? 'is-present' : 'is-missing'; ?>"><?php echo $materialStates[$type] ? '&#10003;' : '&#8212;'; ?> <?php echo h($label); ?></span>
                        <?php } ?>
                    </span>
                    <?php
                    $unitFileNames = [];
                    foreach ($unitSummary['materials'] as $summaryMaterial) {
                        if ($summaryMaterial['file_name'] !== '') {
                            $unitFileNames[] = $summaryMaterial['file_name'];
                        }
                    }
                    if ($unitFileNames) { ?>
                        <span class="materials-unit-card__files"><?php echo h(implode(' · ', $unitFileNames)); ?></span>
                    <?php } ?>
                </a>
            <?php } ?>
        </div>
    </section>

    <section class="panel materials-selected-status">
        <div class="materials-selected-status__copy">
            <span class="materials-selected-status__icon" aria-hidden="true"><?php echo $savedMaterialCount === count($materialTypes) ? '&#10003;' : '&#9432;'; ?></span>
            <div>
                <h2><?php echo $savedMaterialCount === count($materialTypes) ? 'Semua materi unit ini tersedia' : 'Kelengkapan materi unit ini'; ?></h2>
                <p><?php echo h($units[$selectedUnit] ?? ''); ?> · <?php echo h($selectedYear); ?> — <?php echo $savedMaterialCount; ?> dari <?php echo count($materialTypes); ?> materi tersedia dan <?php echo (int) $selectedSummary['question_count']; ?> pertanyaan tersimpan.</p>
            </div>
        </div>
    </section>

    <section class="panel material-import-panel">
        <div class="panel-header"><div><h2>Impor pertanyaan dari spreadsheet</h2><span class="panel-subtitle">XLSX atau CSV. Kolom wajib: Pertanyaan, Tipe, Pilihan; kolom Wajib opsional. Baris pertama lembar pertama digunakan.</span></div></div>
        <form method="post" enctype="multipart/form-data" class="material-import-form">
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="import_questions">
            <input type="hidden" name="kode_unit" value="<?php echo h($selectedUnit); ?>"><input type="hidden" name="th_ajaran" value="<?php echo h($selectedYear); ?>">
            <div class="material-template"><strong>Format tipe:</strong> Teks, Pilihan tunggal, atau Pilihan ganda. Isi Pilihan dengan opsi dipisah titik koma (contoh: Ya; Tidak).</div>
            <label class="material-file-input">Pilih spreadsheet<input type="file" name="questionnaire_file" accept=".xlsx,.csv" required></label>
            <button class="button-secondary" type="submit">Impor dan tinjau</button>
        </form>
    </section>

    <?php foreach ($materialTypes as $type => $_label) { ?>
        <form id="delete-material-file-<?php echo h($type); ?>" method="post" data-delete-material-form data-material-label="<?php echo h($_label); ?>" data-unit-label="<?php echo h($units[$selectedUnit] ?? ''); ?>" data-academic-year="<?php echo h($selectedYear); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="delete_material_file">
            <input type="hidden" name="kode_unit" value="<?php echo h($selectedUnit); ?>">
            <input type="hidden" name="th_ajaran" value="<?php echo h($selectedYear); ?>">
            <input type="hidden" name="jenis_materi" value="<?php echo h($type); ?>">
        </form>
    <?php } ?>

    <form method="post" enctype="multipart/form-data" class="materials-save-form">
        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save">
        <input type="hidden" name="kode_unit" value="<?php echo h($selectedUnit); ?>"><input type="hidden" name="th_ajaran" value="<?php echo h($selectedYear); ?>">
        <?php foreach ($materialTypes as $type => $label) {
            $material = $materials[$type] ?? null;
            $materialReady = $material && (trim((string) $material['isi_html']) !== '' || ppdb_material_has_preview($material));
            $submittedBody = $_POST['isi_html'][$type] ?? null;
            $body = ppdb_sanitize_rich_text((string) ($submittedBody ?? $material['isi_html'] ?? ''));
            $fileQuery = ['id' => (int) ($material['id'] ?? 0), 'file' => 'preview'];
            $previewUrl = 'ppdb-materi-file.php?'.http_build_query($fileQuery);
            $originalUrl = 'ppdb-materi-file.php?'.http_build_query(array_merge($fileQuery, ['file' => 'original']));
        ?>
            <section class="panel material-edit-panel">
                <div class="panel-header"><div><span class="materials-section-kicker"><?php echo h($units[$selectedUnit] ?? ''); ?> · <?php echo h($selectedYear); ?></span><h2><?php echo h($label); ?></h2><span class="panel-subtitle"><?php echo $materialReady ? 'Materi tersimpan dan siap ditampilkan kepada pendaftar.' : 'Materi belum tersedia untuk kombinasi unit dan tahun ini.'; ?></span></div><span class="materials-item-status<?php echo $materialReady ? ' is-present' : ''; ?>"><?php echo $materialReady ? 'Tersimpan' : 'Belum diisi'; ?></span></div>
                <div class="material-editor">
                    <div class="rich-toolbar" role="toolbar" aria-label="Format <?php echo h($label); ?>">
                        <button type="button" data-format="bold"><strong>B</strong></button><button type="button" data-format="italic"><em>I</em></button><button type="button" data-format="underline"><u>U</u></button>
                        <button type="button" data-format="insertUnorderedList">• Daftar</button><button type="button" data-format="insertOrderedList">1. Daftar</button>
                        <button type="button" data-format="formatBlock" data-value="h2">Judul</button><button type="button" data-format="createLink">Tautan</button>
                    </div>
                    <div class="rich-editable" contenteditable="true" data-rich-editor><?php echo $body; ?></div>
                    <textarea name="isi_html[<?php echo h($type); ?>]" data-rich-source hidden><?php echo h($body); ?></textarea>
                </div>
                <div class="material-files">
                    <?php if (!empty($material['nama_file_asli']) || !empty($material['path_file_pratinjau'])) { ?>
                        <div class="material-current-file">
                            <span class="material-current-file__icon" aria-hidden="true">&#128196;</span>
                            <span class="material-current-file__details"><strong><?php echo h($material['nama_file_asli'] ?: $material['nama_file_pratinjau']); ?></strong><small><?php echo ppdb_material_has_preview($material) ? 'Pratinjau peserta tersedia' : 'Pratinjau tidak tersedia; unggah ulang berkas'; ?></small></span>
                            <?php if (ppdb_material_has_original($material)) { ?><a class="material-current-file__link" href="<?php echo h($originalUrl); ?>">Unduh file</a><?php } ?>
                            <button class="material-delete-button" type="submit" form="delete-material-file-<?php echo h($type); ?>">Hapus file</button>
                        </div>
                        <?php if (ppdb_material_has_preview($material) && ($material['mime_file_pratinjau'] ?? '') === 'application/pdf') { ?><iframe class="material-admin-preview" src="<?php echo h($previewUrl); ?>" title="Pratinjau admin <?php echo h($label); ?>"></iframe><?php } elseif (ppdb_material_has_preview($material) && in_array($material['mime_file_pratinjau'] ?? '', ['image/jpeg', 'image/png'], true)) { ?><img class="material-admin-preview-image" src="<?php echo h($previewUrl); ?>" alt="Pratinjau admin <?php echo h($label); ?>"><?php } ?>
                    <?php } ?>
                    <label>File asli (PDF, JPG, PNG, DOC, DOCX, PSD; maksimal 20 MB)<input type="file" name="materi_asli[<?php echo h($type); ?>]" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.psd"></label>
                    <label>Pratinjau untuk Word/PSD atau dokumen lain (PDF, JPG, PNG)<input type="file" name="materi_pratinjau[<?php echo h($type); ?>]" accept=".pdf,.jpg,.jpeg,.png"></label>
                </div>
            </section>
        <?php } ?>

        <section class="panel questionnaire-edit-panel">
            <div class="panel-header"><div><h2>Kuisioner pendaftar</h2><span class="panel-subtitle">Pertanyaan ini ditampilkan sebelum data lengkap dan jawaban disimpan bersama pendaftaran.</span></div><div class="questionnaire-edit-actions"><button class="button-secondary" type="button" data-require-all-questions aria-pressed="false">Wajibkan semua</button><button class="button-secondary" type="button" data-add-question>Tambah pertanyaan</button></div></div>
            <div class="question-editor-list" data-question-list>
                <?php foreach ($questions as $index => $question) { ?>
                    <fieldset class="question-editor" data-question-row>
                        <legend>Pertanyaan <span data-question-number><?php echo $index + 1; ?></span></legend>
                        <input type="hidden" name="questions[<?php echo (int) $index; ?>][key]" value="<?php echo h($question['key'] ?? 'q'.$index); ?>">
                        <label>Pertanyaan<input type="text" name="questions[<?php echo (int) $index; ?>][prompt]" value="<?php echo h($question['prompt'] ?? ''); ?>" maxlength="500"></label>
                        <label>Jenis jawaban<select name="questions[<?php echo (int) $index; ?>][type]" data-question-type><option value="text"<?php echo ($question['type'] ?? '') === 'text' ? ' selected' : ''; ?>>Teks</option><option value="single"<?php echo ($question['type'] ?? '') === 'single' ? ' selected' : ''; ?>>Pilihan tunggal</option><option value="multiple"<?php echo ($question['type'] ?? '') === 'multiple' ? ' selected' : ''; ?>>Pilihan ganda</option></select></label>
                        <label class="question-choices">Pilihan (pisahkan dengan titik koma)<input type="text" name="questions[<?php echo (int) $index; ?>][choices]" value="<?php echo h($question['choices'] ?? ''); ?>" placeholder="Ya; Tidak"></label>
                        <label class="question-required"><input type="checkbox" name="questions[<?php echo (int) $index; ?>][required]" value="1"<?php echo !empty($question['required']) ? ' checked' : ''; ?>> Wajib dijawab</label>
                        <button class="button-secondary question-remove" type="button" data-remove-question>Hapus</button>
                    </fieldset>
                <?php } ?>
            </div>
        </section>
        <div class="materials-save-actions"><button class="button-primary" type="submit">Simpan dan terbitkan</button></div>
    </form>
    <template id="question-editor-template">
        <fieldset class="question-editor" data-question-row>
            <legend>Pertanyaan <span data-question-number></span></legend>
            <input type="hidden" data-question-name="key">
            <label>Pertanyaan<input type="text" data-question-name="prompt" maxlength="500"></label>
            <label>Jenis jawaban<select data-question-name="type" data-question-type><option value="text">Teks</option><option value="single">Pilihan tunggal</option><option value="multiple">Pilihan ganda</option></select></label>
            <label class="question-choices">Pilihan (pisahkan dengan titik koma)<input type="text" data-question-name="choices" placeholder="Ya; Tidak"></label>
            <label class="question-required"><input type="checkbox" data-question-name="required" value="1" checked> Wajib dijawab</label>
            <button class="button-secondary question-remove" type="button" data-remove-question>Hapus</button>
        </fieldset>
    </template>
</div>
<script src="js/materials-editor.js"></script>
<?php require 'admin_footer.php'; ?>
