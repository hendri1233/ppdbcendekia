<?php
require_once __DIR__.'/app_helpers.php';
require_once __DIR__.'/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/*
 * Bahan bersama PDF Dompdf: kop surat yayasan, font Source Serif, dan render A4.
 * Dipakai surat keterangan/bukti pendaftaran (cetak-bukti.php) dan salinan cetak
 * peserta di Google Drive (cetak_drive.php).
 */

/** Gambar JPG untuk PDF; Dompdf memasangnya tanpa ekstensi GD. */
function cetak_image(string $path, string $class): string
{
    return is_file($path) ? '<img class="'.$class.'" src="data:image/jpeg;base64,'.base64_encode((string) file_get_contents($path)).'" alt="">' : '';
}

/** Kop surat dipakai bersama oleh bukti pendaftaran, surat keterangan pendaftaran, dan cetak peserta. */
function cetak_kop(string $unitName, string $adminPhone): string
{
    return '<table class="kop"><tr>
        <td class="kop-logo">'.cetak_image(__DIR__.'/img/logo-pdf.jpg', 'logo').'</td>
        <td class="kop-text">
            <div class="kop-org">YAYASAN WAKAF CENDEKIA TAKENGON</div>
            <div class="kop-unit">Panitia Penerimaan Peserta Didik Baru · '.h($unitName).'</div>
            <div class="kop-meta">Jalan Pertamina–Kebet, Kecamatan Bebesen, Kabupaten Aceh Tengah, Aceh<br>WhatsApp Admin '.h($adminPhone).' · wakafcendekiatakengon@gmail.com · cendekiatakengon.or.id</div>
        </td>
    </tr></table>
    <div class="kop-line"></div>';
}

function cetak_kop_css(): string
{
    return '.kop { width: 100%; border-collapse: collapse; }
        .kop td { vertical-align: middle; }
        .kop-logo { width: 84px; }
        .logo { width: 76px; height: 76px; }
        .kop-text { text-align: center; padding-right: 84px; }
        .kop-org { color: #123f32; font-size: 18.5px; font-weight: bold; letter-spacing: .3px; line-height: 1.2; }
        .kop-unit { color: #123f32; font-size: 12.5px; font-weight: bold; }
        .kop-meta { margin-top: 2px; color: #4c5852; font-size: 8.8px; line-height: 1.1; }
        .kop-line { margin-top: 8px; border-top: 2.2px solid #123f32; border-bottom: .6px solid #123f32; height: 2px; }';
}

/** Render HTML menjadi PDF A4 dengan font Source Serif; mengembalikan isi berkas PDF. */
function cetak_pdf_render(string $html): string
{
    // Metrik font disimpan di folder sementara karena folder vendor belum tentu dapat ditulis.
    $fontDir = sys_get_temp_dir().'/ppdb-dompdf-fonts';
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Serif');
    $options->set('chroot', __DIR__);
    if (is_dir($fontDir) || @mkdir($fontDir, 0755, true)) {
        $options->set('fontDir', $fontDir);
        $options->set('fontCache', $fontDir);
    }
    $dompdf = new Dompdf($options);
    $fontMetrics = $dompdf->getFontMetrics();
    foreach ([
        ['normal', 'normal', 'SourceSerif4-400.ttf'],
        ['bold', 'normal', 'SourceSerif4-700.ttf'],
        ['normal', 'italic', 'SourceSerif4-400italic.ttf'],
    ] as [$weight, $style, $file]) {
        $styleKey = $fontMetrics->getType($weight.' '.$style);
        $installed = $fontMetrics->getFamily('source serif')[$styleKey] ?? '';
        if ($installed === '' || !is_file($installed.'.ttf')) {
            @$fontMetrics->registerFont(['family' => 'Source Serif', 'weight' => $weight, 'style' => $style], __DIR__.'/assets/fonts/'.$file);
        }
    }
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return (string) $dompdf->output();
}

/** Render lalu kirim sebagai unduhan. */
function cetak_stream(string $html, string $filename): void
{
    $pdf = cetak_pdf_render($html);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.str_replace('"', '', $filename).'"');
    header('Content-Length: '.strlen($pdf));
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;
}
