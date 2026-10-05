export default async function run(page, ui) {
  const log = [];
  await page.goto("http://localhost/ppdb-web/daftar.php");
  await page.selectOption("#th_ajaran", "2026/2027");
  await page.selectOption("#kode_unit", { index: 1 });
  await page.fill("#nama_ananda", "Ahmad Fauzan Ramadhan");
  await page.fill("#tanggal_lahir", "2016-05-14");
  await page.selectOption("#jenis_kelamin", "laki-laki");
  await page.selectOption("#pendaftar", "Ayah dan Bunda");
  await page.fill("#no_hp_pendaftar", "081298765432");
  await page.fill("#no_hp_ayah", "081298765432");
  await page.fill("#no_hp_ibu", "081277654321");
  await page.check("#konfirmasi_data");

  await page.click(".submit-button");
  await page.waitForTimeout(2500);
  log.push({
    url: page.url(),
    errorBox: (await page.locator(".error-summary").count())
      ? await page.locator(".error-summary").first().innerText()
      : null,
  });
  return { log };
}
