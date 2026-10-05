export default async function run(page, ui) {
  const log = [];
  const base = "http://localhost/ppdb-web";
  const code = process.env.TEST_CODE;
  const proof = "C:\\xampp\\htdocs\\ppdb-web\\tmp-bukti.png";
  const token = "aa".repeat(32);

  await page.goto(base + "/formulir.php?kode=" + code + "&token=" + token);
  await page.waitForSelector("dialog#konfirmasi-awal");
  await page.locator(".choice-yes").click();
  await page.waitForTimeout(300);

  const requiredIds = await page.$$eval(
    ".document-card input[type=file]",
    (els) =>
      els.map((e) => ({
        id: e.id,
        required: e.required,
        files: e.files.length,
      })),
  );
  log.push({ step: "file-inputs", requiredIds });

  log.push({
    step: "formValidity",
    isValid: await page
      .locator("#form-utama")
      .evaluate((f) => f.checkValidity()),
    invalidNames: await page
      .locator("#form-utama")
      .evaluate((f) =>
        [...f.querySelectorAll(":invalid")].map((e) => e.name || e.id),
      ),
  });
  return { log };
}
