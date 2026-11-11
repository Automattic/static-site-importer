// What an editor gets: every imported page opened in the block editor, as the
// editor parsed it. Each page lists its blocks with name, nesting depth, and
// whether the editor validated it, plus the wrapper tags and attribute names a
// generated block keeps (see shape.mjs). An invalid block shows "This block contains
// unexpected or invalid content" and cannot be edited until recovered.
//
// The site is a disposable import: its first administrator gets a fresh
// password for the session. `wpCli` runs WP-CLI against `site` by path.
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { chromium } from 'playwright';
import { themeChecks } from './theme.mjs';

export async function editorPages(wpUrl, site, wpCli, report = () => {}) {
  const [command, ...prefix] = wpCli.split(/\s+/);
  const wp = (...argv) => execFileSync(command, [...prefix, `--path=${site}`, ...argv], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
  const user = wp('user', 'list', '--role=administrator', '--field=user_login').split('\n')[0];
  const pass = randomBytes(12).toString('hex');
  wp('user', 'update', user, `--user_pass=${pass}`, '--skip-email');
  const pages = JSON.parse(wp('post', 'list', '--post_type=page', '--post_status=publish', '--fields=ID,url', '--format=json'));

  const browser = await chromium.launch();
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    page.setDefaultTimeout(10_000);
    await page.goto(new URL('/wp-login.php', wpUrl).href);
    await page.fill('#user_login', user);
    await page.fill('#user_pass', pass);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    const result = [];
    for (const { ID, url } of pages) {
      await page.goto(new URL(`/wp-admin/post.php?post=${ID}&action=edit`, wpUrl).href, { waitUntil: 'load', timeout: 120_000 });
      await page.waitForFunction(() => window.wp?.data?.select('core/editor')?.getEditorBlocks().length > 0, null, { timeout: 30_000 });
      const blocks = await page.evaluate(() => {
        const all = [];
        const walk = (list, depth) => list.forEach((b) => { all.push({ name: b.name, valid: b.isValid, depth, wrappers: Array.isArray(b.attributes?.wrappers) ? b.attributes.wrappers.map((w) => ({ tagName: w?.tagName, attributes: Object.fromEntries(Object.keys(w?.attributes || {}).map((n) => [n, ''])) })) : undefined }); walk(b.innerBlocks, depth + 1); });
        walk(window.wp.data.select('core/editor').getEditorBlocks(), 1);
        return all;
      });
      const route = new URL(url).pathname;
      const check = async (name, action) => {
        try { await action(); } catch (error) { report({ stage: 'editability', check: name, owner_repo: 'blocks-engine', subject: name, route, width: 1440, evidence: String(error.message || error).slice(0, 500) }); }
      };
      await page.getByRole('button', { name: /close/i }).first().click({ timeout: 1500 }).catch(() => {});
      const canvas = page.locator('iframe[name="editor-canvas"]');
      const frame = await canvas.count() ? page.frameLocator('iframe[name="editor-canvas"]') : page;
      await check('editor_canvas_parity', async () => {
        const front = await browser.newPage({ viewport: { width: 1440, height: 900 } });
        try {
          await front.goto(url);
          const sample = (root) => [...root.querySelectorAll('h1,h2,p,figure img')].filter((e) => e.getBoundingClientRect().width > 0).slice(0, 40).map((e) => ({ text: e.textContent.trim(), tag: e.tagName, color: getComputedStyle(e).color, font: getComputedStyle(e).fontFamily }));
          const a = await frame.locator('.editor-styles-wrapper, .is-root-container').first().evaluate(sample);
          const b = await front.locator('main, .wp-site-blocks').first().evaluate(sample);
          if (!a.length || a.some((e) => !b.some((f) => e.tag === f.tag && e.text === f.text && e.color === f.color && e.font === f.font))) throw new Error(JSON.stringify({ editor: a, frontend: b }));
        } finally { await front.close(); }
      });
      await check('editor_real_controls', async () => {
        await frame.locator('[data-type="core/paragraph"], [data-type="core/heading"]').first().click();
        if (!await page.locator('.block-editor-block-toolbar, .block-editor-block-inspector').count()) throw new Error('No block toolbar or inspector for selected text');
      });
      await check('editor_list_view', async () => {
        await page.getByRole('button', { name: /document overview|list view/i }).first().click();
        const tree = page.locator('.block-editor-list-view-tree');
        await tree.waitFor();
        if (blocks.filter((b) => b.name === 'core/group').length > blocks.length / 2 && Math.max(...blocks.map((b) => b.depth)) > 8) throw new Error('List View dominated by deeply nested groups');
      });
      // Save real edits, then restore the exact original post even on failure.
      const original = wp('post', 'get', String(ID), '--field=post_content');
      const marker = `Import loop edit ${randomBytes(5).toString('hex')}`;
      try {
        await check('editor_saved_text', async () => {
          const text = frame.locator('[data-type="core/paragraph"] [contenteditable="true"], [data-type="core/heading"][contenteditable="true"]').first();
          await text.fill(marker);
          await page.evaluate(async () => { await window.wp.data.dispatch('core/editor').savePost(); });
          const front = await browser.newPage();
          try {
            await front.goto(url);
            if (!(await front.locator('body').innerText()).includes(marker)) throw new Error('Saved text absent from frontend');
          } finally { await front.close(); }
        });
        if (blocks.some((b) => b.name === 'core/image')) await check('editor_saved_image', async () => {
          await frame.locator('[data-type="core/image"]').first().click();
          await page.getByRole('button', { name: /replace/i }).first().click();
          await page.getByRole('menuitem', { name: /media library/i }).click();
          const dialog = page.getByRole('dialog');
          await dialog.waitFor();
          const choices = dialog.locator('.attachment');
          await choices.nth(1).waitFor();
          await choices.nth(1).click();
          await dialog.getByRole('button', { name: /select|replace/i }).last().click();
          await page.evaluate(async () => { await window.wp.data.dispatch('core/editor').savePost(); });
          const image = await frame.locator('[data-type="core/image"] img').first().getAttribute('src');
          const front = await browser.newPage();
          try {
            await front.goto(url);
            const paths = await front.locator('img').evaluateAll((images) => images.map((i) => new URL(i.src).pathname));
            if (!image || !paths.includes(new URL(image, wpUrl).pathname)) throw new Error('Replacement image absent from frontend');
          } finally { await front.close(); }
        });
      } finally {
        const file = `${site}/.import-loop-restore-${ID}.html`;
        const { writeFileSync, unlinkSync } = await import('node:fs');
        writeFileSync(file, original);
        try { wp('post', 'update', String(ID), file); } finally { unlinkSync(file); }
      }
      result.push({ id: ID, route, blocks });
    }
    try { await themeChecks(page, wpUrl, result.map((p) => p.route), report); }
    catch (error) { report({ stage: 'theme', check: 'theme_checks_unverified', owner_repo: 'static-site-importer', subject: 'site_editor', evidence: String(error.message || error) }); }
    return result;
  } finally {
    await browser.close();
  }
}
