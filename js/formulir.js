/**
 * Halaman formulir lengkap: popup konfirmasi data awal, materi, formulir
 * bertahap, isian bersyarat, dan simpan draf otomatis ke server agar isian
 * yang terhenti di tengah jalan tidak hilang.
 */
(function () {
    'use strict';

    const dialog = document.querySelector('[data-confirmation]');
    const formSection = document.getElementById('formulir-terbuka');
    const form = document.getElementById('form-utama');
    const materialStage = document.querySelector('[data-material-stage]');
    const materialNext = document.querySelector('[data-materials-next]');
    const materialRead = document.querySelector('[data-materials-read]');
    const materialConfirmValue = document.querySelector('[data-material-confirm-value]');
    const steps = form ? Array.from(form.querySelectorAll('[data-step]')) : [];
    const stepButtons = Array.from(document.querySelectorAll('[data-step-target]'));
    const stepValue = form ? form.querySelector('[data-step-value]') : null;
    const MAX_FILE_BYTES = 8 * 1024 * 1024;

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(0) + ' KB';
        return (bytes / 1048576).toFixed(2) + ' MB';
    }

    function fileIsAcceptable(file) {
        return /^image\/(jpeg|png)$/.test(file.type) || file.type === 'application/pdf'
            ? file.size <= MAX_FILE_BYTES
            : false;
    }

    /* ---------- Simpan draf ---------- */

    let saveTimer = null;
    let saving = false;
    let pendingSave = false;
    let dirty = false;

    function setDraftStatus(text, state) {
        for (const status of document.querySelectorAll('[data-draft-status]')) {
            status.textContent = text;
            status.dataset.state = state || '';
        }
    }

    function draftFormData(includeFiles) {
        const data = new FormData(form);
        data.set('aksi', 'simpan_draf');
        data.delete('submit');
        for (const input of form.querySelectorAll('input[type="file"]')) {
            const file = input.files && input.files[0];
            if (!includeFiles || !file || !fileIsAcceptable(file)) {
                data.delete(input.name);
            }
        }
        return data;
    }

    function applySavedDocuments(documents) {
        for (const card of form.querySelectorAll('[data-upload-card]')) {
            const slot = card.dataset.uploadCard;
            const saved = documents && documents[slot];
            if (!saved) continue;
            const input = card.querySelector('input[type="file"]');
            const savedLabel = card.querySelector('[data-upload-saved]');
            const nameField = card.querySelector('[data-file-name]');
            const pickLabel = card.querySelector('.file-drop__label');
            if (input && input.files && input.files.length) {
                input.value = '';
                if (nameField) nameField.textContent = 'Belum ada berkas baru dipilih';
            }
            if (input) input.required = false;
            if (savedLabel) {
                savedLabel.textContent = 'Tersimpan · ' + saved.name + ' (' + formatBytes(saved.size) + ')';
                savedLabel.hidden = false;
            }
            if (pickLabel) pickLabel.textContent = 'Ganti berkas';
            card.classList.add('has-file');
            card.classList.remove('is-invalid');
        }
    }

    function saveDraft(includeFiles) {
        if (!form) return Promise.resolve();
        window.clearTimeout(saveTimer);
        if (saving) {
            pendingSave = includeFiles ? 'files' : (pendingSave || true);
            return Promise.resolve();
        }
        saving = true;
        dirty = false;
        setDraftStatus(includeFiles ? 'Mengunggah dan menyimpan…' : 'Menyimpan…', 'busy');
        return fetch(form.getAttribute('action'), {
            method: 'POST',
            body: draftFormData(includeFiles),
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, message: 'Balasan server tidak dapat dibaca.' };
                });
            })
            .then(function (result) {
                if (!result.ok) {
                    dirty = true;
                    setDraftStatus(result.message || 'Gagal menyimpan. Coba lagi.', 'error');
                    return;
                }
                applySavedDocuments(result.documents);
                if (result.errors && result.errors.length) {
                    setDraftStatus(result.errors.join(' '), 'error');
                } else {
                    setDraftStatus('Tersimpan otomatis pukul ' + result.saved_at, 'saved');
                }
            })
            .catch(function () {
                dirty = true;
                setDraftStatus('Belum tersimpan, periksa koneksi internet. Akan dicoba lagi.', 'error');
                saveTimer = window.setTimeout(function () { saveDraft(false); }, 15000);
            })
            .finally(function () {
                saving = false;
                if (pendingSave) {
                    const withFiles = pendingSave === 'files';
                    pendingSave = false;
                    saveDraft(withFiles);
                }
            });
    }

    function scheduleSave() {
        dirty = true;
        setDraftStatus('Ada perubahan belum tersimpan…', 'pending');
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(function () { saveDraft(false); }, 2500);
    }

    /* ---------- Isian bersyarat ---------- */

    function controllerValue(name) {
        const control = form.elements.namedItem(name);
        return control ? control.value : '';
    }

    // data-show-if berisi [[nama_field, [nilai...]], ...]; semua syarat harus terpenuhi.
    function conditionsMet(element) {
        let conditions = [];
        try {
            conditions = JSON.parse(element.dataset.showIf || '[]');
        } catch (error) {
            return true;
        }
        return conditions.every(function (condition) {
            return condition[1].indexOf(controllerValue(condition[0])) !== -1;
        });
    }

    function updateConditionalFields() {
        if (!form) return;
        for (const element of form.querySelectorAll('[data-show-if]')) {
            element.hidden = !conditionsMet(element);
        }
    }

    /* ---------- Pemeriksaan NIK (peringatan, tidak memblokir) ---------- */

    // Digit 7-8 tanggal (perempuan +40), 9-10 bulan, 11-12 tahun dua digit.
    function parseNik(nik) {
        if (!/^[0-9]{16}$/.test(nik)) return null;
        let day = parseInt(nik.substr(6, 2), 10);
        const female = day > 40;
        if (female) day -= 40;
        const month = parseInt(nik.substr(8, 2), 10);
        if (day < 1 || day > 31 || month < 1 || month > 12) return { invalid: true };
        return { day: day, month: month, year2: parseInt(nik.substr(10, 2), 10), female: female };
    }

    function pad(number) {
        return (number < 10 ? '0' : '') + number;
    }

    function nikWarning(name) {
        const nik = controllerValue(name).trim();
        const parsed = parseNik(nik);
        if (!parsed) return '';
        if (nik === controllerValue('nomor_kk').trim()) return 'NIK ini sama dengan nomor KK. Periksa kembali.';
        if (parsed.invalid) return 'Bagian tanggal lahir pada NIK ini tidak terbaca. Periksa kembali angkanya.';
        if (name === 'nik') {
            const birth = (form.dataset.birthDate || '').split('-');
            const messages = [];
            if (birth.length === 3 && (parsed.day !== parseInt(birth[2], 10) || parsed.month !== parseInt(birth[1], 10) || parsed.year2 !== parseInt(birth[0], 10) % 100)) {
                messages.push('Tanggal lahir di NIK (' + pad(parsed.day) + '-' + pad(parsed.month) + '-' + pad(parsed.year2) + ') berbeda dengan tanggal lahir registrasi (' + birth[2] + '-' + birth[1] + '-' + birth[0] + ').');
            }
            const gender = form.dataset.gender;
            if ((gender === 'perempuan' || gender === 'laki-laki') && parsed.female !== (gender === 'perempuan')) {
                messages.push('NIK terbaca ' + (parsed.female ? 'perempuan' : 'laki-laki') + ', sedangkan registrasi ' + gender + '.');
            }
            return messages.join(' ');
        }
        const role = name.replace('_nik', '');
        const year = controllerValue(role + '_tahun_lahir').trim();
        if (/^[0-9]{4}$/.test(year) && parseInt(year, 10) % 100 !== parsed.year2) {
            return 'Tahun lahir (' + year + ') berbeda dengan tahun pada NIK (' + pad(parsed.year2) + ').';
        }
        if (role === 'ayah' && parsed.female) return 'NIK ini terbaca sebagai NIK perempuan.';
        if (role === 'ibu' && !parsed.female) return 'NIK ini terbaca sebagai NIK laki-laki.';
        return '';
    }

    function updateNikWarnings() {
        if (!form) return;
        for (const name of ['nik', 'ayah_nik', 'ibu_nik', 'wali_nik']) {
            const wrapper = form.querySelector('[data-field="' + name + '"]');
            if (!wrapper) continue;
            let warning = wrapper.querySelector('[data-nik-warning]');
            const message = nikWarning(name);
            if (!message) {
                if (warning) warning.remove();
                continue;
            }
            if (!warning) {
                warning = document.createElement('small');
                warning.className = 'field-warning';
                warning.dataset.nikWarning = '1';
                warning.setAttribute('role', 'status');
                wrapper.appendChild(warning);
            }
            warning.textContent = message + ' Jika data registrasi yang keliru, formulir tetap dapat dikirim dan panitia akan memeriksanya.';
        }
    }

    /* ---------- Langkah formulir ---------- */

    let currentStep = form ? parseInt(form.dataset.currentStep || '0', 10) || 0 : 0;

    function isVisibleControl(control) {
        return !control.closest('[hidden]') && !control.disabled && control.type !== 'hidden';
    }

    function firstInvalidIn(container) {
        for (const question of container.querySelectorAll('[data-question-required="1"]')) {
            const textarea = question.querySelector('textarea');
            const answered = textarea
                ? textarea.value.trim() !== ''
                : Boolean(question.querySelector('input:checked'));
            question.classList.toggle('is-invalid', !answered);
            if (!answered) return question.querySelector('textarea, input');
        }
        for (const control of container.querySelectorAll('input, select, textarea')) {
            if (isVisibleControl(control) && !control.checkValidity()) return control;
        }
        return null;
    }

    function showInvalid(control) {
        const wrapper = control.closest('.field') || control.closest('[data-upload-card]') || control.closest('.participant-question');
        if (wrapper) wrapper.classList.add('is-invalid');
        control.focus({ preventScroll: true });
        control.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (typeof control.reportValidity === 'function' && !control.closest('.participant-question')) {
            control.reportValidity();
        }
    }

    function showStep(index, focusHeading) {
        if (!steps.length) return;
        currentStep = Math.max(0, Math.min(steps.length - 1, index));
        steps.forEach(function (step, stepIndex) {
            step.hidden = stepIndex !== currentStep;
        });
        steps[currentStep].classList.remove('will-reveal');
        steps[currentStep].classList.add('is-revealed');
        steps[currentStep].style.transitionDelay = '';
        stepButtons.forEach(function (button) {
            const target = parseInt(button.dataset.stepTarget, 10);
            if (target === currentStep) {
                button.setAttribute('aria-current', 'step');
            } else {
                button.removeAttribute('aria-current');
            }
            button.classList.toggle('is-done', target < currentStep);
        });
        if (stepValue) stepValue.value = String(currentStep);
        if (focusHeading) {
            const heading = steps[currentStep].querySelector('h2');
            if (heading) {
                heading.setAttribute('tabindex', '-1');
                heading.focus({ preventScroll: true });
            }
            const nav = document.querySelector('[data-step-nav]');
            (nav || steps[currentStep]).scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    if (form && steps.length) {
        showStep(currentStep, false);
        updateConditionalFields();
        updateNikWarnings();
        form.addEventListener('focusout', function (event) {
            if (/(^|_)(nik|tahun_lahir)$|^nomor_kk$/.test(event.target.name || '')) updateNikWarnings();
        });

        form.addEventListener('click', function (event) {
            const next = event.target.closest('[data-step-next]');
            const previous = event.target.closest('[data-step-prev]');
            if (next) {
                const invalid = firstInvalidIn(steps[currentStep]);
                if (invalid) {
                    showInvalid(invalid);
                    return;
                }
                showStep(currentStep + 1, true);
                saveDraft(false);
            } else if (previous) {
                showStep(currentStep - 1, true);
                saveDraft(false);
            }
        });

        stepButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                showStep(parseInt(button.dataset.stepTarget, 10), true);
                saveDraft(false);
            });
        });

        form.addEventListener('input', function (event) {
            if (event.target.type === 'file') return;
            const wrapper = event.target.closest('.field, .participant-question');
            if (wrapper) wrapper.classList.remove('is-invalid');
            scheduleSave();
        });
        form.addEventListener('change', function (event) {
            if (event.target.type === 'file') return;
            updateConditionalFields();
            scheduleSave();
        });

        // Simpan sisa perubahan saat halaman ditutup atau berpindah aplikasi.
        const flush = function () {
            if (!dirty || !navigator.sendBeacon) return;
            if (navigator.sendBeacon(form.getAttribute('action'), draftFormData(false))) {
                dirty = false;
            }
        };
        window.addEventListener('pagehide', flush);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') flush();
        });
    }

    /* ---------- Konfirmasi data awal dan materi ---------- */

    if (dialog && formSection) {
        const valueField = dialog.querySelector('[data-confirm-value]');
        const noteField = dialog.querySelector('[data-confirm-note]');
        const alreadyConfirmed = valueField && valueField.value === '1';
        const alreadyReadMaterials = materialConfirmValue && materialConfirmValue.value === '1';

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
                if (materialStage) {
                    materialStage.hidden = false;
                    materialStage.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        }

        if (alreadyConfirmed) {
            closeDialog();
            if (alreadyReadMaterials) {
                formSection.hidden = false;
            } else if (materialStage) {
                materialStage.hidden = false;
            } else {
                openDialog();
            }
            dialog.classList.add('is-confirmed');
        } else {
            openDialog();
        }
    }

    if (materialNext && materialRead && materialStage && formSection) {
        materialNext.addEventListener('click', function () {
            let warning = materialStage.querySelector('[data-material-warning]');
            if (!warning) {
                warning = document.createElement('p');
                warning.dataset.materialWarning = '1';
                warning.className = 'materials-stage__notice';
                warning.setAttribute('role', 'alert');
                materialStage.appendChild(warning);
            }
            if (!materialRead.checked) {
                warning.textContent = 'Centang konfirmasi setelah membaca ketiga materi.';
                materialRead.focus();
                return;
            }
            if (materialConfirmValue) materialConfirmValue.value = '1';
            warning.remove();
            materialStage.hidden = true;
            formSection.hidden = false;
            showStep(currentStep, true);
            saveDraft(false);
        });
    }

    /* ---------- Berkas: pratinjau nama dan unggah langsung ---------- */

    for (const input of document.querySelectorAll('.file-drop input[type="file"]')) {
        const card = input.closest('[data-upload-card]');
        const nameField = card ? card.querySelector('[data-file-name]') : null;
        input.addEventListener('change', function () {
            const file = this.files && this.files[0];
            if (!nameField) return;
            if (!file) {
                nameField.textContent = 'Belum ada berkas baru dipilih';
                nameField.classList.remove('is-invalid');
                return;
            }
            const valid = fileIsAcceptable(file);
            nameField.textContent = file.name + ' · ' + formatBytes(file.size) + (valid ? '' : ' · tidak sesuai ketentuan (JPG, PNG, atau PDF maks. 8 MB)');
            nameField.classList.toggle('is-invalid', !valid);
            if (valid && form) {
                if (saving) {
                    pendingSave = 'files';
                } else {
                    saveDraft(true);
                }
            }
        });
    }

    /* ---------- Kirim formulir ---------- */

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
            // Periksa seluruh langkah; tampilkan langkah pertama yang belum lengkap.
            for (let index = 0; index < steps.length; index++) {
                steps[index].hidden = false;
                const invalid = firstInvalidIn(steps[index]);
                steps[index].hidden = index !== currentStep;
                if (invalid) {
                    event.preventDefault();
                    showStep(index, false);
                    showInvalid(invalid);
                    return;
                }
            }
            window.clearTimeout(saveTimer);
            dirty = false;
            const button = form.querySelector('[data-final-submit]');
            if (button) {
                button.disabled = true;
                button.classList.add('is-busy');
            }
        });
    }
})();
