// Garis kemajuan saat berpindah halaman pada alur pendaftaran. Tidak mengubah kiriman formulir.
(function () {
  "use strict";

  const bar = document.createElement("div");
  bar.className = "flow-progress";
  bar.setAttribute("aria-hidden", "true");
  document.body.appendChild(bar);

  function show() {
    bar.classList.add("is-active");
  }

  document.addEventListener("submit", function (event) {
    if (!event.defaultPrevented) show();
  });

  document.addEventListener("click", function (event) {
    const link = event.target.closest("a[href]");
    if (!link || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey) return;
    if (link.target === "_blank" || link.hasAttribute("download")) return;
    const url = new URL(link.href, window.location.href);
    const samePageAnchor = url.pathname === window.location.pathname && url.hash !== "";
    // PDF diunduh tanpa meninggalkan halaman, jadi garisnya tidak perlu tampil.
    if (url.origin !== window.location.origin || samePageAnchor || url.pathname.endsWith("cetak-bukti.php")) return;
    show();
  });

  // Salin nominal, nomor rekening, dan kode tanpa memilih teks secara manual.
  document.addEventListener("click", function (event) {
    const button = event.target.closest("[data-copy]");
    if (!button || !navigator.clipboard) return;
    navigator.clipboard.writeText(button.dataset.copy).then(function () {
      const label = button.querySelector("span");
      label.textContent = "Tersalin";
      button.classList.add("is-copied");
      window.setTimeout(function () {
        label.textContent = "Salin";
        button.classList.remove("is-copied");
      }, 1600);
    });
  });

  // Halaman yang dibuka kembali lewat tombol kembali tidak boleh tetap menampilkan garis.
  window.addEventListener("pageshow", function () {
    bar.classList.remove("is-active");
  });
})();
