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
    const finalIntroDialog = document.querySelector('[data-final-intro-dialog]');
    const finalIntroContinue = document.querySelector('[data-final-intro-continue]');
    const finalIntroWarning = document.querySelector('[data-final-intro-warning]');
    const declineForm = document.querySelector('[data-decline-form]');
    const REGION_API_BASE = 'https://www.emsifa.com/api-wilayah-indonesia/api';
    const regionLevels = ['province', 'regency', 'district', 'village'];
    const regionSets = new Map();
    if (form) {
        form.querySelectorAll('[data-region-select]').forEach(function (select) {
            const group = select.dataset.regionGroup || 'default';
            if (!regionSets.has(group)) regionSets.set(group, new Map());
            regionSets.get(group).set(select.dataset.regionSelect, select);
        });
    }

    function regionStatus(select, message, isError) {
        const field = select.closest('.field');
        const status = field ? field.querySelector('[data-region-status]') : null;
        if (!status) return;
        status.textContent = message;
        status.classList.toggle('is-error', Boolean(isError));
    }

    function normalizeRegionName(name) {
        return String(name || '').trim().toLocaleUpperCase('id-ID')
            .replace(/^(KABUPATEN ADMINISTRASI|KOTA ADMINISTRASI|KABUPATEN|KOTA)\s+/, '')
            .replace(/[^A-Z0-9]+/g, ' ')
            .trim();
    }

    function savedRegionValues(regionSelects) {
        const values = new Map();
        regionSelects.forEach(function (select, level) {
            const saved = select.querySelector('[data-region-saved]');
            values.set(level, saved ? saved.value : '');
        });
        return values;
    }

    function resetRegionSelect(select, enabled) {
        if (!select) return;
        select.replaceChildren(new Option(select.dataset.placeholder || 'Pilih wilayah', ''));
        select.disabled = !enabled;
    }

    function fillRegionSelect(select, regions, savedValue) {
        resetRegionSelect(select, true);
        let matched = null;
        regions.forEach(function (region) {
            const option = new Option(region.name, region.name);
            option.dataset.regionId = region.id;
            select.add(option);
            if (savedValue && normalizeRegionName(region.name) === normalizeRegionName(savedValue)) {
                matched = region;
            }
        });
        if (matched) {
            select.value = matched.name;
        } else if (savedValue) {
            const oldValue = new Option(savedValue, savedValue);
            oldValue.dataset.regionSaved = '1';
            select.add(oldValue);
            select.value = savedValue;
        }
        return matched;
    }

    function regionEndpoint(childLevel, parentId) {
        const endpoint = { regency: 'regencies', district: 'districts', village: 'villages' }[childLevel];
        return REGION_API_BASE + '/' + endpoint + '/' + encodeURIComponent(parentId) + '.json';
    }

    async function fetchRegions(url) {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const regions = await response.json();
        if (!Array.isArray(regions)) throw new Error('Format data wilayah tidak sesuai.');
        return regions;
    }

    async function loadChildRegions(regionSelects, parentLevel, parentId, restoreValues) {
        const parentIndex = regionLevels.indexOf(parentLevel);
        const childLevel = regionLevels[parentIndex + 1];
        const childSelect = regionSelects.get(childLevel);
        if (!childSelect) return null;

        const saved = restoreValues ? restoreValues.get(childLevel) || '' : '';
        resetRegionSelect(childSelect, false);
        regionStatus(childSelect, 'Memuat daftar wilayah…');
        try {
            const regions = await fetchRegions(regionEndpoint(childLevel, parentId));
            const match = fillRegionSelect(childSelect, regions, saved);
            const childLabel = childSelect.closest('.field').querySelector('label').childNodes[0].textContent.trim();
            regionStatus(childSelect, match ? childLabel + ' terpilih.' : 'Pilih ' + childLabel.toLowerCase() + '.');
            return match;
        } catch (error) {
            resetRegionSelect(childSelect, Boolean(saved));
            if (saved) {
                const savedOption = new Option(saved, saved);
                savedOption.dataset.regionSaved = '1';
                childSelect.add(savedOption);
                childSelect.value = saved;
            }
            regionStatus(childSelect, 'Daftar wilayah gagal dimuat. Periksa koneksi, lalu pilih ulang wilayah di atas untuk mencoba kembali.', true);
            return null;
        }
    }

    async function restoreRegionHierarchy(regionSelects) {
        if (!regionSelects.size) return;
        const saved = savedRegionValues(regionSelects);
        const province = regionSelects.get('province');
        regionStatus(province, 'Memuat daftar provinsi…');
        try {
            const provinces = await fetchRegions(REGION_API_BASE + '/provinces.json');
            const selectedProvince = fillRegionSelect(province, provinces, saved.get('province'));
            const provinceLabel = province.closest('.field').querySelector('label').childNodes[0].textContent.trim();
            regionStatus(province, selectedProvince ? provinceLabel + ' terpilih.' : 'Pilih provinsi.');

            if (!selectedProvince) {
                regionLevels.slice(1).forEach(function (level) {
                    const select = regionSelects.get(level);
                    const oldValue = saved.get(level);
                    resetRegionSelect(select, Boolean(oldValue));
                    if (oldValue) {
                        const option = new Option(oldValue, oldValue);
                        option.dataset.regionSaved = '1';
                        select.add(option);
                        select.value = oldValue;
                    }
                    regionStatus(select, 'Pilih provinsi terlebih dahulu.');
                });
                return;
            }

            let parentLevel = 'province';
            let parent = selectedProvince;
            for (const childLevel of regionLevels.slice(1)) {
                const matched = await loadChildRegions(regionSelects, parentLevel, parent.id, saved);
                if (!matched) {
                    const currentIndex = regionLevels.indexOf(childLevel);
                    regionLevels.slice(currentIndex + 1).forEach(function (level) {
                        const select = regionSelects.get(level);
                        const oldValue = saved.get(level);
                        resetRegionSelect(select, Boolean(oldValue));
                        if (oldValue) {
                            const option = new Option(oldValue, oldValue);
                            option.dataset.regionSaved = '1';
                            select.add(option);
                            select.value = oldValue;
                        }
                        regionStatus(select, 'Pilih wilayah di atas terlebih dahulu.');
                    });
                    break;
                }
                parentLevel = childLevel;
                parent = matched;
            }
        } catch (error) {
            resetRegionSelect(province, true);
            const savedProvince = saved.get('province');
            if (savedProvince) {
                const savedOption = new Option(savedProvince, savedProvince);
                savedOption.dataset.regionSaved = '1';
                province.add(savedOption);
                province.value = savedProvince;
            }
            regionStatus(province, 'Daftar provinsi gagal dimuat. Periksa koneksi internet, lalu muat ulang halaman.', true);
            regionLevels.slice(1).forEach(function (level) {
                const select = regionSelects.get(level);
                const oldValue = saved.get(level);
                resetRegionSelect(select, Boolean(oldValue));
                if (oldValue) {
                    const savedOption = new Option(oldValue, oldValue);
                    savedOption.dataset.regionSaved = '1';
                    select.add(savedOption);
                    select.value = oldValue;
                }
                regionStatus(select, oldValue ? 'Data alamat tersimpan; daftar wilayah tidak dapat diperbarui saat ini.' : 'Pilih provinsi terlebih dahulu.');
            });
        }
    }

    function initializeRegionSelectors() {
        regionSets.forEach(function (regionSelects) {
            restoreRegionHierarchy(regionSelects);
            regionLevels.forEach(function (level, index) {
                const select = regionSelects.get(level);
                if (!select) return;
                select.addEventListener('change', function () {
                    regionLevels.slice(index + 1).forEach(function (childLevel) {
                        const child = regionSelects.get(childLevel);
                        resetRegionSelect(child, false);
                        regionStatus(child, 'Pilih wilayah di atas terlebih dahulu.');
                    });
                    const selected = select.selectedOptions[0];
                    const id = selected ? selected.dataset.regionId : '';
                    if (id && index < regionLevels.length - 1) {
                        loadChildRegions(regionSelects, level, id, null);
                    }
                });
            });
        });
    }

    initializeRegionSelectors();

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
        return {
            day: day,
            month: month,
            year2: parseInt(nik.substr(10, 2), 10),
            female: female,
            invalid: day < 1 || day > 31 || month < 1 || month > 12,
        };
    }

    function pad(number) {
        return (number < 10 ? '0' : '') + number;
    }

    function nikWarning(name) {
        const nik = controllerValue(name).trim();
        const parsed = parseNik(nik);
        if (!parsed) return '';
        if (nik === controllerValue('nomor_kk').trim()) return 'NIK ini sama dengan nomor KK. Periksa kembali.';
        if (name === 'nik') {
            if (parsed.invalid) return 'Bagian tanggal lahir pada NIK ini tidak terbaca. Periksa kembali angkanya.';
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
        // Jenis kelamin hanya dinilai bila kode tanggal wajar (01-31 atau 41-71).
        if (parsed.day < 1 || parsed.day > 31) return '';
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

    // Wadah tempat pesan galat sebuah isian ditampilkan.
    function holderOf(control) {
        return control.closest('.participant-question') || control.closest('.field')
            || control.closest('[data-upload-card]') || control.closest('.consent-check');
    }

    function controlValue(control) {
        if (control.type === 'checkbox' || control.type === 'radio') return control.checked ? '1' : '';
        return control.value;
    }

    // Galat dari server tetap berlaku selama isiannya belum diubah.
    function serverErrorActive(control) {
        const holder = holderOf(control);
        if (!holder || !holder.dataset.serverError) return false;
        if (controlValue(control) === holder.dataset.serverValue) return true;
        delete holder.dataset.serverError;
        delete holder.dataset.serverValue;
        return false;
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
            if (isVisibleControl(control) && (!control.checkValidity() || serverErrorActive(control))) return control;
        }
        return null;
    }

    /* ---------- Validasi langsung per isian ---------- */

    function validationMessage(control) {
        const validity = control.validity;
        if (validity.valueMissing) {
            if (control.type === 'file') return 'Berkas ini wajib diunggah.';
            return control.tagName === 'SELECT' ? 'Pilih salah satu.' : 'Wajib diisi.';
        }
        if (validity.badInput) return 'Isi dengan angka.';
        if (validity.patternMismatch) return control.dataset.patternMessage || 'Format belum sesuai.';
        if (validity.typeMismatch && control.type === 'email') return 'Format email belum benar, contoh: nama@gmail.com.';
        if (validity.rangeUnderflow) return 'Nilai minimal ' + control.min + '.';
        if (validity.rangeOverflow) return 'Nilai maksimal ' + control.max + '.';
        if (validity.stepMismatch) return 'Gunakan paling banyak satu angka di belakang koma.';
        if (validity.tooLong) return 'Maksimal ' + control.maxLength + ' karakter.';
        return control.validationMessage || 'Periksa kembali isian ini.';
    }

    function questionAnswered(question) {
        const textarea = question.querySelector('textarea');
        return textarea ? textarea.value.trim() !== '' : Boolean(question.querySelector('input:checked'));
    }

    function setFieldError(wrapper, message) {
        let error = wrapper.querySelector(':scope > [data-field-error]');
        const control = wrapper.querySelector('input:not([type="hidden"]), select, textarea');
        wrapper.classList.toggle('is-invalid', Boolean(message));
        if (message) wrapper.classList.remove('is-valid');
        wrapper.dataset.hasError = message ? '1' : '';
        if (control) control.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (!message) {
            if (error) error.remove();
            return;
        }
        if (!error) {
            error = document.createElement('small');
            error.className = 'field-error';
            error.dataset.fieldError = '1';
            error.setAttribute('role', 'alert');
            error.id = 'galat-' + Math.random().toString(36).slice(2);
            if (control) control.setAttribute('aria-describedby', error.id);
            wrapper.appendChild(error);
        }
        error.textContent = message;
    }

    // Periksa satu isian. Isian kosong baru ditegur setelah pernah disentuh,
    // kecuali `force` (saat menekan lanjut atau kirim).
    function validateControl(control, force) {
        if (!control || control.type === 'hidden' || !control.name) return true;
        const question = control.closest('.participant-question');
        if (question) {
            const required = question.dataset.questionRequired === '1';
            const ok = !required || questionAnswered(question);
            if (ok || force || question.dataset.touched === '1') setFieldError(question, ok ? '' : 'Pertanyaan ini wajib dijawab.');
            return ok;
        }
        const wrapper = control.closest('.field') || control.closest('[data-upload-card]') || control.closest('.consent-check');
        if (!wrapper) return true;
        if (control.closest('[hidden]')) {
            setFieldError(wrapper, '');
            return true;
        }
        if (serverErrorActive(control)) {
            setFieldError(wrapper, wrapper.dataset.serverError);
            return false;
        }
        const valid = control.checkValidity();
        const empty = control.type === 'file' ? !(control.files && control.files.length) : control.value.trim() === '';
        if (!valid && empty && !force && wrapper.dataset.touched !== '1') return false;
        setFieldError(wrapper, valid ? '' : validationMessage(control));
        if (control.type !== 'file') wrapper.classList.toggle('is-valid', valid && !empty);
        return valid;
    }

    function showInvalid(control) {
        validateControl(control, true);
        control.focus({ preventScroll: true });
        control.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    if (form) {
        // Tandai isian yang pernah disentuh, lalu periksa saat keluar dari isian.
        form.addEventListener('focusout', function (event) {
            const control = event.target;
            if (!control.name || control.type === 'hidden') return;
            const holder = control.closest('.participant-question') || control.closest('.field') || control.closest('[data-upload-card]');
            if (holder) holder.dataset.touched = '1';
            validateControl(control, false);
        });
        // Saat mengetik/memilih: perbarui langsung agar pesan hilang begitu sudah benar.
        const live = function (event) {
            const control = event.target;
            if (control.getAttribute('inputmode') === 'numeric' && control.tagName === 'INPUT') {
                const digits = control.value.replace(/\D+/g, '');
                if (digits !== control.value) control.value = digits;
            }
            const holder = holderOf(control);
            if (!holder) return;
            if (event.type === 'change') holder.dataset.touched = '1';
            // Isian yang baru pertama kali diketik tidak langsung ditegur;
            // pesan muncul saat keluar dari isian, lalu diperbarui setiap ketikan.
            if (event.type === 'input' && holder.dataset.touched !== '1' && holder.dataset.hasError !== '1') return;
            validateControl(control, false);
        };
        form.addEventListener('input', live);
        form.addEventListener('change', live);
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
        // Isian draf yang dimuat ulang langsung diperiksa bila sudah berisi.
        for (const control of form.querySelectorAll('.field input:not([type="hidden"]), .field select, .field textarea')) {
            if (control.value.trim() !== '') validateControl(control, true);
        }
        form.addEventListener('focusout', function (event) {
            if (/(^|_)(nik|tahun_lahir)$|^nomor_kk$/.test(event.target.name || '')) updateNikWarnings();
        });

        const goToControl = function (control) {
            const step = control.closest('[data-step]');
            if (step) showStep(parseInt(step.dataset.step, 10), false);
            showInvalid(control);
        };
        const controlByName = function (name) {
            return form.querySelector('[name="' + CSS.escape(name) + '"]:not([type="hidden"])');
        };
        let serverErrors = {};
        try {
            serverErrors = JSON.parse((document.getElementById('server-field-errors') || {}).textContent || '{}') || {};
        } catch (error) {
            serverErrors = {};
        }
        let firstServerError = null;
        for (const control of form.querySelectorAll('input, select, textarea')) {
            const message = serverErrors[control.name];
            const holder = message && control.type !== 'hidden' ? holderOf(control) : null;
            if (!holder || holder.dataset.serverError) continue;
            holder.dataset.serverError = message;
            holder.dataset.serverValue = controlValue(control);
            holder.dataset.touched = '1';
            setFieldError(holder, message);
            if (!firstServerError) firstServerError = control;
        }
        if (firstServerError) {
            // Tunggu tata letak selesai agar guliran berhenti tepat di isian.
            window.setTimeout(function () { goToControl(firstServerError); }, 250);
        }
        // Pesan di ringkasan galat membawa langsung ke isiannya.
        document.addEventListener('click', function (event) {
            const link = event.target.closest('[data-error-field]');
            const control = link ? controlByName(link.dataset.errorField) : null;
            if (!control) return;
            event.preventDefault();
            goToControl(control);
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
            if (finalIntroDialog && typeof finalIntroDialog.showModal === 'function') {
                finalIntroDialog.showModal();
            } else if (finalIntroDialog) {
                finalIntroDialog.setAttribute('open', '');
            }
        });
    }

    if (finalIntroContinue) {
        finalIntroContinue.addEventListener('click', function () {
            const materialsReady = materialNext && materialNext.dataset.materialsReady === '1';
            if (!materialsReady) {
                if (finalIntroWarning) {
                    finalIntroWarning.textContent = 'Formulir belum dapat dibuka karena brosur, rincian biaya, dan SOP untuk unit dan tahun ajaran ini belum lengkap. Silakan hubungi panitia PPDB agar dilengkapi.';
                    finalIntroWarning.hidden = false;
                    finalIntroWarning.setAttribute('tabindex', '-1');
                    finalIntroWarning.focus();
                }
                return;
            }
            if (finalIntroWarning) finalIntroWarning.hidden = true;
            if (finalIntroDialog && typeof finalIntroDialog.close === 'function' && finalIntroDialog.open) {
                finalIntroDialog.close();
            } else if (finalIntroDialog) {
                finalIntroDialog.removeAttribute('open');
            }
            materialStage.hidden = true;
            formSection.hidden = false;
            showStep(currentStep, true);
            saveDraft(false);
        });
    }

    if (declineForm) {
        for (const button of document.querySelectorAll('[data-intro-decline]')) {
            button.addEventListener('click', function () {
                button.disabled = true;
                button.textContent = 'Menyimpan pilihan…';
                declineForm.submit();
            });
        }
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
            const incompleteRegion = Array.from(regionSets.values())
                .flatMap(function (regionSelects) { return regionLevels.map(function (level) { return regionSelects.get(level); }); })
                .find(function (select) { return select && !select.value && !select.closest('[hidden]'); });
            if (incompleteRegion) {
                event.preventDefault();
                const addressStep = incompleteRegion.closest('[data-step]');
                if (addressStep) showStep(parseInt(addressStep.dataset.step, 10), false);
                if (incompleteRegion.disabled) {
                    incompleteRegion.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    regionStatus(incompleteRegion, 'Pilih wilayah sebelumnya terlebih dahulu, lalu tunggu daftar wilayah dimuat.', true);
                } else {
                    showInvalid(incompleteRegion);
                }
                return;
            }
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
            // Skrip lain (mis. versi lama dari cache) bisa membatalkan pengiriman;
            // jangan tampilkan spinner bila pengiriman tidak benar-benar berjalan.
            if (event.defaultPrevented) return;
            window.clearTimeout(saveTimer);
            dirty = false;
            const button = form.querySelector('[data-final-submit]');
            if (button) {
                button.disabled = true;
                button.classList.add('is-busy');
                // Pengaman: bila server tidak membalas, tombol dapat ditekan lagi.
                window.setTimeout(function () {
                    button.disabled = false;
                    button.classList.remove('is-busy');
                    setDraftStatus('Pengiriman belum mendapat balasan. Periksa koneksi lalu tekan Kirim lagi; isian Anda tetap tersimpan.', 'error');
                }, 45000);
            }
        });
        // Kembali lewat tombol Back: pulihkan tombol kirim.
        window.addEventListener('pageshow', function (event) {
            if (!event.persisted) return;
            const button = form.querySelector('[data-final-submit]');
            if (button) {
                button.disabled = false;
                button.classList.remove('is-busy');
            }
        });
    }
})();
