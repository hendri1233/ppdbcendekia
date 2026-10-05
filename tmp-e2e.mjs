export default async function run(page, ui) {
  const log = [];

  // --- Langkah 1: registrasi awal ---
  await page.goto("http://localhost/ppdb-web/daftar.php");
  await page.selectOption("#th_ajaran", "2026/2027");
  await page.waitForTimeout(200);
  await page.selectOption("#kode_unit", { index: 1 });
  await page.fill("#nama_ananda", "Ahmad Fauzan Ramadhan");
  await page.fill("#tanggal_lahir", "2016-05-14");
  await page.selectOption("#jenis_kelamin", "laki-laki");
  await page.selectOption("#pendaftar", "Ayah dan Bunda");
  await page.fill("#no_hp_pendaftar", "081298765432");
  await page.fill("#no_hp_ayah", "081298765432");
  await page.fill("#no_hp_ibu", "081277654321");
  await page.check("#konfirmasi_data");

  const offerText = await page.locator("#offer-summary").innerText();
  log.push({ step: "offer-summary", text: offerText.slice(0, 80) });

  await Promise.all([
    page.waitForNavigation({ waitUntil: "load" }),
    page.click(".submit-button"),
  ]);
  log.push({
    step: "after-submit",
    url: page.url(),
    title: await page.title(),
  });

  const codeBlock = await page.locator(".registration-code strong").innerText();
  log.push({ step: "kode", code: codeBlock });

  const bodyText = await page.locator("body").innerText();
  log.push({ step: "berhasil-has-kode", ok: bodyText.includes(codeBlock) });
  log.push({
    step: "berhasil-has-nama",
    ok: bodyText.includes("Ahmad Fauzan Ramadhan"),
  });
  log.push({ step: "berhasil-tdk-accent", ok: !bodyText.includes("Â") });

  return { log };
}
