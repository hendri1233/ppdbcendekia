/**
 * Pembaruan otomatis panel admin. Setiap 20 detik, selama tab sedang dibuka, halaman
 * memeriksa perubahan data pendaftar. Dashboard memperbarui angkanya sendiri; halaman lain
 * menampilkan pemberitahuan agar baris tabel tidak bergeser saat admin sedang bekerja.
 */
(function () {
    'use strict';

    const stateNode = document.querySelector('[data-admin-live]');
    if (!stateNode) return;

    const POLL_INTERVAL = 20000;
    const pageTitle = document.title;
    const isListPage = location.pathname.endsWith('daftar_peserta.php');
    let state;
    try {
        state = JSON.parse(stateNode.dataset.adminLive);
    } catch (error) {
        return;
    }
    let baseline = { total: state.total, proof: state.proof };
    let checking = false;
    let timer = null;
    let bar = null;

    function changeMessage(next) {
        const parts = [];
        const newRegistrants = next.total - baseline.total;
        const newProofs = next.proof - baseline.proof;
        if (newRegistrants > 0) parts.push(newRegistrants + ' pendaftar baru');
        if (newProofs > 0) parts.push(newProofs + ' bukti transfer baru');
        return { text: parts.length ? parts.join(' dan ') : 'Data pendaftar diperbarui', count: Math.max(newRegistrants, 0) + Math.max(newProofs, 0) };
    }

    function showBar(text, actionLabel, onAction) {
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'live-bar';
            bar.setAttribute('role', 'status');
            bar.innerHTML = '<span class="live-bar__text"></span><button type="button" class="live-bar__action"></button><button type="button" class="live-bar__close" aria-label="Tutup pemberitahuan">&times;</button>';
            bar.querySelector('.live-bar__close').addEventListener('click', () => { bar.hidden = true; });
            bar.querySelector('.live-bar__action').addEventListener('click', () => { bar.onAction(); });
            document.body.appendChild(bar);
        }
        bar.querySelector('.live-bar__text').textContent = text;
        bar.querySelector('.live-bar__action').textContent = actionLabel;
        bar.onAction = onAction;
        bar.hidden = false;
    }

    /** Ganti angka dashboard dari halaman terbaru tanpa memuat ulang grafik dan peta. */
    async function refreshRegions(regions) {
        const response = await fetch(location.href, { credentials: 'same-origin', cache: 'no-store' });
        if (!response.ok || response.redirected) throw new Error('Halaman tidak dapat dimuat.');
        const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
        for (const region of regions) {
            const replacement = fresh.querySelector('[data-live-region="' + region.dataset.liveRegion + '"]');
            if (replacement) region.replaceWith(document.importNode(replacement, true));
        }
        for (const node of document.querySelectorAll('[data-count]')) {
            node.textContent = (parseInt(node.getAttribute('data-count'), 10) || 0).toLocaleString('id-ID');
        }
    }

    async function applyChange(next) {
        const message = changeMessage(next);
        const regions = Array.from(document.querySelectorAll('[data-live-region]'));
        if (regions.length) {
            try {
                await refreshRegions(regions);
                baseline = { total: next.total, proof: next.proof };
                showBar(message.text + '. Angka sudah diperbarui.', 'Perbarui grafik', () => location.reload());
                state = next;
                return;
            } catch (error) {
                // Bila gagal, admin tetap diberi tahu lewat tombol muat ulang di bawah.
            }
        }
        document.title = (message.count > 0 ? '(' + message.count + ') ' : '') + pageTitle;
        if (isListPage || regions.length) {
            showBar(message.text, 'Tampilkan', () => location.reload());
        } else {
            showBar(message.text, 'Lihat data peserta', () => { location.href = 'daftar_peserta.php'; });
        }
        state = next;
    }

    async function check() {
        if (checking || document.hidden) return;
        checking = true;
        try {
            const response = await fetch('admin-pembaruan.php', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            // Sesi berakhir: berhenti memeriksa tanpa memindahkan admin dari pekerjaannya.
            if (response.redirected || !response.ok) {
                window.clearInterval(timer);
                return;
            }
            const next = await response.json();
            if (next.rev && next.rev !== state.rev) await applyChange(next);
        } catch (error) {
            // Koneksi terputus sesaat; pemeriksaan berikutnya mencoba lagi.
        } finally {
            checking = false;
        }
    }

    timer = window.setInterval(check, POLL_INTERVAL);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) check();
    });
})();
