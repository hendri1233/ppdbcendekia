<?php
require_once 'koneksi.php';
header('Content-Type: application/json');
echo json_encode([
    'post_keys' => array_keys($_POST),
    'files' => array_map(static fn($f) => is_array($f) ? array_map(static fn($x) => is_array($x) ? ($x['error'] ?? 'no-err') : $x, $f) : $f, $_FILES),
    'post_max' => ini_get('post_max_size'),
    'upload_max' => ini_get('upload_max_filesize'),
    'content_len' => $_SERVER['CONTENT_LENGTH'] ?? null,
], JSON_PRETTY_PRINT);
