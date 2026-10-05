export default async function run(page, ui) {
  const base = "http://localhost/ppdb-web";
  await page.goto(base + "/login.php");
  await page.fill("#email", "uji.alur@example.test");
  await page.fill("#password", "UjiAlur2026");
  await page.click('.auth-form button[type="submit"]');
  await page.waitForTimeout(1200);
  await page.goto(base + "/detail-peserta.php?id=P202600018");
  await page.waitForTimeout(500);
  await page.locator(".share-panel").scrollIntoViewIfNeeded();
  await page.waitForTimeout(400);
  await page.screenshot({
    path: "C:\\xampp\\htdocs\\ppdb-web\\shot-share.png",
    animations: "disabled",
    fullPage: false,
  });
  return { ok: true };
}
