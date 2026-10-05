export default async function run(page, ui) {
  const base = "http://localhost/ppdb-web";
  await page.goto(base + "/login.php");
  await page.fill("#email", "uji.alur@example.test");
  await page.fill("#password", "UjiAlur2026");
  await page.click('.auth-form button[type="submit"]');
  await page.waitForTimeout(1200);
  await page.setViewportSize({ width: 1440, height: 1000 });

  const results = {};
  // Cegah permintaan font eksternal agar headless tidak menunggu.
  await page.route("**fonts.googleapis.com**", (route) => route.abort());
  await page.route("**fonts.gstatic.com**", (route) => route.abort());

  for (const [name, path] of [
    ["dash", "/admin.php"],
    ["peserta", "/daftar_peserta.php"],
  ]) {
    await page.goto(base + path, { waitUntil: "domcontentloaded" });
    await page.waitForTimeout(700);
    try {
      await page.screenshot({
        path: "C:\\xampp\\htdocs\\ppdb-web\\shot-" + name + ".png",
        animations: "disabled",
        timeout: 20000,
      });
      results[name] = "ok";
    } catch (e) {
      results[name] = "FAIL: " + e.message.slice(0, 80);
    }
  }
  return results;
}
