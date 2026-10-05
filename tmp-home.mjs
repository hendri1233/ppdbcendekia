export default async function run(page, ui) {
  const base = "http://localhost/ppdb-web/";
  const out = {};

  // Cegah font eksternal agar headless tidak menunggu (uji saja, bukan bug situs).
  await page.route("**fonts.g**", (r) => r.abort());

  for (const [vp, w, h] of [
    ["mobile", 390, 844],
    ["tablet", 768, 1024],
    ["desktop", 1440, 900],
  ]) {
    await page.setViewportSize({ width: w, height: h });
    const errors = [];
    const onErr = (m) => {
      if (m.type() === "error") errors.push(m.text());
    };
    page.on("console", onErr);
    page.on("pageerror", (e) => errors.push("pageerror: " + e.message));

    await page.goto(base, { waitUntil: "domcontentloaded" });
    await page.waitForTimeout(600);

    out[vp] = await page.evaluate(() => {
      const doc = document.documentElement;
      return {
        title: document.title,
        overflow: doc.scrollWidth - doc.clientWidth,
        h1: document
          .querySelector("h1")
          ?.innerText.replace(/\n/g, " ")
          .slice(0, 60),
        heroImg: document.querySelector(".hero__media img")?.naturalWidth || 0,
        units: document.querySelectorAll(".unit-card").length,
        steps: document.querySelectorAll(".alur-step").length,
        faq: document.querySelectorAll(".faq-item").length,
        jsonLd: document.querySelectorAll('script[type="application/ld+json"]')
          .length,
        canonical: document.querySelector("link[rel=canonical]")?.href || null,
        descLen:
          document.querySelector("meta[name=description]")?.content.length || 0,
        og: document.querySelectorAll('meta[property^="og:"]').length,
      };
    });
    out[vp].consoleErrors = errors;
    page.off("console", onErr);
  }

  // Tangkapan layar.
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(base, { waitUntil: "domcontentloaded" });
  await page.waitForTimeout(800);
  await page.screenshot({
    path: "C:\\xampp\\htdocs\\ppdb-web\\shot-home.png",
    animations: "disabled",
    timeout: 25000,
  });

  await page.goto(base + "#unit", { waitUntil: "domcontentloaded" });
  await page.waitForTimeout(800);
  await page.screenshot({
    path: "C:\\xampp\\htdocs\\ppdb-web\\shot-home-unit.png",
    animations: "disabled",
    timeout: 25000,
  });

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base, { waitUntil: "domcontentloaded" });
  await page.waitForTimeout(700);
  await page.screenshot({
    path: "C:\\xampp\\htdocs\\ppdb-web\\shot-home-mobile.png",
    animations: "disabled",
    timeout: 25000,
  });

  return out;
}
