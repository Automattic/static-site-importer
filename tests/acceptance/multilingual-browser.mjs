import { writeFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
const { chromium } = await import(process.env.SSI_MULTILINGUAL_PLAYWRIGHT_MODULE ? pathToFileURL(process.env.SSI_MULTILINGUAL_PLAYWRIGHT_MODULE).href : 'playwright');
const [base, evidence] = process.argv.slice(2);
const browser = await chromium.launch({ headless: true });
const results = [];
try {
  for (const width of [1440, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const response = await page.goto(base, { waitUntil: 'domcontentloaded' });
    if (response.status() !== 200) throw new Error('The native homepage did not load');
    await page.locator('.trp-floating-switcher').waitFor({ state: 'visible' });
    await page.locator('.trp-floating-switcher').hover();
    const french = page.locator('.trp-floating-switcher a[href*="/fr/"]');
    await french.waitFor({ state: 'visible' });
    const control = page.locator('.trp-floating-switcher [role="button"]');
    await control.focus();
    await control.press('Escape');
    await control.press('Enter');
    await french.waitFor({ state: 'visible' });
    await page.screenshot({ path: `${evidence}/selector-${width}.png` });
    await french.click();
    await page.waitForURL('**/fr/');
    await page.getByRole('heading', { name: 'Hello community', exact: true }).waitFor();
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.locator('.trp-floating-switcher').waitFor({ state: 'visible' });
    results.push({ width, visibleSelector: true, nativeLanguageNavigation: true, frenchReload: true, sourceContent: true });
    await page.close();
  }
  await writeFile(`${evidence}/browser.json`, JSON.stringify({ status: 'passed', results }, null, 2));
  console.log(JSON.stringify({ status: 'passed', results }));
} finally { await browser.close(); }
