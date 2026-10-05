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
  await page.waitForTimeout(600);
  log.push({
    step: "workflow",
    actions: await page.locator(".workflow-actions button").allInnerTexts(),
  });

  await page.locator('button:has-text("Terima peserta")').first().click();
  await page.waitForTimeout(1500);
  log.push({
    step: "terima",
    alert: await page
      .locator(".alert-success,.alert-error")
      .first()
      .innerText()
      .catch(() => null),
  });

  await page.goto(base + "/daftar_peserta.php", {
    waitUntil: "domcontentloaded",
  });
  await page.waitForTimeout(500);
  log.push({
    step: "list",
    rows: await page.locator(".data-table tbody tr").count(),
    pills: await page.locator(".status-pill").allInnerTexts(),
  });

  await page.goto(base + "/admin.php", { waitUntil: "domcontentloaded" });
  await page.waitForTimeout(500);
  log.push({
    step: "dash",
    stats: await page.locator(".stat-card").allInnerTexts(),
  });
  return { log };
}
