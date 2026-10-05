export default async function run(page, ui) {
  const log = [];
  const base = "http://localhost/ppdb-web";
  const code = process.env.TEST_CODE;
  const proof = "C:\\xampp\\htdocs\\ppdb-web\\tmp-bukti.png";
  const token = "aa".repeat(32);

  await page.goto(base + "/formulir.php?kode=" + code + "&token=" + token);
  await page.waitForSelector("dialog#konfirmasi-awal");
  await page.locator(".choice-yes").click();
  await page.waitForTimeout(400);

  // Validasi: kirim kosong harus ditolak per field.
  await page.locator(".submit-button").click();
  await page.waitForTimeout(400);
  log.push({
    step: "kirim-kosong",
    errorBox: (await page.locator(".error-summary").count())
      ? await page.locator(".error-summary").first().innerText()
      : null,
  });

  await page.fill("#nik", "1234567890123456");
  await page.fill("#nomor_kk", "1234567890123456");
  await page.fill("#NISN", "0123456789");
  await page.fill("#tmp_lahir", "Takengon");
  await page.selectOption("#agama", "Islam");
  await page.selectOption("#kewarganegaraan", "WNI");
  await page.selectOption("#pernah_paud_tk", "Ya");
  await page.selectOption("#jenis_pendaftaran", "Siswa baru");
  await page.fill("#alamat_jalan", "Dusun Pediwi");
  await page.fill("#rt", "03");
  await page.fill("#rw", "05");
  await page.fill("#desa_kelurahan", "Kebet");
  await page.fill("#kecamatan", "Bebesen");
  await page.fill("#kabupaten", "Aceh Tengah");
  await page.fill("#provinsi", "Aceh");
  await page.selectOption("#jenis_tinggal", "Bersama orang tua");
  await page.selectOption("#alat_transportasi", "Sepeda motor");
  await page.fill("#ayah_nama", "Sudirman");
  await page.fill("#ayah_nik", "1234567890123456");
  await page.fill("#ayah_tahun_lahir", "1990");
  await page.selectOption("#ayah_pendidikan", "SMA/sederajat");
  await page.fill("#ayah_pekerjaan", "Petani");
  await page.selectOption("#ayah_penghasilan", "Rp500.000–Rp1.000.000");
  await page.fill("#ibu_nama", "SITI Aminah");
  await page.fill("#ibu_nik", "6543210987654321");
  await page.fill("#ibu_tahun_lahir", "1992");
  await page.selectOption("#ibu_pendidikan", "SMA/sederajat");
  await page.fill("#ibu_pekerjaan", "Ibu rumah tangga");
  await page.selectOption("#ibu_penghasilan", "Tidak berpenghasilan");
  await page.fill("#tinggi_badan_cm", "118");
  await page.fill("#berat_badan_kg", "22.5");
  await page.fill("#jumlah_saudara_kandung", "1");

  // Lima dokumen wajib; NISN sengaja dikosongkan (opsional).
  for (const slot of ["foto", "kk", "akta_lahir", "ktp_ayah", "ktp_ibu"]) {
    await page.setInputFiles("#dokumen-" + slot, proof);
  }
  await page.waitForTimeout(300);
  log.push({
    step: "file-previews",
    names: await page.locator(".file-drop__name").allInnerTexts(),
  });

  await page.check("#konfirmasi_data");
  await Promise.all([
    page.waitForNavigation(),
    page.locator(".submit-button").click(),
  ]);
  await page.waitForTimeout(600);
  log.push({
    step: "after-submit",
    url: page.url(),
    heading: await page.locator("h1").first().innerText(),
    guidance: await page
      .locator(".payment-guidance")
      .first()
      .innerText()
      .catch(() => null),
  });
  return { log };
}
