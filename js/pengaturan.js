/**
 * Halaman Kuota PPDB: mengisi formulir otomatis saat unit dan tahun
 * Luxury dipilih, sertaDangerous konfirmasi sebelum menutup pendaftaran.
 */
(function () {
    'use strict';

    const settings = window.PPDB_QUOTA_SETTINGS || {};
    const form = document.querySelector('#quota-config-form');
    if (!form) return;

    const unitSelect = form.querySelector('#kode_unit');
    const yearSelect = form.querySelector('#th_ajaran');
    const modeLabel = form.querySelector('#quota-form-mode');
    const saveButton = form.querySelector('#quota-save-button');
    const internalOpenToggle = form.querySelector('[name="internal_dibuka"]');
    const externalOpenToggle = form.querySelector('[name="eksternal_dibuka"]');
    const quotaInputs = [
        form.querySelector('#kuota_internal'),
        form.querySelector('#kuota_eksternal'),
    ];

    const fields = {
        bank_nama: form.querySelector('#bank_nama'),
        nomor_rekening: form.querySelector('#nomor_rekening'),
        nama_pemilik_rekening: form.querySelector('#nama_pemilik_rekening'),
        biaya_pendaftaran: form.querySelector('#biaya_pendaftaran'),
        masa_pembayaran_hari: form.querySelector('#masa_pembayaran_hari'),
    };

    function loadSetting() {
        const setting = settings[unitSelect.value + '|' + yearSelect.value];

        if (setting) {
            Object.entries(fields).forEach(([name, input]) => {
                if (input) input.value = setting[name];
            });
            if (internalOpenToggle) internalOpenToggle.checked = setting.internal_dibuka === 1;
            if (externalOpenToggle) externalOpenToggle.checked = setting.eksternal_dibuka === 1;
            quotaInputs[0].dataset.reserved = String(setting.reserved_internal);
            quotaInputs[1].dataset.reserved = String(setting.reserved_eksternal);
            modeLabel.textContent = 'Pengaturan ditemukan. Perubahan akan memperbarui data unit dan tahun ini.';
            saveButton.textContent = 'Perbarui pengaturan';
            validateQuotas();
            return;
        }

        if (unitSelect.value && yearSelect.value) {
            Object.values(fields).forEach((input) => { if (input) input.value = ''; });
            if (fields.masa_pembayaran_hari) fields.masa_pembayaran_hari.value = 7;
            if (internalOpenToggle) internalOpenToggle.checked = false;
            if (externalOpenToggle) externalOpenToggle.checked = false;
            quotaInputs.forEach((input) => { if (input) input.dataset.reserved = '0'; });
            modeLabel.textContent = 'Belum ada pengaturan untuk kombinasi ini. Isi nilai untuk membuat konfigurasi baru.';
            saveButton.textContent = 'Simpan pengaturan';
            validateQuotas();
            return;
        }

        modeLabel.textContent = 'Pilih unit dan tahun ajaran untuk melihat atau mengatur kuota.';
        saveButton.textContent = 'Simpan pengaturan';
        validateQuotas();
    }

    unitSelect.addEventListener('change', loadSetting);
    yearSelect.addEventListener('change', loadSetting);

    // Tombol "Ubah" pada tabel mengisi formulir dengan data yang dipilih.
    for (const button of document.querySelectorAll('.quota-edit-button')) {
        button.addEventListener('click', () => {
            unitSelect.value = button.dataset.unit;
            yearSelect.value = button.dataset.year;
            loadSetting();
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    // Prevent a quota allocation from being reduced below active registrations.
    function validateQuotas() {
        const total = quotaInputs.reduce((sum, input) => sum + parseInt(input.value || '0', 10), 0);
        quotaInputs.forEach((input) => {
            const reserved = parseInt(input.dataset.reserved || '0', 10);
            const value = parseInt(input.value || '0', 10);
            if (value < reserved) {
                input.setCustomValidity('Kuota jalur tidak boleh lebih kecil dari pendaftar aktif yang sudah tercatat.');
            } else if (total > 10000) {
                input.setCustomValidity('Jumlah kuota gabungan maksimal 10.000.');
            } else {
                input.setCustomValidity('');
            }
        });
    }
    quotaInputs.forEach((input) => input.addEventListener('input', validateQuotas));

    // Konfirmasi eksplisit saat menutup pendaftaran.
    form.addEventListener('submit', (event) => {
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }
        const closesInternal = internalOpenToggle && !internalOpenToggle.checked;
        const closesExternal = externalOpenToggle && !externalOpenToggle.checked;
        if ((closesInternal || closesExternal) && !window.confirm('Jalur yang tidak dicentang akan ditutup untuk unit dan tahun ini. Lanjutkan?')) {
            event.preventDefault();
        }
    });

    // Isi otomatis hanya saat halaman dibuka, bukan setelah submit gagal.
    if (!form.dataset.submitted) {
        loadSetting();
    }
})();
