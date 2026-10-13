/**
 * Halaman admin "Situs web": editor teks, pratinjau gambar, pratinjau video
 * YouTube, dan slug berita otomatis.
 */
(function () {
    'use strict';

    // --- Editor teks kaya ---
    for (const field of document.querySelectorAll('[data-rich-field]')) {
        const editor = field.querySelector('[data-rich-editor]');
        const source = field.querySelector('[data-rich-source]');
        if (!editor || !source) continue;
        const sync = () => { source.value = editor.innerHTML.trim() === '<br>' ? '' : editor.innerHTML; };
        for (const button of field.querySelectorAll('[data-format]')) {
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => {
                editor.focus();
                const command = button.dataset.format;
                if (command === 'createLink') {
                    const url = window.prompt('Alamat tautan (https:// atau mailto:):', 'https://');
                    if (!url || !/^(https?:\/\/|mailto:)/i.test(url)) return;
                    document.execCommand(command, false, url);
                } else {
                    document.execCommand(command, false, button.dataset.value || null);
                }
                sync();
            });
        }
        // Tempelan dari Word/situs lain dijadikan teks polos agar format tetap rapi.
        editor.addEventListener('paste', (event) => {
            event.preventDefault();
            const text = (event.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, text);
        });
        editor.addEventListener('input', sync);
        editor.closest('form')?.addEventListener('submit', sync);
    }

    // --- Pratinjau gambar sebelum diunggah ---
    for (const input of document.querySelectorAll('[data-image-input]')) {
        const field = input.closest('.cms-image');
        const preview = field?.querySelector('[data-image-preview]');
        const empty = field?.querySelector('[data-image-empty]');
        const original = preview?.getAttribute('src') || '';
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            field.querySelector('.cms-image__error')?.remove();
            if (!file) {
                if (preview) {
                    preview.hidden = !original;
                    if (original) preview.src = original;
                }
                if (empty) empty.hidden = !!original;
                return;
            }
            let problem = '';
            if (!/^image\/(jpeg|png|webp)$/.test(file.type)) problem = 'Format harus JPG, PNG, atau WebP.';
            else if (file.size > 6 * 1024 * 1024) problem = 'Ukuran gambar melebihi 6 MB.';
            if (problem) {
                input.value = '';
                input.insertAdjacentHTML('afterend', '<span class="cms-image__error" role="alert"></span>');
                field.querySelector('.cms-image__error').textContent = problem;
                return;
            }
            if (preview) {
                preview.src = URL.createObjectURL(file);
                preview.hidden = false;
            }
            if (empty) empty.hidden = true;
        });
    }

    // --- Pratinjau YouTube ---
    const youtubeInput = document.querySelector('[data-youtube-input]');
    const youtubePreview = document.querySelector('[data-youtube-preview]');
    if (youtubeInput && youtubePreview) {
        const parseId = (value) => {
            value = value.trim();
            if (/^[\w-]{11}$/.test(value)) return value;
            try {
                const url = new URL(value);
                const host = url.hostname.replace(/^(www\.|m\.)/, '');
                if (host === 'youtu.be') return url.pathname.slice(1, 12);
                if (/youtube(-nocookie)?\.com$/.test(host)) {
                    if (url.searchParams.get('v')) return url.searchParams.get('v');
                    const match = url.pathname.match(/^\/(?:embed|shorts|live|v)\/([\w-]{11})/);
                    if (match) return match[1];
                }
            } catch (e) { /* bukan URL */ }
            return '';
        };
        let current = '';
        youtubeInput.addEventListener('input', () => {
            const id = parseId(youtubeInput.value);
            youtubeInput.setCustomValidity(youtubeInput.value.trim() && !id ? 'Tautan YouTube tidak dikenali.' : '');
            if (id === current) return;
            current = id;
            youtubePreview.hidden = !id;
            youtubePreview.replaceChildren();
            if (id) {
                const frame = document.createElement('iframe');
                frame.src = 'https://www.youtube-nocookie.com/embed/' + id;
                frame.title = 'Pratinjau video';
                frame.allowFullscreen = true;
                youtubePreview.append(frame);
            }
        });
    }

    // --- Slug berita dari judul (selama slug belum diubah manual) ---
    const slugSource = document.querySelector('[data-slug-source]');
    const slugTarget = document.querySelector('[data-slug-target]');
    if (slugSource && slugTarget) {
        let touched = slugTarget.value.trim() !== '';
        const slugify = (text) => text.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 150);
        slugTarget.addEventListener('input', () => {
            touched = slugTarget.value.trim() !== '';
            slugTarget.value = slugTarget.value.toLowerCase().replace(/[^a-z0-9-]/g, '-');
        });
        slugSource.addEventListener('input', () => {
            if (!touched) slugTarget.placeholder = slugify(slugSource.value) || 'dibuat otomatis dari judul';
        });
    }
})();
