export default async function run(page, ui) {
  const proof = "C:\\xampp\\htdocs\\ppdb-web\\tmp-bukti.png";
  await page.setContent(`<form id="f" method="post" action="/ppdb-web/tmp-probe.php" enctype="multipart/form-data">
    <input type="hidden" name="kode" value="X1">
    <input id="dokumen-foto" type="file" name="dokumen[foto]">
    <input id="dokumen-kk" type="file" name="dokumen[kk]">
    <button type="submit">go</button>
  </form>`);
  await page.setInputFiles("#dokumen-foto", proof);
  await page.setInputFiles("#dokumen-kk", proof);
  await Promise.all([page.waitForNavigation(), page.click("button")]);
  return { body: (await page.locator("body").innerText()).slice(0, 900) };
}
