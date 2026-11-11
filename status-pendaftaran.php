<?php
require_once 'app_helpers.php';
require_once 'koneksi.php';
require_once 'form_deadline.php';
start_app_session();

$registrationId = $_GET['id'] ?? '';
$receiptToken = $_GET['token'] ?? '';
$registration = null;
$tokenIsValid = preg_match('/^P[0-9]{9}$/', $registrationId) === 1 && preg_match('/^[a-f0-9]{64}$/', $receiptToken) === 1;
if ($tokenIsValid) {
    $tokenHash = hash('sha256', $receiptToken);
    $groupClickColumn = ppdb_column_exists($conn, 'tb_pendaftaran', 'grup_diklik_pada') ? 'p.grup_diklik_pada' : 'NULL AS grup_diklik_pada';
    $stmt = mysqli_prepare($conn, 'SELECT '.$groupClickColumn.',p.id_pendaftaran,p.kode_unit,p.nm_peserta,p.tgl_lahir,p.jenis_kelamin,p.pendaftar,p.no_hp_pendaftar,p.no_hp_ayah,p.no_hp_ibu,p.th_ajaran,p.tgl_daftar,p.waktu_daftar,p.status_ppdb,p.status_data,p.status_pembayaran,p.revisi_dibuka,p.tanggal_batas_bayar,p.alamat,p.data_diperiksa_pada,u.nama_unit,u.jenjang,g.link_grup,q.biaya_pendaftaran,q.nomor_rekening,q.nama_pemilik_rekening,q.masa_pembayaran_hari,b.status_verifikasi AS status_bukti,b.catatan_admin AS catatan_bukti,b.diunggah_pada,b.nomor_referensi FROM tb_pendaftaran p LEFT JOIN tb_unit_pendidikan u ON u.kode_unit=p.kode_unit LEFT JOIN tb_unit_whatsapp_groups g ON g.kode_unit=p.kode_unit LEFT JOIN tb_pengaturan_ppdb q ON q.kode_unit=p.kode_unit AND q.th_ajaran=p.th_ajaran LEFT JOIN tb_bukti_pembayaran b ON b.id=(SELECT MAX(b2.id) FROM tb_bukti_pembayaran b2 WHERE b2.id_pendaftaran=p.id_pendaftaran) WHERE p.id_pendaftaran=? AND (p.token_bukti=? OR p.token_whatsapp=? OR p.token_formulir=?) LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ssss', $registrationId, $tokenHash, $tokenHash, $tokenHash);
    mysqli_stmt_execute($stmt);
    $registration = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

if (!$registration) {
    http_response_code(404);
}
// Registrasi awal yang belum disetujui panitia (menunggu_kontak) tetap menunggu di halaman ini.

$statusInfo = $registration ? ppdb_status_info($registration['status_ppdb']) : null;
$currentStep = $statusInfo['step'] ?? 0;
// Pendaftar tidak menunggu pemeriksaan bukti: tahap berikutnya sudah bisa dikerjakan.
if ($registration && $registration['status_ppdb'] === 'pembayaran_diperiksa') {
    $currentStep = 3;
}
$isAccepted = $registration && $registration['status_ppdb'] === 'diterima';
// Tautan grup melewati gabung-grup agar panitia tahu siapa yang sudah membukanya.
$groupJoinUrl = $isAccepted && !empty($registration['link_grup']) ? 'gabung-grup?'.http_build_query(['id' => $registration['id_pendaftaran'], 'token' => $receiptToken]) : '';
$groupOpened = !empty($registration['grup_diklik_pada']);
$rejectedCompletedSteps = $registration && $registration['status_ppdb'] === 'ditolak' ? ppdb_rejected_completed_steps($registration) : null;
$paymentDeadlinePassed = $registration && $registration['tanggal_batas_bayar'] && $registration['tanggal_batas_bayar'] < date('Y-m-d');
$hasPaymentAccount = $registration
    && !empty($registration['nomor_rekening'])
    && !empty($registration['nama_pemilik_rekening']);
$uploadError = trim($_GET['upload_error'] ?? '');
// Batas pengisian formulir dari admin; formulir revisi yang dibuka panitia tidak terkena batas.
$formWindow = $registration ? ppdb_form_window($conn, (string) $registration['kode_unit'], (string) $registration['th_ajaran']) : ['buka' => null, 'batas' => null];
$formExpired = $registration && empty($registration['revisi_dibuka']) && ppdb_form_deadline_passed($formWindow['batas']);
$revisionCount = isset($_GET['revisi']) && ctype_digit((string) $_GET['revisi']) ? (int) $_GET['revisi'] : null;

$journeySteps = [
    ['title' => 'Registrasi awal', 'description' => 'Data dasar ananda diterima.'],
    ['title' => 'Pembayaran', 'description' => 'Transfer lalu unggah bukti di halaman ini.'],
    ['title' => 'Formulir lengkap', 'description' => 'Konfirmasi data, baca materi, lalu isi formulir.'],
    ['title' => 'Pemeriksaan', 'description' => 'Panitia memverifikasi kelengkapan berkas.'],
    ['title' => 'Keputusan', 'description' => 'Diterima, daftar tunggu, atau tindak lanjut.'],
];

$documentCount = 0;
if ($registration) {
    $documentQuery = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM tb_dokumen_peserta WHERE id_pendaftaran=?');
    mysqli_stmt_bind_param($documentQuery, 's', $registration['id_pendaftaran']);
    mysqli_stmt_execute($documentQuery);
    $documentCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($documentQuery))['total'];
    mysqli_stmt_close($documentQuery);
}

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $registration ? 'Status pendaftaran '.$registration['id_pendaftaran'] : 'Pendaftaran tidak ditemukan'; ?> | SPMB Cendekia</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300;0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/tokens.css?v=<?php echo (int) @filemtime(__DIR__.'/css/tokens.css'); ?>">
    <link rel="stylesheet" href="css/result.css?v=<?php echo (int) filemtime(__DIR__.'/css/result.css'); ?>">
    <link rel="stylesheet" href="css/alur.css?v=<?php echo (int) @filemtime(__DIR__.'/css/alur.css'); ?>">
    <meta name="theme-color" content="#ffffff">
    <link rel="icon" type="image/x-icon" href="img/favicons/favicon.ico" sizes="16x16 32x32 48x48">
    <link rel="icon" type="image/png" sizes="48x48" href="img/favicons/favicon-48x48.png">
    <link rel="icon" type="image/png" sizes="16x16" href="img/favicons/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicons/favicon-32x32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="img/favicons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="img/favicons/android-chrome-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="img/favicons/android-chrome-512x512.png">
</head>
<body>
<main class="result-shell">
    <a class="result-brand" href="./">
        <img class="brand-logo" src="img/logo-display.png" alt="" width="48" height="47">
        <span><strong>Wakaf Cendekia</strong><small>Takengon · SPMB</small></span>
    </a>

    <?php if ($registration) { ?>
        <?php if ($revisionCount !== null) { ?>
            <div class="payment-success" role="status">
                <strong>Revisi data berhasil dikirim.</strong>
                <?php echo $revisionCount > 0 ? h($revisionCount.' perubahan tercatat dan akan diperiksa panitia.') : 'Tidak ada data yang berubah; panitia tetap akan memeriksa ulang.'; ?>
            </div>
        <?php } ?>
        <?php if ($uploadError !== '') { ?>
            <div class="payment-error" role="alert"><?php echo h($uploadError); ?></div>
        <?php } ?>

        <section class="result-panel status-panel<?php echo $isAccepted ? ' is-accepted' : ''; ?>">
            <?php if ($isAccepted) { ?>
                <header class="acceptance-hero">
                    <span class="acceptance-seal" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M9.5 16.2 5.3 12l-1.6 1.6 5.8 5.8L20.3 8.6 18.7 7z"/></svg></span>
                    <p class="acceptance-eyebrow">Hasil pendaftaran · Tahun ajaran <?php echo h($registration['th_ajaran']); ?></p>
                    <h1>Selamat, ananda diterima</h1>
                    <p class="acceptance-name"><?php echo h($registration['nm_peserta']); ?></p>
                    <p class="acceptance-unit"><?php echo h($registration['nama_unit']); ?></p>
                    <p class="acceptance-code">Kode pendaftaran <strong><?php echo h($registration['id_pendaftaran']); ?></strong></p>
                </header>

                <div class="acceptance-actions">
                    <?php if (!empty($registration['link_grup'])) { ?>
                        <a class="acceptance-button acceptance-button--group group-join-button" href="<?php echo h($groupJoinUrl); ?>" data-group-join target="_blank" rel="noopener noreferrer">
                            <svg viewBox="0 0 32 32" aria-hidden="true" focusable="false"><path d="M16.04 3C8.87 3 3.04 8.77 3.04 15.88c0 2.27.6 4.48 1.75 6.43L3 29l6.9-1.8a13.1 13.1 0 0 0 6.14 1.52h.01c7.17 0 13-5.78 13-12.89 0-3.45-1.36-6.69-3.83-9.12A12.94 12.94 0 0 0 16.04 3Zm5.92 15.5c-.32-.16-1.9-.93-2.2-1.04-.3-.11-.51-.16-.73.16-.21.32-.84 1.04-1.03 1.25-.19.21-.38.24-.7.08-.32-.16-1.36-.5-2.59-1.59-.96-.85-1.61-1.9-1.8-2.22-.19-.32-.02-.49.14-.65.14-.14.32-.37.48-.56.16-.19.21-.32.32-.53.11-.21.05-.4-.03-.56-.08-.16-.73-1.74-1-2.38-.26-.62-.53-.54-.73-.55l-.62-.01c-.21 0-.56.08-.86.4-.3.32-1.12 1.09-1.12 2.67s1.15 3.1 1.31 3.31c.16.21 2.26 3.43 5.47 4.81.76.33 1.36.52 1.82.66.76.24 1.45.21 2 .13.61-.09 1.9-.77 2.17-1.52.27-.75.27-1.39.19-1.52-.08-.13-.3-.21-.62-.37Z"/></svg>
                            <span>Gabung grup WhatsApp <?php echo h($registration['nama_unit']); ?></span>
                        </a>
                    <?php } else { ?>
                        <p class="acceptance-note">Tautan grup WhatsApp <?php echo h($registration['nama_unit']); ?> sedang disiapkan panitia dan akan tampil di halaman ini.</p>
                    <?php } ?>
                    <a class="acceptance-button acceptance-button--pdf" href="cetak-bukti?id=<?php echo rawurlencode($registration['id_pendaftaran']); ?>&amp;token=<?php echo rawurlencode($receiptToken); ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 16 6.5 10.5l1.4-1.4 3.1 3.1V3h2v9.2l3.1-3.1 1.4 1.4L12 16Zm-7 3h14v2H5v-2Z"/></svg>
                        <span>Unduh surat keterangan diterima (PDF)</span>
                    </a>
                </div>
                <?php if ($registration['status_pembayaran'] === 'menunggu_verifikasi') { ?>
                    <p class="acceptance-note">Bukti transfer Anda masih diperiksa panitia. Penerimaan ditinjau kembali bila bukti tersebut tidak sah.</p>
                <?php } ?>

                <h2 class="acceptance-heading">Langkah berikutnya</h2>
                <ol class="next-steps" aria-label="Langkah berikutnya">
                    <li><span aria-hidden="true">1</span><div><strong>Gabung grup WhatsApp unit <em class="step-flag<?php echo $groupOpened ? ' is-done' : ''; ?>" data-group-flag><?php echo $groupOpened ? 'Sudah dibuka' : 'Wajib'; ?></em></strong><small>Seluruh informasi lanjutan dari sekolah (jadwal, daftar ulang, perlengkapan) hanya disampaikan melalui grup.</small></div></li>
                    <li><span aria-hidden="true">2</span><div><strong>Simpan surat keterangan diterima</strong><small>Unduh PDF, baca ketentuan dan tata tertibnya, lalu simpan sebagai bukti ananda telah diterima.</small></div></li>
                </ol>
                <h2 class="acceptance-heading">Data peserta didik</h2>
            <?php } else { ?>
            <div class="status-head status-head--<?php echo h($statusInfo['tone']); ?>">
                <div>
                    <p class="result-eyebrow">Status pendaftaran</p>
                    <h1><?php echo h($statusInfo['label']); ?></h1>
                    <p class="result-description"><?php echo h($statusInfo['public']); ?></p>
                </div>
                <div class="registration-code" role="group" aria-label="Kode pendaftaran">
                    <span>Kode pendaftaran</span>
                    <strong><?php echo h($registration['id_pendaftaran']); ?></strong>
                </div>
            </div>

            <ol class="journey-track" aria-label="Progres pendaftaran">
                <?php foreach ($journeySteps as $index => $step) {
                    $stepNumber = $index + 1;
                    if ($rejectedCompletedSteps !== null) {
                        $state = $stepNumber <= $rejectedCompletedSteps ? 'done' : 'stopped';
                    } else {
                        $state = $stepNumber < $currentStep ? 'done' : ($stepNumber === $currentStep ? 'current' : 'todo');
                    }
                ?>
                    <li class="journey-step is-<?php echo $state; ?>">
                        <span class="journey-dot" aria-hidden="true"><?php echo $state === 'done' ? '&check;' : ($state === 'stopped' ? '&times;' : $stepNumber); ?></span>
                        <div>
                            <strong><?php echo h($step['title']); ?></strong>
                            <small><?php echo h($step['description']); ?></small>
                        </div>
                    </li>
                <?php } ?>
            </ol>
            <?php } ?>

            <dl class="summary-list">
                <div><dt>Nama ananda</dt><dd><?php echo h($registration['nm_peserta']); ?></dd></div>
                <div><dt>Tanggal lahir</dt><dd><?php echo h(ppdb_format_date_id($registration['tgl_lahir'])); ?></dd></div>
                <div><dt>Jenis kelamin</dt><dd><?php echo h(ppdb_jenis_kelamin_label($registration['jenis_kelamin'])); ?></dd></div>
                <div><dt>Didaftarkan oleh</dt><dd><?php echo h(ppdb_pendaftar_label($registration['pendaftar'])); ?></dd></div>
                <div><dt>Unit pendidikan</dt><dd><?php echo h($registration['nama_unit'] ?? 'Belum ditentukan'); ?></dd></div>
                <div><dt>Tahun ajaran</dt><dd><?php echo h($registration['th_ajaran']); ?></dd></div>
                <div><dt>Tanggal daftar</dt><dd><?php echo h(ppdb_format_date_id($registration['tgl_daftar'])); ?></dd></div>
            </dl>

            <?php
            $panel = '';
            if ($registration['status_ppdb'] === 'menunggu_kontak') {
                $panel = 'persetujuan';
            } elseif ($registration['status_ppdb'] === 'menunggu_pembayaran'
                || ($registration['status_pembayaran'] === 'ditolak' && in_array($registration['status_ppdb'], ['pembayaran_terverifikasi', 'menunggu_formulir', 'formulir_terisi'], true))) {
                $panel = 'bayar';
            } elseif (!empty($registration['revisi_dibuka']) || in_array($registration['status_ppdb'], ['pembayaran_diperiksa', 'pembayaran_terverifikasi', 'menunggu_formulir'], true)) {
                $panel = 'formulir';
            } elseif ($registration['status_ppdb'] === 'formulir_terisi') {
                $panel = 'cek-formulir';
            } elseif ($rejectedCompletedSteps !== null) {
                $panel = 'ditolak';
            }
            ?>

            <?php if ($panel === 'persetujuan') { ?>
                <div class="approval-wait" role="status">
                    <span class="approval-wait__icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16Zm.5-13H11v6l5.2 3.1.8-1.2-4.5-2.7V7Z"/></svg></span>
                    <div>
                        <strong>Registrasi awal sedang diverifikasi panitia</strong>
                        <p>Setelah disetujui, tautan untuk melanjutkan pendaftaran (pembayaran dan formulir lengkap) dikirim ke WhatsApp <strong><?php echo h(ppdb_display_phone((string) $registration['no_hp_pendaftar'])); ?></strong>. Mohon pastikan nomor tersebut aktif.</p>
                        <p class="approval-wait__note">Simpan kode <strong><?php echo h($registration['id_pendaftaran']); ?></strong>. Halaman ini juga dapat dibuka kembali melalui menu Lanjutkan pendaftaran.</p>
                    </div>
                </div>
            <?php } elseif ($panel === 'bayar') { ?>
                <?php if ($registration['status_pembayaran'] === 'ditolak') { ?>
                    <div class="payment-error" role="alert">Bukti pembayaran sebelumnya belum disetujui. <?php echo h($registration['catatan_bukti']); ?> Isian formulir Anda tetap tersimpan.</div>
                <?php } ?>
                <?php if ($formExpired) { ?>
                    <div class="payment-guidance is-warning"><strong>Ananda dinyatakan gugur</strong><span>Batas pengisian formulir telah berakhir pada <?php echo h(ppdb_format_datetime_id($formWindow['batas'])); ?>. Hubungi panitia SPMB melalui WhatsApp <?php echo h(ppdb_admin_whatsapp_display()); ?> sebelum melakukan pembayaran.</span></div>
                <?php } elseif ($paymentDeadlinePassed) { ?>
                    <div class="payment-guidance is-warning">Batas pembayaran telah lewat. Hubungi panitia sebelum mengirim pembayaran.</div>
                <?php } else { ?>
                    <section class="payment-instruction" aria-labelledby="payment-instruction-title">
                        <div class="payment-instruction__heading">
                            <div>
                                <p class="payment-instruction__eyebrow">Langkah pembayaran</p>
                                <h2 id="payment-instruction-title">Instruksi pembayaran</h2>
                                <p>Periksa nominal dan tujuan transfer sebelum melakukan pembayaran.</p>
                            </div>
                        </div>

                        <?php if ((float) $registration['biaya_pendaftaran'] > 0) { ?>
                            <div class="payment-amount">
                                <span>Nominal yang perlu dibayarkan</span>
                                <div class="payment-amount__row">
                                    <strong><small>Rp</small><?php echo h(number_format((float) $registration['biaya_pendaftaran'], 0, ',', '.')); ?></strong>
                                    <button class="flow-copy" type="button" data-copy="<?php echo (int) $registration['biaya_pendaftaran']; ?>" aria-label="Salin nominal"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3h10a2 2 0 0 1 2 2v12h-2V5H9V3ZM5 7h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Zm0 2v10h10V9H5Z"/></svg><span>Salin</span></button>
                                </div>
                            </div>
                            <?php if ($hasPaymentAccount) { ?>
                                <div class="payment-bank-brand" aria-label="Bank tujuan: <?php echo h(PPDB_BANK_NAME); ?>">
                                    <img class="payment-bank-brand__logo" src="img/Logo_Bank_Aceh_Syariah.png" alt="Logo <?php echo h(PPDB_BANK_NAME); ?>">
                                    <span class="payment-bank-brand__name"><small>Transfer ke</small><strong><?php echo h(PPDB_BANK_NAME); ?></strong></span>
                                </div>
                                <dl class="payment-detail-list">
                                    <div><dt>Nomor rekening</dt><dd class="payment-account-number"><span><?php echo h($registration['nomor_rekening']); ?></span><button class="flow-copy" type="button" data-copy="<?php echo h($registration['nomor_rekening']); ?>" aria-label="Salin nomor rekening"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3h10a2 2 0 0 1 2 2v12h-2V5H9V3ZM5 7h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Zm0 2v10h10V9H5Z"/></svg><span>Salin</span></button></dd></div>
                                    <div><dt>Atas nama</dt><dd><?php echo h($registration['nama_pemilik_rekening']); ?></dd></div>
                                    <div><dt>Batas pembayaran</dt><dd><?php echo $registration['tanggal_batas_bayar'] ? h(ppdb_format_date_id($registration['tanggal_batas_bayar'])) : 'Belum ditentukan'; ?></dd></div>
                                </dl>
                                <div class="payment-reference">
                                    <span>Berita transfer / keterangan</span>
                                    <div class="payment-reference__row"><strong><?php echo h($registration['id_pendaftaran']); ?></strong><button class="flow-copy" type="button" data-copy="<?php echo h($registration['id_pendaftaran']); ?>" aria-label="Salin kode berita transfer"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3h10a2 2 0 0 1 2 2v12h-2V5H9V3ZM5 7h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Zm0 2v10h10V9H5Z"/></svg><span>Salin</span></button></div>
                                    <small>Mohon cantumkan kode ini agar pembayaran dapat dikenali dan diverifikasi.</small>
                                </div>
                                <ol class="payment-steps">
                                    <li>Transfer sesuai nominal ke rekening tujuan yang tertera.</li>
                                    <li>Cantumkan kode pendaftaran pada berita transfer.</li>
                                    <li>Unggah bukti transfer yang jelas melalui formulir di bawah.</li>
                                </ol>
                            <?php } else { ?>
                                <div class="payment-error" role="alert">Informasi rekening belum tersedia. Jangan melakukan transfer terlebih dahulu. Hubungi admin SPMB untuk mendapatkan arahan pembayaran.</div>
                            <?php } ?>
                        <?php } else { ?>
                            <div class="payment-free">
                                <strong>Tidak ada biaya pendaftaran</strong>
                                <span>Ayah/Bunda tidak perlu melakukan transfer. Silakan hubungi admin SPMB untuk arahan proses selanjutnya.</span>
                            </div>
                        <?php } ?>
                    </section>
                    <?php if ((float) $registration['biaya_pendaftaran'] > 0 && $hasPaymentAccount) { ?>
                    <form class="payment-upload" action="upload-bukti-pembayaran" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="id" value="<?php echo h($registration['id_pendaftaran']); ?>">
                        <input type="hidden" name="token" value="<?php echo h($receiptToken); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                        <label for="bukti_transfer">Unggah bukti transfer <span>Pastikan nama rekening, nominal, dan waktu transaksi terlihat jelas. Format JPG, PNG, atau PDF · maksimal 5 MB.</span></label>
                        <input id="bukti_transfer" type="file" name="bukti_transfer" accept="image/jpeg,image/png,application/pdf" required>
                        <button class="download-button" type="submit">Kirim bukti dan lanjutkan <span aria-hidden="true">&rarr;</span></button>
                    </form>
                    <?php } ?>
                <?php } ?>
            <?php } elseif ($panel === 'formulir' && $formExpired) { ?>
                <div class="payment-guidance is-warning">
                    <strong>Ananda dinyatakan gugur</strong>
                    <span>Formulir lengkap belum dikirim sampai batas pengisian <?php echo h(ppdb_format_datetime_id($formWindow['batas'])); ?>. Hubungi panitia SPMB melalui WhatsApp <?php echo h(ppdb_admin_whatsapp_display()); ?> bila memerlukan penjelasan.</span>
                </div>
            <?php } elseif ($panel === 'formulir') { ?>
                <div class="payment-guidance">
                    <strong>Lanjutkan pengisian formulir</strong>
                    <?php if ($registration['status_pembayaran'] === 'menunggu_verifikasi') { ?>
                        <span>Bukti transfer sedang diperiksa panitia<?php echo $registration['diunggah_pada'] ? ' (diunggah '.h(date('d-m-Y H:i', strtotime($registration['diunggah_pada']))).')' : ''; ?>. Anda tidak perlu menunggu.</span>
                    <?php } else { ?>
                        <span>Isian yang sudah tersimpan akan dimuat kembali.</span>
                    <?php } ?>
                    <?php if ($formWindow['batas'] && empty($registration['revisi_dibuka'])) { ?><span>Batas pengisian formulir: <strong><?php echo h(ppdb_format_datetime_id($formWindow['batas'])); ?></strong>. Lewat dari batas ini Ananda dinyatakan gugur.</span><?php } ?>
                </div>
                <a class="download-button" href="formulir?kode=<?php echo rawurlencode($registration['id_pendaftaran']); ?>&amp;token=<?php echo rawurlencode($receiptToken); ?>">Lanjutkan formulir</a>
            <?php } elseif ($panel === 'cek-formulir') { ?>
                <div class="payment-guidance">
                    <strong>Formulir lengkap diterima</strong>
                    <span><?php echo $documentCount; ?> dokumen pendukung telah diunggah. Data sedang diperiksa panitia.</span>
                    <?php if ($registration['status_pembayaran'] === 'menunggu_verifikasi') { ?><span>Bukti transfer juga sedang diperiksa panitia.</span><?php } ?>
                    <span>Hasil pendaftaran akan tampil di halaman ini. Untuk kembali, buka menu Lanjutkan pendaftaran di situs SPMB.</span>
                </div>
            <?php } elseif ($panel === 'ditolak') { ?>
                <div class="payment-error" role="status">
                    <strong>Perlu tindak lanjut panitia.</strong>
                    Pendaftaran terhenti pada tahap <?php echo h($journeySteps[$rejectedCompletedSteps]['title']); ?>. Silakan hubungi panitia SPMB untuk informasi lebih lanjut.
                </div>
            <?php } ?>

            <?php if (!$isAccepted) { ?><a class="secondary-download" href="cetak-bukti?id=<?php echo rawurlencode($registration['id_pendaftaran']); ?>&amp;token=<?php echo rawurlencode($receiptToken); ?>">Unduh bukti pendaftaran (PDF)</a><?php } ?>
            <p class="result-note">Halaman ini memuat token akses pribadi. Jangan bagikan tautannya kepada orang lain. Untuk kembali lagi, buka <a href="lanjutkan-pendaftaran?kode=<?php echo rawurlencode($registration['id_pendaftaran']); ?>">Lanjutkan pendaftaran</a>.</p>
            <div class="admin-contact">
                <span>Perlu bantuan? Hubungi admin SPMB.</span>
                <a class="whatsapp-button" href="<?php echo h(ppdb_admin_whatsapp_url('Assalamu’alaikum, saya memerlukan bantuan terkait pendaftaran '.$registration['id_pendaftaran'].'.')); ?>" target="_blank" rel="noopener noreferrer" aria-label="Hubungi WhatsApp admin SPMB <?php echo h(ppdb_admin_whatsapp_display()); ?>">
                    <svg viewBox="0 0 32 32" aria-hidden="true" focusable="false"><path d="M16.04 3C8.87 3 3.04 8.77 3.04 15.88c0 2.27.6 4.48 1.75 6.43L3 29l6.9-1.8a13.1 13.1 0 0 0 6.14 1.52h.01c7.17 0 13-5.78 13-12.89 0-3.45-1.36-6.69-3.83-9.12A12.94 12.94 0 0 0 16.04 3Zm0 23.54h-.01c-1.93 0-3.83-.52-5.48-1.51l-.39-.23-4.09 1.07 1.09-3.96-.25-.4a10.62 10.62 0 0 1-1.65-5.63c0-5.92 4.85-10.74 10.79-10.74 2.88 0 5.59 1.12 7.62 3.14a10.61 10.61 0 0 1 3.16 7.59c0 5.92-4.85 10.74-10.79 10.74Zm5.92-8.04c-.32-.16-1.9-.93-2.2-1.04-.3-.11-.51-.16-.73.16-.21.32-.84 1.04-1.03 1.25-.19.21-.38.24-.7.08-.32-.16-1.36-.5-2.59-1.59-.96-.85-1.61-1.9-1.8-2.22-.19-.32-.02-.49.14-.65.14-.14.32-.37.48-.56.16-.19.21-.32.32-.53.11-.21.05-.4-.03-.56-.08-.16-.73-1.74-1-2.38-.26-.62-.53-.54-.73-.55l-.62-.01c-.21 0-.56.08-.86.4-.3.32-1.12 1.09-1.12 2.67s1.15 3.1 1.31 3.31c.16.21 2.26 3.43 5.47 4.81.76.33 1.36.52 1.82.66.76.24 1.45.21 2 .13.61-.09 1.9-.77 2.17-1.52.27-.75.27-1.39.19-1.52-.08-.13-.3-.21-.62-.37Z"/></svg>
                    <span><?php echo h(ppdb_admin_whatsapp_display()); ?></span>
                </a>
            </div>
        </section>
    <?php } else { ?>
        <section class="result-panel result-error">
            <p class="result-eyebrow">Tautan tidak valid</p>
            <h1>Status pendaftaran<br>tidak ditemukan.</h1>
            <p class="result-description">Masuk kembali lewat halaman Lanjutkan pendaftaran, atau hubungi panitia SPMB.</p>
            <a class="download-button" href="lanjutkan-pendaftaran">Lanjutkan pendaftaran</a>
        </section>
    <?php } ?>

    <footer>Yayasan Wakaf Cendekia Takengon · <a href="mailto:wakafcendekiatakengon@gmail.com">Hubungi panitia</a> · WhatsApp <?php echo h(ppdb_admin_whatsapp_display()); ?></footer>
</main>
<?php if ($registration && !$formExpired && empty($registration['revisi_dibuka']) && isset($panel) && in_array($panel, ['bayar', 'formulir'], true)) {
    ppdb_render_form_deadline_dialog($formWindow, (string) ($registration['nama_unit'] ?? ''), (string) $registration['nm_peserta'], 'batas-formulir-'.$registration['id_pendaftaran']);
} ?>
<?php if ($groupJoinUrl !== '' && !$groupOpened) { ?>
    <dialog class="group-reminder" data-group-reminder aria-labelledby="group-reminder-title" aria-describedby="group-reminder-text">
        <header class="group-reminder__head">
            <form method="dialog"><button class="group-reminder__close" type="submit" aria-label="Tutup"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6z"/></svg></button></form>
            <img class="group-reminder__logo" src="img/logo-display.png" alt="" width="52" height="51">
            <p class="group-reminder__eyebrow">SPMB <?php echo h($registration['th_ajaran']); ?> &middot; Langkah terakhir</p>
            <h2 id="group-reminder-title">Bergabung dengan Grup Resmi Wali Murid</h2>
            <p class="group-reminder__unit"><?php echo h($registration['nama_unit']); ?></p>
        </header>
        <ol class="group-reminder__progress" aria-label="Tahapan pendaftaran">
            <li class="is-done"><span aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M9.5 16.2 5.3 12l-1.6 1.6 5.8 5.8L20.3 8.6 18.7 7z"/></svg></span>Registrasi</li>
            <li class="is-done"><span aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M9.5 16.2 5.3 12l-1.6 1.6 5.8 5.8L20.3 8.6 18.7 7z"/></svg></span>Pembayaran</li>
            <li class="is-done"><span aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M9.5 16.2 5.3 12l-1.6 1.6 5.8 5.8L20.3 8.6 18.7 7z"/></svg></span>Formulir</li>
            <li class="is-current" aria-current="step"><span aria-hidden="true">4</span>Grup WA</li>
        </ol>
        <div class="group-reminder__body">
            <p id="group-reminder-text">Ayah/Bunda, formulir ananda <strong><?php echo h($registration['nm_peserta']); ?></strong> telah kami terima. Mohon bergabung ke grup resmi unit karena <strong>seluruh informasi lanjutan hanya disampaikan melalui grup ini</strong>.</p>
            <ul class="group-reminder__list">
                <li>Jadwal dan agenda awal tahun ajaran</li>
                <li>Informasi daftar ulang dan administrasi</li>
                <li>Perlengkapan dan persiapan ananda</li>
            </ul>
            <a class="group-reminder__join" href="<?php echo h($groupJoinUrl); ?>" target="_blank" rel="noopener noreferrer" data-group-join autofocus>
                <svg viewBox="0 0 32 32" aria-hidden="true" focusable="false"><path d="M16.04 3C8.87 3 3.04 8.77 3.04 15.88c0 2.27.6 4.48 1.75 6.43L3 29l6.9-1.8a13.1 13.1 0 0 0 6.14 1.52h.01c7.17 0 13-5.78 13-12.89 0-3.45-1.36-6.69-3.83-9.12A12.94 12.94 0 0 0 16.04 3Zm5.92 15.5c-.32-.16-1.9-.93-2.2-1.04-.3-.11-.51-.16-.73.16-.21.32-.84 1.04-1.03 1.25-.19.21-.38.24-.7.08-.32-.16-1.36-.5-2.59-1.59-.96-.85-1.61-1.9-1.8-2.22-.19-.32-.02-.49.14-.65.14-.14.32-.37.48-.56.16-.19.21-.32.32-.53.11-.21.05-.4-.03-.56-.08-.16-.73-1.74-1-2.38-.26-.62-.53-.54-.73-.55l-.62-.01c-.21 0-.56.08-.86.4-.3.32-1.12 1.09-1.12 2.67s1.15 3.1 1.31 3.31c.16.21 2.26 3.43 5.47 4.81.76.33 1.36.52 1.82.66.76.24 1.45.21 2 .13.61-.09 1.9-.77 2.17-1.52.27-.75.27-1.39.19-1.52-.08-.13-.3-.21-.62-.37Z"/></svg>
                <span>Gabung Grup WhatsApp</span>
            </a>
            <p class="group-reminder__secure"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Zm-1.2 14.2-3.5-3.5 1.4-1.4 2.1 2.1 4.9-4.9 1.4 1.4-6.3 6.3Z"/></svg>Tautan resmi panitia SPMB Cendekia</p>
            <form method="dialog"><button class="group-reminder__later" type="submit">Nanti saja</button></form>
        </div>
    </dialog>
<?php } ?>
<script src="js/status.js?v=<?php echo (int) @filemtime(__DIR__.'/js/status.js'); ?>"></script>
<script src="js/alur.js?v=<?php echo (int) @filemtime(__DIR__.'/js/alur.js'); ?>"></script>
</body>
</html>
