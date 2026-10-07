/**
 * Rich-text controls and questionnaire authoring for the admin materials page.
 */
(function () {
  "use strict";

  for (const form of document.querySelectorAll("[data-delete-material-form]")) {
    form.addEventListener("submit", function (event) {
      const material = this.dataset.materialLabel || "materi";
      const unit = this.dataset.unitLabel || "unit";
      const year = this.dataset.academicYear || "";
      if (!window.confirm("Hapus file asli dan pratinjau " + material + " untuk " + unit + " · " + year + "? Isi materi dan kuisioner tidak akan dihapus.")) {
        event.preventDefault();
      }
    });
  }

  for (const editor of document.querySelectorAll("[data-rich-editor]")) {
    const panel = editor.closest(".material-edit-panel");
    const source = panel && panel.querySelector("[data-rich-source]");
    if (!source) continue;
    for (const button of panel.querySelectorAll("[data-format]")) {
      button.addEventListener("click", function () {
        editor.focus();
        const command = this.dataset.format;
        if (command === "createLink") {
          const url = window.prompt("Masukkan alamat tautan (https:// atau mailto:):");
          if (!url || !/^(https?:\/\/|mailto:)/i.test(url)) return;
          document.execCommand(command, false, url);
        } else {
          document.execCommand(command, false, this.dataset.value || null);
        }
        source.value = editor.innerHTML;
      });
    }
    editor.addEventListener("input", function () {
      source.value = editor.innerHTML;
    });
  }

  const list = document.querySelector("[data-question-list]");
  const template = document.getElementById("question-editor-template");
  const addButton = document.querySelector("[data-add-question]");
  const requireAllButton = document.querySelector("[data-require-all-questions]");
  if (!list || !template || !addButton) return;

  function syncRequireAllButton() {
    if (!requireAllButton) return;
    const checkboxes = Array.from(
      list.querySelectorAll(".question-required input[type='checkbox']"),
    );
    requireAllButton.setAttribute(
      "aria-pressed",
      String(checkboxes.length > 0 && checkboxes.every((checkbox) => checkbox.checked)),
    );
  }

  function updateQuestionRows() {
    const rows = Array.from(list.querySelectorAll("[data-question-row]"));
    rows.forEach(function (row, index) {
      const number = row.querySelector("[data-question-number]");
      if (number) number.textContent = String(index + 1);
      const type = row.querySelector("[data-question-type]");
      const choices = row.querySelector(".question-choices");
      if (choices) choices.hidden = type && type.value === "text";
      row.querySelectorAll("[data-question-name]").forEach(function (field) {
        const name = field.dataset.questionName;
        field.name = "questions[" + index + "][" + name + "]";
        if (name === "key" && !field.value) field.value = "q" + index;
      });
    });
    if (!rows.length) addQuestion();
    syncRequireAllButton();
  }

  function addQuestion() {
    const fragment = template.content.cloneNode(true);
    const row = fragment.querySelector("[data-question-row]");
    const index = list.querySelectorAll("[data-question-row]").length;
    row.querySelector('[data-question-name="key"]').value = "q" + index;
    row.querySelector("[data-question-number]").textContent = String(index + 1);
    list.appendChild(fragment);
    updateQuestionRows();
  }

  addButton.addEventListener("click", addQuestion);
  if (requireAllButton) {
    requireAllButton.addEventListener("click", function () {
      for (const checkbox of list.querySelectorAll(
        ".question-required input[type='checkbox']",
      )) {
        checkbox.checked = true;
      }
      syncRequireAllButton();
    });
  }
  list.addEventListener("change", function (event) {
    if (event.target.matches("[data-question-type]")) {
      updateQuestionRows();
    } else if (event.target.matches(".question-required input[type='checkbox']")) {
      syncRequireAllButton();
    }
  });
  list.addEventListener("click", function (event) {
    const remove = event.target.closest("[data-remove-question]");
    if (remove) {
      remove.closest("[data-question-row]").remove();
      updateQuestionRows();
    }
  });
  updateQuestionRows();
})();
