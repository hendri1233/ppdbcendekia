/* Dasbor admin: angka bergerak, sparkline, grafik tren, dan donut komposisi status.
   SVG murni tanpa pustaka; warna diambil dari variabel CSS agar ikut tema terang/gelap. */
(function () {
    'use strict';

    var SVG = 'http://www.w3.org/2000/svg';
    var reduceMotion = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    var dataNode = document.getElementById('dashboard-data');
    var data = { days: [], mix: [] };
    try { if (dataNode) { data = JSON.parse(dataNode.textContent); } } catch (e) {}

    function el(name, attrs, parent) {
        var node = document.createElementNS(SVG, name);
        Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
        if (parent) { parent.appendChild(node); }
        return node;
    }

    function formatNumber(value) {
        return Math.round(value).toLocaleString('id-ID');
    }

    /* Angka KPI menghitung naik saat kartu terlihat. */
    function countUp(node) {
        var target = parseInt(node.getAttribute('data-count'), 10) || 0;
        if (reduceMotion || target === 0) { node.textContent = formatNumber(target); return; }
        var start = null;
        var duration = 900;
        function step(time) {
            if (start === null) { start = time; }
            var progress = Math.min(1, (time - start) / duration);
            node.textContent = formatNumber(target * (1 - Math.pow(1 - progress, 3)));
            if (progress < 1) { requestAnimationFrame(step); }
        }
        node.textContent = '0';
        requestAnimationFrame(step);
    }

    var counters = document.querySelectorAll('[data-count]');
    if ('IntersectionObserver' in window) {
        var counterObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) { countUp(entry.target); counterObserver.unobserve(entry.target); }
            });
        }, { threshold: 0.4 });
        counters.forEach(function (node) { counterObserver.observe(node); });
    } else {
        counters.forEach(countUp);
    }

    /* Garis halus melalui titik-titik (kurva monotone sederhana). */
    function smoothPath(points) {
        if (!points.length) { return ''; }
        var d = 'M' + points[0][0] + ',' + points[0][1];
        for (var i = 1; i < points.length; i++) {
            var p0 = points[i - 1];
            var p1 = points[i];
            var mid = (p0[0] + p1[0]) / 2;
            d += ' C' + mid + ',' + p0[1] + ' ' + mid + ',' + p1[1] + ' ' + p1[0] + ',' + p1[1];
        }
        return d;
    }

    /* Sparkline kecil di kartu KPI. */
    document.querySelectorAll('svg[data-spark]').forEach(function (svg) {
        var values = svg.getAttribute('data-spark').split(',').map(Number);
        if (values.length < 2) { return; }
        var max = Math.max.apply(null, values.concat([1]));
        var points = values.map(function (value, index) {
            return [index / (values.length - 1) * 120, 32 - value / max * 28];
        });
        var line = smoothPath(points);
        el('path', { d: line + ' L120,36 L0,36 Z', class: 'spark-area' }, svg);
        el('path', { d: line, class: 'spark-line' }, svg);
    });

    /* Grafik tren pendaftar baru dan akses tautan dengan tooltip garis bantu. */
    var trend = document.querySelector('[data-trend]');
    var trendChart = trend && trend.querySelector('[data-trend-chart]');
    var trendRange = 14;

    function niceMax(value) {
        if (value <= 4) { return 4; }
        var power = Math.pow(10, Math.floor(Math.log10(value)));
        var steps = [1, 2, 2.5, 5, 10];
        for (var i = 0; i < steps.length; i++) {
            if (steps[i] * power >= value) { return steps[i] * power; }
        }
        return 10 * power;
    }

    function renderTrend() {
        if (!trendChart || !data.days.length) { return; }
        var days = data.days.slice(-trendRange);
        var width = Math.max(280, trendChart.clientWidth);
        var height = width < 520 ? 200 : 250;
        var pad = { top: 14, right: 12, bottom: 28, left: 34 };
        var innerW = width - pad.left - pad.right;
        var innerH = height - pad.top - pad.bottom;
        var max = niceMax(Math.max.apply(null, days.map(function (day) { return Math.max(day.visits, day.registrants); }).concat([1])));
        var x = function (index) { return pad.left + (days.length === 1 ? innerW / 2 : index / (days.length - 1) * innerW); };
        var y = function (value) { return pad.top + innerH - value / max * innerH; };

        trendChart.textContent = '';
        var svg = el('svg', { viewBox: '0 0 ' + width + ' ' + height, width: '100%', height: height, class: 'trend-svg' }, trendChart);
        var defs = el('defs', {}, svg);
        [['visits', 'trend-grad-visits'], ['registrants', 'trend-grad-registrants']].forEach(function (pair) {
            var gradient = el('linearGradient', { id: pair[1], x1: 0, y1: 0, x2: 0, y2: 1 }, defs);
            el('stop', { offset: '0%', class: 'stop-top is-' + pair[0] }, gradient);
            el('stop', { offset: '100%', class: 'stop-bottom is-' + pair[0] }, gradient);
        });

        for (var tick = 0; tick <= 4; tick++) {
            var value = max / 4 * tick;
            el('line', { x1: pad.left, x2: width - pad.right, y1: y(value), y2: y(value), class: 'trend-grid' }, svg);
            el('text', { x: pad.left - 8, y: y(value) + 4, class: 'trend-axis', 'text-anchor': 'end' }, svg).textContent = formatNumber(value);
        }
        var labelEvery = Math.ceil(days.length / (width < 520 ? 5 : 8));
        days.forEach(function (day, index) {
            if (index % labelEvery === 0 || index === days.length - 1) {
                el('text', { x: x(index), y: height - 8, class: 'trend-axis', 'text-anchor': 'middle' }, svg).textContent = day.label;
            }
        });

        ['visits', 'registrants'].forEach(function (series) {
            var points = days.map(function (day, index) { return [x(index), y(day[series])]; });
            var line = smoothPath(points);
            el('path', { d: line + ' L' + x(days.length - 1) + ',' + y(0) + ' L' + x(0) + ',' + y(0) + ' Z', fill: 'url(#trend-grad-' + series + ')', class: 'trend-area' }, svg);
            var path = el('path', { d: line, class: 'trend-line is-' + series }, svg);
            if (!reduceMotion && path.getTotalLength) {
                var length = path.getTotalLength();
                path.style.strokeDasharray = length;
                path.style.strokeDashoffset = length;
                requestAnimationFrame(function () { path.style.strokeDashoffset = 0; });
            }
        });

        var guide = el('line', { y1: pad.top, y2: pad.top + innerH, class: 'trend-guide', opacity: 0 }, svg);
        var dots = ['visits', 'registrants'].map(function (series) {
            return el('circle', { r: 4.5, class: 'trend-dot is-' + series, opacity: 0 }, svg);
        });
        var tooltip = document.createElement('div');
        tooltip.className = 'trend-tooltip';
        tooltip.hidden = true;
        trendChart.appendChild(tooltip);

        function show(index) {
            var day = days[index];
            var px = x(index);
            guide.setAttribute('x1', px);
            guide.setAttribute('x2', px);
            guide.setAttribute('opacity', 1);
            dots[0].setAttribute('cx', px); dots[0].setAttribute('cy', y(day.visits)); dots[0].setAttribute('opacity', 1);
            dots[1].setAttribute('cx', px); dots[1].setAttribute('cy', y(day.registrants)); dots[1].setAttribute('opacity', 1);
            tooltip.innerHTML = '<strong></strong><span><i class="trend-key is-registrants"></i>Pendaftar baru <b></b></span><span><i class="trend-key is-visits"></i>Akses tautan <b></b></span>';
            tooltip.querySelector('strong').textContent = day.full;
            tooltip.querySelectorAll('b')[0].textContent = formatNumber(day.registrants);
            tooltip.querySelectorAll('b')[1].textContent = formatNumber(day.visits);
            tooltip.hidden = false;
            var left = px / width * trendChart.clientWidth;
            tooltip.style.left = Math.min(trendChart.clientWidth - tooltip.offsetWidth - 4, Math.max(4, left - tooltip.offsetWidth / 2)) + 'px';
        }
        function hide() {
            guide.setAttribute('opacity', 0);
            dots.forEach(function (dot) { dot.setAttribute('opacity', 0); });
            tooltip.hidden = true;
        }
        function pointerIndex(event) {
            var rect = svg.getBoundingClientRect();
            var px = (event.clientX - rect.left) / rect.width * width;
            return Math.max(0, Math.min(days.length - 1, Math.round((px - pad.left) / innerW * (days.length - 1))));
        }
        svg.addEventListener('pointermove', function (event) { show(pointerIndex(event)); });
        svg.addEventListener('pointerleave', hide);
        svg.setAttribute('tabindex', '0');
        var focusIndex = days.length - 1;
        svg.addEventListener('focus', function () { show(focusIndex); });
        svg.addEventListener('blur', hide);
        svg.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                event.preventDefault();
                focusIndex = Math.max(0, Math.min(days.length - 1, focusIndex + (event.key === 'ArrowRight' ? 1 : -1)));
                show(focusIndex);
            }
        });

        var totals = days.reduce(function (sum, day) {
            sum.visits += day.visits;
            sum.registrants += day.registrants;
            return sum;
        }, { visits: 0, registrants: 0 });
        trend.querySelector('[data-trend-total="registrants"]').textContent = formatNumber(totals.registrants);
        trend.querySelector('[data-trend-total="visits"]').textContent = formatNumber(totals.visits);
        trend.querySelector('[data-trend-total="conversion"]').textContent = totals.visits > 0
            ? Math.min(100, Math.round(totals.registrants / totals.visits * 100)) + '%'
            : '–';
    }

    if (trend) {
        trend.querySelectorAll('[data-trend-range]').forEach(function (button) {
            button.addEventListener('click', function () {
                trendRange = parseInt(button.getAttribute('data-trend-range'), 10) || 14;
                trend.querySelectorAll('[data-trend-range]').forEach(function (other) {
                    other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
                });
                renderTrend();
            });
        });
        renderTrend();
        if ('ResizeObserver' in window) {
            var lastWidth = trendChart ? trendChart.clientWidth : 0;
            new ResizeObserver(function () {
                if (trendChart && Math.abs(trendChart.clientWidth - lastWidth) > 8) {
                    lastWidth = trendChart.clientWidth;
                    renderTrend();
                }
            }).observe(trendChart);
        }
    }

    /* Donut komposisi status; segmen dan legenda saling menyorot. */
    var donut = document.querySelector('[data-mix-donut]');
    if (donut && data.mix.length) {
        var total = data.mix.reduce(function (sum, item) { return sum + item.count; }, 0);
        var radius = 54;
        var circumference = 2 * Math.PI * radius;
        var svg = el('svg', { viewBox: '0 0 140 140', class: 'mix-svg' }, donut);
        el('circle', { cx: 70, cy: 70, r: radius, class: 'mix-track' }, svg);
        var offset = 0;
        var gap = data.mix.length > 1 ? 2 : 0;
        var segments = data.mix.map(function (item, index) {
            var length = item.count / total * circumference;
            var segment = el('circle', {
                cx: 70, cy: 70, r: radius,
                class: 'mix-seg st-' + item.key,
                'stroke-dasharray': Math.max(0, length - gap) + ' ' + circumference,
                'stroke-dashoffset': -offset,
                transform: 'rotate(-90 70 70)'
            }, svg);
            offset += length;
            return segment;
        });
        var center = document.createElement('div');
        center.className = 'mix-center';
        center.innerHTML = '<b></b><span></span>';
        donut.appendChild(center);
        var items = document.querySelectorAll('[data-mix-item]');

        function focusSegment(index) {
            segments.forEach(function (segment, i) { segment.classList.toggle('is-dim', index !== null && i !== index); });
            items.forEach(function (item, i) { item.classList.toggle('is-active', i === index); });
            if (index === null) {
                center.querySelector('b').textContent = formatNumber(total);
                center.querySelector('span').textContent = 'pendaftar';
            } else {
                center.querySelector('b').textContent = Math.round(data.mix[index].count / total * 100) + '%';
                center.querySelector('span').textContent = data.mix[index].label;
            }
        }
        segments.forEach(function (segment, index) {
            segment.addEventListener('pointerenter', function () { focusSegment(index); });
            segment.addEventListener('pointerleave', function () { focusSegment(null); });
        });
        items.forEach(function (item, index) {
            item.addEventListener('pointerenter', function () { focusSegment(index); });
            item.addEventListener('pointerleave', function () { focusSegment(null); });
            item.addEventListener('focus', function () { focusSegment(index); });
            item.addEventListener('blur', function () { focusSegment(null); });
        });
        focusSegment(null);
    }
})();
