<?php

/**
 * Endpoint asisten tanya-jawab situs yayasan. Menjawab hanya berdasarkan
 * konten situs (unit, kuota PPDB, alur, persyaratan, profil, berita) melalui
 * Claude API. Dibatasi per alamat IP dan per hari.
 */

require_once __DIR__.'/koneksi.php';
require_once __DIR__.'/cms_helpers.php';

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const AI_MODEL = 'claude-opus-5-5';
const AI_LIMIT_PER_IP_10_MIN = 12;
const AI_LIMIT_PER_IP_DAY = 60;
const AI_LIMIT_GLOBAL_DAY = 1500;

function ai_respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    ai_respond(405, ['error' => 'Metode tidak didukung.']);
}

// Permintaan lintas situs ditolak: hanya halaman situs ini yang boleh memanggil.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://'.($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
    ai_respond(403, ['error' => 'Permintaan tidak diizinkan.']);
}

if (!cms_ai_available($conn)) {
    ai_respond(503, ['error' => 'Asisten sedang tidak aktif. Silakan hubungi panitia melalui halaman Kontak.']);
}

// ---------- Validasi percakapan ----------
$input = json_decode((string) file_get_contents('php://input', false, null, 0, 40000), true);
$messages = [];
foreach (array_slice(is_array($input['messages'] ?? null) ? $input['messages'] : [], -8) as $message) {
    $role = $message['role'] ?? '';
    $content = is_string($message['content'] ?? null) ? trim($message['content']) : '';
    if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
        continue;
    }
    $content = mb_substr($content, 0, $role === 'user' ? 1000 : 4000);
    // Pesan dengan peran sama berturut-turut digabung agar urutan tetap user/assistant.
    if ($messages && end($messages)['role'] === $role) {
        $messages[count($messages) - 1]['content'] .= "\n\n".$content;
    } else {
        $messages[] = ['role' => $role, 'content' => $content];
    }
}
while ($messages && $messages[0]['role'] !== 'user') {
    array_shift($messages);
}
if (!$messages || end($messages)['role'] !== 'user') {
    ai_respond(422, ['error' => 'Tulis pertanyaan terlebih dahulu.']);
}

// ---------- Pembatas laju ----------
$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '').'|cendekia-ai');
$limits = mysqli_prepare($conn, 'SELECT
    SUM(ip_hash=? AND created_at > NOW() - INTERVAL 10 MINUTE) AS recent,
    SUM(ip_hash=? AND created_at > NOW() - INTERVAL 1 DAY) AS daily,
    COUNT(*) AS global_daily
    FROM tb_ai_requests WHERE created_at > NOW() - INTERVAL 1 DAY');
mysqli_stmt_bind_param($limits, 'ss', $ipHash, $ipHash);
mysqli_stmt_execute($limits);
$usage = mysqli_fetch_assoc(mysqli_stmt_get_result($limits));
mysqli_stmt_close($limits);
if ((int) $usage['recent'] >= AI_LIMIT_PER_IP_10_MIN || (int) $usage['daily'] >= AI_LIMIT_PER_IP_DAY) {
    ai_respond(429, ['error' => 'Terlalu banyak pertanyaan dalam waktu singkat. Coba lagi beberapa menit lagi.']);
}
if ((int) $usage['global_daily'] >= AI_LIMIT_GLOBAL_DAY) {
    ai_respond(429, ['error' => 'Kuota asisten hari ini sudah habis. Silakan hubungi panitia melalui halaman Kontak.']);
}
$log = mysqli_prepare($conn, 'INSERT INTO tb_ai_requests (ip_hash) VALUES (?)');
mysqli_stmt_bind_param($log, 's', $ipHash);
mysqli_stmt_execute($log);
mysqli_stmt_close($log);
if (random_int(1, 50) === 1) {
    mysqli_query($conn, 'DELETE FROM tb_ai_requests WHERE created_at < NOW() - INTERVAL 3 DAY');
}

// ---------- Basis pengetahuan dari konten situs ----------
function ai_knowledge_base(mysqli $conn): string
{
    $year = '2027/2028';
    $lines = [];
    $lines[] = '# Identitas yayasan';
    $lines[] = 'Nama: Yayasan Wakaf Cendekia Takengon. Semboyan: '.cms_setting($conn, 'site_tagline', 'Shaleh, cerdas, dan terampil.');
    $lines[] = 'Berdiri 5 Januari 2022. Badan hukum: SK Kemenkumham AHU-0000410.AH.01.12 Tahun 2022. Nazhir wakaf uang terdaftar di Badan Wakaf Indonesia, kode 3.3.00369.';
    $lines[] = 'Pimpinan: '.cms_setting($conn, 'leader_name', 'Ilawarni').' ('.cms_setting($conn, 'leader_role', 'Pimpinan yayasan').').';
    $lines[] = 'Alamat: '.cms_setting($conn, 'contact_address');
    $lines[] = 'WhatsApp panitia: '.cms_setting($conn, 'contact_phone').'. Email: '.cms_setting($conn, 'contact_email').'. Situs: '.cms_setting($conn, 'contact_website');
    if (cms_setting($conn, 'contact_hours') !== '') {
        $lines[] = 'Jam layanan: '.cms_setting($conn, 'contact_hours');
    }

    $lines[] = '';
    $lines[] = '# Unit pendidikan dan PPDB '.$year.' (jalur umum)';
    $result = mysqli_query($conn, "SELECT u.kode_unit,u.nama_unit,u.jenjang,u.npsn,u.email,u.alamat,p.tagline,p.description_html,p.phone,
        q.kuota_eksternal,q.eksternal_dibuka,q.biaya_pendaftaran,
        (SELECT COUNT(*) FROM tb_pendaftaran r WHERE r.kode_unit=u.kode_unit AND r.th_ajaran=q.th_ajaran AND r.jalur_pendaftaran='eksternal' AND r.status_ppdb NOT IN ('ditolak','data_lama')) AS terisi
        FROM tb_unit_pendidikan u
        LEFT JOIN tb_cms_unit_profiles p ON p.kode_unit=u.kode_unit
        LEFT JOIN tb_pengaturan_ppdb q ON q.kode_unit=u.kode_unit AND q.th_ajaran='".$year."'
        ORDER BY FIELD(u.jenjang,'KB','TPA','TK','SD','SMP')");
    while ($unit = mysqli_fetch_assoc($result)) {
        $remaining = max(0, (int) $unit['kuota_eksternal'] - (int) $unit['terisi']);
        $status = (int) $unit['eksternal_dibuka'] === 1 && (int) $unit['kuota_eksternal'] > 0
            ? ($remaining > 0 ? 'DIBUKA, sisa '.$remaining.' kursi dari kuota '.(int) $unit['kuota_eksternal'] : 'kuota terpenuhi')
            : 'belum dibuka';
        $parts = [
            ppdb_unit_display_name($unit['nama_unit']).' ('.ppdb_jenjang_label($unit['jenjang']).')',
            $unit['npsn'] ? 'NPSN '.$unit['npsn'] : '',
            $unit['email'] ? 'email '.$unit['email'] : '',
            $unit['phone'] ? 'telepon '.$unit['phone'] : '',
            'PPDB: '.$status,
            (float) $unit['biaya_pendaftaran'] > 0 ? 'biaya pendaftaran Rp '.number_format((float) $unit['biaya_pendaftaran'], 0, ',', '.') : '',
            'halaman: unit.php?kode='.$unit['kode_unit'],
        ];
        $lines[] = '- '.implode('; ', array_filter($parts));
        if ($unit['tagline']) {
            $lines[] = '  Ringkasan: '.$unit['tagline'];
        }
        if (trim((string) $unit['description_html']) !== '') {
            $lines[] = '  Profil: '.cms_plain_excerpt($unit['description_html'], 900);
        }
    }

    $lines[] = '';
    $lines[] = '# Alur pendaftaran (halaman ppdb.php, formulir di daftar.php)';
    $lines[] = '1. Registrasi awal di daftar.php: nama lengkap ananda, tanggal lahir, jenis kelamin, siapa yang mendaftarkan, nomor WhatsApp pendaftar, ayah, dan ibu. Sekitar 3 menit, tanpa NIK.';
    $lines[] = '2. Verifikasi dan pembayaran: panitia memeriksa data lalu mengirim tautan pembayaran lewat WhatsApp; pendaftar mengunggah bukti transfer di halaman status.';
    $lines[] = '3. Formulir lengkap: data identitas, alamat, keluarga, membaca materi PPDB unit, lalu mengunggah dokumen. Sediakan sekitar 1 jam.';
    $lines[] = 'Setelah registrasi awal pendaftar menerima kode pendaftaran dan tautan status pribadi. Jalur internal SDM yayasan memakai kode undangan terpisah. Pendaftar bisa masuk daftar tunggu bila kuota penuh.';

    $guide = ppdb_technical_guide();
    if (!empty($guide['documents'])) {
        $lines[] = 'Dokumen yang disiapkan: '.implode('; ', $guide['documents']).'; foto ananda.';
    }
    foreach ($guide['ages'] ?? [] as $age) {
        $lines[] = 'Acuan usia '.$age['unit'].': '.(int) $age['minimumYears'].'–'.(int) $age['maximumYears'].' tahun'.(!empty($age['note']) ? ' ('.$age['note'].')' : '').'.';
    }
    if (!empty($guide['programs'])) {
        $lines[] = 'Program bagi calon wali siswa (diseleksi sesuai syarat yayasan): '.implode('; ', $guide['programs']).'.';
    }

    foreach (['tentang' => 'Tentang yayasan (tentang.php)', 'kurikulum' => 'Kurikulum JSIT (kurikulum.php)'] as $page => $heading) {
        $sections = array_filter(cms_sections($conn, $page), static fn ($s) => trim(strip_tags((string) $s['body_html'])) !== '');
        if ($sections) {
            $lines[] = '';
            $lines[] = '# '.$heading;
            foreach ($sections as $section) {
                $lines[] = '## '.$section['title'];
                $lines[] = cms_plain_excerpt($section['body_html'], 1500);
            }
        }
    }
    $people = cms_items($conn, 'manajemen');
    if ($people) {
        $lines[] = 'Manajemen: '.implode('; ', array_map(static fn ($p) => $p['title'].($p['subtitle'] ? ' ('.$p['subtitle'].')' : ''), $people)).'.';
    }
    $facilities = cms_items($conn, 'fasilitas');
    if ($facilities) {
        $lines[] = 'Fasilitas: '.implode('; ', array_map(static fn ($f) => $f['title'].($f['description'] ? ' — '.cms_plain_excerpt($f['description'], 120) : ''), $facilities)).'.';
    }

    [$news] = cms_published_news($conn, 8);
    if ($news) {
        $lines[] = '';
        $lines[] = '# Berita terbaru (berita.php)';
        foreach ($news as $item) {
            $lines[] = '- '.substr((string) $item['published_at'], 0, 10).' · '.$item['title'].' — '.cms_news_excerpt($item, 200).' (berita.php?baca='.$item['slug'].')';
        }
    }
    return implode("\n", $lines);
}

$instructions = <<<'PROMPT'
Anda adalah Asisten Cendekia, asisten tanya jawab di situs resmi Yayasan Wakaf Cendekia Takengon (Bebesen, Aceh Tengah). Pengunjung Anda sebagian besar orang tua calon siswa dengan tingkat literasi digital yang beragam.

Cara menjawab:
- Gunakan Bahasa Indonesia yang ramah, sopan, dan ringkas (umumnya 2–5 kalimat atau daftar pendek). Boleh menyapa dengan "Ayah/Bunda" bila sesuai.
- Jawab hanya berdasarkan bagian "Informasi situs" di bawah. Jangan mengarang biaya, tanggal, kuota, nama guru, akreditasi, prestasi, atau kebijakan yang tidak tercantum.
- Jika informasinya tidak ada, katakan terus terang bahwa informasi tersebut belum tersedia di situs, lalu arahkan ke WhatsApp panitia atau halaman Kontak.
- Bila relevan, sertakan tautan halaman situs dengan format [teks](nama-halaman.php), misalnya [formulir pendaftaran](daftar.php) atau [info PPDB](ppdb.php).
- Status kuota berubah setiap hari; sebutkan bahwa angka berasal dari sistem saat ini.
- Jangan meminta atau menyimpan data pribadi (NIK, nomor KK, alamat rumah). Untuk status pendaftaran pribadi, arahkan ke tautan status yang dikirim panitia lewat WhatsApp.
- Untuk pertanyaan di luar urusan yayasan, sekolah, dan pendaftaran, sampaikan dengan sopan bahwa Anda hanya membantu seputar Yayasan Wakaf Cendekia Takengon.
- Tulis teks biasa; gunakan **tebal** dan daftar berpoin seperlunya, tanpa tabel atau judul.
PROMPT;

$system = [
    ['type' => 'text', 'text' => $instructions],
    ['type' => 'text', 'text' => "# Informasi situs\n".ai_knowledge_base($conn), 'cacheControl' => ['type' => 'ephemeral']],
];

try {
    $client = new Client();
    $response = $client->beta->messages->create(
        maxTokens: 1024,
        messages: $messages,
        model: AI_MODEL,
        system: $system,
        outputConfig: ['effort' => 'low'],
        fallbacks: 'default',
        betas: ['server-side-fallback-2026-07-01'],
        requestOptions: ['timeout' => 45.0, 'maxRetries' => 1],
    );
} catch (RateLimitException $exception) {
    error_log('Asisten AI rate limit: '.$exception->getMessage());
    ai_respond(429, ['error' => 'Asisten sedang sibuk. Coba lagi sebentar lagi.']);
} catch (APIStatusException $exception) {
    error_log('Asisten AI galat API: '.$exception->getMessage());
    ai_respond(502, ['error' => 'Asisten sedang tidak dapat menjawab. Silakan hubungi panitia melalui halaman Kontak.']);
} catch (APIConnectionException $exception) {
    error_log('Asisten AI koneksi: '.$exception->getMessage());
    ai_respond(503, ['error' => 'Koneksi ke layanan asisten terputus. Coba lagi sebentar lagi.']);
}

if ($response->stopReason === 'refusal') {
    ai_respond(200, ['answer' => 'Maaf, pertanyaan tersebut tidak dapat saya jawab. Untuk informasi lebih lanjut, silakan hubungi panitia melalui [halaman Kontak](kontak.php).']);
}

$answer = '';
foreach ($response->content as $block) {
    if ($block->type === 'text') {
        $answer .= $block->text;
    }
}
$answer = trim($answer);
if ($answer === '') {
    ai_respond(502, ['error' => 'Asisten tidak memberikan jawaban. Coba ajukan pertanyaan dengan kalimat lain.']);
}
if ($response->stopReason === 'max_tokens') {
    $answer .= "\n\n(Jawaban dipotong karena terlalu panjang. Silakan ajukan pertanyaan yang lebih spesifik.)";
}

ai_respond(200, ['answer' => $answer]);
