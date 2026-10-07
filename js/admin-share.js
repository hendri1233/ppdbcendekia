/**
 * Panel berbagi tautan lokal: menyalin tautan ke papan klip dan
 * memberi umpan balik singkat pada tombol.
 */
(function () {
    'use strict';

    for (const button of document.querySelectorAll('[data-copy-target]')) {
        button.addEventListener('click', async function () {
            const field = document.querySelector(button.dataset.copyTarget);
            if (!field) return;
            const originalLabel = this.textContent;

            const setFeedback = (message, state) => {
                this.textContent = message;
                this.classList.toggle('is-copied', state === 'ok');
            };

            try {
                field.removeAttribute('readonly');
                field.select();
                field.setSelectionRange(0, field.value.length);
                const copied = document.execCommand && document.execCommand('copy');
                if (!copied && navigator.clipboard) {
                    await navigator.clipboard.writeText(field.value);
                }
                setFeedback('Tersalin', 'ok');
            } catch (error) {
                // Fallback ke select manual bila papan klip diblokir browser.
                field.select();
                setFeedback('Pilih & salin manual', 'warn');
            } finally {
                field.setAttribute('readonly', 'readonly');
                setTimeout(() => {
                    this.textContent = originalLabel;
                    this.classList.remove('is-copied');
                }, 2200);
            }
        });
    }
})();
