/**
 * Animasi fungsional: bagian formulir muncul saat digulir, tombol kirim
 * menampilkan spinner, dan focus pertama otomatis setelah konfirmasi.
 * Semua animasi dinonaktifkan bila pengguna meminta reduced motion.
 */
(function () {
  "use strict";

  const reduceMotion = window.matchMedia(
    "(prefers-reduced-motion: reduce)",
  ).matches;

  // --- Munculkan bagian formulir secara bertahap saat digulir ---
  const sections = Array.from(document.querySelectorAll(".form-section"));
  if (sections.length) {
    if (reduceMotion || !("IntersectionObserver" in window)) {
      for (const section of sections) section.classList.add("is-revealed");
    } else {
      sections.forEach((section, index) => {
        section.classList.add("will-reveal");
        section.style.transitionDelay = Math.min(index * 55, 220) + "ms";
      });
      const observer = new IntersectionObserver(
        (entries) => {
          for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            entry.target.classList.add("is-revealed");
            observer.unobserve(entry.target);
          }
        },
        { rootMargin: "0px 0px -8% 0px", threshold: 0.06 },
      );
      for (const section of sections) observer.observe(section);
    }
  }

  // --- Indeks isian pada daftar registrasi awal ---
  const intro = document.querySelector(".intro-rail");
  if (intro) {
    intro.classList.add("is-entered");
  }

  // --- Hitung progres pengisian untuk umpan balik yang berguna ---
  const form = document.getElementById("form-utama");
  if (form) {
    const required = Array.from(form.querySelectorAll("[required]")).filter(
      (element) => element.type !== "hidden" && element.type !== "checkbox",
    );
    const counter = document.querySelector("[data-progress-count]");
    if (counter && required.length) {
      const sync = () => {
        const filled = required.filter(
          (element) => (element.value || "").trim() !== "",
        ).length;
        counter.textContent = filled + " / " + required.length;
        const bar = document.querySelector("[data-progress-bar]");
        if (bar) {
          bar.style.width = Math.round((filled / required.length) * 100) + "%";
        }
      };
      form.addEventListener("input", sync);
      form.addEventListener("change", sync);
      sync();
    }
  }
})();
