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
        " setelah Anda menerima tautan pembayaran dari panitia."
      : "Informasi pembayaran dikirim setelah Anda dihubungi panitia.";
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
        "Pilih unit dan tahun ajaran serta isi tanggal lahir untuk melihat umur pada tanggal acuan Dapodik.";
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

  if (externalRoute) {
    externalRoute.addEventListener("change", updateDapodikAge);
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

  const form = document.getElementById("form-utama");
  if (form) {
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
      firstInvalid.focus({ preventScroll: true });
      firstInvalid.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  }
})();
