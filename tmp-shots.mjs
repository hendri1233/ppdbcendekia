export default async function run(page, ui) {
  const base = "http://localhost/ppdb-web";
  const token = "aa".repeat(32);
  await page.setViewportSize({ width: 1280, height: 1100 });
  await page.goto(base + "/formulir.php?kode=P202600014&token=" + token);
  await page.waitForSelector("dialog#konfirmasi-awal");
  await page.locator(".choice-yes").click();
  await page.waitForTimeout(900);
  await page.screenshot({
    path: "C:\\xampp\\htdocs\\ppdb-web\\shot-7-form-desktop.png",
  });

  // Tampilan ponsel
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(500);
  await page.screenshot({
    path: "C:\\xampp\\htdocs\\ppdb-web\\shot-8-form-mobile.png",
  });

  const overflow = await page.evaluate(
    () =>
      document.documentElement.scrollWidth -
      document.documentElement.clientWidth,
  );
  return { horizontalOverflowPx: overflow };
}
