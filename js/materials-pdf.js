const mobileViewport = window.matchMedia("(max-width: 640px)");

async function setupPdfViewer(viewer) {
  const canvas = viewer.querySelector("[data-pdf-canvas]");
  const canvasWrap = viewer.querySelector("[data-pdf-canvas-wrap]");
  const status = viewer.querySelector("[data-pdf-status]");
  const pageLabel = viewer.querySelector("[data-pdf-page]");
  const previousButton = viewer.querySelector("[data-pdf-previous]");
  const nextButton = viewer.querySelector("[data-pdf-next]");
  const zoomOutButton = viewer.querySelector("[data-pdf-zoom-out]");
  const zoomInButton = viewer.querySelector("[data-pdf-zoom-in]");
  const fallbackLink = viewer.querySelector("[data-pdf-fallback]");

  let pdfjs;
  try {
    pdfjs = await import("../assets/pdfjs/pdf.min.js");
  } catch {
    console.error("PDF.js failed to load.");
    status.textContent =
      "Penampil PDF tidak tersedia. Gunakan tautan untuk membuka dokumen.";
    status.hidden = false;
    fallbackLink.hidden = false;
    return;
  }
  pdfjs.GlobalWorkerOptions.workerSrc = new URL(
    "../assets/pdfjs/pdf.worker.min.js",
    import.meta.url,
  ).href;

  const context = canvas.getContext("2d", { alpha: false });
  let pdfDocument = null;
  let currentPage = 1;
  let zoom = 1;
  let renderTask = null;
  let previousWidth = 0;
  let renderVersion = 0;

  function updateControls() {
    pageLabel.textContent = pdfDocument
      ? "Halaman " + currentPage + " dari " + pdfDocument.numPages
      : "Memuat PDF…";
    previousButton.disabled = !pdfDocument || currentPage <= 1;
    nextButton.disabled = !pdfDocument || currentPage >= pdfDocument.numPages;
    zoomOutButton.disabled = !pdfDocument || zoom <= 1;
    zoomInButton.disabled = !pdfDocument || zoom >= 2.5;
  }

  async function renderPage() {
    if (!pdfDocument) return;

    const currentRender = ++renderVersion;
    if (renderTask) {
      renderTask.cancel();
      renderTask = null;
    }

    const page = await pdfDocument.getPage(currentPage);
    if (currentRender !== renderVersion) return;
    const baseViewport = page.getViewport({ scale: 1 });
    const availableWidth = Math.max(1, canvasWrap.clientWidth);
    const fitScale = availableWidth / baseViewport.width;
    const scale = fitScale * zoom;
    const viewport = page.getViewport({ scale });
    const outputScale = Math.min(window.devicePixelRatio || 1, 2);

    canvas.width = Math.ceil(viewport.width * outputScale);
    canvas.height = Math.ceil(viewport.height * outputScale);
    canvas.style.width = Math.ceil(viewport.width) + "px";
    canvas.style.height = Math.ceil(viewport.height) + "px";

    const task = page.render({
      canvasContext: context,
      viewport,
      transform:
        outputScale === 1
          ? null
          : [outputScale, 0, 0, outputScale, 0, 0],
    });
    renderTask = task;

    try {
      await task.promise;
      status.hidden = true;
      canvasWrap.hidden = false;
      previousWidth = availableWidth;
    } catch (error) {
      if (error?.name !== "RenderingCancelledException") {
        throw error;
      }
    } finally {
      if (renderTask === task) {
        renderTask = null;
      }
    }
  }

  async function displayPage() {
    updateControls();
    try {
      await renderPage();
    } catch (error) {
      console.error("PDF page rendering failed.");
      status.textContent =
        "Halaman PDF tidak dapat ditampilkan. Gunakan tautan untuk membuka dokumen.";
      status.hidden = false;
      fallbackLink.hidden = false;
      canvasWrap.hidden = true;
    }
  }

  previousButton.addEventListener("click", function () {
    if (currentPage > 1) {
      currentPage -= 1;
      displayPage();
    }
  });

  nextButton.addEventListener("click", function () {
    if (pdfDocument && currentPage < pdfDocument.numPages) {
      currentPage += 1;
      displayPage();
    }
  });

  zoomOutButton.addEventListener("click", function () {
    if (zoom > 1) {
      zoom = Math.max(1, zoom - 0.25);
      displayPage();
    }
  });

  zoomInButton.addEventListener("click", function () {
    if (zoom < 2.5) {
      zoom = Math.min(2.5, zoom + 0.25);
      displayPage();
    }
  });

  const resizeObserver = new ResizeObserver(function () {
    const width = canvasWrap.clientWidth;
    if (pdfDocument && width > 0 && width !== previousWidth) {
      displayPage();
    }
  });
  resizeObserver.observe(canvasWrap);

  pdfjs
    .getDocument({ url: viewer.dataset.pdfUrl })
    .promise.then(function (document) {
      pdfDocument = document;
      status.hidden = true;
      canvasWrap.hidden = false;
      updateControls();
      return renderPage();
    })
    .catch(function () {
      console.error("Protected PDF loading failed.");
      status.textContent =
        "PDF belum dapat ditampilkan. Pastikan koneksi tersedia, lalu coba lagi.";
      status.hidden = false;
      fallbackLink.hidden = false;
      updateControls();
    });
}

function initializeMobilePdfViewers() {
  if (!mobileViewport.matches) return;
  document.querySelectorAll("[data-pdf-viewer]").forEach(function (viewer) {
    if (viewer.closest("[hidden]") || viewer.dataset.pdfInitialized) return;
    viewer.dataset.pdfInitialized = "true";
    setupPdfViewer(viewer);
  });
}

initializeMobilePdfViewers();
mobileViewport.addEventListener("change", initializeMobilePdfViewers);

new MutationObserver(initializeMobilePdfViewers).observe(document.body, {
  attributes: true,
  attributeFilter: ["hidden"],
  childList: true,
  subtree: true,
});
