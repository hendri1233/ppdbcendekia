/**
 * Halaman PPDB: grafik pengunjung harian dengan tooltip dan navigasi keyboard.
 * Menu, tema, dan akordeon ditangani js/site.js.
 */
(function () {
    'use strict';

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
