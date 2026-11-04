(() => {
    const status = document.querySelector('[data-print-status]');
    document.querySelector('[data-print-now]').addEventListener('click', () => window.print());

    const failureNote = (section, message, url) => {
        const note = section.querySelector('.attachment-note') || section.appendChild(document.createElement('p'));
        note.className = 'attachment-note';
        note.textContent = message;
        if (url) {
            const link = document.createElement('a');
            link.href = url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = ' Buka berkas';
            note.appendChild(link);
        }
    };

    const waitForImage = (image) => new Promise((resolve) => {
        const finish = () => {
            if (!image.naturalWidth) {
                failureNote(image.closest('.attachment'), 'Berkas tidak dapat dimuat.');
                image.remove();
                resolve(false);
                return;
            }
            resolve(true);
        };
        if (image.complete) {
            finish();
            return;
        }
        image.addEventListener('load', finish, { once: true });
        image.addEventListener('error', finish, { once: true });
    });

    /** Setiap halaman PDF menjadi satu lembar A4 tersendiri. */
    const renderPdf = async (section) => {
        const url = section.dataset.pdfUrl;
        try {
            const pdf = await window.pdfjsLib.getDocument({ url, withCredentials: true }).promise;
            let sheet = section;
            for (let number = 1; number <= pdf.numPages; number += 1) {
                const page = await pdf.getPage(number);
                const baseWidth = page.getViewport({ scale: 1 }).width;
                const viewport = page.getViewport({ scale: 1400 / baseWidth });
                const canvas = document.createElement('canvas');
                canvas.width = viewport.width;
                canvas.height = viewport.height;
                await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
                if (number > 1) {
                    const next = document.createElement('section');
                    next.className = 'sheet attachment';
                    const head = section.querySelector('.attachment-head').cloneNode(true);
                    head.querySelector('h2').textContent = `${section.dataset.pdfTitle} · halaman ${number}`;
                    next.appendChild(head);
                    sheet.after(next);
                    sheet = next;
                }
                sheet.appendChild(canvas);
            }
            section.querySelector('.attachment-note')?.remove();
            return true;
        } catch (error) {
            failureNote(section, 'Berkas PDF tidak dapat dimuat di halaman ini.', url);
            return false;
        }
    };

    const prepare = async () => {
        const images = [...document.querySelectorAll('[data-attachment-image]')];
        const pdfSections = [...document.querySelectorAll('[data-pdf-url]')];
        if (pdfSections.length && window.pdfjsLib) {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        }
        const results = await Promise.all([
            ...images.map(waitForImage),
            ...pdfSections.map((section) => {
                if (!window.pdfjsLib) {
                    failureNote(section, 'Pembaca PDF gagal dimuat. Periksa koneksi internet.', section.dataset.pdfUrl);
                    return false;
                }
                return renderPdf(section);
            }),
        ]);
        const failed = results.filter((result) => !result).length;
        status.textContent = failed ? `${failed} lampiran gagal dimuat. Periksa sebelum mencetak.` : 'Siap dicetak, kertas A4.';
        // Dialog cetak hanya terbuka otomatis bila semua lampiran sudah lengkap.
        if (!failed) {
            window.print();
        }
    };

    prepare();
})();
