<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'wa_queue.php';
require_super_admin();
require_once __DIR__ . '/admin-partials.php';

$error = '';
$notice = '';
$ready = wa_queue_ready($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi formulir kedaluwarsa. Muat ulang halaman dan coba kembali.';
    } elseif (!$ready) {
        $error = 'Jalankan migrasi 038 terlebih dahulu.';
    } else {
        $state = wa_queue_state($conn);
        if ($action === 'pause') {
            $state['paused'] = true;
            wa_queue_save_state($conn, $state);
            $notice = 'Antrean dijeda. Pesan tetap tersimpan dan dikirim setelah dilanjutkan.';
        } elseif ($action === 'resume') {
            $state['paused'] = false;
            $state['paused_until'] = 0;
            $state['fails'] = 0;
            $state['next_at'] = 0;
            wa_queue_save_state($conn, $state);
            $notice = 'Antrean dilanjutkan. Pesan dikirim bertahap mulai kunjungan halaman berikutnya.';
        } elseif ($action === 'retry_failed') {
            mysqli_query($conn, "UPDATE tb_wa_antrean SET status='menunggu',percobaan=0,galat='',dijadwalkan_pada=NOW() WHERE status='gagal'");
            $notice = mysqli_affected_rows($conn).' pesan gagal dimasukkan kembali ke antrean.';
        } elseif ($action === 'cancel' && ctype_digit((string) ($_POST['item'] ?? ''))) {
            $itemId = (int) $_POST['item'];
            mysqli_query($conn, "UPDATE tb_wa_antrean SET status='dibatalkan' WHERE status='menunggu' AND id=".$itemId);
            $notice = mysqli_affected_rows($conn) ? 'Pesan dibatalkan.' : 'Pesan sudah tidak dalam antrean.';
        }
    }
}

$counts = ['menunggu' => 0, 'terkirim' => 0, 'gagal' => 0, 'dibatalkan' => 0];
$sentToday = 0;
$items = [];
$state = wa_queue_state($conn);
if ($ready) {
    $result = mysqli_query($conn, 'SELECT status, COUNT(*) AS total FROM tb_wa_antrean GROUP BY status');
    while ($row = mysqli_fetch_assoc($result)) {
        $counts[$row['status']] = (int) $row['total'];
    }
    $sentToday = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM tb_wa_antrean WHERE status='terkirim' AND dikirim_pada>=CURDATE()"))['total'];
    $listing = mysqli_query($conn, "SELECT a.*, p.nm_peserta FROM tb_wa_antrean a LEFT JOIN tb_pendaftaran p ON p.id_pendaftaran=a.id_pendaftaran ORDER BY a.status='menunggu' DESC, a.id DESC LIMIT 60");
    while ($row = mysqli_fetch_assoc($listing)) {
        $items[] = $row;
    }
}
$now = time();
$hour = (int) (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('G');
if (!ppdb_wablas_enabled()) {
    $stateLabel = 'Wablas belum diatur';
    $stateTone = 'warn';
} elseif (!empty($state['paused'])) {
    $stateLabel = 'Dijeda oleh admin';
    $stateTone = 'warn';
} elseif ((int) $state['paused_until'] > $now) {
    $stateLabel = 'Dijeda otomatis sampai '.date('H.i', (int) $state['paused_until']).' karena Wablas gagal beruntun';
    $stateTone = 'warn';
} elseif ($hour < WA_QUEUE_START_HOUR || $hour >= WA_QUEUE_END_HOUR) {
    $stateLabel = 'Di luar jam kirim (pukul '.WA_QUEUE_START_HOUR.'.00–'.WA_QUEUE_END_HOUR.'.00 WIB)';
    $stateTone = 'warn';
} elseif ($sentToday >= WA_QUEUE_DAILY_LIMIT) {
    $stateLabel = 'Batas harian tercapai, dilanjutkan besok';
    $stateTone = 'warn';
} else {
    $stateLabel = 'Berjalan';
    $stateTone = 'ok';
}
$typeLabels = ['registrasi' => 'Konfirmasi registrasi', 'revisi' => 'Tautan revisi'];
$statusLabels = ['menunggu' => 'Menunggu', 'terkirim' => 'Terkirim', 'gagal' => 'Gagal', 'dibatalkan' => 'Dibatalkan'];
$statusTones = ['menunggu' => 'info', 'terkirim' => 'ok', 'gagal' => 'danger', 'dibatalkan' => 'slate'];

$activeAdminPage = 'wa-queue';
$adminPageTitle = 'Antrean WhatsApp';
$adminPageDescription = 'Pesan WhatsApp otomatis dikirim bertahap dengan jeda agar nomor tidak diblokir.';
require 'admin_header.php';
?>
<div class="admin-content">
    <header class="page-heading">
        <div>
            <p class="eyebrow">Pesan otomatis</p>
            <h1>Antrean WhatsApp</h1>
            <p>Pesan dikirim satu per satu dengan jeda <?php echo WA_QUEUE_MIN_GAP; ?>–<?php echo WA_QUEUE_MAX_GAP; ?> detik, pukul <?php echo WA_QUEUE_START_HOUR; ?>.00–<?php echo WA_QUEUE_END_HOUR; ?>.00 WIB, paling banyak <?php echo WA_QUEUE_DAILY_LIMIT; ?> pesan per hari. Antrean berjalan selama situs dikunjungi.</p>
        </div>
    </header>

    <?php if ($error !== '') { ?><div class="alert-error" role="alert"><?php echo h($error); ?></div><?php } ?>
    <?php if ($notice !== '') { ?><div class="alert-success" role="status"><?php echo h($notice); ?></div><?php } ?>
    <?php if (!$ready) { ?><div class="alert-error" role="alert">Tabel antrean belum ada. Jalankan <code>migrations/038_antrean_whatsapp.sql</code> di phpMyAdmin; sebelum itu pesan otomatis tidak dikirim.</div><?php } ?>

    <section class="panel wa-queue-summary">
        <div class="wa-queue-state tone-<?php echo h($stateTone); ?>">
            <span class="wa-queue-state__dot" aria-hidden="true"></span>
            <div>
                <strong><?php echo h($stateLabel); ?></strong>
                <small>
                    <?php if ($counts['menunggu'] > 0 && $stateTone === 'ok') { ?>Pesan berikutnya sekitar pukul <?php echo h(date('H.i', max($now, (int) $state['next_at']))); ?> WIB.<?php } ?>
                    <?php if ($state['last_error'] !== '') { ?> Galat terakhir: <?php echo h($state['last_error']); ?><?php } ?>
                </small>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <?php if (!empty($state['paused']) || (int) $state['paused_until'] > $now) { ?>
                    <button class="button-primary" type="submit" name="action" value="resume">Lanjutkan antrean</button>
                <?php } else { ?>
                    <button class="button-secondary" type="submit" name="action" value="pause">Jeda antrean</button>
                <?php } ?>
            </form>
        </div>
        <dl class="wa-queue-counts">
            <div><dt>Menunggu</dt><dd><?php echo $counts['menunggu']; ?></dd></div>
            <div><dt>Terkirim hari ini</dt><dd><?php echo $sentToday; ?> / <?php echo WA_QUEUE_DAILY_LIMIT; ?></dd></div>
            <div><dt>Total terkirim</dt><dd><?php echo $counts['terkirim']; ?></dd></div>
            <div><dt>Gagal</dt><dd><?php echo $counts['gagal']; ?></dd></div>
        </dl>
        <?php if ($counts['gagal'] > 0) { ?>
            <form method="post" data-confirm="Masukkan kembali semua pesan gagal ke antrean?">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <button class="button-secondary" type="submit" name="action" value="retry_failed">Antrekan ulang pesan gagal</button>
            </form>
        <?php } ?>
    </section>

    <section class="panel">
        <div class="panel-header"><div><h2>Pesan terbaru</h2><span class="panel-subtitle">Pesan menunggu di atas, lalu 60 pesan terakhir.</span></div></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th scope="col">Status</th><th scope="col">Peserta</th><th scope="col">Nomor</th><th scope="col">Jenis</th><th scope="col">Masuk</th><th scope="col">Dikirim</th><th scope="col">Keterangan</th><th scope="col">Aksi</th></tr></thead>
                <tbody>
                <?php if (!$items) { ?><tr><td colspan="8" class="empty-state">Belum ada pesan di antrean.</td></tr><?php } ?>
                <?php foreach ($items as $item) { ?>
                    <tr>
                        <td data-label="Status"><span class="status-pill tone-<?php echo h($statusTones[$item['status']] ?? 'slate'); ?>"><?php echo h($statusLabels[$item['status']] ?? $item['status']); ?></span></td>
                        <td data-label="Peserta"><?php if ($item['id_pendaftaran']) { ?><a href="detail-peserta.php?id=<?php echo rawurlencode($item['id_pendaftaran']); ?>"><?php echo h($item['nm_peserta'] ?? $item['id_pendaftaran']); ?></a><small class="cell-sub"><?php echo h($item['id_pendaftaran']); ?></small><?php } else { ?>&mdash;<?php } ?></td>
                        <td data-label="Nomor"><?php echo h(ppdb_display_phone($item['nomor'])); ?></td>
                        <td data-label="Jenis"><?php echo h($typeLabels[$item['jenis']] ?? $item['jenis']); ?></td>
                        <td data-label="Masuk"><?php echo h(date('d-m H.i', strtotime($item['dibuat_pada']))); ?></td>
                        <td data-label="Dikirim"><?php echo $item['dikirim_pada'] ? h(date('d-m H.i', strtotime($item['dikirim_pada']))) : '&mdash;'; ?></td>
                        <td data-label="Keterangan"><?php echo $item['galat'] !== '' ? h($item['galat']).' · percobaan '.(int) $item['percobaan'] : ($item['status'] === 'menunggu' && (int) $item['percobaan'] > 0 ? 'Dicoba ulang' : '&mdash;'); ?></td>
                        <td data-label="Aksi">
                            <?php if ($item['status'] === 'menunggu') { ?>
                                <form method="post" data-confirm="Batalkan pesan ini?">
                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="item" value="<?php echo (int) $item['id']; ?>">
                                    <button class="table-action danger" type="submit" name="action" value="cancel">Batalkan</button>
                                </form>
                            <?php } else { ?>&mdash;<?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php require 'admin_footer.php'; ?>
