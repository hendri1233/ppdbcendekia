// Isian jalur prestasi oleh wali murid (data-prestasi): kategori dibuka bergiliran
// seperti pada halaman pendaftaran, dan isian wajib mengikuti jawaban "Ada"/"Tidak ada".
(function () {
  "use strict";
  const section = document.querySelector("[data-prestasi-section]");
  if (!section) return;
  const categories = Array.from(section.querySelectorAll("[data-prestasi-category]"));

  function categoryComplete(category) {
    return Array.from(category.querySelectorAll("input, textarea")).every(function (control) {
      return control.disabled || control.validity.valid;
    });
  }
  function firstIncomplete() {
    return categories.find(function (category) { return !categoryComplete(category); });
  }
  function openCategory(target) {
    for (const category of categories) category.open = category === target;
  }
  function update() {
    for (const block of section.querySelectorAll("[data-prestasi-when]")) {
      const checked = section.querySelector('input[name="' + block.dataset.prestasiWhen + '"]:checked');
      block.hidden = !checked || checked.value !== block.dataset.prestasiIs;
    }
    for (const control of section.querySelectorAll("input, textarea")) {
      const shown = !control.closest("[data-prestasi-when][hidden]");
      control.disabled = !shown;
      control.required = shown && control.hasAttribute("data-prestasi-required");
    }
    let locked = false;
    for (const category of categories) {
      const complete = categoryComplete(category);
      category.querySelector("[data-prestasi-status]").textContent = locked ? "Terkunci" : complete ? "Sudah diisi" : "Belum diisi";
      category.classList.toggle("is-complete", !locked && complete);
      category.classList.toggle("is-locked", locked);
      if (locked) category.open = false;
      if (!complete) locked = true;
    }
  }

  section.addEventListener("input", update);
  section.addEventListener("change", function (event) {
    update();
    const category = event.target.closest("[data-prestasi-category]");
    if (!category || !category.open || !categoryComplete(category)) return;
    // Beri jeda agar pilihan terlihat sebelum lanjut ke kategori berikutnya.
    window.setTimeout(function () {
      if (!category.open || !categoryComplete(category)) return;
      const next = firstIncomplete();
      openCategory(next || null);
      if (next) next.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }, 420);
  });
  for (const category of categories) {
    category.querySelector("summary").addEventListener("click", function (event) {
      if (!category.classList.contains("is-locked")) return;
      event.preventDefault();
      category.classList.remove("is-shake");
      void category.offsetWidth;
      category.classList.add("is-shake");
    });
    category.addEventListener("toggle", function () {
      if (category.open) openCategory(category);
    });
  }
  section.addEventListener("invalid", function () {
    const first = firstIncomplete();
    if (first && !first.open) openCategory(first);
  }, true);

  update();
  openCategory(firstIncomplete() || null);
})();
