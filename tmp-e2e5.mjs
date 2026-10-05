export default async function run(page, ui) {
  const log = [];
  const base = "http://localhost/ppdb-web";
  const code = process.env.TEST_CODE;
  const proof = "C:\\xampp\\htdocs\\ppdb-web\\tmp-bukti.png";

  // --- Admin: setujui pembayaran, lalu kirim tautan formulir ---
  await page.goto(base + "/login.php");
  await page.fill("#email", "uji.alur@example.test");
  await page.fill("#password", "UjiAlur2026");
  await Promise.all([
    page.waitForNavigation(),
    page.click('.auth-form button[type="submit"]'),
  ]);

  await page.goto(base + "/detail-peserta.php?id=" + code);
  await page.waitForTimeout(300);
  log.push({
    step: "detail-cek-bayar",
    actions: await page.locator(".workflow-actions button").allInnerTexts(),
  });

  await Promise.all([
    page.waitForNavigation(),
    page.locator('button:has-text("Setujui pembayaran")').first().click(),
  ]);
  await page.waitForTimeout(400);
  log.push({
    step: "after-approve",
    alert: await page
      .locator(".alert-success, .alert-error")
      .first()
      .innerText()
      .catch(() => null),
  });

  await Promise.all([
    page.waitForNavigation(),
    page
      .locator('button:has-text("Kirim tautan formulir lengkap")')
      .first()
      .click(),
  ]);
  await page.waitForTimeout(400);
  log.push({
    step: "after-send-formulir",
    alert: await page
      .locator(".alert-success, .alert-error")
      .first()
      .innerText()
      .catch(() => null),
  });

  // --- Pendaftar: buka formulir dengan kode + token ---
  const token = "aa".repeat(32);
  await page.goto(base + "/formulir.php?kode=" + code + "&token=" + token);
  await page.waitForTimeout(500);
  log.push({
    step: "formulir-gate-open",
    dialogOpen: await page
      .locator("dialog#konfirmasi-awal")
      .isVisible()
      .catch(() => false),
    formHiddenBefore: await page.locator("#formulir-terbuka").isHidden(),
    confirmValues: await page.locator(".confirm-grid dd").allInnerTexts(),
  });

  // Klik "Tidak" dulu untuk memastikan popup memberi arahan.
  await page.locator(".choice-no").click();
  await page.waitForTimeout(200);
  log.push({
    step: "klik-tidak",
    note: await page.locator("[data-confirm-note]").innerText(),
    stillClosed: await page.locator("#formulir-terbuka").isHidden(),
  });

  // Klik "Ya" untuk membuka formulir.
  await page.locator(".choice-yes").click();
  await page.waitForTimeout(500);
  log.push({
    step: "klik-ya",
    dialogVisible: await page
      .locator("dialog#konfirmasi-awal")
      .isVisible()
      .catch(() => false),
    formVisible: await page.locator("#formulir-terbuka").isVisible(),
    hiddenValue: await page.locator("[data-confirm-value]").inputValue(),
    docCards: await page.locator(".document-card").count(),
    sections: await page.locator(".form-section").count(),
  });
  return { log };
}
