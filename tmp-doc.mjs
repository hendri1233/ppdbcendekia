export default async function run(page, ui) {
  const log = [];
  const base = "http://localhost/ppdb-web";

  await page.goto(base + "/login.php");
  await page.fill("#email", "uji.alur@example.test");
  await page.fill("#password", "UjiAlur2026");
  await page.click('.auth-form button[type="submit"]');
  await page.waitForTimeout(1200);

  await page.goto(base + "/detail-peserta.php?id=P202600015", {
    waitUntil: "domcontentloaded",
  });
  await page.waitForTimeout(500);
  log.push({
    step: "detail",
    docCards: await page.locator(".admin-documents .document-card").count(),
    present: await page
      .locator(".admin-documents .document-card.has-file")
      .count(),
    missing: await page
      .locator(".admin-documents .document-card.is-missing")
      .count(),
    actions: await page.locator(".workflow-actions button").allInnerTexts(),
  });

  // Buka dokumen lewat dokumen-admin.php (dulu tidak pernah teruji).
  const href = await page
    .locator(".admin-documents a.button-secondary")
    .first()
    .getAttribute("href");
  const resp = await page.request.get(base + "/" + href.replace(/^\.?\//, ""));
  log.push({
    step: "dokumen-admin",
    url: href,
    status: resp.status(),
    contentType: resp.headers()["content-type"],
    bytes: (await resp.body()).length,
  });

  // Jalankan keputusan akhir: terima peserta.
  await page.goto(base + "/detail-peserta.php?id=P202600015", {
    waitUntil: "domcontentloaded",
  });
  await page.waitForTimeout(400);
  await page.locator('button:has-text("Terima peserta")').first().click();
  await page.waitForTimeout(1200);
  log.push({
    step: "terima",
    alert: await page
      .locator(".alert-success,.alert-error")
      .first()
      .innerText()
      .catch(() => null),
  });

  return { log };
}
