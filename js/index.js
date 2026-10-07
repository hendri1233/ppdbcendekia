/**
 * Halaman beranda: menu ponsel, navigasi aktif, Munculnya konten
 * saat digulir, dankbdan penanda tautan bagian.
 */
(function () {
    'use strict';

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // --- Menu ponsel ---
    const menuToggle = document.querySelector('[data-menu-toggle]');
    const navigation = document.querySelector('[data-navigation]');
    const header = document.querySelector('.site-header');

    const closeMenu = () => {
        if (!menuToggle || !navigation) return;
        menuToggle.setAttribute('aria-expanded', 'false');
        menuToggle.setAttribute('aria-label', 'Buka navigasi');
        navigation.classList.remove('is-open');
        document.body.classList.remove('menu-open');
    };

    if (menuToggle && navigation) {
        menuToggle.addEventListener('click', () => {
            const isOpen = menuToggle.getAttribute('aria-expanded') === 'true';
            menuToggle.setAttribute('aria-expanded', String(!isOpen));
            menuToggle.setAttribute('aria-label', isOpen ? 'Buka navigasi' : 'Tutup navigasi');
            navigation.classList.toggle('is-open', !isOpen);
            document.body.classList.toggle('menu-open', !isOpen);
        });

        navigation.addEventListener('click', (event) => {
            if (event.target.closest('a')) closeMenu();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && navigation.classList.contains('is-open')) {
                closeMenu();
                menuToggle.focus();
            }
        });

        // Tutup menu bila layar kembali lebar.
        window.addEventListener('resize', () => {
            if (window.innerWidth > 900) closeMenu();
        });
    }

    // Header mendapat bayangan saat halaman digulir.
    if (header) {
        const onScroll = () => {
            header.classList.toggle('is-stuck', window.scrollY > 8);
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    // --- Konten muncul ketika masuk viewport ---
    const revealables = Array.from(document.querySelectorAll('[data-reveal]'));
    if (revealables.length) {
        if (reduceMotion || !('IntersectionObserver' in window)) {
            for (const element of revealables) element.classList.add('is-visible');
        } else {
            const observer = new IntersectionObserver((entries) => {
                for (const entry of entries) {
                    if (!entry.isIntersecting) continue;
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            }, { rootMargin: '0px 0px -6% 0px', threshold: 0.08 });
            for (const element of revealables) observer.observe(element);
        }
    }

    // Garis atas pada langkah alur menyala saat bagian terlihat.
    const alurSection = document.getElementById('alur');
    if (alurSection && !reduceMotion && 'IntersectionObserver' in window) {
        const steps = Array.from(alurSection.querySelectorAll('.alur-step'));
        const stepObserver = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) entry.target.classList.add('is-visible');
            }
        }, { threshold: 0.4 });
        for (const step of steps) stepObserver.observe(step);
    }

    // --- Tandai tautan navigasi sesuai bagian yang sedang terlihat ---
    const sectionLinks = Array.from(document.querySelectorAll('.primary-navigation a[href^="#"]'));
    const observedSections = sectionLinks
        .map((link) => document.querySelector(link.getAttribute('href')))
        .filter(Boolean);

    if (observedSections.length && 'IntersectionObserver' in window) {
        const spy = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                if (!entry.isIntersecting) continue;
                for (const link of sectionLinks) {
                    link.classList.toggle('is-active', link.getAttribute('href') === '#' + entry.target.id);
                }
            }
        }, { rootMargin: '-45% 0px -50% 0px' });
        for (const section of observedSections) spy.observe(section);
    }

    // --- Hanya satu jawaban FAQ terbuka pada satu waktu ---
    const accordion = document.querySelector('[data-accordion]');
    if (accordion) {
        const items = Array.from(accordion.querySelectorAll('.faq-item'));
        for (const item of items) {
            item.addEventListener('toggle', () => {
                if (!item.open) return;
                for (const other of items) {
                    if (other !== item) other.open = false;
                }
            });
        }
    }
})();
