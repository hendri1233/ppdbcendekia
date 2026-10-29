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
  const unrequireAllButton = document.querySelector("[data-unrequire-all-questions]");
  if (!list || !template || !addButton) return;

  function requiredCheckboxes() {
    return Array.from(
      list.querySelectorAll(".question-required input[type='checkbox']"),
    );
  }

  function syncRequireAllButton() {
    const checkboxes = requiredCheckboxes();
    if (requireAllButton) {
      requireAllButton.setAttribute(
        "aria-pressed",
        String(checkboxes.length > 0 && checkboxes.every((checkbox) => checkbox.checked)),
      );
    }
    if (unrequireAllButton) {
      unrequireAllButton.disabled = !checkboxes.some((checkbox) => checkbox.checked);
    }
  }

  function setQuestionExpanded(row, expanded) {
    const toggle = row.querySelector("[data-toggle-question]");
    const body = row.querySelector("[data-question-body]");
    if (!toggle || !body) return;
    toggle.setAttribute("aria-expanded", String(expanded));
    body.hidden = !expanded;
    row.classList.toggle("is-expanded", expanded);
  }

  function updateQuestionRows() {
    const rows = Array.from(list.querySelectorAll("[data-question-row]"));
    rows.forEach(function (row, index) {
      const number = row.querySelector("[data-question-number]");
      if (number) number.textContent = String(index + 1);
      const toggle = row.querySelector("[data-toggle-question]");
      const body = row.querySelector("[data-question-body]");
      const prompt = row.querySelector('[data-question-name="prompt"]');
      const summary = row.querySelector("[data-question-summary]");
      if (body) body.id = "question-body-" + index;
      if (toggle && body) toggle.setAttribute("aria-controls", body.id);
      if (summary) summary.textContent = prompt && prompt.value.trim() ? prompt.value.trim() : "Belum ada pernyataan";
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
    setQuestionExpanded(list.lastElementChild, true);
    updateQuestionRows();
  }

  addButton.addEventListener("click", addQuestion);
  if (requireAllButton) {
    requireAllButton.addEventListener("click", function () {
      for (const checkbox of requiredCheckboxes()) {
        checkbox.checked = true;
      }
      syncRequireAllButton();
    });
  }
  if (unrequireAllButton) {
    unrequireAllButton.addEventListener("click", function () {
      for (const checkbox of requiredCheckboxes()) {
        checkbox.checked = false;
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
  list.addEventListener("input", function (event) {
    if (event.target.matches('[data-question-name="prompt"]')) {
      const row = event.target.closest("[data-question-row]");
      const summary = row && row.querySelector("[data-question-summary]");
      if (summary) summary.textContent = event.target.value.trim() || "Belum ada pernyataan";
    }
  });
  list.addEventListener("click", function (event) {
    const toggle = event.target.closest("[data-toggle-question]");
    if (toggle) {
      const row = toggle.closest("[data-question-row]");
      setQuestionExpanded(row, toggle.getAttribute("aria-expanded") !== "true");
      return;
    }
    const remove = event.target.closest("[data-remove-question]");
    if (remove) {
      remove.closest("[data-question-row]").remove();
      updateQuestionRows();
    }
  });
  list.querySelectorAll("[data-question-row]").forEach(function (row) {
    setQuestionExpanded(row, false);
  });
  updateQuestionRows();
})();
