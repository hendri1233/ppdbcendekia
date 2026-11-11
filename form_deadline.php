<?php
/**
 * Pemberitahuan batas pengisian formulir lengkap bagi pendaftar
 * (popup kaca di halaman registrasi berhasil dan status pendaftaran).
 */

require_once __DIR__.'/app_helpers.php';

/**
 * @param array{buka: ?string, batas: ?string} $window hasil ppdb_form_window()
 * @param string $rememberKey kunci agar popup tidak muncul berulang dalam satu sesi; kosong berarti selalu muncul
 * @param string $confirmLabel teks tombol penutup (mis. "Cek WhatsApp" bila disusul popup WhatsApp)
 */
function ppdb_render_form_deadline_dialog(array $window, string $unitName, string $participantName, string $rememberKey = '', string $confirmLabel = 'Saya mengerti'): void
{
    $deadline = $window['batas'] ?? null;
    if (!$deadline || ppdb_form_deadline_passed($deadline)) {
        return;
    }
    $opens = $window['buka'] ?? null;
    $deadlineIso = (new DateTimeImmutable($deadline, new DateTimeZone('Asia/Jakarta')))->format(DATE_ATOM);
    $remember = $rememberKey !== '' ? ' data-deadline-remember="'.h($rememberKey).'"' : '';
    ?>
    <dialog class="deadline-notice" data-deadline-notice data-deadline-at="<?php echo h($deadlineIso); ?>"<?php echo $remember; ?> aria-labelledby="deadline-notice-title" aria-describedby="deadline-notice-text">
        <form method="dialog" class="deadline-notice__close-form">
            <button class="deadline-notice__close" type="submit" aria-label="Tutup pemberitahuan"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7l-1.4-1.4L9.2 12 2.9 5.7l1.4-1.4 6.3 6.3 6.3-6.3z"/></svg></button>
        </form>
        <span class="deadline-notice__icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16Zm.5-13H11v6l5.2 3.1.8-1.2-4.5-2.7V7Z"/></svg></span>
        <p class="deadline-notice__eyebrow"><?php echo h($unitName); ?></p>
        <h2 id="deadline-notice-title">Batas pengisian formulir</h2>
        <p class="deadline-notice__text" id="deadline-notice-text">Ayah/Bunda, mohon selesaikan pembayaran dan formulir lengkap ananda <strong><?php echo h($participantName); ?></strong> sebelum batas waktu berikut.</p>
        <div class="deadline-notice__date">
            <strong><?php echo h(ppdb_format_day_date_id($deadline)); ?></strong>
            <span>pukul <?php echo h(date('H.i', strtotime($deadline))); ?> WIB</span>
        </div>
        <div class="deadline-notice__countdown" data-deadline-countdown aria-live="polite" hidden>
            <div><b data-unit="hari">0</b><small>hari</small></div>
            <div><b data-unit="jam">0</b><small>jam</small></div>
            <div><b data-unit="menit">0</b><small>menit</small></div>
            <div class="is-seconds"><b data-unit="detik">0</b><small>detik</small></div>
        </div>
        <ol class="deadline-notice__timeline">
            <li><span></span><div><small>Pengisian dibuka</small><strong><?php echo $opens ? h(ppdb_format_datetime_id($opens)) : 'Sejak pembayaran selesai'; ?></strong></div></li>
            <li class="is-end"><span></span><div><small>Batas akhir formulir</small><strong><?php echo h(ppdb_format_datetime_id($deadline)); ?></strong></div></li>
        </ol>
        <p class="deadline-notice__note"><strong>Penting:</strong> apabila pembayaran dan formulir lengkap belum diselesaikan sampai batas ini, formulir tidak dapat diisi lagi dan Ananda <strong>dinyatakan gugur</strong>.</p>
        <form method="dialog">
            <button class="deadline-notice__confirm" type="submit" autofocus><?php echo h($confirmLabel); ?></button>
        </form>
    </dialog>
    <?php
}
