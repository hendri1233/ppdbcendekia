/**
 * Interaksi dashboard: menu ponsel, konfirmasi hapus, dan pencarian
 * dengan penundaan agar tidak membebani server.
 */
(function () {
    'use strict';

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
