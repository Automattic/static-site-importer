import assert from 'node:assert/strict';
import { chromium } from 'playwright';
const origin = process.env.SSI_CONSENT_ORIGIN;
if (!origin) throw new Error('SSI_CONSENT_ORIGIN must name a disposable imported WordPress site.');
const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROMIUM_PATH });
try {
 const context = await browser.newContext();
 const page = await context.newPage();
 for (const path of ['/', '/about/']) {
  await page.goto(new URL(path, origin).href);
  await page.locator('.wp-block-jetpack-cookie-consent[role="dialog"]').waitFor({state:'visible'});
  assert.match(await page.locator('.wp-block-jetpack-cookie-consent[role="dialog"]').innerText(), /analytics & preferences/);
 }
 await page.getByRole('button', {name:'Accept cookies', exact:true}).click();
 // Released Jetpack wraps the banner and fades the wrapper, so wait for the consent cookie rather than a style.
 await page.waitForFunction(() => document.cookie.includes('eucookielaw='));
 await page.reload();
 assert.equal(await page.locator('.wp-block-jetpack-cookie-consent[role="dialog"]').count(), 0);
 await page.goto(origin);
 assert.equal(await page.locator('.wp-block-jetpack-cookie-consent[role="dialog"]').count(), 0);
 console.log('Actual Jetpack site-wide banner and persistent acceptance passed.');
} finally { await browser.close(); }
