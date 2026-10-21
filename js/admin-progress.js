/**
 * Panel capaian harian di dashboard: angka ringkasan akses dan pendaftar, serta
 * grafik rantai per unit dan jalur. Setiap grafik menunjukkan kursi terisi
 * kumulatif per hari; puncak sumbunya sama dengan kuota jalur yang dibuka.
 * Digambar sebagai SVG tanpa pustaka grafik.
 */
(function () {
    'use strict';

    const panel = document.querySelector('[data-progress-chart]');
    const dataNode = document.getElementById('progress-data');
    if (!panel || !dataNode) return;

    let data = {};
    try {
        data = JSON.parse(dataNode.textContent) || {};
    } catch (error) {
        data = {};
    }
    const days = data.days || [];
    const units = data.units || [];
    if (!days.length) return;

    const tooltip = panel.querySelector('[data-progress-tooltip]');
    const buttons = panel.querySelectorAll('[data-range]');
    const charts = panel.querySelectorAll('[data-chain-canvas]');
    const combinedCharts = panel.querySelectorAll('[data-combined-canvas]');
    const NS = 'http://www.w3.org/2000/svg';
    const PAD = { top: 14, right: 14, bottom: 40, left: 30 };
    const DAY_NAMES = { Min: 'Minggu', Sen: 'Senin', Sel: 'Selasa', Rab: 'Rabu', Kam: 'Kamis', Jum: 'Jumat', Sab: 'Sabtu' };
    let range = 14;

    const el = (name, attrs, parent) => {
        const node = document.createElementNS(NS, name);
        Object.entries(attrs || {}).forEach(([key, value]) => node.setAttribute(key, value));
        if (parent) parent.appendChild(node);
        return node;
    };

    // Langkah garis bantu bulat (1, 2, 5, 10, ...) dengan paling banyak 5 garis.
    const niceStep = (value) => {
        const magnitude = Math.pow(10, Math.floor(Math.log10(Math.max(value, 1))));
        return [1, 2, 5, 10].map((m) => m * magnitude).find((s) => value / s <= 5);
    };

    const formatNumber = (value) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(value);

    const carousel = panel.querySelector('[data-chain-carousel]');
    const carouselExpanded = () => Boolean(carousel) && (document.fullscreenElement === carousel || carousel.classList.contains('is-expanded'));
    // Tinggi grafik saat kartu unit layar penuh; layar pendek (HP mendatar) memakai ruang tersisa.
    const fullChartHeight = (min, max, overhead) => {
        const room = window.innerHeight - (window.innerHeight < 520 ? 150 : overhead);
        return Math.max(min, Math.min(max, room));
    };
    const isExpanded = () => document.fullscreenElement === panel || panel.classList.contains('is-expanded') || carouselExpanded();

    const updateStats = (visible) => {
        const visits = visible.reduce((sum, day) => sum + day.visits, 0);
        const registrants = visible.reduce((sum, day) => sum + day.registrants, 0);
        const busiest = visible.reduce((best, day) => (day.visits + day.registrants > best.visits + best.registrants ? day : best), visible[0]);
        panel.querySelector('[data-stat="visits"]').textContent = formatNumber(visits);
        panel.querySelector('[data-stat="registrants"]').textContent = formatNumber(registrants);
        panel.querySelector('[data-stat="conversion"]').textContent = visits > 0 ? `${Math.round(registrants / visits * 100)}%` : '–';
        panel.querySelector('[data-stat="busiest"]').textContent = busiest.visits + busiest.registrants > 0 ? `${busiest.day}, ${busiest.label}` : '–';
    };

    // Keterangan ditempatkan di wadah yang sedang tampil (panel, atau kartu unit saat layar penuh).
    const tooltipHost = () => {
        const host = carouselExpanded() ? carousel : panel;
        if (tooltip.parentElement !== host) host.appendChild(tooltip);
        return host;
    };

    const hideTooltip = () => {
        tooltip.hidden = true;
        panel.querySelectorAll('.chain-hover.is-active').forEach((node) => node.classList.remove('is-active'));
    };

    const showTooltip = (container, band, centerX, day, point, route) => {
        panel.querySelectorAll('.chain-hover.is-active').forEach((node) => node.classList.remove('is-active'));
        band.classList.add('is-active');
        tooltip.innerHTML = '';
        const title = document.createElement('strong');
        title.textContent = `${DAY_NAMES[day.day]}, ${day.full}`;
        tooltip.appendChild(title);
        [
            [`Jalur ${route.label.toLowerCase()}`, `${point.filled} / ${route.quota} kursi`],
            ['Pendaftar baru hari itu', String(point.added)],
            ['Sisa kursi', String(Math.max(0, route.quota - point.filled))],
        ].forEach(([label, value]) => {
            const row = document.createElement('span');
            row.append(`${label}: `);
            const number = document.createElement('b');
            number.textContent = value;
            row.appendChild(number);
            tooltip.appendChild(row);
        });
        tooltip.hidden = false;
        const box = container.getBoundingClientRect();
        const host = tooltipHost();
        const panelBox = host.getBoundingClientRect();
        const left = box.left - panelBox.left + centerX;
        const half = tooltip.offsetWidth / 2;
        tooltip.style.left = `${Math.min(Math.max(left, half + 8), host.clientWidth - half - 8)}px`;
        tooltip.style.top = `${box.top - panelBox.top + host.scrollTop - tooltip.offsetHeight - 6}px`;
    };

    const renderChain = (container) => {
        const route = (units[Number(container.dataset.unit)] || {}).routes?.[Number(container.dataset.route)];
        if (!route) return;
        container.innerHTML = '';

        const visibleDays = days.slice(-range);
        const points = route.points.slice(-range);
        const width = Math.max(container.clientWidth, 240);
        const height = carouselExpanded() ? fullChartHeight(window.innerHeight < 520 ? 140 : 220, 420, 330) : (isExpanded() ? 240 : 180);
        const plotWidth = width - PAD.left - PAD.right;
        const plotHeight = height - PAD.top - PAD.bottom;
        // Puncak sumbu = kuota; bila terisi melebihi kuota, sumbu ikut naik agar titik tidak terpotong.
        const top = Math.max(route.quota, ...points.map((point) => point.filled), 1);
        const y = (value) => PAD.top + plotHeight - (value / top) * plotHeight;
        const slot = plotWidth / points.length;
        const x = (index) => PAD.left + slot * index + slot / 2;
        const labelEvery = Math.ceil(44 / slot);

        const svg = el('svg', { width, height, viewBox: `0 0 ${width} ${height}`, 'aria-hidden': 'true' }, container);

        const step = niceStep(top);
        for (let value = 0; value < top; value += step) {
            el('line', { x1: PAD.left, x2: width - PAD.right, y1: y(value), y2: y(value), class: value === 0 ? 'progress-axis' : 'progress-grid' }, svg);
            el('text', { x: PAD.left - 7, y: y(value) + 4, 'text-anchor': 'end', class: 'progress-tick' }, svg).textContent = formatNumber(value);
        }

        // Garis batas kuota di puncak grafik.
        el('line', { x1: PAD.left, x2: width - PAD.right, y1: y(route.quota), y2: y(route.quota), class: 'chain-quota' }, svg);
        el('text', { x: PAD.left - 7, y: y(route.quota) + 4, 'text-anchor': 'end', class: 'progress-tick chain-quota-tick' }, svg).textContent = formatNumber(route.quota);
        el('text', { x: PAD.left + 4, y: y(route.quota) - 5, 'text-anchor': 'start', class: 'chain-quota-label' }, svg).textContent = `Kuota ${route.quota}`;

        const bandLayer = el('g', {}, svg);
        const lineLayer = el('g', { class: `chain-series is-${route.key}` }, svg);
        const hitLayer = el('g', {}, svg);

        const coords = points.map((point, index) => [x(index), y(point.filled)]);
        const baseline = y(0);
        el('path', { d: `M${coords[0][0]},${baseline}L${coords.map((c) => c.join(',')).join('L')}L${coords[coords.length - 1][0]},${baseline}Z`, class: 'chain-area' }, lineLayer);
        el('path', { d: `M${coords.map((c) => c.join(',')).join('L')}`, class: 'chain-line' }, lineLayer);

        const smallDot = slot >= 14 ? 3 : 2;
        points.forEach((point, index) => {
            const day = visibleDays[index];
            const band = el('rect', { x: PAD.left + slot * index, y: PAD.top, width: slot, height: plotHeight, class: 'chain-hover' }, bandLayer);
            // Mata rantai: titik di setiap hari, lebih besar pada hari ada pendaftar baru.
            el('circle', { cx: coords[index][0], cy: coords[index][1], r: point.added > 0 ? 5 : smallDot, class: point.added > 0 ? 'chain-dot is-new' : 'chain-dot' }, lineLayer);

            const isToday = index === points.length - 1;
            if (isToday || (index % labelEvery === 0 && points.length - 1 - index >= labelEvery)) {
                el('text', { x: x(index), y: height - PAD.bottom + 17, 'text-anchor': 'middle', class: `progress-day${isToday ? ' is-today' : ''}${day.day === 'Min' ? ' is-sunday' : ''}` }, svg).textContent = day.day;
                el('text', { x: x(index), y: height - PAD.bottom + 31, 'text-anchor': 'middle', class: `progress-date${isToday ? ' is-today' : ''}` }, svg).textContent = isToday ? 'Hari ini' : day.label;
            }

            const hit = el('rect', { x: PAD.left + slot * index, y: 0, width: slot, height, class: 'progress-hit', tabindex: 0, 'aria-label': `${day.full}: ${point.filled} dari ${route.quota} kursi, ${point.added} pendaftar baru` }, hitLayer);
            const show = () => showTooltip(container, band, x(index), day, point, route);
            hit.addEventListener('mouseenter', show);
            hit.addEventListener('focus', show);
            hit.addEventListener('click', show);
            hit.addEventListener('mouseleave', hideTooltip);
            hit.addEventListener('blur', hideTooltip);
        });
    };

    // Keterangan batang gabungan saat kursor di atas satu unit.
    const showUnitTooltip = (container, band, centerX, bar) => {
        panel.querySelectorAll('.chain-hover.is-active').forEach((node) => node.classList.remove('is-active'));
        band.classList.add('is-active');
        tooltip.innerHTML = '';
        const title = document.createElement('strong');
        title.textContent = bar.name;
        tooltip.appendChild(title);
        [
            ['Internal', `${bar.internal.filled} / ${bar.internal.quota} kursi`],
            ['Eksternal', `${bar.eksternal.filled} / ${bar.eksternal.quota} kursi`],
            ['Total terisi', `${bar.filled} / ${bar.quota} kursi`],
        ].forEach(([label, value]) => {
            const row = document.createElement('span');
            row.append(`${label}: `);
            const number = document.createElement('b');
            number.textContent = value;
            row.appendChild(number);
            tooltip.appendChild(row);
        });
        tooltip.hidden = false;
        const box = container.getBoundingClientRect();
        const host = tooltipHost();
        const panelBox = host.getBoundingClientRect();
        const half = tooltip.offsetWidth / 2;
        const left = box.left - panelBox.left + centerX;
        tooltip.style.left = `${Math.min(Math.max(left, half + 8), host.clientWidth - half - 8)}px`;
        tooltip.style.top = `${box.top - panelBox.top + host.scrollTop - tooltip.offsetHeight - 6}px`;
    };

    // Gabungan: kelompok batang internal/eksternal per unit (KB sampai SMP),
    // bayangan batang setinggi kuota, dan rantai yang menyambung total terisi tiap unit.
    const renderCombined = (container) => {
        const bars = (units[Number(container.dataset.unit)] || {}).bars || [];
        container.innerHTML = '';
        if (!bars.length) return;

        const pad = { top: 26, right: 14, bottom: 44, left: 40 };
        const width = Math.max(container.clientWidth, 280);
        const height = carouselExpanded() ? fullChartHeight(window.innerHeight < 520 ? 160 : 260, 2000, 300) : (isExpanded() ? 320 : 260);
        const plotWidth = width - pad.left - pad.right;
        const plotHeight = height - pad.top - pad.bottom;
        const top = Math.max(1, ...bars.map((bar) => Math.max(bar.quota, bar.filled)));
        const step = niceStep(top);
        const max = Math.ceil(top / step) * step;
        const y = (value) => pad.top + plotHeight - (value / max) * plotHeight;
        const slot = plotWidth / bars.length;
        const barWidth = Math.max(8, Math.min(30, (slot - 24) / 2));

        const svg = el('svg', { width, height, viewBox: `0 0 ${width} ${height}`, 'aria-hidden': 'true' }, container);
        for (let value = 0; value <= max; value += step) {
            el('line', { x1: pad.left, x2: width - pad.right, y1: y(value), y2: y(value), class: value === 0 ? 'progress-axis' : 'progress-grid' }, svg);
            el('text', { x: pad.left - 8, y: y(value) + 4, 'text-anchor': 'end', class: 'progress-tick' }, svg).textContent = formatNumber(value);
        }

        const bandLayer = el('g', {}, svg);
        const barLayer = el('g', {}, svg);
        const chainLayer = el('g', { class: 'combined-chain' }, svg);
        const hitLayer = el('g', {}, svg);
        const baseline = y(0);
        const roundTop = (x, yTop, w) => {
            const h = baseline - yTop;
            const r = Math.min(5, w / 2, h);
            return `M${x},${baseline}V${yTop + r}Q${x},${yTop} ${x + r},${yTop}H${x + w - r}Q${x + w},${yTop} ${x + w},${yTop + r}V${baseline}Z`;
        };

        const chainPoints = [];
        bars.forEach((bar, index) => {
            const center = pad.left + slot * index + slot / 2;
            const band = el('rect', { x: pad.left + slot * index, y: pad.top, width: slot, height: plotHeight, class: 'chain-hover' }, bandLayer);
            [['internal', center - barWidth - 2], ['eksternal', center + 2]].forEach(([key, x]) => {
                const route = bar[key];
                if (route.quota > 0) {
                    el('path', { d: roundTop(x, y(route.quota), barWidth), class: 'combined-quota' }, barLayer);
                }
                if (route.filled > 0) {
                    el('path', { d: roundTop(x, y(route.filled), barWidth), class: `combined-bar is-${key}` }, barLayer);
                }
            });
            el('text', { x: center, y: height - pad.bottom + 18, 'text-anchor': 'middle', class: 'progress-day' }, svg).textContent = bar.label;
            el('text', { x: center, y: height - pad.bottom + 32, 'text-anchor': 'middle', class: 'progress-date' }, svg).textContent = `${bar.filled}/${bar.quota}`;
            chainPoints.push([center, y(bar.filled)]);

            const hit = el('rect', { x: pad.left + slot * index, y: 0, width: slot, height, class: 'progress-hit', tabindex: 0, 'aria-label': `${bar.name}: ${bar.filled} dari ${bar.quota} kursi terisi` }, hitLayer);
            const show = () => showUnitTooltip(container, band, center, bar);
            hit.addEventListener('mouseenter', show);
            hit.addEventListener('focus', show);
            hit.addEventListener('click', show);
            hit.addEventListener('mouseleave', hideTooltip);
            hit.addEventListener('blur', hideTooltip);
        });

        // Rantai: garis dan mata rantai dari KB sampai SMP.
        el('path', { d: `M${chainPoints.map((point) => point.join(',')).join('L')}`, class: 'combined-chain__line' }, chainLayer);
        chainPoints.forEach(([cx, cy], index) => {
            el('circle', { cx, cy, r: 6, class: 'combined-chain__link' }, chainLayer);
            el('text', { x: cx, y: cy - 11, 'text-anchor': 'middle', class: 'combined-chain__value' }, chainLayer).textContent = String(bars[index].filled);
        });
    };

    const render = () => {
        updateStats(days.slice(-range));
        charts.forEach(renderChain);
        combinedCharts.forEach(renderCombined);
    };

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            range = Number(button.dataset.range);
            buttons.forEach((item) => item.setAttribute('aria-pressed', item === button ? 'true' : 'false'));
            hideTooltip();
            render();
        });
    });

    // --- Tampilan penuh ---
    const fullButton = panel.querySelector('[data-progress-fullscreen]');

    const syncFullButton = () => {
        if (!fullButton) return;
        const expanded = isExpanded();
        fullButton.setAttribute('aria-pressed', expanded ? 'true' : 'false');
        fullButton.setAttribute('aria-label', expanded ? 'Keluar dari tampilan penuh' : 'Tampilan penuh');
        fullButton.title = expanded ? 'Keluar dari tampilan penuh (Esc)' : 'Tampilan penuh';
        fullButton.textContent = expanded ? '✕' : '⛶';
        hideTooltip();
        requestAnimationFrame(render);
        // Gambar ulang setelah animasi masuk/keluar layar penuh selesai dan ukuran layar sudah final.
        setTimeout(render, 350);
    };

    const setFallback = (on) => {
        panel.classList.toggle('is-expanded', on);
        document.documentElement.classList.toggle('has-expanded-map', on);
        syncFullButton();
    };

    if (fullButton) {
        fullButton.addEventListener('click', () => {
            if (document.fullscreenElement === panel) {
                document.exitFullscreen();
            } else if (panel.classList.contains('is-expanded')) {
                setFallback(false);
            } else if (panel.requestFullscreen && document.fullscreenEnabled) {
                panel.requestFullscreen().catch(() => setFallback(true));
            } else {
                setFallback(true);
            }
        });
    }
    document.addEventListener('fullscreenchange', syncFullButton);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && panel.classList.contains('is-expanded')) setFallback(false);
    });

    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(render, 150);
    });
    panel.addEventListener('mouseleave', hideTooltip);

    // --- Geser unit ke kanan/kiri (satu kartu per tampilan) ---
    if (carousel) {
        const track = carousel.querySelector('[data-carousel-track]');
        const slides = Array.from(track.children);
        const dots = Array.from(carousel.querySelectorAll('[data-carousel-dot]'));
        const indexLabel = carousel.querySelector('[data-carousel-index]');
        const nameLabel = carousel.querySelector('[data-carousel-name]');
        const prev = carousel.querySelector('[data-carousel-prev]');
        const next = carousel.querySelector('[data-carousel-next]');
        let active = 0;
        // Satu kartu saja (admin unit): tombol geser dan penanda unit tidak diperlukan.
        carousel.classList.toggle('is-single', slides.length < 2);

        // Tinggi area geser mengikuti kartu yang sedang tampil (kecuali saat layar penuh).
        const fitHeight = () => {
            track.style.height = carouselExpanded() ? '' : `${slides[active].offsetHeight}px`;
        };
        const sync = (index) => {
            active = Math.max(0, Math.min(slides.length - 1, index));
            fitHeight();
            indexLabel.textContent = String(active + 1);
            const title = slides[active].querySelector('.quota-progress__head strong');
            nameLabel.textContent = title ? title.textContent : '';
            dots.forEach((dot, dotIndex) => dot.setAttribute('aria-selected', dotIndex === active ? 'true' : 'false'));
            prev.disabled = active === 0;
            next.disabled = active === slides.length - 1;
        };
        const goTo = (index, smooth) => {
            const target = Math.max(0, Math.min(slides.length - 1, index));
            hideTooltip();
            track.scrollTo({ left: slides[target].offsetLeft - track.offsetLeft, behavior: smooth === false ? 'auto' : 'smooth' });
            sync(target);
        };

        prev.addEventListener('click', () => goTo(active - 1));
        next.addEventListener('click', () => goTo(active + 1));
        dots.forEach((dot) => dot.addEventListener('click', () => goTo(Number(dot.dataset.carouselDot))));
        let scrollTimer;
        track.addEventListener('scroll', () => {
            clearTimeout(scrollTimer);
            scrollTimer = setTimeout(() => sync(Math.round(track.scrollLeft / Math.max(1, track.clientWidth))), 80);
        }, { passive: true });
        track.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowRight') { event.preventDefault(); goTo(active + 1); }
            if (event.key === 'ArrowLeft') { event.preventDefault(); goTo(active - 1); }
        });
        document.addEventListener('keydown', (event) => {
            if (!carouselExpanded() || event.target.closest('input, textarea, select')) return;
            if (event.key === 'ArrowRight') goTo(active + 1);
            if (event.key === 'ArrowLeft') goTo(active - 1);
        });

        // Geser dengan menahan klik mouse (sentuhan jari sudah didukung bawaan).
        let drag = null;
        track.addEventListener('pointerdown', (event) => {
            if (event.pointerType !== 'mouse' || event.button !== 0) return;
            drag = { x: event.clientX, left: track.scrollLeft, moved: false };
        });
        window.addEventListener('pointermove', (event) => {
            if (!drag) return;
            const delta = event.clientX - drag.x;
            if (Math.abs(delta) > 6) {
                drag.moved = true;
                track.classList.add('is-dragging');
                track.scrollLeft = drag.left - delta;
            }
        });
        window.addEventListener('pointerup', (event) => {
            if (!drag) return;
            const delta = event.clientX - drag.x;
            const moved = drag.moved;
            drag = null;
            track.classList.remove('is-dragging');
            if (moved) goTo(active + (delta < -40 ? 1 : delta > 40 ? -1 : 0));
        });

        // Tampilan penuh khusus kartu unit.
        const fullCarousel = carousel.querySelector('[data-carousel-fullscreen]');
        const syncCarouselFull = () => {
            const expanded = carouselExpanded();
            fullCarousel.setAttribute('aria-pressed', expanded ? 'true' : 'false');
            fullCarousel.setAttribute('aria-label', expanded ? 'Keluar dari tampilan penuh' : 'Tampilan penuh unit');
            fullCarousel.title = expanded ? 'Keluar dari tampilan penuh (Esc)' : 'Tampilan penuh unit';
            fullCarousel.textContent = expanded ? '✕' : '⛶';
            hideTooltip();
            const keep = active;
            const refresh = () => { render(); goTo(keep, false); fitHeight(); };
            requestAnimationFrame(refresh);
            setTimeout(refresh, 350);
        };
        const setCarouselFallback = (on) => {
            carousel.classList.toggle('is-expanded', on);
            document.documentElement.classList.toggle('has-expanded-map', on);
            syncCarouselFull();
        };
        fullCarousel.addEventListener('click', () => {
            if (document.fullscreenElement === carousel) {
                document.exitFullscreen();
            } else if (carousel.classList.contains('is-expanded')) {
                setCarouselFallback(false);
            } else if (carousel.requestFullscreen && document.fullscreenEnabled) {
                carousel.requestFullscreen().catch(() => setCarouselFallback(true));
            } else {
                setCarouselFallback(true);
            }
        });
        document.addEventListener('fullscreenchange', () => {
            if (document.fullscreenElement === carousel || !document.fullscreenElement) syncCarouselFull();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && carousel.classList.contains('is-expanded')) setCarouselFallback(false);
        });
        window.addEventListener('resize', () => setTimeout(() => goTo(active, false), 200));

        sync(0);
        // Grafik digambar ulang saat rentang hari atau ukuran layar berubah.
        buttons.forEach((button) => button.addEventListener('click', () => requestAnimationFrame(fitHeight)));
        window.addEventListener('resize', () => setTimeout(fitHeight, 250));
        requestAnimationFrame(fitHeight);
    }

    render();
    // Gambar ulang setelah huruf dan tata letak selesai agar lebar grafik pas dengan wadahnya.
    window.addEventListener('load', render);
})();
