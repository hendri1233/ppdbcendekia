/**
 * Halaman formulir lengkap: popup konfirmasi data awal, pratinjau berkas,
 * dan gate sebelum isian dibuka.
 */
(function () {
    'use strict';

    const dialog = document.querySelector('[data-confirmation]');
    const formSection = document.getElementById('formulir-terbuka');
    const form = document.getElementById('form-utama');

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(0) + ' KB';
        return (bytes / 1048576).toFixed(2) + ' MB';
    }

    // Konfirmasi data awal: Ya membuka formulir, Tidak menutup dengan arahan.
    if (dialog && formSection) {
        const valueField = dialog.querySelector('[data-confirm-value]');
        const noteField = dialog.querySelector('[data-confirm-note]');
        const alreadyConfirmed = valueField && valueField.value === '1';

        const openDialog = function () {
            if (typeof dialog.showModal === 'function') {
                if (!dialog.open) dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }
        };

        const closeDialog = function () {
            if (typeof dialog.close === 'function') {
                if (dialog.open) dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
        };

        for (const button of dialog.querySelectorAll('[data-confirm-choice]')) {
            button.addEventListener('click', function () {
                const confirmed = this.dataset.confirmChoice === '1';
                if (!confirmed) {
                    if (noteField) {
                        noteField.textContent = 'Silakan hubungi panitia PPDB untuk memperbaiki data registrasi awal. Formulir belum dapat diisi.';
                        noteField.classList.add('is-warning');
                    }
                    this.classList.remove('is-picked');
                    this.classList.add('is-picked');
                    return;
                }
                if (valueField) valueField.value = '1';
                if (noteField) {
                    noteField.textContent = 'Terima kasih. Lengkapi isian dan dokumen di bawah ini.';
                    noteField.classList.remove('is-warning');
                }
                dialog.classList.add('is-confirmed');
                closeDialog();
                formSection.hidden = false;
                formSection.classList.add('is-revealed');
                const firstField = formSection.querySelector('input:not([type="hidden"]), select');
                if (firstField) firstField.focus({ preventScroll: true });
                formSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }

        if (alreadyConfirmed) {
            if (typeof dialog.showModal === 'function') {
                if (!dialog.open) dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }
            dialog.classList.add('is-confirmed');
        } else {
            openDialog();
        }
    }

    // Pratinjau nama berkas dan penanda validitas sebelum dikirim.
    for (const input of document.querySelectorAll('.file-drop input[type="file"]')) {
        const card = input.closest('[data-upload-card]');
        const nameField = card ? card.querySelector('[data-file-name]') : null;
        input.addEventListener('change', function () {
            const file = this.files && this.files[0];
            if (card) card.classList.toggle('has-file', Boolean(file));
            if (!nameField) return;
            if (!file) {
                nameField.textContent = 'Belum ada berkas dipilih';
                return;
            }
            const isImage = /^image\/(jpeg|png)$/.test(file.type);
            const isPdf = file.type === 'application/pdf';
            const validType = isImage || isPdf;
            const validSize = file.size <= 8 * 1024 * 1024;
            nameField.textContent = file.name + ' · ' + formatBytes(file.size) + (validType && validSize ? '' : ' · tidak sesuai ketentuan');
            nameField.classList.toggle('is-invalid', !(validType && validSize));
            if (isImage) {
                nameField.dataset.preview = 'ready';
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            const confirmField = document.querySelector('[data-confirm-value]');
            if (confirmField && confirmField.value !== '1') {
                event.preventDefault();
                const noteField = document.querySelector('[data-confirm-note]');
                if (noteField) {
                    noteField.textContent = 'Konfirmasi data awal belum Anda pilih. Pilih "Ya, data ini benar" sebelum mengirim.';
                    noteField.classList.add('is-warning');
                }
                const dialogElement = document.querySelector('[data-confirmation]');
                if (dialogElement && typeof dialogElement.showModal === 'function' && !dialogElement.open) {
                    dialogElement.showModal();
                }
                return;
            }
            if (form.checkValidity()) {
                const button = form.querySelector('.submit-button');
                if (button) {
                    button.disabled = true;
                    button.classList.add('is-busy');
                }
                return;
            }
            event.preventDefault();
            form.reportValidity();
            const firstInvalid = form.querySelector(':invalid');
            if (!firstInvalid) return;
            const wrapper = firstInvalid.closest('.field') || firstInvalid.closest('[data-upload-card]');
            if (wrapper) wrapper.classList.add('is-invalid');
            firstInvalid.focus({ preventScroll: true });
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }
})();
