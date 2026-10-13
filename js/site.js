/**
 * Interaksi situs publik: tema terang/gelap, loader halaman, menu ponsel,
 * slider beranda, akordeon, penanda subnavigasi, dan asisten AI.
 */
(function () {
    'use strict';

    const root = document.documentElement;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // --- Loader halaman ---
    const finishLoading = () => root.classList.remove('is-loading');
    if (document.readyState === 'complete') {
        finishLoading();
    } else {
        window.addEventListener('load', finishLoading, { once: true });
        // Jangan menahan pengunjung bila ada gambar/iframe yang lambat.
        setTimeout(finishLoading, 2500);
    }
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) root.classList.remove('is-loading', 'is-leaving');
    });
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if (link.target && link.target !== '_self') return;
        if (link.hasAttribute('download')) return;
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) return;
        if (url.pathname === location.pathname && url.search === location.search && url.hash) return;
        root.classList.add('is-leaving');
    });

    // --- Tema ---
    const themeButtons = document.querySelectorAll('[data-theme-toggle]');
    const syncThemeLabel = () => {
        const dark = root.getAttribute('data-theme') === 'dark';
        for (const button of themeButtons) {
            button.setAttribute('aria-label', dark ? 'Ganti ke mode terang' : 'Ganti ke mode gelap');
            button.setAttribute('aria-pressed', String(dark));
        }
    };
    for (const button of themeButtons) {
        button.addEventListener('click', () => {
            const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem('cendekia-theme', next); } catch (e) { /* mode privat */ }
            syncThemeLabel();
        });
    }
    syncThemeLabel();

    // --- Menu ponsel ---
    const menuToggle = document.querySelector('[data-menu-toggle]');
    const navigation = document.querySelector('[data-navigation]');
    const closeMenu = () => {
        if (!menuToggle || !navigation) return;
        menuToggle.setAttribute('aria-expanded', 'false');
        menuToggle.setAttribute('aria-label', 'Buka navigasi');
        navigation.classList.remove('is-open');
        document.body.classList.remove('menu-open');
    };
    if (menuToggle && navigation) {
        menuToggle.addEventListener('click', () => {
            const isOpen = menuToggle.getAttribute('aria-expanded') === 'true';
            menuToggle.setAttribute('aria-expanded', String(!isOpen));
            menuToggle.setAttribute('aria-label', isOpen ? 'Buka navigasi' : 'Tutup navigasi');
            navigation.classList.toggle('is-open', !isOpen);
            document.body.classList.toggle('menu-open', !isOpen);
        });
        navigation.addEventListener('click', (event) => {
            if (event.target.closest('a')) closeMenu();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && navigation.classList.contains('is-open')) {
                closeMenu();
                menuToggle.focus();
            }
        });
        window.addEventListener('resize', () => {
            if (window.innerWidth > 1060) closeMenu();
        });
    }

    // --- Slider beranda ---
    const slider = document.querySelector('[data-slider]');
    if (slider) {
        const slides = Array.from(slider.querySelectorAll('[data-slide]'));
        const dots = Array.from(slider.querySelectorAll('[data-slide-dot]'));
        const caption = slider.querySelector('[data-slide-caption]');
        const more = slider.querySelector('[data-slide-link]');
        let index = 0;
        let timer = null;
        const show = (next) => {
            index = (next + slides.length) % slides.length;
            slides.forEach((slide, i) => {
                slide.classList.toggle('is-active', i === index);
                slide.setAttribute('aria-hidden', String(i !== index));
            });
            dots.forEach((dot, i) => dot.setAttribute('aria-current', String(i === index)));
            if (caption) caption.textContent = slides[index].dataset.caption || '';
            if (more) {
                const link = slides[index].dataset.link || '';
                more.hidden = !link;
                if (link) more.href = link;
            }
        };
        const stop = () => { clearInterval(timer); timer = null; };
        const start = () => {
            if (reduceMotion || slides.length < 2 || timer) return;
            timer = setInterval(() => show(index + 1), 6500);
        };
        slider.querySelector('[data-slide-prev]')?.addEventListener('click', () => { stop(); show(index - 1); });
        slider.querySelector('[data-slide-next]')?.addEventListener('click', () => { stop(); show(index + 1); });
        dots.forEach((dot, i) => dot.addEventListener('click', () => { stop(); show(i); }));
        slider.addEventListener('mouseenter', stop);
        slider.addEventListener('mouseleave', start);
        slider.addEventListener('focusin', stop);
        document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));
        show(0);
        start();
    }

    // --- Akordeon: satu jawaban terbuka ---
    for (const accordion of document.querySelectorAll('[data-accordion]')) {
        const items = Array.from(accordion.querySelectorAll('details'));
        for (const item of items) {
            item.addEventListener('toggle', () => {
                if (!item.open) return;
                for (const other of items) if (other !== item) other.open = false;
            });
        }
    }

    // --- Penanda bagian aktif pada subnavigasi ---
    const spyLinks = Array.from(document.querySelectorAll('[data-spy] a[href^="#"]'));
    const spyTargets = spyLinks.map((link) => document.getElementById(link.getAttribute('href').slice(1))).filter(Boolean);
    if (spyTargets.length && 'IntersectionObserver' in window) {
        const spy = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                if (!entry.isIntersecting) continue;
                for (const link of spyLinks) link.classList.toggle('is-active', link.getAttribute('href') === '#' + entry.target.id);
            }
        }, { rootMargin: '-35% 0px -55% 0px' });
        spyTargets.forEach((target) => spy.observe(target));
    }

    // --- Tombol kirim menampilkan status memproses ---
    for (const form of document.querySelectorAll('form[data-loading-form]')) {
        form.addEventListener('submit', () => {
            const button = form.querySelector('[type="submit"]');
            if (!button || button.disabled) return;
            button.disabled = true;
            button.insertAdjacentHTML('afterbegin', '<span class="spinner" aria-hidden="true"></span>');
        });
    }

    // --- Asisten AI ---
    const assistant = document.querySelector('[data-assistant]');
    if (assistant) {
        const toggle = assistant.querySelector('[data-assistant-toggle]');
        const panel = assistant.querySelector('[data-assistant-panel]');
        const close = assistant.querySelector('[data-assistant-close]');
        const log = assistant.querySelector('[data-assistant-log]');
        const form = assistant.querySelector('[data-assistant-form]');
        const input = form.querySelector('textarea');
        const send = form.querySelector('button[type="submit"]');
        const suggest = assistant.querySelector('[data-assistant-suggest]');
        const history = [];

        const open = () => {
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            input.focus();
        };
        const shut = () => {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            toggle.focus();
        };
        toggle.addEventListener('click', open);
        close.addEventListener('click', shut);
        panel.addEventListener('keydown', (event) => { if (event.key === 'Escape') shut(); });

        // Format ringan: paragraf, daftar, tebal, dan tautan internal/https.
        const render = (text) => {
            const escape = (value) => value.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
            const inline = (value) => escape(value)
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\[([^\]]+)\]\(((?:https:\/\/|[a-z0-9-]+\.php)[^\s)]*)\)/gi, '<a href="$2">$1</a>');
            const blocks = text.trim().split(/\n{2,}/);
            return blocks.map((block) => {
                const lines = block.split('\n');
                if (lines.every((line) => /^\s*([-*•]|\d+\.)\s+/.test(line))) {
                    return '<ul>' + lines.map((line) => '<li>' + inline(line.replace(/^\s*([-*•]|\d+\.)\s+/, '')) + '</li>').join('') + '</ul>';
                }
                return '<p>' + lines.map(inline).join('<br>') + '</p>';
            }).join('');
        };
        const addMessage = (role, html) => {
            const bubble = document.createElement('div');
            bubble.className = 'assistant__msg assistant__msg--' + role;
            bubble.innerHTML = html;
            log.append(bubble);
            log.scrollTop = log.scrollHeight;
            return bubble;
        };

        const ask = async (question) => {
            if (suggest) suggest.remove();
            addMessage('user', '<p>' + question.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c]) + '</p>');
            history.push({ role: 'user', content: question });
            const typing = addMessage('bot', '<span class="assistant__typing"><span class="spinner" aria-hidden="true"></span>Mencari jawaban…</span>');
            send.disabled = true;
            input.disabled = true;
            try {
                const response = await fetch('api-tanya.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ messages: history.slice(-8) }),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || !data.answer) throw new Error(data.error || 'Asisten sedang tidak dapat menjawab. Coba lagi sebentar lagi.');
                typing.innerHTML = render(data.answer);
                history.push({ role: 'assistant', content: data.answer });
            } catch (error) {
                typing.classList.add('assistant__msg--error');
                typing.textContent = error.message;
                history.pop();
            } finally {
                send.disabled = false;
                input.disabled = false;
                input.focus();
                log.scrollTop = log.scrollHeight;
            }
        };

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const question = input.value.trim();
            if (!question || send.disabled) return;
            input.value = '';
            input.style.height = '';
            ask(question);
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                form.requestSubmit();
            }
        });
        input.addEventListener('input', () => {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 140) + 'px';
        });
        if (suggest) {
            suggest.addEventListener('click', (event) => {
                const button = event.target.closest('button');
                if (button) ask(button.textContent.trim());
            });
        }
    }
})();
