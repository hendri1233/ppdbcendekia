<?php

require_once __DIR__.'/app_helpers.php';

function ppdb_material_uploaded_file(array $files, string $fieldName): ?array
{
    if (!isset($files['name'][$fieldName])) {
        return null;
    }

    return [
        'name' => $files['name'][$fieldName],
        'type' => $files['type'][$fieldName] ?? '',
        'tmp_name' => $files['tmp_name'][$fieldName] ?? '',
        'error' => $files['error'][$fieldName] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$fieldName] ?? 0,
    ];
}

function ppdb_material_has_preview(array $material): bool
{
    $mime = (string) ($material['mime_file_pratinjau'] ?? '');
    if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
        return false;
    }

    $path = (string) ($material['path_file_pratinjau'] ?? '');
    return $path !== '' && ppdb_resolve_stored_file('ppdb-materials', $path) !== null;
}

function ppdb_material_has_original(array $material): bool
{
    $path = (string) ($material['path_file_asli'] ?? '');
    return $path !== '' && ppdb_resolve_stored_file('ppdb-materials', $path) !== null;
}

function ppdb_sanitize_rich_text(string $html): string
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $previousSetting = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div id="ppdb-rich-root">'.$html.'</div>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
    libxml_clear_errors();
    libxml_use_internal_errors($previousSetting);

    $root = $document->getElementById('ppdb-rich-root');
    if (!$root) {
        return '';
    }

    $allowedTags = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'blockquote', 'a'];
    $sanitizeNode = static function (DOMNode $node) use (&$sanitizeNode, $allowedTags): void {
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if (!in_array($tag, $allowedTags, true)) {
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'], true)) {
                    $node->parentNode?->removeChild($node);
                    return;
                }
                foreach (iterator_to_array($node->childNodes) as $child) {
                    $sanitizeNode($child);
                }
                $parent = $node->parentNode;
                if ($parent) {
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                }
                return;
            }

            $href = $tag === 'a' ? trim($node->getAttribute('href')) : '';
            while ($node->attributes->length > 0) {
                $node->removeAttributeNode($node->attributes->item(0));
            }
            if ($tag === 'a' && preg_match('/^(https?:\/\/|mailto:)/i', $href)) {
                $node->setAttribute('href', $href);
                $node->setAttribute('rel', 'noopener noreferrer');
                $node->setAttribute('target', '_blank');
            }
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            $sanitizeNode($child);
        }
    };

    foreach (iterator_to_array($root->childNodes) as $child) {
        $sanitizeNode($child);
    }

    $cleanHtml = '';
    foreach ($root->childNodes as $child) {
        $cleanHtml .= $document->saveHTML($child);
    }
    return trim($cleanHtml);
}

function ppdb_validate_questions($input): array
{
    if (!is_array($input)) {
        throw new RuntimeException('Daftar kuisioner tidak valid.');
    }
    $questions = [];
    foreach ($input as $index => $question) {
        if (!is_array($question)) {
            continue;
        }
        $prompt = trim((string) ($question['prompt'] ?? ''));
        $type = (string) ($question['type'] ?? 'text');
        $choiceInput = $question['choices'] ?? '';
        $choiceValues = is_array($choiceInput)
            ? array_map(static fn ($choice) => (string) $choice, $choiceInput)
            : explode(';', (string) $choiceInput);
        $choices = array_values(array_filter(array_map('trim', $choiceValues), static fn ($choice) => $choice !== ''));
        if ($prompt === '' && $type === 'text' && !$choices) {
            continue;
        }
        if ($prompt === '' || mb_strlen($prompt) > 500) {
            throw new RuntimeException('Setiap pertanyaan wajib diisi dan maksimal 500 karakter.');
        }
        if (!in_array($type, ['text', 'single', 'multiple'], true)) {
            throw new RuntimeException('Tipe kuisioner tidak dikenal.');
        }
        if ($type !== 'text' && (count($choices) < 2 || count($choices) > 50)) {
            throw new RuntimeException('Pertanyaan pilihan harus memiliki 2 sampai 50 opsi yang dipisahkan titik koma.');
        }
        if ($type === 'text' && $choices) {
            throw new RuntimeException('Pilihan hanya diisi untuk pertanyaan pilihan tunggal atau ganda.');
        }
        $key = trim((string) ($question['key'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $key)) {
            $key = 'q'.(int) $index;
        }
        $questions[] = [
            'key' => $key,
            'prompt' => $prompt,
            'type' => $type,
            'choices' => $choices,
            'required' => ($question['required'] ?? '') === '1' || ($question['required'] ?? false) === true,
        ];
    }
    if (!$questions) {
        throw new RuntimeException('Tambahkan minimal satu pertanyaan kuisioner sebelum menyimpan.');
    }
    return $questions;
}

function ppdb_validate_optional_questions($input): array
{
    if (!is_array($input)) {
        throw new RuntimeException('Daftar kuisioner tidak valid.');
    }
    foreach ($input as $question) {
        if (is_array($question) && (trim((string) ($question['prompt'] ?? '')) !== '' || trim(is_array($question['choices'] ?? null) ? implode('; ', $question['choices']) : (string) ($question['choices'] ?? '')) !== '')) {
            return ppdb_validate_questions($input);
        }
    }
    return [];
}

function ppdb_read_questionnaire_spreadsheet(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Pilih file CSV atau XLSX berukuran maksimal 5 MB.');
    }
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($extension === 'csv') {
        $handle = fopen($file['tmp_name'], 'rb');
        if (!$handle) {
            throw new RuntimeException('File CSV tidak dapat dibaca.');
        }
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
    } elseif ($extension === 'xlsx') {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Ekstensi PHP ZipArchive diperlukan untuk membaca XLSX.');
        }
        $archive = new ZipArchive();
        if ($archive->open($file['tmp_name']) !== true) {
            throw new RuntimeException('File XLSX tidak dapat dibuka.');
        }
        $workbookXml = $archive->getFromName('xl/workbook.xml');
        $relationshipsXml = $archive->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relationshipsXml === false) {
            $archive->close();
            throw new RuntimeException('File XLSX tidak memiliki informasi lembar kerja yang dapat dibaca.');
        }
        $workbook = simplexml_load_string($workbookXml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
        $relationships = simplexml_load_string($relationshipsXml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
        if ($workbook === false || $relationships === false) {
            $archive->close();
            throw new RuntimeException('Struktur lembar kerja XLSX tidak valid.');
        }
        $sheets = $workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') ?: [];
        $firstSheet = $sheets[0] ?? null;
        $relationshipAttributes = $firstSheet ? $firstSheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships') : null;
        $relationshipId = (string) ($relationshipAttributes['id'] ?? '');
        $worksheetTarget = '';
        foreach ($relationships->xpath('//*[local-name()="Relationship"]') ?: [] as $relationship) {
            if ((string) $relationship['Id'] === $relationshipId) {
                $worksheetTarget = (string) $relationship['Target'];
                break;
            }
        }
        if ($worksheetTarget === '') {
            $archive->close();
            throw new RuntimeException('Lembar pertama pada XLSX tidak dapat ditemukan.');
        }
        $worksheetPath = str_starts_with($worksheetTarget, '/')
            ? ltrim($worksheetTarget, '/')
            : (str_starts_with($worksheetTarget, 'xl/') ? $worksheetTarget : 'xl/'.$worksheetTarget);
        $pathParts = [];
        foreach (explode('/', $worksheetPath) as $pathPart) {
            if ($pathPart === '' || $pathPart === '.') {
                continue;
            }
            if ($pathPart === '..') {
                if (!$pathParts) {
                    $archive->close();
                    throw new RuntimeException('Jalur lembar pertama XLSX tidak valid.');
                }
                array_pop($pathParts);
                continue;
            }
            $pathParts[] = $pathPart;
        }
        $worksheet = $archive->getFromName(implode('/', $pathParts));
        if ($worksheet === false) {
            $archive->close();
            throw new RuntimeException('File XLSX tidak memiliki lembar pertama yang dapat dibaca.');
        }
        $sharedStrings = [];
        $sharedXml = $archive->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $sharedDocument = simplexml_load_string($sharedXml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
            if ($sharedDocument === false) {
                $archive->close();
                throw new RuntimeException('Daftar teks pada XLSX tidak valid.');
            }
            foreach ($sharedDocument->xpath('//*[local-name()="si"]') ?: [] as $sharedItem) {
                $sharedStrings[] = implode('', array_map('strval', $sharedItem->xpath('.//*[local-name()="t"]') ?: []));
            }
        }
        $sheetDocument = simplexml_load_string($worksheet, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
        $archive->close();
        if ($sheetDocument === false) {
            throw new RuntimeException('Isi lembar pertama XLSX tidak valid.');
        }
        $rows = [];
        foreach ($sheetDocument->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $xmlRow) {
            $cells = [];
            foreach ($xmlRow->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                $reference = (string) $cell['r'];
                preg_match('/^[A-Z]+/', strtoupper($reference), $columnMatch);
                $columnIndex = 0;
                foreach (str_split($columnMatch[0] ?? 'A') as $letter) {
                    $columnIndex = $columnIndex * 26 + ord($letter) - 64;
                }
                $columnIndex--;
                $cellType = (string) $cell['t'];
                if ($cellType === 'inlineStr') {
                    $value = implode('', array_map('strval', $cell->xpath('.//*[local-name()="t"]') ?: []));
                } else {
                    $raw = (string) ($cell->v ?? '');
                    $value = $cellType === 's' ? (string) ($sharedStrings[(int) $raw] ?? '') : $raw;
                }
                $cells[$columnIndex] = $value;
            }
            if ($cells) {
                ksort($cells);
                $rows[] = $cells;
            }
        }
    } else {
        throw new RuntimeException('Gunakan CSV atau XLSX. File XLS lama (.xls) tidak didukung.');
    }

    if (count($rows) < 2) {
        throw new RuntimeException('Spreadsheet harus memiliki baris judul dan sedikitnya satu pertanyaan.');
    }
    $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($rows[0][0] ?? ''));
    $headers = array_map(static fn ($value) => mb_strtolower(trim((string) $value)), $rows[0]);
    $requiredHeaders = ['pertanyaan', 'tipe', 'pilihan'];
    foreach ($requiredHeaders as $header) {
        if (!in_array($header, $headers, true)) {
            throw new RuntimeException('Judul kolom wajib: Pertanyaan, Tipe, dan Pilihan. Kolom Wajib bersifat opsional.');
        }
    }
    $column = array_flip($headers);
    $typeMap = [
        'teks' => 'text',
        'text' => 'text',
        'pilihan tunggal' => 'single',
        'single' => 'single',
        'pilihan ganda' => 'multiple',
        'multiple' => 'multiple',
    ];
    $questions = [];
    foreach (array_slice($rows, 1) as $index => $row) {
        $prompt = trim((string) ($row[$column['pertanyaan']] ?? ''));
        if ($prompt === '') {
            continue;
        }
        $sourceType = mb_strtolower(trim((string) ($row[$column['tipe']] ?? '')));
        if (!isset($typeMap[$sourceType])) {
            throw new RuntimeException('Tipe pertanyaan pada baris '.($index + 2).' tidak valid.');
        }
        $requiredColumn = $column['wajib'] ?? null;
        $requiredValue = $requiredColumn === null ? 'ya' : mb_strtolower(trim((string) ($row[$requiredColumn] ?? 'ya')));
        $questions[] = [
            'key' => 'import_'.($index + 1),
            'prompt' => $prompt,
            'type' => $typeMap[$sourceType],
            'choices' => trim((string) ($row[$column['pilihan']] ?? '')),
            'required' => in_array($requiredValue, ['ya', 'yes', '1', 'wajib'], true) ? '1' : '',
        ];
    }
    if (!$questions) {
        throw new RuntimeException('Tidak ada pertanyaan yang ditemukan pada spreadsheet.');
    }
    return $questions;
}
