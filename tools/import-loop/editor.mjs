// What an editor gets: every imported page opened in the block editor, as the
// editor parsed it. Each page lists its blocks with name, nesting depth,
// className and anchor, and whether the editor validated it, plus the wrapper
// tags and attribute names a generated block keeps (see shape.mjs). An invalid
// block shows "This block contains unexpected or invalid content" and cannot
// be edited until recovered.
//
// The site is a disposable import: its first administrator gets a fresh
// password for the session. `wpCli` runs WP-CLI against `site` by path.
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { chromium } from 'playwright';
import { listViewEvidence } from './list-view.mjs';
import { themeChecks } from './theme.mjs';
import { imageSubject } from './image-subject.mjs';

// Text the user can edit: Gutenberg marks template and template-part blocks
// shown around the post as inert, and a rich-text block is itself the
// contenteditable element.
const EDITABLE_TEXT = ':is([data-type="core/paragraph"], [data-type="core/heading"])[contenteditable="true"]:not([inert])';

// Each Replace probe saves the post and loads the frontend, so the probes are
// bounded: a page with dozens of images must not dominate the whole run.
const SAVED_IMAGE_PROBES = 5;

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
        const walk = (list, depth, parent) => list.forEach((b) => {
          const { className, anchor } = b.attributes || {};
          const at = all.push({ name: b.name, valid: b.isValid, depth, parent, ...(className ? { className } : {}), ...(anchor ? { anchor } : {}), wrappers: Array.isArray(b.attributes?.wrappers) ? b.attributes.wrappers.map((w) => ({ tagName: w?.tagName, attributes: Object.fromEntries(Object.keys(w?.attributes || {}).map((n) => [n, ''])) })) : undefined }) - 1;
          walk(b.innerBlocks, depth + 1, at);
        });
        walk(window.wp.data.select('core/editor').getEditorBlocks(), 1, -1);
        return all;
      });
      const route = new URL(url).pathname;
      // A finding's subject names the defect: callers may pass an image
      // identity so a Replace failure is never reported anonymously.
      const check = async (name, action, subject) => {
        try { await action(); } catch (error) { report({ stage: 'editability', check: name, owner_repo: 'blocks-engine', subject: subject || name, route, width: 1440, evidence: error.evidence ?? String(error.message || error).slice(0, 500) }); }
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
        await frame.locator(EDITABLE_TEXT).first().click();
        if (!await page.locator('.block-editor-block-toolbar, .block-editor-block-inspector').count()) throw new Error('No block toolbar or inspector for selected text');
      });
      await check('editor_list_view', async () => {
        await page.getByRole('button', { name: /document overview|list view/i }).first().click();
        const tree = page.locator('.block-editor-list-view-tree');
        await tree.waitFor();
        // The fixed evidence string gave an agent no path, counts, or target,
        // so its attempts were blind (issue #2956): report the counts and the
        // deepest chain, whose classNames and anchors map to the capture's DOM.
        if (blocks.filter((b) => b.name === 'core/group').length > blocks.length / 2 && Math.max(...blocks.map((b) => b.depth)) > 8) throw Object.assign(new Error('List View dominated by deeply nested groups'), { evidence: listViewEvidence(blocks) });
      });
      // Save real edits, then restore the exact original post even on failure.
      const original = wp('post', 'get', String(ID), '--field=post_content');
      const marker = `Import loop edit ${randomBytes(5).toString('hex')}`;
      try {
        await check('editor_saved_text', async () => {
          const text = frame.locator(EDITABLE_TEXT).first();
          await text.fill(marker);
          await page.evaluate(async () => { await window.wp.data.dispatch('core/editor').savePost(); });
          const front = await browser.newPage();
          try {
            await front.goto(url);
            // textContent is the saved text as written; innerText applies CSS such as text-transform.
            if (!(await front.locator('body').textContent()).includes(marker)) throw new Error('Saved text absent from frontend');
          } finally { await front.close(); }
        });
        // Probe every non-inert image up to a small bound: a fix that moves a
        // header (with its logo) into a template part used to shift this probe
        // onto an unrelated image, and its Replace timeout was scored against
        // the fix without naming any image at all. Each probe reports its own
        // image by src or anchor, so a failure points at the defect.
        if (blocks.some((b) => b.name === 'core/image')) {
          const images = frame.locator('[data-type="core/image"]:not([inert])');
          const probes = Math.min(await images.count(), SAVED_IMAGE_PROBES);
          for (let index = 0; index < probes; index++) {
            const image = images.nth(index);
            const identity = await image.evaluate((figure) => ({ src: figure.querySelector('img')?.getAttribute('src') ?? null, anchor: figure.querySelector('a[href]')?.getAttribute('href') ?? null })).catch(() => ({}));
            await check('editor_saved_image', async () => {
              await image.click();
              await page.getByRole('button', { name: /replace/i }).first().click();
              await page.getByRole('menuitem', { name: /media library/i }).click();
              const dialog = page.getByRole('dialog');
              await dialog.waitFor();
              // The modal can open on "Upload files"; choosing from the library is a tab away.
              await dialog.getByRole('tab', { name: /media library/i }).click();
              const choices = dialog.locator('.attachment');
              await choices.nth(1).waitFor();
              await choices.nth(1).click();
              await dialog.getByRole('button', { name: /select|replace/i }).last().click();
              await page.evaluate(async () => { await window.wp.data.dispatch('core/editor').savePost(); });
              const replaced = await image.locator('img').first().getAttribute('src');
              const front = await browser.newPage();
              try {
                await front.goto(url);
                const paths = await front.locator('img').evaluateAll((list) => list.map((i) => new URL(i.src).pathname));
                if (!replaced || !paths.includes(new URL(replaced, wpUrl).pathname)) throw new Error('Replacement image absent from frontend');
              } finally { await front.close(); }
            }, imageSubject(wpUrl, identity, index));
            // A failed probe can leave the media modal open: dismiss it so the
            // next probe fails on its own image, not on a leftover overlay.
            if (await page.getByRole('dialog').count()) {
              await page.keyboard.press('Escape');
              await page.getByRole('dialog').waitFor({ state: 'hidden', timeout: 2500 }).catch(() => {});
            }
          }
        }
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
