/**
 * Brosur unit layar penuh di daftar.php: tampil setelah unit dipilih, dapat
 * diperbesar (tombol, Ctrl+gulir, ketuk dua kali, cubit) dan ditutup.
 */
const viewer = document.querySelector("[data-brochure-viewer]");

if (viewer && typeof viewer.showModal === "function") {
  const unitSelect = document.getElementById("external_route");
  const unitTrigger = unitSelect
    ? unitSelect.parentElement.querySelector("[data-brochure-open]")
    : null;
  const routeTrigger = document.querySelector("[data-brochure-open][data-brochure-url]");
  const title = viewer.querySelector("[data-brochure-title]");
  const stage = viewer.querySelector("[data-brochure-stage]");
  const content = viewer.querySelector("[data-brochure-content]");
  const status = viewer.querySelector("[data-brochure-status]");
  const pages = viewer.querySelector("[data-brochure-pages]");
  const zoomLabel = viewer.querySelector("[data-brochure-zoom-reset]");
  const zoomOut = viewer.querySelector("[data-brochure-zoom-out]");
  const zoomIn = viewer.querySelector("[data-brochure-zoom-in]");
  const download = viewer.querySelector("[data-brochure-download]");

  const MIN_ZOOM = 1;
  const MAX_ZOOM = 4;
  const MAX_PDF_PAGES = 30;
  let zoom = 1;
  let loadedUrl = "";
  let lastTrigger = null;
  let pdfjsPromise = null;

  function storageKey(url) {
    return "brosur-dilihat:" + url;
  }

  function wasShown(url) {
    try {
      return sessionStorage.getItem(storageKey(url)) === "1";
    } catch {
      return false;
    }
  }

  function markShown(url) {
    try {
      sessionStorage.setItem(storageKey(url), "1");
    } catch {
      /* Penyimpanan sesi tidak tersedia: brosur tetap dapat dibuka manual. */
    }
  }

  function selectedBrochure() {
    const option = unitSelect ? unitSelect.selectedOptions[0] : null;
    return option && option.dataset.brochureUrl ? option.dataset : null;
  }

  function setZoom(next, focusX, focusY) {
    const previous = zoom;
    zoom = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, Math.round(next * 100) / 100));
    if (zoom === previous) return;
    // Pertahankan titik yang sedang dilihat agar tidak melompat saat diperbesar.
    const rect = stage.getBoundingClientRect();
    const x = focusX === undefined ? rect.width / 2 : focusX - rect.left;
    const y = focusY === undefined ? rect.height / 2 : focusY - rect.top;
    const ratio = zoom / previous;
    const left = (stage.scrollLeft + x) * ratio - x;
    const top = (stage.scrollTop + y) * ratio - y;
    applyWidth();
    stage.scrollLeft = left;
    stage.scrollTop = top;
    updateZoomControls();
  }

  // Lebar dasar mengikuti layar (maks. 1100px); brosur gambar dibuat utuh terlihat
  // pada 100%. Perbesaran mengalikan lebar dasar itu.
  function baseWidth() {
    let width = Math.min(stage.clientWidth, 1100);
    const image = content.querySelector("img");
    if (image && image.naturalWidth) {
      const padding = 32;
      const fitHeight = ((stage.clientHeight - padding) * image.naturalWidth) / image.naturalHeight + padding;
      width = Math.max(Math.min(width, fitHeight), Math.min(stage.clientWidth, 320));
    }
    return width;
  }

  function applyWidth() {
    content.style.width = baseWidth() * zoom + "px";
  }

  function updateZoomControls() {
    zoomLabel.textContent = Math.round(zoom * 100) + "%";
    zoomOut.disabled = zoom <= MIN_ZOOM;
    zoomIn.disabled = zoom >= MAX_ZOOM;
  }

  function showStatus(message) {
    status.textContent = message;
    status.hidden = false;
  }

  function loadPdfjs() {
    if (!pdfjsPromise) {
      pdfjsPromise = import("../assets/pdfjs/pdf.min.js").then(function (pdfjs) {
        pdfjs.GlobalWorkerOptions.workerSrc = new URL(
          "../assets/pdfjs/pdf.worker.min.js",
          import.meta.url,
        ).href;
        return pdfjs;
      });
    }
    return pdfjsPromise;
  }

  async function renderPdf(url, version) {
    const pdfjs = await loadPdfjs();
    const pdf = await pdfjs.getDocument({ url }).promise;
    if (version !== loadedUrl) return;
    const total = Math.min(pdf.numPages, MAX_PDF_PAGES);
    pages.textContent = pdf.numPages + " halaman";
    pages.hidden = pdf.numPages < 2;
    // Dirender lebih tajam dari lebar layar agar tetap jelas saat diperbesar.
    const targetWidth = Math.min(2400, stage.clientWidth * 2 * Math.min(window.devicePixelRatio || 1, 2));
    for (let number = 1; number <= total; number++) {
      const page = await pdf.getPage(number);
      if (version !== loadedUrl) return;
      const base = page.getViewport({ scale: 1 });
      const viewport = page.getViewport({ scale: targetWidth / base.width });
      const canvas = document.createElement("canvas");
      canvas.width = Math.ceil(viewport.width);
      canvas.height = Math.ceil(viewport.height);
      canvas.setAttribute("aria-label", "Halaman " + number + " brosur");
      content.append(canvas);
      await page.render({ canvasContext: canvas.getContext("2d", { alpha: false }), viewport }).promise;
      if (number === 1) status.hidden = true;
    }
  }

  function renderImage(url) {
    return new Promise(function (resolve, reject) {
      const image = new Image();
      image.alt = title.textContent;
      image.decoding = "async";
      image.onload = function () {
        content.append(image);
        applyWidth();
        status.hidden = true;
        resolve();
      };
      image.onerror = reject;
      image.src = url;
    });
  }

  async function load(data) {
    if (loadedUrl === data.brochureUrl && content.childElementCount) return;
    loadedUrl = data.brochureUrl;
    content.replaceChildren();
    pages.hidden = true;
    showStatus("Memuat brosur…");
    try {
      if (data.brochureType === "pdf") {
        await renderPdf(data.brochureUrl, data.brochureUrl);
      } else {
        await renderImage(data.brochureUrl);
      }
    } catch {
      if (loadedUrl === data.brochureUrl) {
        content.replaceChildren();
        loadedUrl = "";
        showStatus("Brosur belum dapat ditampilkan. Gunakan tautan Buka file asli di bawah.");
      }
    }
  }

  function open(data, trigger) {
    if (!data) return;
    lastTrigger = trigger || null;
    title.textContent = data.brochureTitle || "Brosur unit";
    download.href = data.brochureUrl;
    zoom = 1;
    updateZoomControls();
    if (!viewer.open) viewer.showModal();
    applyWidth();
    document.documentElement.classList.add("brochure-open");
    stage.scrollTo(0, 0);
    stage.focus({ preventScroll: true });
    markShown(data.brochureUrl);
    load(data);
  }

  window.addEventListener("resize", function () {
    if (viewer.open) applyWidth();
  });

  viewer.addEventListener("close", function () {
    document.documentElement.classList.remove("brochure-open");
    if (lastTrigger && !lastTrigger.hidden) lastTrigger.focus({ preventScroll: true });
  });
  viewer.querySelectorAll("[data-brochure-close]").forEach(function (button) {
    button.addEventListener("click", function () {
      viewer.close();
    });
  });

  zoomIn.addEventListener("click", function () {
    setZoom(zoom + 0.5);
  });
  zoomOut.addEventListener("click", function () {
    setZoom(zoom - 0.5);
  });
  zoomLabel.addEventListener("click", function () {
    setZoom(1);
  });

  stage.addEventListener(
    "wheel",
    function (event) {
      if (!event.ctrlKey) return;
      event.preventDefault();
      setZoom(zoom * (event.deltaY < 0 ? 1.15 : 1 / 1.15), event.clientX, event.clientY);
    },
    { passive: false },
  );
  stage.addEventListener("dblclick", function (event) {
    setZoom(zoom > 1 ? 1 : 2, event.clientX, event.clientY);
  });
  stage.addEventListener("keydown", function (event) {
    if (event.key === "+" || event.key === "=") setZoom(zoom + 0.5);
    if (event.key === "-") setZoom(zoom - 0.5);
    if (event.key === "0") setZoom(1);
  });

  // Cubit dua jari di layar sentuh.
  let pinchDistance = 0;
  let pinchZoom = 1;
  function distance(touches) {
    return Math.hypot(touches[0].clientX - touches[1].clientX, touches[0].clientY - touches[1].clientY);
  }
  stage.addEventListener(
    "touchstart",
    function (event) {
      if (event.touches.length === 2) {
        pinchDistance = distance(event.touches);
        pinchZoom = zoom;
      }
    },
    { passive: true },
  );
  stage.addEventListener(
    "touchmove",
    function (event) {
      if (event.touches.length !== 2 || !pinchDistance) return;
      event.preventDefault();
      const midX = (event.touches[0].clientX + event.touches[1].clientX) / 2;
      const midY = (event.touches[0].clientY + event.touches[1].clientY) / 2;
      setZoom(pinchZoom * (distance(event.touches) / pinchDistance), midX, midY);
    },
    { passive: false },
  );
  stage.addEventListener("touchend", function (event) {
    if (event.touches.length < 2) pinchDistance = 0;
  });

  function syncUnitTrigger() {
    if (!unitTrigger) return null;
    const data = selectedBrochure();
    unitTrigger.hidden = !data;
    return data;
  }

  if (unitSelect && unitTrigger) {
    unitSelect.addEventListener("change", function () {
      const data = syncUnitTrigger();
      if (data) open(data, unitTrigger);
    });
    unitTrigger.addEventListener("click", function () {
      open(selectedBrochure(), unitTrigger);
    });
    // Unit yang sudah terpilih dari tautan halaman unit: tampilkan sekali per sesi.
    const initial = syncUnitTrigger();
    if (initial && !wasShown(initial.brochureUrl)) open(initial, unitTrigger);
  }

  // Tombol "Lihat Brosur" di popup unit yang belum dibuka: tutup popup, lalu buka brosur.
  const unavailableBrochure = document.querySelector("[data-unavailable-brochure]");
  if (unavailableBrochure) {
    unavailableBrochure.addEventListener("click", function () {
      if (!unavailableBrochure.dataset.brochureUrl) return;
      const dialog = unavailableBrochure.closest("dialog");
      if (dialog && dialog.open) dialog.close();
      open(unavailableBrochure.dataset, null);
    });
  }

  if (routeTrigger) {
    routeTrigger.addEventListener("click", function () {
      open(routeTrigger.dataset, routeTrigger);
    });
    if (routeTrigger.hasAttribute("data-brochure-auto") && !wasShown(routeTrigger.dataset.brochureUrl)) {
      open(routeTrigger.dataset, routeTrigger);
    }
  }
}
