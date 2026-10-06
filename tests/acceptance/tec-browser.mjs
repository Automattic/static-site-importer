import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';

const [base, evidence] = process.argv.slice(2);
const { chromium } = await import(process.env.SSI_TEC_PLAYWRIGHT_MODULE || 'playwright');
const rows = (await readFile(`${evidence}/native-tec.jsonl`, 'utf8')).trim().split('\n').map(line => JSON.parse(line));
const proof = rows.at(-1);
assert.equal(proof.status, 'passed');
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
const result = { routes: [], editors: [], calendar: null };
const errors = [];
page.on('pageerror', error => errors.push(error.message));
page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
try {
  for (const [route, title] of [['/notes/future/', 'Future gathering'], ['/notes/past/', 'Past gathering']]) {
    const response = await page.request.get(`${base}${route}`, { maxRedirects: 0 });
    assert.equal(response.status(), 301, `original GET ${route} redirects`);
    const head = await page.request.head(`${base}${route}`, { maxRedirects: 0 });
    assert.equal(head.status(), 301, `original HEAD ${route} redirects`);
    const location = response.headers().location;
    await page.goto(location, { waitUntil: 'domcontentloaded' });
    assert.ok((await page.locator('body').innerText()).includes('Full source copy'), `${title} retains source content`);
    result.routes.push({ route, location, get: response.status(), head: head.status() });
  }
  const calendar = await page.goto(`${base}/events/`, { waitUntil: 'domcontentloaded' });
  assert.equal(calendar.status(), 200);
  assert.ok((await page.locator('body').innerText()).includes('Future gathering'), 'native calendar lists the future event');
  result.calendar = { status: calendar.status(), nativeEventVisible: true };
  await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('password');
  await Promise.all([page.waitForURL('**/wp-admin/**'), page.locator('#wp-submit').click()]);
  for (const [title, postId] of Object.entries(proof.event_ids)) {
    await page.goto(`${base}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0, null, { timeout: 60000 });
    const checked = await page.evaluate(() => {
      const flatten = blocks => blocks.flatMap(block => [block, ...flatten(block.innerBlocks || [])]);
      const blocks = flatten(window.wp.data.select('core/block-editor').getBlocks());
      return { blocks: blocks.length, invalid: blocks.filter(block => !window.wp.blocks.validateBlock(block)[0]).map(block => block.name), html: blocks.filter(block => ['core/html', 'core/freeform'].includes(block.name)).length };
    });
    assert.deepEqual(checked.invalid, []);
    assert.equal(checked.html, 0);
    const marker = `Saved native event edit: ${title}`;
    await page.evaluate(async text => {
      const flatten = blocks => blocks.flatMap(block => [block, ...flatten(block.innerBlocks || [])]);
      const paragraph = flatten(window.wp.data.select('core/block-editor').getBlocks()).find(block => block.name === 'core/paragraph');
      if (!paragraph) throw new Error('Editable source paragraph unavailable');
      window.wp.data.dispatch('core/block-editor').updateBlockAttributes(paragraph.clientId, { content: text });
      await window.wp.data.dispatch('core/editor').savePost();
    }, marker);
    await page.waitForFunction(() => !window.wp.data.select('core/editor').isSavingPost() && !window.wp.data.select('core/editor').isEditedPostDirty(), null, { timeout: 30000 });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForFunction(text => JSON.stringify(window.wp?.data?.select('core/block-editor')?.getBlocks() || []).includes(text), marker, { timeout: 60000 });
    await page.goto(`${base}/?p=${postId}`, { waitUntil: 'domcontentloaded' });
    assert.ok((await page.locator('body').innerText()).includes(marker), 'saved native edit survives frontend reload');
    result.editors.push({ title, postId, ...checked, savedAndReloaded: true });
  }
  await writeFile(`${evidence}/browser-tec.json`, JSON.stringify({ status: 'passed', ...result }, null, 2));
  console.log(JSON.stringify({ status: 'passed', ...result }));
} finally {
  await writeFile(`${evidence}/browser-stage.json`, JSON.stringify({ ...result, errors, url: page.url(), title: await page.title(), body: (await page.locator('body').innerText()).slice(0, 5000) }, null, 2));
  await page.screenshot({ path: `${evidence}/browser-final.png`, fullPage: true }).catch(() => {});
  await browser.close();
}
