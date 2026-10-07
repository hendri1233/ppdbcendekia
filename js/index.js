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

    // --- Statistik: tooltip bersama, grafik batang unit, dan grafik kunjungan ---
    const tooltip = document.querySelector('[data-stats-tooltip]');
    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    const formatDate = (iso, withYear) => {
        const [year, month, day] = iso.split('-').map(Number);
        return day + ' ' + monthNames[month - 1] + (withYear ? ' ' + year : '');
    };

    const showTooltip = (title, rows, x, y) => {
        if (!tooltip) return;
        tooltip.replaceChildren();
        const heading = document.createElement('div');
        heading.className = 'stats-tooltip__title';
        heading.textContent = title;
        tooltip.append(heading);
        for (const row of rows) {
            const line = document.createElement('div');
            line.className = 'stats-tooltip__row';
            const key = document.createElement('i');
            key.className = 'stats-tooltip__key' + (row.muted ? ' is-muted' : '');
            const value = document.createElement('strong');
            value.textContent = row.value;
            const label = document.createElement('span');
            label.textContent = row.label;
            line.append(key, value, label);
            tooltip.append(line);
        }
        tooltip.hidden = false;
        const box = tooltip.getBoundingClientRect();
        const left = Math.min(Math.max(8, x + 14), window.innerWidth - box.width - 8);
        const top = y - box.height - 14 < 8 ? y + 18 : y - box.height - 14;
        tooltip.style.left = left + 'px';
        tooltip.style.top = top + 'px';
    };
    const hideTooltip = () => {
        if (tooltip) tooltip.hidden = true;
    };

    for (const row of document.querySelectorAll('.unit-bars__row')) {
        const rows = () => [
            { value: row.dataset.total, label: 'total pendaftar' },
            { value: row.dataset.external, label: 'jalur umum', muted: true },
            { value: row.dataset.internal, label: 'internal SDM', muted: true },
        ];
        row.addEventListener('pointermove', (event) => showTooltip(row.dataset.name, rows(), event.clientX, event.clientY));
        row.addEventListener('pointerleave', hideTooltip);
        row.addEventListener('focus', () => {
            const box = row.getBoundingClientRect();
            showTooltip(row.dataset.name, rows(), box.left + box.width / 2, box.top);
        });
        row.addEventListener('blur', hideTooltip);
    }

    const chartHost = document.querySelector('[data-visit-chart]');
    const visitSource = document.getElementById('visit-data');
    if (chartHost && visitSource) {
        const data = JSON.parse(visitSource.textContent || '[]');
        const svgNs = 'http://www.w3.org/2000/svg';
        const make = (name, attrs) => {
            const node = document.createElementNS(svgNs, name);
            for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
            return node;
        };
        // Batas atas sumbu Y dibulatkan agar empat garis bantu bernilai rapi.
        const niceMax = (value) => {
            if (value <= 4) return 4;
            const magnitude = Math.pow(10, Math.floor(Math.log10(value)));
            const step = [0.25, 0.5, 1, 2, 2.5, 5, 10].find((s) => s * magnitude * 4 >= value) * magnitude;
            return step * 4;
        };

        let activeIndex = -1;

        const render = () => {
            const width = chartHost.clientWidth;
            const height = chartHost.clientHeight;
            if (!width || !data.length) return;
            const pad = { top: 12, right: 12, bottom: 28, left: 34 };
            const plotW = width - pad.left - pad.right;
            const plotH = height - pad.top - pad.bottom;
            const yMax = niceMax(Math.max(...data.map((d) => d.visitors)));
            const x = (i) => pad.left + (data.length === 1 ? plotW / 2 : (i / (data.length - 1)) * plotW);
            const y = (v) => pad.top + plotH - (v / yMax) * plotH;
            const baseline = y(0);

            const svg = make('svg', { viewBox: '0 0 ' + width + ' ' + height, tabindex: '0', 'aria-hidden': 'true' });

            for (let t = 0; t <= 4; t += 1) {
                const value = (yMax / 4) * t;
                svg.append(make('line', { class: 'grid-line', x1: pad.left, x2: width - pad.right, y1: y(value), y2: y(value) }));
                const label = make('text', { class: 'axis-text', x: pad.left - 8, y: y(value) + 4, 'text-anchor': 'end' });
                label.textContent = Number.isInteger(value) ? value : value.toFixed(1);
                svg.append(label);
            }

            const tickEvery = width < 480 ? 10 : 7;
            data.forEach((d, i) => {
                const fromEnd = data.length - 1 - i;
                if (fromEnd % tickEvery !== 0) return;
                const label = make('text', { class: 'axis-text', x: x(i), y: height - 8, 'text-anchor': fromEnd === 0 ? 'end' : 'middle' });
                label.textContent = fromEnd === 0 ? 'Hari ini' : formatDate(d.date);
                svg.append(label);
            });

            const points = data.map((d, i) => [x(i), y(d.visitors)]);
            const linePath = points.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ',' + p[1].toFixed(1)).join('');
            const first = points[0][0];
            const last = points[points.length - 1][0];
            svg.append(make('path', { class: 'area', d: linePath + 'L' + last + ',' + baseline + 'L' + first + ',' + baseline + 'Z' }));
            svg.append(make('path', { class: 'line', d: linePath }));

            const crosshair = make('line', { class: 'crosshair', y1: pad.top, y2: baseline, visibility: 'hidden' });
            const marker = make('circle', { class: 'marker', r: 5, visibility: 'hidden' });
            svg.append(crosshair, marker);
            chartHost.replaceChildren(svg);

            const select = (index, clientX, clientY) => {
                activeIndex = index;
                const d = data[index];
                crosshair.setAttribute('x1', x(index));
                crosshair.setAttribute('x2', x(index));
                marker.setAttribute('cx', x(index));
                marker.setAttribute('cy', y(d.visitors));
                crosshair.setAttribute('visibility', 'visible');
                marker.setAttribute('visibility', 'visible');
                const box = svg.getBoundingClientRect();
                showTooltip(formatDate(d.date, true), [
                    { value: d.visitors, label: 'pengunjung' },
                    { value: d.views, label: 'halaman dilihat', muted: true },
                ], clientX ?? box.left + x(index), clientY ?? box.top + y(d.visitors));
            };
            const clear = () => {
                crosshair.setAttribute('visibility', 'hidden');
                marker.setAttribute('visibility', 'hidden');
                hideTooltip();
            };

            svg.addEventListener('pointermove', (event) => {
                const box = svg.getBoundingClientRect();
                const ratio = (event.clientX - box.left - pad.left) / plotW;
                select(Math.round(Math.min(1, Math.max(0, ratio)) * (data.length - 1)), event.clientX, event.clientY);
            });
            svg.addEventListener('pointerleave', clear);
            svg.addEventListener('focus', () => select(activeIndex >= 0 ? activeIndex : data.length - 1));
            svg.addEventListener('blur', clear);
            svg.addEventListener('keydown', (event) => {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
                event.preventDefault();
                select(Math.min(data.length - 1, Math.max(0, activeIndex + (event.key === 'ArrowRight' ? 1 : -1))));
            });
        };

        render();
        if ('ResizeObserver' in window) {
            let lastWidth = chartHost.clientWidth;
            new ResizeObserver(() => {
                if (chartHost.clientWidth === lastWidth) return;
                lastWidth = chartHost.clientWidth;
                hideTooltip();
                render();
            }).observe(chartHost);
        }
        window.addEventListener('scroll', hideTooltip, { passive: true });
    }
})();
