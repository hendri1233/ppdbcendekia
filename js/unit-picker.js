/**
 * Pilihan unit pendidikan (daftar.php) sebagai daftar khusus agar pilihan
 * selalu berada di dalam kotak isian, termasuk di layar HP. Elemen <select>
 * asli tetap dipakai untuk pengiriman formulir dan pemicu lain (popup unit,
 * brosur, jenjang TK); daftar ini hanya mengubah nilainya lalu memicu "change".
 */
(function () {
  "use strict";

  const select = document.getElementById("external_route");
  if (!select) return;
  const field = select.closest(".field");
  const label = field ? field.querySelector('label[for="external_route"]') : null;

  const statusLabels = {
    belum: "Belum dibuka",
    ditutup: "Ditutup",
    penuh: "Penuh",
  };

  const picker = document.createElement("div");
  picker.className = "unit-picker";

  const trigger = document.createElement("button");
  trigger.type = "button";
  trigger.id = "external_route-picker";
  trigger.className = "unit-picker__trigger";
  trigger.dataset.pickerFor = select.id;
  trigger.setAttribute("aria-haspopup", "listbox");
  trigger.setAttribute("aria-expanded", "false");
  const valueText = document.createElement("span");
  valueText.className = "unit-picker__value";
  trigger.append(valueText);
  trigger.insertAdjacentHTML(
    "beforeend",
    '<svg class="unit-picker__chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
  );

  const list = document.createElement("ul");
  list.className = "unit-picker__list";
  list.id = "external_route-listbox";
  list.setAttribute("role", "listbox");
  list.tabIndex = -1;
  list.hidden = true;
  trigger.setAttribute("aria-controls", list.id);
  if (label) {
    label.id = label.id || "external_route-label";
    label.htmlFor = trigger.id;
    list.setAttribute("aria-labelledby", label.id);
  }

  const items = [];
  Array.from(select.options).forEach(function (option, index) {
    if (!option.value) return;
    const item = document.createElement("li");
    item.id = "external_route-option-" + index;
    item.className = "unit-picker__option";
    item.setAttribute("role", "option");
    item.dataset.value = option.value;
    const name = document.createElement("span");
    name.className = "unit-picker__name";
    name.textContent = option.dataset.name || option.textContent.trim();
    item.append(name);
    const state = option.dataset.unavailable;
    const badge = document.createElement("span");
    badge.className = "unit-picker__badge is-" + (state || "dibuka");
    badge.textContent = statusLabels[state] || "Dibuka";
    item.append(badge);
    list.append(item);
    items.push(item);
  });

  picker.append(trigger, list);
  select.after(picker);
  select.classList.add("unit-picker__native");
  select.tabIndex = -1;
  select.setAttribute("aria-hidden", "true");
  if (field) field.classList.add("has-unit-picker");

  let activeIndex = -1;

  function sync() {
    const option = select.selectedOptions[0];
    const chosen = option && option.value ? option : null;
    valueText.textContent = chosen ? chosen.dataset.name || chosen.textContent.trim() : "Pilih unit";
    valueText.classList.toggle("is-placeholder", !chosen);
    trigger.disabled = select.disabled;
    items.forEach(function (item) {
      item.setAttribute("aria-selected", chosen && item.dataset.value === chosen.value ? "true" : "false");
    });
  }

  function setActive(index) {
    if (!items.length) return;
    activeIndex = (index + items.length) % items.length;
    items.forEach(function (item, itemIndex) {
      item.classList.toggle("is-active", itemIndex === activeIndex);
    });
    list.setAttribute("aria-activedescendant", items[activeIndex].id);
    items[activeIndex].scrollIntoView({ block: "nearest" });
  }

  function open() {
    if (trigger.disabled || !list.hidden) return;
    list.hidden = false;
    picker.classList.add("is-open");
    trigger.setAttribute("aria-expanded", "true");
    const selectedIndex = items.findIndex(function (item) {
      return item.dataset.value === select.value;
    });
    setActive(selectedIndex >= 0 ? selectedIndex : 0);
    list.focus({ preventScroll: true });
  }

  function close(returnFocus) {
    if (list.hidden) return;
    list.hidden = true;
    picker.classList.remove("is-open");
    trigger.setAttribute("aria-expanded", "false");
    if (returnFocus) trigger.focus({ preventScroll: true });
  }

  function choose(item) {
    close(true);
    if (field) field.classList.remove("is-invalid");
    if (select.value !== item.dataset.value) {
      select.value = item.dataset.value;
      select.dispatchEvent(new Event("change", { bubbles: true }));
    }
    sync();
  }

  trigger.addEventListener("click", function () {
    if (list.hidden) open();
    else close(true);
  });
  trigger.addEventListener("keydown", function (event) {
    if (["ArrowDown", "ArrowUp", "Enter", " "].includes(event.key)) {
      event.preventDefault();
      open();
    }
  });

  list.addEventListener("keydown", function (event) {
    if (event.key === "ArrowDown") setActive(activeIndex + 1);
    else if (event.key === "ArrowUp") setActive(activeIndex - 1);
    else if (event.key === "Home") setActive(0);
    else if (event.key === "End") setActive(items.length - 1);
    else if (event.key === "Enter" || event.key === " ") {
      if (items[activeIndex]) choose(items[activeIndex]);
    } else if (event.key === "Escape") close(true);
    else if (event.key === "Tab") {
      close(false);
      return;
    } else return;
    event.preventDefault();
  });

  list.addEventListener("click", function (event) {
    const item = event.target.closest(".unit-picker__option");
    if (item) choose(item);
  });
  list.addEventListener("mousemove", function (event) {
    const item = event.target.closest(".unit-picker__option");
    if (item) setActive(items.indexOf(item));
  });

  document.addEventListener("pointerdown", function (event) {
    if (!picker.contains(event.target)) close(false);
  });

  // Pemicu lain (mis. popup unit yang tidak tersedia) dapat mengosongkan pilihan.
  select.addEventListener("change", sync);
  sync();
})();
