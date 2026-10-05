export default async function run(page, ui) {
  const log = [];
  const base = "http://localhost/ppdb-web";

  await page.goto(base + "/daftar.php");
  await page.selectOption("#th_ajaran", "2026/2027");
  await page.selectOption("#kode_unit", { index: 1 });
  await page.fill("#nama_ananda", "SITI AISYAH PUTRI");
  await page.fill("#tanggal_lahir", "2013-09-02");
  await page.selectOption("#jenis_kelamin", "perempuan");
  await page.selectOption("#pendaftar", "Bunda");
  await page.fill("#email", "uji.alur@example.test");
  await page.fill("#password", "UjiAlur2026");
  await page.click('.auth-form button[type="submit"]');
  await page.waitForTimeout(1200);

  await page.goto(base + "/detail-peserta.php?id=P202600017");
  await page.waitForTimeout(400);
  await page
    .locator('button:has-text("Siapkan tautan pembayaran")')
    .first()
    .click();
  await page.waitForTimeout(1800);

  log.push({
    step: "tautan-pembayaran",
    alert: await page
      .locator(".alert-success,.alert-error")
      .first()
      .innerText()
      .catch(() => null),
    links: await page.$$eval(".share-link", (els) =>
      els.map((e) => ({
        role: e.querySelector(".share-link__role").textContent,
        phone: e.querySelector(".share-link__phone").textContent,
        href: e.getAttribute("href").slice(0, 50),
      })),
    ),
    shareUrl: await page.locator("#share-url").inputValue(),
  });
  return { log };
}
