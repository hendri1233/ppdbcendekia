/**
 * Interaksi dashboard: menu ponsel, konfirmasi hapus, dan pencarian
 * dengan penundaan agar tidak membebani server.
 */
(function () {
    'use strict';

    const root = document.documentElement;

    // --- Loader halaman: tampil saat memuat dan saat berpindah halaman ---
    const finishLoading = () => root.classList.remove('is-loading');
    if (document.readyState === 'complete') {
        finishLoading();
    } else {
        window.addEventListener('load', finishLoading, { once: true });
        setTimeout(finishLoading, 2500);
    }
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) root.classList.remove('is-loading', 'is-leaving');
    });
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if ((link.target && link.target !== '_self') || link.hasAttribute('download')) return;
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) return;
        if (url.pathname === location.pathname && url.search === location.search && url.hash) return;
        // Unduhan berkas (PDF, bukti, dokumen) tidak berpindah halaman.
        if (/(export-token-internal|bukti-pembayaran-admin|dokumen-admin|cetak-bukti|ppdb-materi-file)\.php$/.test(url.pathname)) return;
        root.classList.add('is-leaving');
    });

    // --- Tema terang/gelap ---
    const themeButtons = document.querySelectorAll('[data-theme-toggle]');
    const syncTheme = () => {
        const dark = root.getAttribute('data-theme') === 'dark';
        for (const button of themeButtons) {
            button.setAttribute('aria-label', dark ? 'Ganti ke mode terang' : 'Ganti ke mode gelap');
            button.setAttribute('aria-pressed', String(dark));
        }
    };
    for (const button of themeButtons) {
        button.addEventListener('click', () => {
            const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem('cendekia-theme', next); } catch (e) { /* mode privat */ }
            syncTheme();
        });
    }
    syncTheme();

    // --- Tombol kirim menampilkan status memproses ---
    for (const form of document.querySelectorAll('form[data-loading-form]')) {
        form.addEventListener('submit', (event) => {
            if (event.defaultPrevented) return;
            const button = event.submitter || form.querySelector('[type="submit"]');
            if (!button || button.disabled) return;
            setTimeout(() => {
                button.disabled = true;
                button.classList.add('is-busy');
                button.insertAdjacentHTML('afterbegin', '<span class="spinner" aria-hidden="true"></span>');
            }, 0);
        });
    }

    // --- Menu navigasi pada layar kecil ---
    const menuToggle = document.querySelector('[data-menu-toggle]');
    const mobileNav = document.querySelector('[data-mobile-nav]');
    if (menuToggle && mobileNav) {
        const closeMenu = () => {
            menuToggle.setAttribute('aria-expanded', 'false');
            menuToggle.setAttribute('aria-label', 'Buka navigasi');
            mobileNav.classList.remove('is-open');
            document.body.classList.remove('menu-open');
        };
        menuToggle.addEventListener('click', () => {
            const isOpen = menuToggle.getAttribute('aria-expanded') === 'true';
            menuToggle.setAttribute('aria-expanded', String(!isOpen));
            menuToggle.setAttribute('aria-label', isOpen ? 'Buka navigasi' : 'Tutup navigasi');
            mobileNav.classList.toggle('is-open', !isOpen);
            document.body.classList.toggle('menu-open', !isOpen);
        });
        mobileNav.addEventListener('click', (event) => {
            if (event.target.closest('a')) closeMenu();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && mobileNav.classList.contains('is-open')) {
                closeMenu();
                menuToggle.focus();
            }
        });
    }

    // --- Konfirmasi sebelum menghapus data yang tidak dapat dikembalikan ---
    for (const form of document.querySelectorAll('form[data-confirm]')) {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    }

    // --- Notifikasi WA: cegah klik ganda agar pesan tidak terkirim dua kali ---
    for (const form of document.querySelectorAll('form[data-wa-send]')) {
        form.addEventListener('submit', (event) => {
            const button = form.querySelector('button[type="submit"]');
            if (form.dataset.sending === '1') {
                event.preventDefault();
                return;
            }
            form.dataset.sending = '1';
            if (button) {
                button.disabled = true;
                button.textContent = 'Mengirim…';
            }
        });
    }

    // --- Pencarian dengan tunda agar tidak mengirim setiap ketikan ---
    const searchInput = document.querySelector('.search-field input[type="search"]');
    const filterForm = searchInput ? searchInput.closest('form') : null;
    if (searchInput && filterForm) {
        let timer = null;
        searchInput.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => {
                if (filterForm.requestSubmit) {
                    filterForm.requestSubmit();
                } else {
                    filterForm.submit();
                }
            }, 550);
        });
    }

    // --- Tandai baris tabel yang perlu ditindaklanjuti admin ---
})();
