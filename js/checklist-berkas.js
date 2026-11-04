// Menyimpan setiap centang begitu berubah; bila gagal, centang dikembalikan agar tampilan sesuai data tersimpan.
(function () {
    const form = document.querySelector('[data-checklist]');
    const errorBox = document.querySelector('[data-save-error]');
    const doneText = document.querySelector('[data-progress-done]');
    const doneBar = document.querySelector('[data-progress-bar]');

    form.addEventListener('submit', (event) => event.preventDefault());
    form.addEventListener('change', async (event) => {
        const box = event.target;
        box.disabled = true;
        errorBox.hidden = true;
        try {
            const response = await fetch('checklist-berkas.php', {
                method: 'POST',
                body: new URLSearchParams({ t: form.dataset.token, berkas: box.name, dicentang: box.checked ? '1' : '0' }),
            });
            const result = await response.json();
            if (!response.ok || !result.ok) {
                throw new Error('save failed');
            }
            doneText.textContent = result.selesai;
            doneBar.value = result.selesai;
        } catch (error) {
            box.checked = !box.checked;
            errorBox.hidden = false;
        }
        box.disabled = false;
    });
})();
