/**
 * Interaksi formulir registrasi awal.
 * Bertangent pada elemen yang selalu ada di daftar.php.
 */
(function () {
  "use strict";

  const yearSelect = document.getElementById("th_ajaran");
  const unitSelect = document.getElementById("kode_unit");
  const offerSummary = document.getElementById("offer-summary");

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

  if (yearSelect && unitSelect) {
    yearSelect.addEventListener("change", updateOpenOffers);
    unitSelect.addEventListener("change", updateOpenOffers);
    updateOpenOffers();
  }

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
