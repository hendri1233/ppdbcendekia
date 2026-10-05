/**
 * Halaman autentikasi: indikator kekuatan kata sandi dan pencocokan
 * ulang kata sandi. Penilaian hanya untuk membantu pengguna, bukan
 * menggantikan validasi di sisi server.
 */
(function () {
    'use strict';

    const passwordInput = document.querySelector('[data-password-meter]');
    const meter = document.querySelector('[data-meter]');
    const bar = document.querySelector('[data-meter-bar]');
    const label = document.querySelector('[data-meter-label]');
    const matchHint = document.querySelector('[data-match-hint]');

    if (passwordInput && meter && bar && label) {
        const levels = [
            { score: 0, text: 'Terlalu lemah', tone: 'weak' },
            { score: 1, text: 'Lemah', tone: 'weak' },
            { score: 2, text: 'Cukup', tone: 'fair' },
            { score: 3, text: 'Kuat', tone: 'good' },
            { score: 4, text: 'Sangat kuat', tone: 'strong' },
        ];

        const scorePassword = (value) => {
            let score = 0;
            if (value.length >= 12) score++;
            if (value.length >= 16) score++;
            if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
            if (/[0-9]/.test(value) && /[^\w\s]/.test(value)) score++;
            return score;
        };

        const update = () => {
            const value = passwordInput.value;
            meter.hidden = value === '';
            if (value === '') return;

            const level = levels[Math.min(scorePassword(value), 4)];
            bar.style.width = (scorePassword(value) / 4) * 100 + '%';
            bar.dataset.tone = level.tone;
            label.textContent = value.length < 12
                ? 'Minimal 12 karakter'
                : 'Kekuatan: ' + level.text;
            meter.dataset.tone = level.tone;
        };

        passwordInput.addEventListener('input', update);
        passwordInput.addEventListener('blur', update);
        if (passwordInput.value !== '') update();
    }

    // Pastikan kedua kata sandi sama sebelum mengirim formulir.
    if (passwordInput && matchHint) {
        const form = passwordInput.closest('form');
        const confirmInput = form ? form.querySelector('#confirm_password') : null;
        if (confirmInput) {
            confirmInput.addEventListener('input', function () {
                const mismatch = this.value !== '' && this.value !== passwordInput.value;
                matchHint.hidden = !mismatch;
                confirmInput.setCustomValidity(mismatch ? 'Kata sandi tidak cocok.' : '');
            });
        }
    }
})();
