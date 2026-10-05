export default async function run(page, ui) {
  const log = [];
  const base = "http://localhost/ppdb-web";

  // --- Admin: masuk dan buka detail peserta ---
  await page.goto(base + "/login.php");
  await page.fill("#username", "admin");
  await page.fill("#password", "admin123");
  await Promise.all([
    page.waitForNavigation(),
    page.click('button[type="submit"], .login-button, input[type="submit"]'),
  ]);
  log.push({ step: "login", url: page.url(), title: await page.title() });

  const code = process.env.TEST_CODE || "P202600014";
  await page.goto(base + "/detail-peserta.php?id=" + code);
  await page.waitForTimeout(400);
  log.push({
    step: "detail-initial",
    heading: await page.locator("h1").first().innerText(),
    hasContact: await page.locator(".contact-strip").count(),
    actions: await page.locator(".workflow-actions button").allInnerTexts(),
  });

  // --- Admin: kirim tautan pembayaran ---
  await Promise.all([
    page.waitForNavigation(),
    page.locator('button:has-text("Kirim tautan pembayaran")').first().click(),
  ]);
  await page.waitForTimeout(600);
  log.push({
    step: "after-send-payment",
    alert: await page
      .locator(".alert-success, .alert-error")
      .first()
      .innerText()
      .catch(() => null),
  });
  return { log };
}
