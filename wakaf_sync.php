<?php
/**
 * Program wakaf di halaman Sosial disalin otomatis dari cendekiabisa.com.
 * Hasilnya disimpan di tb_cms_settings agar halaman tetap cepat; salinan yang
 * kedaluwarsa diperbarui setelah halaman terkirim ke pengunjung.
 */

require_once __DIR__.'/cms_helpers.php';

const WAKAF_SITE = 'https://cendekiabisa.com/';
const WAKAF_PROGRAMS_CACHE_KEY = 'wakaf_programs_cache';
const WAKAF_PROGRAMS_TTL = 6 * 3600;
const WAKAF_PROGRAMS_RETRY = 15 * 60;

/**
 * @param array<int, string> $summaries ringkasan buatan admin per id program, dipakai bila ada
 * @param array $fallback daftar cadangan bila salinan belum pernah berhasil diambil
 */
function wakaf_programs(mysqli $conn, array $summaries, array $fallback): array
{
    $cache = json_decode(cms_setting($conn, WAKAF_PROGRAMS_CACHE_KEY, ''), true);
    $cache = is_array($cache) ? $cache : [];
    $programs = is_array($cache['programs'] ?? null) ? $cache['programs'] : [];
    $now = time();
    $fresh = ($cache['fetched_at'] ?? 0) + WAKAF_PROGRAMS_TTL > $now;
    $retryDue = ($cache['attempted_at'] ?? 0) + WAKAF_PROGRAMS_RETRY <= $now;

    if (!$programs && $retryDue) {
        // Belum ada salinan sama sekali: ambil langsung dengan batas waktu singkat.
        $programs = wakaf_refresh_programs($conn, $cache);
    } elseif (!$fresh && $retryDue) {
        register_shutdown_function(static function () use ($conn, $cache): void {
            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            wakaf_refresh_programs($conn, $cache);
        });
    }

    if (!$programs) {
        return $fallback;
    }
    foreach ($programs as &$program) {
        if (($summaries[$program['id']] ?? '') !== '') {
            $program['summary'] = $summaries[$program['id']];
        }
    }
    unset($program);
    return $programs;
}

/** Mengambil ulang daftar program; bila gagal, salinan lama tetap dipakai. */
function wakaf_refresh_programs(mysqli $conn, array $cache): array
{
    $old = is_array($cache['programs'] ?? null) ? $cache['programs'] : [];
    // Waktu percobaan dicatat lebih dulu agar pengunjung bersamaan tidak mengambil berulang.
    $cache['attempted_at'] = time();
    cms_save_setting($conn, WAKAF_PROGRAMS_CACHE_KEY, (string) json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $programs = wakaf_fetch_programs();
    if ($programs === null) {
        return $old;
    }
    $cache['programs'] = $programs;
    $cache['fetched_at'] = time();
    cms_save_setting($conn, WAKAF_PROGRAMS_CACHE_KEY, (string) json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $programs;
}

/** @return array|null null bila situs tidak dapat dibaca atau tidak berisi program */
function wakaf_fetch_programs(): ?array
{
    if (!function_exists('curl_init') || !class_exists('DOMDocument')) {
        return null;
    }
    $curl = curl_init(WAKAF_SITE.'program.php');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_USERAGENT => 'CendekiaTakengon-Sinkron/1.0 (+https://cendekiatakengon.or.id/sosial)',
    ]);
    $html = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if (!is_string($html) || $status !== 200 || strlen($html) > 3 * 1024 * 1024) {
        return null;
    }
    return wakaf_parse_programs($html);
}

function wakaf_parse_programs(string $html): ?array
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);
    $class = static fn (string $name): string => "contains(concat(' ', normalize-space(@class), ' '), ' {$name} ')";

    $programs = [];
    foreach ($xpath->query("//div[{$class('program-card')}]") as $card) {
        $title = trim((string) $xpath->evaluate("string(.//*[{$class('program-title')}])", $card));
        $href = (string) $xpath->evaluate("string(.//a[contains(@href, 'program_id=') or contains(@href, 'program.php?id=')]/@href)", $card);
        $image = trim((string) $xpath->evaluate("string(.//div[{$class('program-image')}]//img/@src)", $card));
        $description = (string) $xpath->evaluate("string(.//*[{$class('program-description')}])", $card);
        if ($title === '' || !preg_match('/(?:program_id|[?&]id)=([0-9]+)/', $href, $match)) {
            continue;
        }
        $programs[] = [
            'id' => (int) $match[1],
            'title' => mb_substr($title, 0, 120),
            'summary' => wakaf_program_summary($description),
            'image' => wakaf_image_url($image),
            'url' => WAKAF_SITE.'program.php?id='.(int) $match[1],
        ];
    }
    return $programs ?: null;
}

/** Ringkasan pendek dari deskripsi program, tanpa salam pembuka. */
function wakaf_program_summary(string $text): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    $text = (string) preg_replace('/^assalamu.?alaikum[^,.]*[,.]\s*/iu', '', $text);
    // Deskripsi di situs sumber sudah dipotong ("..."), sehingga kata terakhirnya bisa terpenggal.
    $truncated = (bool) preg_match('/(\.\.\.|\x{2026})$/u', $text);
    $text = rtrim($text, ". \u{2026}");
    if ($truncated || mb_strlen($text) > 120) {
        $text = (string) preg_replace('/\s+\S*$/u', '', mb_substr($text, 0, 120));
    }
    $text = rtrim($text, ',;:. ');
    return $text === '' ? '' : $text.'…';
}

/** Gambar hanya diterima dari folder unggahan cendekiabisa.com. */
function wakaf_image_url(string $src): string
{
    $src = (string) preg_replace('#^https?://(www\.)?cendekiabisa\.com/#i', '', $src);
    if (!preg_match('~^uploads/[^?#]+$~', $src)) {
        return '';
    }
    return WAKAF_SITE.implode('/', array_map('rawurlencode', explode('/', rawurldecode($src))));
}
