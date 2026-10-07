/**
 * Halaman status: pratinjau nama berkas bukti, dan animasi progres ringan.
 */
(function () {
  "use strict";

  function formatBytes(bytes) {
    if (bytes < 1024) return bytes + " B";
    if (bytes < 1048576) return (bytes / 1024).toFixed(0) + " KB";
    return (bytes / 1048576).toFixed(2) + " MB";
  }

  for (const input of document.querySelectorAll(
    '.payment-upload input[type="file"]',
  )) {
    input.addEventListener("change", function () {
      const file = this.files && this.files[0];
      const label = this.closest(".payment-upload");
      if (!label) return;
      let nameField = label.querySelector("[data-file-name]");
      if (!nameField) {
        nameField = document.createElement("small");
        nameField.className = "field-hint";
        nameField.setAttribute("data-file-name", "");
        label.appendChild(nameField);
      }
      if (!file) {
        nameField.textContent = "";
        return;
      }
      const validType =
        /^image\/(jpeg|png)$/.test(file.type) ||
        file.type === "application/pdf";
      const validSize = file.size <= 5 * 1048576;
      nameField.textContent =
        file.name +
        " · " +
        formatBytes(file.size) +
        (validType && validSize ? "" : " · format atau ukuran tidak sesuai");
      nameField.classList.toggle("is-invalid", !(validType && validSize));
      label.classList.add("has-file");
    });
  }

  // Menyorot langkah yang sedang berjalan agar mata langsung menangkap.
  const currentStep = document.querySelector(".journey-step.is-current");
  if (currentStep && typeof currentStep.scrollIntoView === "function") {
    currentStep.classList.add("is-highlighted");
  }
})();
