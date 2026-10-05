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

  log.push({
    fileInputsInsideForm: await page
      .locator("#form-utama .document-card input[type=file]")
      .count(),
    fileInputsTotal: await page
      .locator(".document-card input[type=file]")
      .count(),
    confirmInputInsideForm: await page
      .locator("#form-utama [data-confirm-value]")
      .count(),
    formEnctype: await page.locator("#form-utama").getAttribute("enctype"),
  });

  for (const slot of ["foto", "kk", "akta_lahir", "ktp_ayah", "ktp_ibu"]) {
    await page.setInputFiles("#dokumen-" + slot, proof);
  }
  await page.waitForTimeout(200);
  log.push({
    filesAttached: await page.$$eval(".document-card input[type=file]", (els) =>
      els.map((e) => ({
        id: e.id,
        n: e.files.length,
        inForm: Boolean(e.form),
      })),
    ),
  });
  return { log };
}
