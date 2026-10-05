export default async function run(page, ui) {
  const log = [];
  const base = "http://localhost/ppdb-web";
  const code = process.env.TEST_CODE;
  const proof = "C:\\xampp\\htdocs\\ppdb-web\\tmp-bukti.png";

  // Ambil token tautan langsung dari database (WA tidak terkirim di lingkungan ini).
  const token = process.env.TEST_TOKEN;

  // --- Pendaftar: halaman status & unggah bukti ---
  const statusUrl =
    base + "/status-pendaftaran.php?id=" + code + "&token=" + token;
  await page.goto(statusUrl);
  await page.waitForTimeout(300);
  log.push({
    step: "status-bayar",
    heading: await page.locator("h1").first().innerText(),
    currentStep: await page
      .locator(".journey-step.is-current strong")
      .innerText()
      .catch(() => null),
    steps: await page.locator(".journey-step").count(),
    hasUpload: await page.locator("#bukti_transfer").count(),
  });

  await page.setInputFiles("#bukti_transfer", proof);
  await Promise.all([
    page.waitForNavigation(),
    page.click('.payment-upload button[type="submit"]'),
  ]);
  await page.waitForTimeout(500);
  log.push({
    step: "after-upload",
    url: page.url(),
    heading: await page.locator("h1").first().innerText(),
    currentStep: await page
      .locator(".journey-step.is-current strong")
      .innerText()
      .catch(() => null),
  });
  return { log };
}
