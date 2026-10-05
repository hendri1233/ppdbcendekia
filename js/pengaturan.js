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
    const openToggle = form.querySelector('[name="pendaftaran_dibuka"]');
    const quotaInput = form.querySelector('#kuota');

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
            if (openToggle) openToggle.checked = setting.pendaftaran_dibuka === 1;
            modeLabel.textContent = 'Pengaturan ditemukan. Perubahan akan memperbarui data unit dan tahun ini.';
            saveButton.textContent = 'Perbarui pengaturan';
            return;
        }

        if (unitSelect.value && yearSelect.value) {
            Object.values(fields).forEach((input) => { if (input) input.value = ''; });
            if (fields.masa_pembayaran_hari) fields.masa_pembayaran_hari.value = 7;
            if (openToggle) openToggle.checked = false;
            modeLabel.textContent = 'Belum ada pengaturan untuk kombinasi ini. Isi nilai untuk membuat konfigurasi baru.';
            saveButton.textContent = 'Simpan pengaturan';
            return;
        }

        modeLabel.textContent = 'Pilih unit dan tahun ajaran untuk melihat atau mengatur kuota.';
        saveButton.textContent = 'Simpan pengaturan';
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

    // Peringatan bila kuota dikurangi di bawah jumlah pendaftar.
    quotaInput.addEventListener('input', function () {
        const reserved = parseInt(this.dataset.reserved || '0', 10);
        const value = parseInt(this.value || '0', 10);
        this.setCustomValidity(value < reserved ? 'Kuota tidak boleh lebih kecil dari pendaftar yang sudah tercatat.' : '');
    });

    // Konfirmasi eksplisit saat menutup pendaftaran.
    form.addEventListener('submit', (event) => {
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }
        if (openToggle && !openToggle.checked && !window.confirm('Tutup pendaftaran untuk unit dan tahun ini? Pendaftar tidak dapat mendaftar.')) {
            event.preventDefault();
        }
    });

    // Isi otomatis hanya saat halaman dibuka, bukan setelah submit gagal.
    if (!form.dataset.submitted) {
        loadSetting();
    }
})();
