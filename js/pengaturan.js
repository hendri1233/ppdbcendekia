/**
 * Halaman Kuota SPMB: mengisi formulir otomatis saat unit dan tahun
 * Luxury dipilih, sertaDangerous konfirmasi sebelum menutup pendaftaran.
 */
(function () {
    'use strict';

    // Salin tautan bersama (mis. tautan pendaftaran SDM) dengan satu klik.
    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-copy-link]');
        if (!button) return;
        const label = button.querySelector('span');
        const done = function (text) {
            label.textContent = text;
            button.classList.toggle('is-copied', text === 'Tersalin');
            window.setTimeout(function () {
                label.textContent = 'Salin tautan';
                button.classList.remove('is-copied');
            }, 1800);
        };
        const fallback = function () {
            const field = document.createElement('textarea');
            field.value = button.dataset.copyLink;
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            const copied = document.execCommand('copy');
            field.remove();
            done(copied ? 'Tersalin' : 'Gagal menyalin');
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(button.dataset.copyLink).then(function () { done('Tersalin'); }, fallback);
        } else {
            fallback();
        }
    });

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
        nomor_rekening: form.querySelector('#nomor_rekening'),
        nama_pemilik_rekening: form.querySelector('#nama_pemilik_rekening'),
        biaya_pendaftaran: form.querySelector('#biaya_pendaftaran'),
        masa_pembayaran_hari: form.querySelector('#masa_pembayaran_hari'),
        batas_pembayaran: form.querySelector('#batas_pembayaran'),
        jadwal_buka: form.querySelector('#jadwal_buka'),
        jadwal_tutup: form.querySelector('#jadwal_tutup'),
        batas_formulir: form.querySelector('#batas_formulir'),
    };

    // Unit TK per jenjang dan unit SMP per jenis kelamin: kuota jalur menjadi jumlahnya.
    const levelBoxes = Array.from(form.querySelectorAll('[data-level-quota]'));
    const levelInputs = Array.from(form.querySelectorAll('[data-level-quota] input[data-level]'));

    function activeLevelBox() {
        const option = unitSelect.selectedOptions[0];
        return option ? levelBoxes.find((box) => box.dataset.levelJenjang === option.dataset.jenjang) : undefined;
    }

    function sumLevels() {
        const box = activeLevelBox();
        if (!box) return;
        const totals = { internal: 0, eksternal: 0 };
        box.querySelectorAll('input[data-level]').forEach((input) => {
            totals[input.dataset.route] += parseInt(input.value || '0', 10);
        });
        quotaInputs[0].value = totals.internal;
        quotaInputs[1].value = totals.eksternal;
        validateQuotas();
    }

    function syncLevelMode(setting) {
        const active = activeLevelBox();
        levelBoxes.forEach((box) => { box.hidden = box !== active; });
        levelInputs.forEach((input) => {
            const inActive = Boolean(active) && active.contains(input);
            input.disabled = !inActive;
            input.required = inActive;
            if (!inActive) return;
            const level = setting && setting.levels ? setting.levels[input.dataset.level] : null;
            const pair = level ? level[input.dataset.route] : null;
            if (setting !== undefined) input.value = pair ? pair[0] : 0;
            input.dataset.reserved = pair ? String(pair[1]) : '0';
        });
        quotaInputs.forEach((input) => { input.readOnly = Boolean(active); });
        sumLevels();
    }

    function loadSetting() {
        const setting = settings[unitSelect.value + '|' + yearSelect.value];
        if (setting) {
            quotaInputs[0].value = setting.kuota_internal;
            quotaInputs[1].value = setting.kuota_eksternal;
        }
        syncLevelMode(setting || null);

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

    /* ---------- Waktu pengisian formulir per unit ---------- */

    const deadlineDialog = form.querySelector('[data-deadline-dialog]');
    const deadlineInput = fields.batas_formulir;
    const deadlineOpen = form.querySelector('[data-deadline-open]');
    const deadlineSummary = form.querySelector('[data-deadline-summary]');
    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    let deadlineBefore = '';

    // "2026-11-30T23:59" menjadi "30 Nov 2026 23.59 WIB".
    function formatLocal(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value || '');
        return match ? parseInt(match[3], 10) + ' ' + monthNames[parseInt(match[2], 10) - 1] + ' ' + match[1] + ' ' + match[4] + '.' + match[5] + ' WIB' : '';
    }

    function syncDeadline() {
        if (!deadlineInput) return;
        const ready = Boolean(unitSelect.value && yearSelect.value);
        deadlineOpen.disabled = !ready;
        const opens = formatLocal(fields.jadwal_buka && fields.jadwal_buka.value);
        const deadline = formatLocal(deadlineInput.value);
        if (!ready) {
            deadlineSummary.textContent = 'Pilih unit dan tahun ajaran terlebih dahulu.';
        } else if (deadline) {
            deadlineSummary.textContent = (opens ? opens + ' sampai ' : 'Sampai ') + deadline;
        } else {
            deadlineSummary.textContent = 'Belum dibatasi. Formulir dapat diisi kapan saja setelah pembayaran.';
        }
        deadlineOpen.textContent = deadline ? 'Ubah waktu pengisian' : 'Atur waktu pengisian';
    }

    function validateDeadline() {
        if (!deadlineInput) return;
        const opens = fields.jadwal_buka ? fields.jadwal_buka.value : '';
        const invalid = Boolean(deadlineInput.value && opens && deadlineInput.value <= opens);
        deadlineInput.setCustomValidity(invalid ? 'Batas pengisian formulir harus setelah jadwal buka.' : '');
        if (opens) deadlineInput.min = opens;
        else deadlineInput.removeAttribute('min');
    }

    if (deadlineDialog && deadlineInput) {
        deadlineOpen.addEventListener('click', function () {
            const option = unitSelect.selectedOptions[0];
            deadlineDialog.querySelector('[data-deadline-unit]').textContent = (option ? option.textContent.trim() : '') + ' · ' + yearSelect.value;
            deadlineDialog.querySelector('[data-deadline-start]').textContent = formatLocal(fields.jadwal_buka && fields.jadwal_buka.value) || 'Jadwal buka belum diatur';
            deadlineDialog.querySelector('[data-deadline-close]').textContent = formatLocal(fields.jadwal_tutup && fields.jadwal_tutup.value) || 'Tanpa batas';
            deadlineBefore = deadlineInput.value;
            validateDeadline();
            deadlineDialog.showModal();
            deadlineInput.focus();
        });
        deadlineDialog.querySelector('[data-deadline-cancel]').addEventListener('click', function () {
            deadlineInput.value = deadlineBefore;
            deadlineDialog.close();
            syncDeadline();
        });
        deadlineDialog.querySelector('[data-deadline-clear]').addEventListener('click', function () {
            deadlineInput.value = '';
            validateDeadline();
        });
        deadlineDialog.addEventListener('cancel', function () {
            deadlineInput.value = deadlineBefore;
            syncDeadline();
        });
        deadlineInput.addEventListener('input', function () {
            validateDeadline();
            syncDeadline();
        });
        [unitSelect, yearSelect, fields.jadwal_buka].forEach(function (input) {
            if (input) input.addEventListener('change', function () { validateDeadline(); syncDeadline(); });
        });
        // Batas yang tidak valid ditunjukkan di dalam jendela, bukan di formulir yang tertutup.
        deadlineInput.addEventListener('invalid', function () {
            if (!deadlineDialog.open) deadlineDialog.showModal();
        });
        syncDeadline();
    }

    // Tombol "Ubah" pada tabel mengisi formulir dengan data yang dipilih.
    for (const button of document.querySelectorAll('.quota-edit-button')) {
        button.addEventListener('click', () => {
            unitSelect.value = button.dataset.unit;
            yearSelect.value = button.dataset.year;
            loadSetting();
            unitSelect.dispatchEvent(new Event('change'));
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
    levelInputs.forEach((input) => input.addEventListener('input', () => {
        const reserved = parseInt(input.dataset.reserved || '0', 10);
        input.setCustomValidity(parseInt(input.value || '0', 10) < reserved
            ? 'Kuota ini tidak boleh lebih kecil dari pendaftar aktifnya (' + reserved + ').'
            : '');
        sumLevels();
    }));

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
        validateDeadline();
        syncDeadline();
    } else {
        // Setelah submit gagal: pertahankan isian, cukup atur tampilan kotak kuota.
        syncLevelMode(undefined);
    }
})();
