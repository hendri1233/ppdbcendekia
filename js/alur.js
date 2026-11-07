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
    // Tombol tutup jendela (method="dialog") tidak meninggalkan halaman.
    if (!event.defaultPrevented && event.target.getAttribute("method") !== "dialog") show();
  });

  document.addEventListener("click", function (event) {
    const link = event.target.closest("a[href]");
    if (!link || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey) return;
    if (link.target === "_blank" || link.hasAttribute("download")) return;
    const url = new URL(link.href, window.location.href);
    const samePageAnchor = url.pathname === window.location.pathname && url.hash !== "";
    // PDF diunduh tanpa meninggalkan halaman, jadi garisnya tidak perlu tampil.
    if (url.origin !== window.location.origin || samePageAnchor || url.pathname.endsWith("cetak-bukti")) return;
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

  // Pemberitahuan batas pengisian formulir: tampil saat halaman dibuka, dengan hitung mundur.
  const deadlineNotice = document.querySelector("[data-deadline-notice]");
  if (deadlineNotice && typeof deadlineNotice.showModal === "function") {
    const deadlineAt = new Date(deadlineNotice.dataset.deadlineAt).getTime();
    const countdown = deadlineNotice.querySelector("[data-deadline-countdown]");
    const rememberKey = deadlineNotice.dataset.deadlineRemember || "";
    let seen = false;
    try {
      seen = rememberKey !== "" && window.sessionStorage.getItem(rememberKey) === "1";
    } catch (error) {
      seen = false;
    }
    const tick = function () {
      const left = Math.max(0, deadlineAt - Date.now());
      const seconds = Math.floor(left / 1000);
      const minutes = Math.floor(seconds / 60);
      countdown.querySelector('[data-unit="hari"]').textContent = String(Math.floor(minutes / 1440));
      countdown.querySelector('[data-unit="jam"]').textContent = String(Math.floor((minutes % 1440) / 60)).padStart(2, "0");
      countdown.querySelector('[data-unit="menit"]').textContent = String(minutes % 60).padStart(2, "0");
      const secondField = countdown.querySelector('[data-unit="detik"]');
      secondField.textContent = String(seconds % 60).padStart(2, "0");
      // Denyut halus setiap detik; animasi diulang dengan melepas lalu memasang kelasnya.
      const box = secondField.parentElement;
      box.classList.remove("is-tick");
      void box.offsetWidth;
      if (left > 0) box.classList.add("is-tick");
    };
    if (countdown && !Number.isNaN(deadlineAt)) {
      countdown.hidden = false;
      tick();
      window.setInterval(tick, 1000);
    }
    if (!seen) {
      window.setTimeout(function () {
        if (!document.querySelector("dialog[open]")) deadlineNotice.showModal();
      }, 450);
    }
    deadlineNotice.addEventListener("close", function () {
      try {
        if (rememberKey !== "") window.sessionStorage.setItem(rememberKey, "1");
      } catch (error) {
        // Penyimpanan sesi tidak tersedia: popup muncul lagi saat halaman dibuka ulang.
      }
    });
    // Klik di luar kotak menutup pemberitahuan.
    deadlineNotice.addEventListener("click", function (event) {
      if (event.target === deadlineNotice) deadlineNotice.close();
    });
  }

  // Halaman yang dibuka kembali lewat tombol kembali tidak boleh tetap menampilkan garis.
  window.addEventListener("pageshow", function () {
    bar.classList.remove("is-active");
  });
})();
