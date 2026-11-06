<?php
/**
 * Mode maintenance: saat aktif, seluruh halaman publik menampilkan pemberitahuan
 * pemeliharaan. Halaman admin dan admin yang sedang masuk tetap dapat bekerja.
 */

const PPDB_MAINTENANCE_KEY = 'maintenance_mode';

function ppdb_maintenance_active(mysqli $conn): bool
{
    $result = @mysqli_query($conn, "SELECT setting_value FROM tb_cms_settings WHERE setting_key='".PPDB_MAINTENANCE_KEY."' LIMIT 1");
    $row = $result ? mysqli_fetch_row($result) : null;
    return $row && $row[0] === '1';
}

function ppdb_maintenance_set(mysqli $conn, bool $active): void
{
    $key = PPDB_MAINTENANCE_KEY;
    $value = $active ? '1' : '0';
    $stmt = mysqli_prepare($conn, 'INSERT INTO tb_cms_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    mysqli_stmt_bind_param($stmt, 'ss', $key, $value);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/** Halaman admin dikenali dari pemeriksaan login di awal berkasnya; halaman itu sendiri mengarahkan ke login. */
function ppdb_maintenance_is_admin_script(string $script): bool
{
    $name = basename($script);
    if (in_array($name, ['login.php', 'keluar.php', 'register.php'], true)) {
        return true;
    }
    if ($name === 'ppdb-materi-file.php') {
        return false;
    }
    $source = is_file($script) ? (string) @file_get_contents($script, false, null, 0, 4096) : '';
    return (bool) preg_match('/^require(_once)?\b.*\b(admin_cms|admin_header)\.php|^require_(super_)?admin\(\);/m', $source);
}

function ppdb_maintenance_guard(mysqli $conn): void
{
    if (PHP_SAPI === 'cli' || !ppdb_maintenance_active($conn)) {
        return;
    }
    $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
    if (ppdb_maintenance_is_admin_script($script)) {
        return;
    }
    // Admin yang sedang masuk tetap melihat situs untuk memeriksa perubahan.
    if (!empty($_COOKIE[session_name()])) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'httponly' => true,
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        if (!empty($_SESSION['admin_id'])) {
            define('PPDB_MAINTENANCE_PREVIEW', true);
            return;
        }
    }
    ppdb_maintenance_render();
}

function ppdb_maintenance_render(): void
{
    http_response_code(503);
    header('Retry-After: 3600');
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Website sedang maintenance. Silakan coba lagi nanti.']);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $logo = htmlspecialchars($base.'/img/logo-display.png', ENT_QUOTES, 'UTF-8');
    $icon = htmlspecialchars($base.'/img/favicons/favicon-32x32.png', ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Website Sedang Maintenance · Yayasan Wakaf Cendekia Takengon</title>
<link rel="icon" type="image/png" href="{$icon}">
<style>
:root{--ink:#15201b;--muted:#5b6862;--brand:#1a6a4e;--soft:#e0eee7;--bg:#f4f7f5;--card:#fff;--line:#dfe4e0}
@media (prefers-color-scheme:dark){:root{--ink:#e8eeea;--muted:#a3b0aa;--brand:#8cc4ad;--soft:#163a2d;--bg:#0c1512;--card:#13201b;--line:#24342d}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px 16px;background:var(--bg);color:var(--ink);font:16px/1.6 "Plus Jakarta Sans",system-ui,-apple-system,"Segoe UI",sans-serif}
main{width:100%;max-width:520px;padding:40px 32px;text-align:center;background:var(--card);border:1px solid var(--line);border-radius:20px;box-shadow:0 20px 50px -30px rgba(12,46,35,.35)}
img{display:block;width:72px;height:72px;margin:0 auto;object-fit:contain}
.badge{display:inline-flex;align-items:center;gap:8px;margin:20px 0 12px;padding:6px 14px;color:var(--brand);background:var(--soft);border-radius:999px;font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.badge span{width:8px;height:8px;border-radius:50%;background:currentColor;animation:pulse 1.6s ease-in-out infinite}
h1{margin:0 0 10px;font-size:clamp(24px,5vw,30px);line-height:1.25}
p{margin:0;color:var(--muted)}
small{display:block;margin-top:28px;color:var(--muted);font-size:13px}
@keyframes pulse{50%{opacity:.3}}
@media (prefers-reduced-motion:reduce){.badge span{animation:none}}
</style>
</head>
<body>
<main>
<img src="{$logo}" alt="Logo Yayasan Wakaf Cendekia Takengon" width="72" height="72">
<div class="badge"><span aria-hidden="true"></span>Maintenance</div>
<h1>Website sedang maintenance</h1>
<p>Kami sedang melakukan pemeliharaan agar layanan lebih baik. Silakan kembali beberapa saat lagi. Terima kasih atas kesabaran Anda.</p>
<small>Yayasan Wakaf Cendekia Takengon</small>
</main>
</body>
</html>
HTML;
    exit;
}
