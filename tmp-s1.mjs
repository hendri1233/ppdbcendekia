export default async function run(page, ui) {
  const base = "http://localhost/ppdb-web";
  await page.goto(base + "/login.php");
  await page.fill("#email", "uji.alur@example.test");
  await page.fill("#password", "UjiAlur2026");
  await page.click('.auth-form button[type="submit"]');
  await page.waitForTimeout(1200);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(base + "/admin.php", { waitUntil: "domcontentloaded" });
  await page.waitForTimeout(900);
  await page.screenshot({
    path: "C:\\xampp\\htdocs\\ppdb-web\\shot-dash.png",
    animations: "disabled",
  });
  return { ok: true };
}
