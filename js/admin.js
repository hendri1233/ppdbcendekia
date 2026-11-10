/**
 * Interaksi dashboard: menu ponsel, konfirmasi hapus, dan pencarian
 * dengan penundaan agar tidak membebani server.
 */
(function () {
    'use strict';

    const root = document.documentElement;

    // --- Pekerjaan lambat (spreadsheet, lokasi peta) berjalan setelah halaman siap ---
    if (document.querySelector('[data-admin-background]')) {
        window.setTimeout(() => {
            fetch('admin-latar.php', { credentials: 'same-origin', keepalive: true }).catch(() => {});
        }, 600);
    }

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

    // --- Ciutkan/buka menu samping (layar lebar), diingat antarhalaman ---
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const syncSidebar = () => {
        if (!sidebarToggle) return;
        const collapsed = root.classList.contains('sidebar-collapsed');
        sidebarToggle.setAttribute('aria-expanded', String(!collapsed));
        sidebarToggle.setAttribute('aria-label', collapsed ? 'Buka menu' : 'Ciutkan menu');
        sidebarToggle.title = collapsed ? 'Buka menu' : 'Ciutkan menu';
    };
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            root.classList.toggle('sidebar-collapsed');
            try {
                localStorage.setItem('cendekia-sidebar', root.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open');
            } catch (e) { /* mode privat */ }
            syncSidebar();
            // Grafik dan peta di dashboard menyesuaikan lebar baru setelah animasi selesai.
            setTimeout(() => window.dispatchEvent(new Event('resize')), 500);
        });
    }
    syncSidebar();

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
            if (event.target.closest('[data-nav-close]')) {
                closeMenu();
                menuToggle.focus();
            }
        });
        document.querySelector('[data-nav-backdrop]')?.addEventListener('click', closeMenu);
        document.querySelector('[data-tabbar-menu]')?.addEventListener('click', () => {
            menuToggle.click();
            mobileNav.querySelector('[data-nav-search]')?.focus({ preventScroll: true });
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && mobileNav.classList.contains('is-open')) {
                closeMenu();
                menuToggle.focus();
            }
        });
    }

    // --- Pencarian menu di sidebar dan laci ponsel ---
    for (const search of document.querySelectorAll('[data-nav-search]')) {
        const root = search.closest('.admin-sidebar, .admin-mobile-nav');
        const empty = root?.querySelector('[data-nav-empty]');
        search.addEventListener('input', () => {
            const term = search.value.trim().toLowerCase();
            let visible = 0;
            let group = null;
            let groupHasMatch = false;
            const finishGroup = () => { if (group) group.hidden = term !== '' && !groupHasMatch; };
            for (const node of root.querySelectorAll('.admin-nav__group, nav > a, .admin-nav > a, .sidebar-bottom > a, nav > form, .sidebar-bottom > form')) {
                if (node.classList.contains('admin-nav__group')) {
                    finishGroup();
                    group = node;
                    groupHasMatch = false;
                    continue;
                }
                const match = term === '' || node.textContent.toLowerCase().includes(term);
                node.hidden = !match;
                if (match) {
                    visible++;
                    groupHasMatch = true;
                }
            }
            finishGroup();
            if (empty) empty.hidden = visible > 0;
        });
        search.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                const first = [...root.querySelectorAll('a[href]')].find((link) => !link.hidden && !link.closest('[hidden]') && !link.closest('.mobile-nav-head') && !link.classList.contains('admin-brand'));
                if (first) {
                    event.preventDefault();
                    first.click();
                }
            }
        });
    }
    // Tombol "/" membuka pencarian menu di sidebar desktop.
    document.addEventListener('keydown', (event) => {
        if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;
        const target = event.target;
        if (target.closest('input, textarea, select, [contenteditable="true"]')) return;
        const sidebarSearch = document.querySelector('.admin-sidebar [data-nav-search]');
        if (sidebarSearch && sidebarSearch.offsetParent !== null) {
            event.preventDefault();
            sidebarSearch.focus();
        }
    });

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

    // --- Notifikasi melayang singkat untuk aksi tanpa muat ulang halaman ---
    const showToast = (text, ok) => {
        let toast = document.querySelector('[data-admin-toast]');
        if (!toast) {
            toast = document.createElement('div');
            toast.dataset.adminToast = '1';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            document.body.appendChild(toast);
        }
        toast.className = 'admin-toast ' + (ok ? 'is-ok' : 'is-error');
        toast.textContent = text;
        toast.hidden = false;
        window.clearTimeout(toast.timer);
        toast.timer = window.setTimeout(() => { toast.hidden = true; }, 6000);
    };

    // --- Revisi formulir: sekali klik, tautan langsung dikirim tanpa memuat ulang halaman ---
    for (const form of document.querySelectorAll('form[data-revisi]')) {
        const button = form.querySelector('button[type="submit"]');
        const status = form.querySelector('[data-revisi-status]');
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (form.dataset.sending === '1') return;
            form.dataset.sending = '1';
            button.disabled = true;
            button.textContent = 'Mengirim…';
            status.className = 'revision-action__status';
            status.replaceChildren();
            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((response) => response.json())
                .catch(() => ({ ok: false, text: 'Koneksi terputus. Coba lagi.' }))
                .then((result) => {
                    form.dataset.sending = '';
                    button.disabled = false;
                    button.textContent = result.ok && (result.sent || result.queued) ? 'Kirim ulang' : 'Revisi';
                    status.classList.add(result.ok ? 'is-ok' : 'is-error');
                    status.title = result.text;
                    status.append(document.createTextNode(result.ok && result.sent ? '✓ Terkirim' : result.ok && result.queued ? '✓ Masuk antrean' : result.ok ? 'Formulir dibuka' : 'Gagal'));
                    showToast(result.text, result.ok);
                    for (const link of result.links || []) {
                        const anchor = document.createElement('a');
                        anchor.className = 'table-action stage-wa';
                        anchor.href = link.url;
                        anchor.target = '_blank';
                        anchor.rel = 'noopener';
                        anchor.textContent = 'WA ' + link.role + ' · ' + link.display;
                        status.append(anchor);
                    }
                    // Perbarui label status di baris yang sama.
                    const row = form.closest('tr');
                    const pill = row && row.querySelector('td[data-label="Status"] .status-pill');
                    if (pill && result.status_label) pill.textContent = result.status_label;
                });
        });
    }

    // --- Pencarian dijalankan saat Enter ditekan (atau tombol Terapkan), bukan setiap ketikan ---

    // --- Menu akun pada ikon profil ---
    const userMenu = document.querySelector('[data-user-menu]');
    if (userMenu) {
        const toggle = userMenu.querySelector('[data-user-menu-toggle]');
        const panel = userMenu.querySelector('[data-user-menu-panel]');
        const setOpen = (open) => {
            panel.hidden = !open;
            toggle.setAttribute('aria-expanded', String(open));
        };
        toggle.addEventListener('click', () => setOpen(panel.hidden));
        document.addEventListener('click', (event) => {
            if (!panel.hidden && !userMenu.contains(event.target)) setOpen(false);
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !panel.hidden) {
                setOpen(false);
                toggle.focus();
            }
        });
    }

    // --- Tandai baris tabel yang perlu ditindaklanjuti admin ---
})();

// Detail peserta: kirim pesan WhatsApp manual (cadangan bila Wablas terputus).
(function () {
    const panel = document.querySelector('[data-manual-wa]');
    if (!panel) return;
    const activeText = function () {
        return Array.from(panel.querySelectorAll('[data-manual-wa-text]')).find(function (area) { return !area.hidden; });
    };
    const syncLinks = function () {
        const area = activeText();
        if (!area) return;
        panel.querySelectorAll('[data-manual-wa-send]').forEach(function (link) {
            link.href = 'https://wa.me/' + link.dataset.manualWaSend + '?text=' + encodeURIComponent(area.value);
        });
    };
    panel.querySelectorAll('[data-manual-wa-choice]').forEach(function (choice) {
        choice.addEventListener('change', function () {
            panel.querySelectorAll('[data-manual-wa-text]').forEach(function (area) {
                area.hidden = area.dataset.manualWaText !== choice.value;
            });
            syncLinks();
        });
    });
    panel.addEventListener('input', syncLinks);
    const copy = panel.querySelector('[data-manual-wa-copy]');
    if (copy) {
        copy.addEventListener('click', function () {
            const area = activeText();
            const done = function (label) {
                copy.textContent = label;
                window.setTimeout(function () { copy.textContent = 'Salin pesan'; }, 1800);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(area.value).then(function () { done('Tersalin'); }, function () { area.select(); done(document.execCommand('copy') ? 'Tersalin' : 'Gagal menyalin'); });
            } else {
                area.select();
                done(document.execCommand('copy') ? 'Tersalin' : 'Gagal menyalin');
            }
        });
    }
})();

// Daftar peserta: klik nama memunculkan pilihan Pratinjau atau Lihat detail;
// pratinjau menampilkan data lengkap di panel samping tanpa meninggalkan halaman.
(function () {
    const menu = document.querySelector('[data-name-menu]');
    const drawer = document.querySelector('[data-preview-drawer]');
    if (!menu || !drawer || typeof drawer.showModal !== 'function') return;
    const body = drawer.querySelector('[data-preview-body]');
    const detailLink = drawer.querySelector('[data-preview-detail]');
    const printLink = drawer.querySelector('[data-preview-print]');
    const position = drawer.querySelector('[data-preview-position]');
    const prevButton = drawer.querySelector('[data-preview-prev]');
    const nextButton = drawer.querySelector('[data-preview-next]');
    let menuTarget = null;
    let currentId = '';
    let request = 0;

    const rowIds = function () {
        return Array.from(document.querySelectorAll('tr[data-row-id]'), function (row) { return row.dataset.rowId; });
    };

    const closeMenu = function () {
        menu.hidden = true;
        if (menuTarget) menuTarget.removeAttribute('aria-expanded');
        menuTarget = null;
    };

    // Menu mengikuti posisi nama; tertutup bila nama sudah keluar dari layar.
    const placeMenu = function () {
        if (!menuTarget || menu.hidden) return;
        const rect = menuTarget.getBoundingClientRect();
        if (rect.bottom < 0 || rect.top > window.innerHeight || rect.right < 0 || rect.left > window.innerWidth) {
            closeMenu();
            return;
        }
        const width = menu.offsetWidth;
        const height = menu.offsetHeight;
        const left = Math.min(Math.max(8, rect.left), window.innerWidth - width - 8);
        const below = rect.bottom + 6;
        menu.style.left = left + 'px';
        menu.style.top = (below + height > window.innerHeight - 8 ? Math.max(8, rect.top - height - 6) : below) + 'px';
    };

    const openMenu = function (link) {
        closeMenu();
        menuTarget = link;
        link.setAttribute('aria-expanded', 'true');
        menu.querySelector('[data-name-detail]').href = link.href;
        menu.hidden = false;
        placeMenu();
        menu.querySelector('[data-name-preview]').focus({ preventScroll: true });
    };

    const markRow = function (id) {
        document.querySelectorAll('tr.is-previewing').forEach(function (row) { row.classList.remove('is-previewing'); });
        const row = document.querySelector('tr[data-row-id="' + id + '"]');
        if (row) row.classList.add('is-previewing');
    };

    const load = function (id) {
        currentId = id;
        const ids = rowIds();
        const index = ids.indexOf(id);
        position.textContent = index >= 0 ? (index + 1) + ' / ' + ids.length : '';
        prevButton.disabled = index <= 0;
        nextButton.disabled = index < 0 || index >= ids.length - 1;
        detailLink.href = 'detail-peserta.php?id=' + encodeURIComponent(id);
        printLink.hidden = true;
        markRow(id);
        body.innerHTML = '<div class="pv-loading" aria-label="Memuat data"><span></span><span></span><span></span><span></span></div>';
        const ticket = ++request;
        fetch('pratinjau-peserta.php?id=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { Accept: 'text/html' } })
            .then(function (response) { return response.text(); })
            .catch(function () { return '<div class="pv-empty"><strong>Koneksi terputus.</strong><p>Periksa jaringan lalu coba lagi.</p></div>'; })
            .then(function (html) {
                if (ticket !== request) return;
                body.innerHTML = html;
                body.scrollTop = 0;
                const article = body.querySelector('[data-print-url]');
                if (article) {
                    printLink.href = article.dataset.printUrl;
                    printLink.textContent = article.dataset.printLabel;
                    printLink.hidden = false;
                }
            });
    };

    const open = function (id) {
        if (!drawer.open) drawer.showModal();
        load(id);
        body.focus({ preventScroll: true });
    };

    document.addEventListener('click', function (event) {
        const link = event.target.closest('a.participant-link');
        if (link) {
            // Ctrl/Shift/klik tengah tetap membuka detail di tab baru seperti biasa.
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;
            event.preventDefault();
            if (menuTarget === link && !menu.hidden) {
                closeMenu();
            } else {
                openMenu(link);
            }
            return;
        }
        if (!menu.hidden && !menu.contains(event.target)) closeMenu();
    });

    menu.querySelector('[data-name-preview]').addEventListener('click', function () {
        const id = menuTarget ? menuTarget.dataset.participant : '';
        closeMenu();
        if (id) open(id);
    });

    document.addEventListener('keydown', function (event) {
        if (!menu.hidden && event.key === 'Escape') {
            const target = menuTarget;
            closeMenu();
            if (target) target.focus();
            return;
        }
        if (!drawer.open || event.target.closest('input, textarea, select')) return;
        if (event.key === 'ArrowLeft' && !prevButton.disabled) prevButton.click();
        if (event.key === 'ArrowRight' && !nextButton.disabled) nextButton.click();
    });
    window.addEventListener('scroll', placeMenu, { passive: true });
    window.addEventListener('resize', placeMenu);
    document.querySelectorAll('.table-wrap').forEach(function (wrap) { wrap.addEventListener('scroll', placeMenu, { passive: true }); });

    prevButton.addEventListener('click', function () {
        const ids = rowIds();
        const index = ids.indexOf(currentId);
        if (index > 0) load(ids[index - 1]);
    });
    nextButton.addEventListener('click', function () {
        const ids = rowIds();
        const index = ids.indexOf(currentId);
        if (index >= 0 && index < ids.length - 1) load(ids[index + 1]);
    });
    drawer.querySelector('[data-preview-close]').addEventListener('click', function () { drawer.close(); });
    // Klik di luar panel menutup pratinjau.
    drawer.addEventListener('click', function (event) {
        if (event.target === drawer) drawer.close();
    });
    drawer.addEventListener('close', function () {
        document.querySelectorAll('tr.is-previewing').forEach(function (row) { row.classList.remove('is-previewing'); });
        const link = document.querySelector('a.participant-link[data-participant="' + currentId + '"]');
        if (link) link.focus({ preventScroll: true });
    });
    // Lompat ke bagian di dalam panel tanpa menggulir halaman di belakangnya.
    body.addEventListener('click', function (event) {
        const jump = event.target.closest('.pv-jump a');
        if (!jump) return;
        event.preventDefault();
        const target = body.querySelector(jump.getAttribute('href'));
        if (target) body.scrollTo({ top: target.offsetTop - 64, behavior: 'smooth' });
    });
})();
