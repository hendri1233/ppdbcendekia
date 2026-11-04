<?php
/* Ringkasan data pendaftar untuk pembaruan otomatis panel admin (js/admin-live.js). */
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_admin();
session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
echo json_encode(ppdb_admin_live_state($conn) ?? ['rev' => '', 'total' => 0, 'proof' => 0]);
