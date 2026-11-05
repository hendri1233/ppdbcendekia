/**
 * Interaksi formulir registrasi awal.
 * Bertangent pada elemen yang selalu ada di daftar.php.
 */
(function () {
  "use strict";

  const yearSelect = document.getElementById("th_ajaran");
  const unitSelect = document.getElementById("kode_unit");
  const offerSummary = document.getElementById("offer-summary");
  const externalRoute = document.getElementById("external_route");
  const birthDateInput = document.getElementById("tanggal_lahir");
  const dapodikAgeOutput = document.querySelector("[data-dapodik-age]");
  const unavailableDialog = document.querySelector("[data-unavailable-dialog]");

  // Unit yang belum dibuka, sudah ditutup, atau penuh tetap tampil di pilihan,
  // tetapi saat dipilih memunculkan popup dan pilihannya dikosongkan kembali.
  // Didaftarkan paling awal agar pemicu lain (brosur, jenjang TK) melihat pilihan kosong.
  function checkUnitAvailability() {
    if (!externalRoute) return;
    const option = externalRoute.selectedOptions[0];
    if (!option || !option.dataset.unavailable) return;
    const message = option.dataset.unavailableMessage || "Pendaftaran unit ini belum dibuka.";
    externalRoute.value = "";
    if (!unavailableDialog || typeof unavailableDialog.showModal !== "function") {
      window.alert(message);
      return;
    }
    const titles = { penuh: "Pendaftaran sudah penuh", ditutup: "Pendaftaran sudah ditutup" };
    unavailableDialog.querySelector("[data-unavailable-title]").textContent =
      titles[option.dataset.unavailable] || "Pendaftaran belum dibuka";
    unavailableDialog.querySelector("[data-unavailable-message]").textContent = message;
    unavailableDialog.dataset.state = option.dataset.unavailable;
    // Persiapan berkas hanya relevan untuk SMP yang belum dibuka (surat jalur prestasi).
    const prepare = unavailableDialog.querySelector("[data-unavailable-prepare]");
    if (prepare) prepare.hidden = !(option.dataset.unavailable === "belum" && option.dataset.jenjang === "SMP");
    unavailableDialog.showModal();
  }
  if (externalRoute) {
    externalRoute.addEventListener("change", checkUnitAvailability);
    checkUnitAvailability();
  }
  if (unavailableDialog) {
    unavailableDialog.querySelector("[data-unavailable-close]").addEventListener("click", function () {
      unavailableDialog.close();
    });
    // Klik di luar kartu (latar) juga menutup popup.
    unavailableDialog.addEventListener("click", function (event) {
      if (event.target === unavailableDialog) unavailableDialog.close();
    });
    unavailableDialog.addEventListener("close", function () {
      const picker = document.querySelector('[data-picker-for="external_route"]');
      if (picker) picker.focus();
      else if (externalRoute) externalRoute.focus();
    });
  }

  function rupiah(value) {
    return new Intl.NumberFormat("id-ID").format(value);
  }

  function updateOpenOffers() {
    if (!yearSelect || !unitSelect || !offerSummary) return;
    const selectedYear = yearSelect.value;
    let selectedOptionIsVisible = false;
    for (const option of unitSelect.options) {
      if (!option.value) continue;
      const visible = option.dataset.year === selectedYear;
      option.hidden = !visible;
      option.disabled = !visible;
      if (visible && option.selected) selectedOptionIsVisible = true;
    }
    if (!selectedOptionIsVisible) unitSelect.value = "";

    const option = unitSelect.selectedOptions[0];
    if (!option || !option.value) {
      offerSummary.hidden = true;
      offerSummary.replaceChildren();
      return;
    }

    const fee = Number(option.dataset.fee || 0);
    const bankDetails = [
      option.dataset.bank,
      option.dataset.account,
      option.dataset.accountName,
    ]
      .filter(Boolean)
      .join(" · ");
    const feeText =
      fee > 0
        ? "Biaya pendaftaran Rp " + rupiah(fee)
        : "Biaya pendaftaran tidak dipungut";

    offerSummary.replaceChildren();
    const feeLine = document.createElement("strong");
    feeLine.textContent = feeText;
    const noteLine = document.createElement("span");
    noteLine.textContent = bankDetails
      ? "Transfer ke " +
        bankDetails +
        " setelah registrasi awal."
      : "Informasi pembayaran tampil setelah registrasi awal.";
    offerSummary.append(feeLine, noteLine);
    offerSummary.hidden = false;
    offerSummary.classList.remove("is-flash");
    void offerSummary.offsetWidth;
    offerSummary.classList.add("is-flash");
  }

  function updateDapodikAge() {
    if (!birthDateInput || !dapodikAgeOutput) return;
    const academicYear = externalRoute
      ? externalRoute.value
        ? externalRoute.value.split("|")[1]
        : ""
      : document.body.dataset.academicYear;
    const yearMatch = /^([0-9]{4})\/[0-9]{4}$/.exec(academicYear || "");
    const birthMatch = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(
      birthDateInput.value,
    );
    if (!yearMatch || !birthMatch) {
      dapodikAgeOutput.textContent =
        "Pilih unit serta isi tanggal lahir untuk melihat umur pada tanggal acuan Dapodik.";
      return;
    }

    const birth = new Date(
      Date.UTC(+birthMatch[1], +birthMatch[2] - 1, +birthMatch[3]),
    );
    if (
      birth.getUTCFullYear() !== +birthMatch[1] ||
      birth.getUTCMonth() !== +birthMatch[2] - 1 ||
      birth.getUTCDate() !== +birthMatch[3]
    ) {
      dapodikAgeOutput.textContent = "Tanggal lahir tidak valid.";
      return;
    }

    const cutoffYear = +yearMatch[1];
    const cutoff = new Date(Date.UTC(cutoffYear, 5, 30));
    const referenceDate =
      "30/06/" + String(cutoffYear);
    if (birth > cutoff) {
      dapodikAgeOutput.textContent =
        "Tanggal lahir setelah tanggal acuan " + referenceDate + ".";
      return;
    }

    let years = cutoff.getUTCFullYear() - birth.getUTCFullYear();
    let months = cutoff.getUTCMonth() - birth.getUTCMonth();
    let days = cutoff.getUTCDate() - birth.getUTCDate();
    if (days < 0) {
      months -= 1;
      days += new Date(
        Date.UTC(cutoff.getUTCFullYear(), cutoff.getUTCMonth(), 0),
      ).getUTCDate();
    }
    if (months < 0) {
      years -= 1;
      months += 12;
    }
    dapodikAgeOutput.textContent =
      "Umur ananda pada " +
      referenceDate +
      ": " +
      years +
      " tahun, " +
      months +
      " bulan, " +
      days +
      " hari.";
  }

  if (yearSelect && unitSelect) {
    yearSelect.addEventListener("change", updateOpenOffers);
    unitSelect.addEventListener("change", updateOpenOffers);
    updateOpenOffers();
  }

  // Unit TK meminta jenjang tujuan (TK A / TK B) di samping pilihan unit.
  const tkLevelField = document.querySelector("[data-tk-level]");
  const unitField = document.querySelector("[data-unit-field]");
  function updateTkLevel() {
    if (!externalRoute || !tkLevelField) return;
    const option = externalRoute.selectedOptions[0];
    const isTk = Boolean(option && option.dataset.jenjang === "TK");
    const levelSelect = tkLevelField.querySelector("select");
    tkLevelField.hidden = !isTk;
    levelSelect.disabled = !isTk;
    levelSelect.required = isTk;
    if (!isTk) levelSelect.value = "";
    if (unitField) unitField.classList.toggle("field-wide", !isTk);
  }

  // Unit SMP: pilihan jenis kelamin menampilkan sisa kuota masing-masing.
  const genderSelect = document.getElementById("jenis_kelamin");
  const genderRoute = document.querySelector("[data-gender-route]");
  function updateGenderOptions() {
    if (!genderSelect) return;
    const option = externalRoute ? externalRoute.selectedOptions[0] : null;
    const source = option ? option.dataset.gender : genderRoute ? genderRoute.dataset.genderRoute : "";
    const availability = source ? JSON.parse(source) : null;
    for (const choice of genderSelect.options) {
      if (!choice.value) continue;
      choice.dataset.label = choice.dataset.label || choice.textContent;
      const info = availability ? availability[choice.value] : null;
      const full = Boolean(info && info.left < 1);
      choice.disabled = full;
      choice.textContent = choice.dataset.label + (full ? " (kuota penuh)" : info ? " (sisa " + info.left + ")" : "");
      if (full && choice.selected) genderSelect.value = "";
    }
  }
  updateGenderOptions();

  if (externalRoute) {
    externalRoute.addEventListener("change", updateDapodikAge);
    externalRoute.addEventListener("change", updateTkLevel);
    externalRoute.addEventListener("change", updateGenderOptions);
    updateTkLevel();
  }
  if (birthDateInput) {
    birthDateInput.addEventListener("input", updateDapodikAge);
    birthDateInput.addEventListener("change", updateDapodikAge);
  }
  updateDapodikAge();

  // Nomor telepon Indonesia: 0 -> 62 dinormalkan, maksimal 13 digit.
  for (const input of document.querySelectorAll('input[type="tel"]')) {
    const sync = function () {
      let digits = this.value.replace(/\D+/g, "");
      if (digits.indexOf("62") === 0) digits = "0" + digits.slice(2);
      this.value = digits.slice(0, 13);
      const field = this.closest(".field");
      if (!field) return;
      if (this.value === "") {
        field.classList.remove("is-valid", "is-invalid");
      } else {
        field.classList.toggle(
          "is-valid",
          /^(?:\+62|62|0)8[0-9]{8,11}$/.test(this.value),
        );
        field.classList.toggle(
          "is-invalid",
          !/^(?:\+62|62|0)8[0-9]{8,11}$/.test(this.value),
        );
      }
    };
    input.addEventListener("input", sync);
    input.addEventListener("blur", sync);
    if (input.value !== "") sync.call(input);
  }

  // Unit SMP: pilih jalur dulu sebelum isian data awal; jalur prestasi membuka isian tambahan.
  const jalurField = document.querySelector("[data-jalur-field]");
  const prestasiSection = document.querySelector("[data-prestasi-section]");
  const initialFields = document.querySelector("[data-initial-fields]");
  const prestasiCategories = prestasiSection ? Array.from(prestasiSection.querySelectorAll("[data-prestasi-category]")) : [];
  function updatePrestasiConditions() {
    if (!prestasiSection) return;
    const active = !prestasiSection.hidden;
    const blocks = prestasiSection.querySelectorAll("[data-prestasi-when]");
    for (const block of blocks) {
      const checked = prestasiSection.querySelector('input[name="' + block.dataset.prestasiWhen + '"]:checked');
      block.hidden = !checked || checked.value !== block.dataset.prestasiIs;
    }
    for (const control of prestasiSection.querySelectorAll("input, textarea")) {
      const shown = active && !control.closest("[data-prestasi-when][hidden]");
      control.disabled = !shown;
      control.required = shown && control.hasAttribute("data-prestasi-required");
    }
    // Kategori terbuka bergiliran: yang berikutnya terkunci sampai kategori sebelumnya lengkap.
    let locked = false;
    for (const category of prestasiCategories) {
      const complete = categoryComplete(category);
      const status = category.querySelector("[data-prestasi-status]");
      status.textContent = locked ? "Terkunci" : complete ? "Sudah diisi" : "Belum diisi";
      category.classList.toggle("is-complete", !locked && complete);
      category.classList.toggle("is-locked", locked);
      if (locked) category.open = false;
      if (!complete) locked = true;
    }
  }
  function categoryComplete(category) {
    return Array.from(category.querySelectorAll("input, textarea")).every(function (control) {
      return control.disabled || control.validity.valid;
    });
  }
  function openCategory(target) {
    for (const category of prestasiCategories) category.open = category === target;
  }
  function firstIncompleteCategory() {
    return prestasiCategories.find(function (category) {
      return !categoryComplete(category);
    });
  }
  function updateJalur() {
    if (!jalurField) return;
    // Formulir internal tidak punya pilihan unit; isian jalur hanya dirender untuk kode unit SMP.
    const option = externalRoute ? externalRoute.selectedOptions[0] : null;
    const isSmp = externalRoute ? Boolean(option && option.dataset.jenjang === "SMP") : true;
    const radios = jalurField.querySelectorAll('input[name="jalur_seleksi"]');
    jalurField.hidden = !isSmp;
    let selected = "";
    for (const radio of radios) {
      radio.disabled = !isSmp;
      radio.required = isSmp;
      if (!isSmp) radio.checked = false;
      if (radio.checked) selected = radio.value;
    }
    if (initialFields) initialFields.hidden = isSmp && selected === "";
    if (prestasiSection) prestasiSection.hidden = !(isSmp && selected === "prestasi");
    updatePrestasiConditions();
    if (prestasiSection && !prestasiSection.hidden && !prestasiCategories.some(function (category) { return category.open; })) {
      openCategory(firstIncompleteCategory() || null);
    }
  }
  if (jalurField) {
    jalurField.addEventListener("change", updateJalur);
    if (externalRoute) externalRoute.addEventListener("change", updateJalur);
    if (prestasiSection) {
      prestasiSection.addEventListener("input", updatePrestasiConditions);
      prestasiSection.addEventListener("change", function (event) {
        updatePrestasiConditions();
        const category = event.target.closest("[data-prestasi-category]");
        if (!category || !category.open || !categoryComplete(category)) return;
        // Beri jeda agar pilihan terlihat sebelum lanjut ke kategori berikutnya.
        window.setTimeout(function () {
          if (!category.open || !categoryComplete(category)) return;
          const next = firstIncompleteCategory();
          openCategory(next || null);
          if (next) next.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }, 420);
      });
      for (const category of prestasiCategories) {
        category.querySelector("summary").addEventListener("click", function (event) {
          if (category.classList.contains("is-locked")) {
            event.preventDefault();
            category.classList.remove("is-shake");
            void category.offsetWidth;
            category.classList.add("is-shake");
          }
        });
        category.addEventListener("toggle", function () {
          if (category.open) openCategory(category);
        });
      }
      // Kategori pertama yang belum lengkap dibuka agar isian wajibnya terlihat saat dikirim.
      prestasiSection.addEventListener("invalid", function () {
        const first = firstIncompleteCategory();
        if (first && !first.open) openCategory(first);
      }, true);
    }
    updateJalur();
  }

  // Tandai isian yang sudah terisi agar progres terlihat saat menggulir.
  for (const field of document.querySelectorAll(".field[data-field]")) {
    const control = field.querySelector("input, select");
    if (!control) continue;
    const sync = function () {
      field.classList.toggle("is-filled", this.value !== "");
    };
    control.addEventListener("input", sync);
    control.addEventListener("change", sync);
    sync.call(control);
  }

  // Formulir lengkap bertahap (formulir.php) memvalidasi sendiri di js/formulir.js,
  // karena validasi bawaan ikut memeriksa isian bersyarat yang sedang tersembunyi.
  const form = document.getElementById("form-utama");
  if (form && !form.hasAttribute("data-current-step")) {
    form.addEventListener("submit", function (event) {
      if (form.checkValidity()) {
        const button = form.querySelector(".submit-button");
        if (button) {
          button.disabled = true;
          button.classList.add("is-busy");
        }
        return;
      }
      event.preventDefault();
      form.reportValidity();
      const firstInvalid = form.querySelector(":invalid");
      if (!firstInvalid) return;
      const wrapper = firstInvalid.closest(".field");
      if (wrapper) wrapper.classList.add("is-invalid");
      // Pilihan unit tampil sebagai daftar khusus (js/unit-picker.js); fokuskan tombolnya.
      const focusTarget = document.querySelector('[data-picker-for="' + firstInvalid.id + '"]') || firstInvalid;
      focusTarget.focus({ preventScroll: true });
      focusTarget.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  }
})();
