/**
 * Peta dashboard: pengunjung tautan pendaftaran (satu titik berkedip per
 * kabupaten/kota, ukurannya mengikuti jumlah pengunjung) dan pendaftar yang
 * telah menyelesaikan formulir lengkap (penanda berkedip di desa/kecamatan).
 * Tampilan awal terfokus ke Kabupaten Aceh Tengah.
 */
(function () {
    'use strict';

    const container = document.querySelector('[data-link-map]');
    const dataNode = document.getElementById('link-map-data');
    if (!container || !dataNode || typeof L === 'undefined') return;

    let data = {};
    try {
        data = JSON.parse(dataNode.textContent) || {};
    } catch (error) {
        data = {};
    }
    const points = Array.isArray(data.visitors) ? data.visitors : [];
    const registrants = Array.isArray(data.registrants) ? data.registrants : [];

    // Kabupaten Aceh Tengah: Danau Laut Tawar dan Takengon di tengah layar.
    const homeBounds = L.latLngBounds([4.25, 96.45], [4.95, 97.25]);
    const map = L.map(container, { scrollWheelZoom: false });
    map.fitBounds(homeBounds);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);
    map.on('focus', () => map.scrollWheelZoom.enable());
    map.on('blur', () => map.scrollWheelZoom.disable());

    const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[char]);

    const maxVisitors = Math.max(1, ...points.map((point) => point.visitors));
    const bounds = [];
    points.forEach((point) => {
        // Luas titik sebanding jumlah pengunjung: 14px untuk satu orang, maksimal 34px.
        const size = Math.round(14 + 20 * Math.sqrt(point.visitors / maxVisitors));
        const icon = L.divIcon({
            className: 'link-map-marker' + (point.today ? ' is-today' : ''),
            html: '<span class="link-map-marker__pulse"></span><span class="link-map-marker__core"></span>',
            iconSize: [size, size],
        });
        const units = point.units
            .map((unit) => '<li>' + escapeHtml(unit.name) + ' <strong>' + unit.visitors + '</strong></li>')
            .join('');
        L.marker([point.lat, point.lon], { icon, title: point.place + ': ' + point.visitors + ' pengunjung', riseOnHover: true })
            .bindTooltip(
                '<strong>' + escapeHtml(point.place) + '</strong><br>' + point.visitors + ' pengunjung'
                    + (point.today ? ' · ada yang membuka hari ini' : '')
                    + '<ul class="link-map-tooltip__units">' + units + '</ul>',
                { direction: 'top', offset: [0, -size / 2], className: 'link-map-tooltip' }
            )
            .addTo(map);
        bounds.push([point.lat, point.lon]);
    });

    const precisionLabel = { titik: 'koordinat Dapodik', desa: 'tingkat desa', kecamatan: 'tingkat kecamatan', kabupaten: 'tingkat kabupaten' };
    registrants.forEach((point) => {
        const size = Math.min(30, 16 + 4 * Math.sqrt(point.registrants));
        const icon = L.divIcon({
            className: 'link-map-marker is-registrant',
            html: '<span class="link-map-marker__pulse"></span><span class="link-map-marker__core">' + (point.registrants > 1 ? point.registrants : '') + '</span>',
            iconSize: [size, size],
        });
        const units = point.units
            .map((unit) => '<li>' + escapeHtml(unit.name) + ' <strong>' + unit.registrants + '</strong></li>')
            .join('');
        L.marker([point.lat, point.lon], { icon, title: point.place + ': ' + point.registrants + ' pendaftar', riseOnHover: true, zIndexOffset: 500 })
            .bindTooltip(
                '<strong>' + escapeHtml(point.place) + '</strong><br>' + escapeHtml(point.kabupaten) + '<br>'
                    + point.registrants + ' pendaftar · ' + (precisionLabel[point.precision] || 'perkiraan')
                    + '<ul class="link-map-tooltip__units">' + units + '</ul>',
                { direction: 'top', offset: [0, -size / 2], className: 'link-map-tooltip' }
            )
            .addTo(map);
        bounds.push([point.lat, point.lon]);
    });

    // Tombol pindah fokus: Kabupaten Aceh Tengah atau seluruh titik.
    const ViewControl = L.Control.extend({
        options: { position: 'topleft' },
        onAdd() {
            const wrapper = L.DomUtil.create('div', 'leaflet-bar link-map-views');
            const addButton = (label, title, handler) => {
                const button = L.DomUtil.create('button', '', wrapper);
                button.type = 'button';
                button.textContent = label;
                button.title = title;
                L.DomEvent.on(button, 'click', handler);
            };
            addButton('Aceh Tengah', 'Fokus ke Kabupaten Aceh Tengah', () => map.fitBounds(homeBounds));
            if (bounds.length) {
                addButton('Semua titik', 'Tampilkan semua pengunjung dan pendaftar', () => map.fitBounds(bounds, { padding: [40, 40], maxZoom: 12 }));
            }
            L.DomEvent.disableClickPropagation(wrapper);
            return wrapper;
        },
    });
    map.addControl(new ViewControl());

    // Tombol layar penuh. iPhone tidak mendukung Fullscreen API untuk elemen,
    // jadi peta dibesarkan memenuhi jendela dengan CSS sebagai gantinya.
    const supportsFullscreen = typeof container.requestFullscreen === 'function';
    const isExpanded = () => document.fullscreenElement === container || container.classList.contains('is-expanded');
    let toggleButton;
    const syncButton = () => {
        const expanded = isExpanded();
        toggleButton.setAttribute('aria-pressed', String(expanded));
        toggleButton.setAttribute('aria-label', expanded ? 'Keluar dari layar penuh' : 'Lihat peta layar penuh');
        toggleButton.title = expanded ? 'Keluar dari layar penuh (Esc)' : 'Lihat peta layar penuh';
        toggleButton.innerHTML = expanded ? '&#x2715;' : '&#x26F6;';
        map.scrollWheelZoom[expanded ? 'enable' : 'disable']();
        setTimeout(() => map.invalidateSize(), 50);
    };
    const toggleExpanded = () => {
        if (supportsFullscreen) {
            if (document.fullscreenElement === container) {
                document.exitFullscreen();
            } else {
                container.requestFullscreen().catch(() => {
                    container.classList.add('is-expanded');
                    syncButton();
                });
            }
            return;
        }
        container.classList.toggle('is-expanded');
        document.documentElement.classList.toggle('has-expanded-map', container.classList.contains('is-expanded'));
        syncButton();
    };

    const FullscreenControl = L.Control.extend({
        options: { position: 'topright' },
        onAdd() {
            const wrapper = L.DomUtil.create('div', 'leaflet-bar link-map-fullscreen');
            toggleButton = L.DomUtil.create('button', '', wrapper);
            toggleButton.type = 'button';
            L.DomEvent.disableClickPropagation(wrapper);
            L.DomEvent.on(toggleButton, 'click', toggleExpanded);
            syncButton();
            return wrapper;
        },
    });
    map.addControl(new FullscreenControl());
    document.addEventListener('fullscreenchange', syncButton);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && container.classList.contains('is-expanded')) {
            container.classList.remove('is-expanded');
            document.documentElement.classList.remove('has-expanded-map');
            syncButton();
        }
    });
})();
