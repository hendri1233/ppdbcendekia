export default async function run(page, ui) {
  const base = "http://localhost/ppdb-web";
  const out = {};

  await page.goto(base + "/login.php");
  await page.fill("#email", "uji.alur@example.test");
  await page.fill("#password", "UjiAlur2026");
  await page.click('.auth-form button[type="submit"]');
  await page.waitForTimeout(1200);
  out.loginUrl = page.url();

  const pages = [
    ["dashboard", "/admin.php"],
    ["peserta", "/daftar_peserta.php"],
    ["peserta-filter", "/daftar_peserta.php?status=diterima"],
    ["peserta-page3", "/daftar_peserta.php?page=3"],
    ["kuota", "/pengaturan-ppdb.php"],
    ["detail", "/detail-peserta.php?id=P202600015"],
  ];

  for (const [name, path] of pages) {
    const errors = [];
    const onConsole = (msg) => {
      if (msg.type() === "error") errors.push(msg.text());
    };
    page.on("console", onConsole);
    page.on("pageerror", (e) => errors.push("pageerror: " + e.message));

    await page.goto(base + path, { waitUntil: "domcontentloaded" });
    await page.waitForTimeout(500);
    out[name] = await page.evaluate(() => ({
      title: document.title,
      h1: document.querySelector("h1")?.textContent.trim().slice(0, 40) || null,
      bodyChars: document.body.innerText.length,
      overflow:
        document.documentElement.scrollWidth -
        document.documentElement.clientWidth,
      navLinks: document.querySelectorAll(".admin-nav a, .admin-mobile-nav a")
        .length,
      pills: document.querySelectorAll(".status-pill").length,
    }));
    out[name].consoleErrors = errors;
    page.off("console", onConsole);
  }

  return out;
}
