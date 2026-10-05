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

  const set = (sel, val) => page.fill(sel, val);
  const pick = (sel, val) => page.selectOption(sel, val);

  await set("#nik", "1234567890123456");
  await set("#nomor_kk", "1234567890123456");
  await set("#NISN", "0123456789");
  await set("#tmp_lahir", "Takengon");
  await pick("#agama", "Islam");
  await pick("#kewarganegaraan", "WNI");
  await pick("#pernah_paud_tk", "Ya");
  await pick("#jenis_pendaftaran", "Siswa baru");
  await set("#alamat_jalan", "Dusun Pediwi");
  await set("#rt", "03");
  await set("#rw", "05");
  await set("#desa_kelurahan", "Kebet");
  await set("#kecamatan", "Bebesen");
  await set("#kabupaten", "Aceh Tengah");
  await set("#provinsi", "Aceh");
  await pick("#jenis_tinggal", "Bersama orang tua");
  await pick("#alat_transportasi", "Sepeda motor");
  await set("#ayah_nama", "Sudirman");
  await set("#ayah_nik", "1234567890123456");
  await set("#ayah_tahun_lahir", "1990");
  await pick("#ayah_pendidikan", "SMA/sederajat");
  await set("#ayah_pekerjaan", "Petani");
  await pick("#ayah_penghasilan", "Rp500.000–Rp1.000.000");
  await set("#ibu_nama", "SITI Aminah");
  await set("#ibu_nik", "6543210987654321");
  await set("#ibu_tahun_lahir", "1992");
  await pick("#ibu_pendidikan", "SMA/sederajat");
  await set("#ibu_pekerjaan", "Ibu rumah tangga");
  await pick("#ibu_penghasilan", "Tidak berpenghasilan");
  await set("#tinggi_badan_cm", "118");
  await set("#berat_badan_kg", "22.5");
  await set("#jumlah_saudara_kandung", "1");

  for (const slot of ["foto", "kk", "akta_lahir", "ktp_ayah", "ktp_ibu"]) {
    await page.setInputFiles("#dokumen-" + slot, proof);
  }
  await page.check("#konfirmasi_data");
  await page.waitForTimeout(200);

  await Promise.all([
    page.waitForNavigation(),
    page.locator(".submit-button").click(),
  ]);
  await page.waitForTimeout(800);
  log.push({
    step: "after-submit",
    url: page.url(),
    body: (await page.locator("body").innerText()).slice(0, 600),
  });
  return { log };
}
