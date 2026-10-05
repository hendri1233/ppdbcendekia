export default async function run(page, ui) {
  const base = "http://localhost/ppdb-web";
  await page.route("**fonts.googleapis.com**", (r) => r.abort());
  await page.route("**fonts.gstatic.com**", (r) => r.abort());

  await page.goto(base + "/login.php");
  await page.fill("#email", "uji.alur@example.test");
  await page.fill("#password", "UjiAlur2026");
  await page.click('.auth-form button[type="submit"]');
  await page.waitForTimeout(1200);

  const out = {};
  const viewports = [
    ["mobile", 390, 844],
    ["tablet", 768, 1024],
    ["desktop", 1440, 900],
  ];
  const pages = [
    ["dash", "/admin.php"],
    ["peserta", "/daftar_peserta.php"],
    ["kuota", "/pengaturan-ppdb.php"],
    ["detail", "/detail-peserta.php?id=P202600015"],
    ["daftar", "/daftar_peserta.php?status=menunggu_kontak"],
  ];

  for (const [vp, w, h] of viewports) {
    out[vp] = {};
    await page.setViewportSize({ width: w, height: h });
    for (const [name, path] of pages) {
      const errors = [];
      const onErr = (m) => {
        if (m.type() === "error") errors.push(m.text());
      };
      page.on("console", onErr);
      page.on("pageerror", (e) => errors.push("pageerror: " + e.message));

      await page.goto(base + path, { waitUntil: "domcontentloaded" });
      await page.waitForTimeout(400);
      out[vp][name] = await page.evaluate(() => {
        const doc = document.documentElement;
        const wide = [...document.querySelectorAll(".admin-content *")]
          .filter(
            (el) => el.getBoundingClientRect().right > doc.clientWidth + 2,
          )
          .map((el) => el.className || el.tagName)
          .slice(0, 4);
        return {
          overflow: doc.scrollWidth - doc.clientWidth,
          wide,
          chars: document.body.innerText.length,
        };
      });
      out[vp][name].consoleErrors = errors;
      page.off("console", onErr);
    }
  }

  // Tangkapan layar pada dua ukuran kunci.
  await page.setViewportSize({ width: 1440, height: 1000 });
  for (const [name, path] of [
    ["dash", "/admin.php"],
    ["kuota", "/pengaturan-ppdb.php"],
  ]) {
    await page.goto(base + path, { waitUntil: "domcontentloaded" });
    await page.waitForTimeout(500);
    await page.screenshot({
      path: "C:\\xampp\\htdocs\\ppdb-web\\shot-" + name + ".png",
      animations: "disabled",
      timeout: 25000,
    });
  }
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + "/daftar_peserta.php", {
    waitUntil: "domcontentloaded",
  });
  await page.waitForTimeout(500);
  await page.screenshot({
    path: "C:\\xampp\\htdocs\\ppdb-web\\shot-mobile-peserta.png",
    animations: "disabled",
    timeout: 25000,
  });

  return out;
}
