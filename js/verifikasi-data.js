// Verifikasi data peserta: penampil dokumen (tab, zoom, putar) dan centang cocok/salah per isian.
(() => {
    const root = document.querySelector('[data-verify]');
    if (!root) return;

    // --- Penampil dokumen ---
    const tabs = [...root.querySelectorAll('[data-doc-tab]')];
    const docs = [...root.querySelectorAll('[data-doc]')];
    let scale = 1;
    let rotation = 0;

    const currentImage = () => {
        const visible = docs.find((doc) => !doc.hidden);
        return visible ? visible.querySelector('[data-zoom-img]') : null;
    };
    const applyTransform = () => {
        const image = currentImage();
        if (!image) return;
        image.style.transform = `rotate(${rotation}deg)`;
        image.style.width = `${scale * 100}%`;
    };
    const showDoc = (slot) => {
        const target = docs.find((doc) => doc.dataset.doc === slot);
        if (!target) return;
        for (const doc of docs) doc.hidden = doc !== target;
        for (const tab of tabs) tab.setAttribute('aria-selected', String(tab.dataset.docTab === slot));
        scale = 1;
        rotation = 0;
        applyTransform();
    };

    for (const tab of tabs) tab.addEventListener('click', () => showDoc(tab.dataset.docTab));
    for (const source of root.querySelectorAll('[data-show-doc]')) {
        source.addEventListener('click', () => showDoc(source.dataset.showDoc));
    }
    for (const button of root.querySelectorAll('[data-zoom]')) {
        button.addEventListener('click', () => {
            const step = Number(button.dataset.zoom);
            scale = step === 0 ? 1 : Math.min(4, Math.max(0.5, scale + step * 0.5));
            applyTransform();
        });
    }
    root.querySelector('[data-rotate]')?.addEventListener('click', () => {
        rotation = (rotation + 90) % 360;
        applyTransform();
    });
    // Klik pada gambar memperbesar tepat di titik yang diklik.
    for (const wrap of root.querySelectorAll('[data-zoom-wrap]')) {
        wrap.addEventListener('click', (event) => {
            const rect = wrap.getBoundingClientRect();
            const ratioX = (event.clientX - rect.left + wrap.scrollLeft) / wrap.scrollWidth;
            const ratioY = (event.clientY - rect.top + wrap.scrollTop) / wrap.scrollHeight;
            scale = scale >= 3 ? 1 : scale + 1;
            applyTransform();
            wrap.scrollLeft = ratioX * wrap.scrollWidth - rect.width / 2;
            wrap.scrollTop = ratioY * wrap.scrollHeight - rect.height / 2;
        });
    }

    // --- Centang per isian ---
    const rows = [...root.querySelectorAll('[data-check]')];
    const progress = root.querySelector('[data-progress]');
    const verifyButton = root.querySelector('[data-verify-submit]');
    const revisionForm = root.querySelector('[data-verify-revision]');
    const revisionNote = root.querySelector('[data-revision-note]');
    const revisionPreview = root.querySelector('[data-revision-preview]');

    const buildNote = (badRows) => {
        const parts = badRows.map((row) => {
            const note = row.querySelector('[data-note]').value.trim();
            return row.dataset.label + (note ? ` (seharusnya: ${note})` : '');
        });
        const text = `Mohon perbaiki sesuai KK/akta/KTP: ${parts.join('; ')}.`;
        return text.length > 300 ? `${text.slice(0, 297)}...` : text;
    };

    const refresh = () => {
        const marked = rows.filter((row) => row.dataset.state);
        const badRows = rows.filter((row) => row.dataset.state === 'bad');
        const allOk = marked.length === rows.length && badRows.length === 0;
        progress.textContent = `${marked.length} dari ${rows.length} isian diperiksa` + (badRows.length ? ` · ${badRows.length} berbeda` : '');
        verifyButton.disabled = !allOk;
        verifyButton.closest('form').hidden = badRows.length > 0;
        revisionForm.hidden = badRows.length === 0;
        if (badRows.length) {
            revisionNote.value = buildNote(badRows);
            revisionPreview.textContent = `Pesan ke wali murid: "${revisionNote.value}"`;
        }
    };

    const setState = (row, state) => {
        row.dataset.state = state;
        for (const mark of row.querySelectorAll('[data-mark]')) {
            mark.setAttribute('aria-pressed', String(mark.dataset.mark === state));
        }
        const note = row.querySelector('[data-note]');
        note.hidden = state !== 'bad';
        if (state === 'bad') note.focus();
    };

    for (const row of rows) {
        for (const mark of row.querySelectorAll('[data-mark]')) {
            mark.addEventListener('click', () => {
                setState(row, row.dataset.state === mark.dataset.mark ? '' : mark.dataset.mark);
                refresh();
            });
        }
        row.querySelector('[data-note]').addEventListener('input', refresh);
    }
    root.querySelector('[data-mark-all]')?.addEventListener('click', () => {
        for (const row of rows) if (!row.dataset.state) setState(row, 'ok');
        refresh();
    });
    refresh();

    // --- Kirim permintaan perbaikan lewat revisi-formulir.php (JSON) ---
    revisionForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!window.confirm('Buka kembali formulir dan kirim permintaan perbaikan ke WhatsApp wali murid?')) return;
        const button = revisionForm.querySelector('button[type="submit"]');
        const result = revisionForm.querySelector('[data-revision-result]');
        button.disabled = true;
        button.textContent = 'Mengirim…';
        fetch(revisionForm.action, {
            method: 'POST',
            body: new FormData(revisionForm),
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => response.json())
            .catch(() => ({ ok: false, text: 'Koneksi terputus. Coba lagi.' }))
            .then((data) => {
                button.disabled = false;
                button.textContent = data.ok ? 'Kirim ulang' : 'Kirim permintaan perbaikan ke wali murid';
                result.className = `verify__revision-result ${data.ok ? 'is-ok' : 'is-error'}`;
                result.replaceChildren(document.createTextNode(data.text || ''));
                for (const link of data.links || []) {
                    const anchor = document.createElement('a');
                    anchor.className = 'table-action stage-wa';
                    anchor.href = link.url;
                    anchor.target = '_blank';
                    anchor.rel = 'noopener';
                    anchor.textContent = `WA ${link.role} · ${link.display}`;
                    result.append(anchor);
                }
            });
    });
})();
