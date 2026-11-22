// What an editor gets: every imported page opened in the block editor, as the
// editor parsed it. Each page lists its blocks with name, nesting depth,
// className and anchor, and whether the editor validated it, plus the wrapper
// tags and attribute names a generated block keeps (see shape.mjs). An invalid
// block shows "This block contains unexpected or invalid content" and cannot
// be edited until recovered.
//
// The site is a disposable import: its first administrator gets a fresh
// password for the session. `wpCli` runs WP-CLI against `site` by path.
import { wordpressRunner } from './wordpress.mjs';
import { randomBytes } from 'node:crypto';
import { chromium } from 'playwright';
import { listViewEvidence } from './list-view.mjs';
import { themeChecks } from './theme.mjs';
import { imageSubject } from './image-subject.mjs';
import { duplicatedContentOnPage } from './duplicated-content.mjs';
import { sampleCanvas, canvasParityMismatches, canvasParityEvidence, templatePartsRendered } from './canvas-parity.mjs';

// Text the user can edit: Gutenberg marks template and template-part blocks
// shown around the post as inert, and a rich-text block is itself the
// contenteditable element.
const EDITABLE_TEXT = ':is([data-type="core/paragraph"], [data-type="core/heading"])[contenteditable="true"]:not([inert])';

// Each Replace probe saves the post and loads the frontend, so the probes are
// bounded: a page with dozens of images must not dominate the whole run.
const SAVED_IMAGE_PROBES = 5;
// Blocks of each probed type (heading, paragraph, button, group) whose style controls are set.
const BLOCK_CONTROL_PROBES = 3;

export async function editorPages(wpUrl, site, wpCli, report = () => {}, cells, widths = [1440]) {
  const wp = wordpressRunner(site, wpCli);
  const user = wp('user', 'list', '--role=administrator', '--field=user_login').split('\n')[0];
  const pass = randomBytes(12).toString('hex');
  wp('user', 'update', user, `--user_pass=${pass}`, '--skip-email');
  // The Welcome Guide is a modal over the whole editor: until dismissed, no
  // toolbar button is reachable and no canvas block is clickable. Closing it
  // after load raced its render, and a guide that opened late failed every
  // editor check on every page as a site defect. Store it dismissed instead.
  wp('user', 'meta', 'update', user, 'wp_persisted_preferences', JSON.stringify({ 'core/edit-post': { welcomeGuide: false } }), '--format=json');
  // The editor checks use English accessible names: a translated admin UI
  // times out on those selectors and reports site defects. Set only the probe
  // user's locale so the site language and imported content stay unchanged.
  wp('user', 'meta', 'update', user, 'locale', 'en_US');
  const pages = JSON.parse(wp('post', 'list', '--post_type=page', '--post_status=publish', '--fields=ID,url', '--format=json'));

  const browser = await chromium.launch();
  try {
    // One context: every tab shares the login cookie.
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    page.setDefaultTimeout(10_000);
    await page.goto(new URL('/wp-login.php', wpUrl).href);
    await page.fill('#user_login', user);
    await page.fill('#user_pass', pass);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    // Each page's checks run in their own tab, a few pages at once: an editor
    // boot dominates and the site serves several requests in parallel
    // (PHP_CLI_SERVER_WORKERS). Results keep the site's page order.
    const todo = pages.filter(({ url }) => !cells || cells.some((c) => c.route == null || (c.route === new URL(url).pathname && (c.width == null || c.width === 1440))));
    const result = new Array(todo.length);
    const checkPage = async (page, { ID, url }) => {
      await page.goto(new URL(`/wp-admin/post.php?post=${ID}&action=edit`, wpUrl).href, { waitUntil: 'load', timeout: 120_000 });
      // Ready, not non-empty: a page with no content (WooCommerce's Shop page)
      // opens with zero blocks, and that is what an editor gets. The wait
      // shares the navigation's budget: on a loaded runner an editor takes
      // over 30s to boot, and a shorter wait reports a working editor as
      // editor_unreachable and refutes the fix under test.
      await page.waitForFunction(() => window.wp?.data?.select('core/editor')?.__unstableIsEditorReady?.(), null, { timeout: 120_000 });
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
      // The saved content before any probe edits it, rendered at each width:
      // content saved twice and shown at disjoint widths (duplicated-content.mjs).
      const original = wp('post', 'get', String(ID), '--field=post_content');
      let duplicates;
      try { duplicates = await duplicatedContentOnPage(browser, url, original, widths); }
      catch (error) { duplicates = { error: String(error.message || error) }; }
      // The subject is the check, so a finding's signature stays stable across
      // imports; `image` names the failing image in the evidence, so a Replace
      // failure is never anonymous. Upload paths carry the month and
      // WordPress's -1/-2 duplicate suffixes, so they cannot be a signature.
      const check = async (name, action, image) => {
        try { await action(); } catch (error) {
          // A Playwright call log ends with why an action failed (the element
          // that intercepts pointer events, say): keep its head and its tail.
          const text = String(error.message || error);
          const evidence = error.evidence ?? (text.length > 1000 ? `${text.slice(0, 400)} … ${text.slice(-600)}` : text);
          report({ stage: 'editability', check: name, owner_repo: 'blocks-engine', subject: name, route, width: 1440, evidence: image ? { image, error: evidence } : evidence });
        }
      };

      const canvas = page.locator('iframe[name="editor-canvas"]');
      const frame = await canvas.count() ? page.frameLocator('iframe[name="editor-canvas"]') : page;
      await check('editor_canvas_parity', async () => {
        // Editor-ready precedes the template parts around the post: wait for
        // each to have its blocks and drop its loading spinner, so the canvas
        // is sampled as an editor sees it. A part that never loads is sampled
        // as it stands and reports its own mismatch.
        await page.waitForFunction(templatePartsRendered, null, { timeout: 30_000 }).catch(() => {});
        await frame.locator('[data-type="core/template-part"] .components-spinner').first().waitFor({ state: 'detached', timeout: 15_000 }).catch(() => {});
        const front = await browser.newPage({ viewport: { width: 1440, height: 900 } });
        try {
          await front.goto(url);
          const a = await frame.locator('.editor-styles-wrapper, .is-root-container').first().evaluate(sampleCanvas);
          const b = await front.locator('main, .wp-site-blocks').first().evaluate(sampleCanvas);
          const missing = canvasParityMismatches(a, b);
          if (missing.length) throw new Error(canvasParityEvidence(a, b, missing));
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
              // Replace opens with the image's current attachment selected, and
              // clicking a selected attachment deselects it, leaving Select
              // disabled: choose one that is not selected, and wait for it to
              // be checked before selecting.
              const choice = dialog.locator('.attachment:not(.selected)').first();
              await choice.waitFor();
              await choice.click();
              await dialog.locator('.attachment[aria-checked="true"]').first().waitFor();
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
        // A block's own style controls must style it: set text color, font
        // size and top padding on a few headings, paragraphs, buttons and
        // groups through the editor, save, and require the front end to show
        // all three on that block, with its visible text in that color. Import
        // CSS that outranks block settings (a class rule on the block or on a
        // span inside it) fails here, so the design is not editable even when
        // the editor opens. Text inside a block with its own color setting
        // keeps that setting. Each block that fails counts once.
        await check('editor_block_controls', async () => {
          const picked = await page.evaluate(({ per }) => {
            const { select, dispatch } = window.wp.data;
            // The block editor's own tree: in template mode the post's blocks
            // sit under core/post-content, beside the template's inert blocks.
            const be = select('core/block-editor');
            const ids = be.getClientIdsWithDescendants();
            const content = ids.find((id) => be.getBlockName(id) === 'core/post-content');
            const all = ids.filter((id) => !content || be.getBlockParents(id).includes(content)).map((id) => be.getBlock(id));
            const chosen = ['core/heading', 'core/paragraph', 'core/button', 'core/group'].flatMap((name) => all.filter((b) => b.name === name).slice(0, per));
            return chosen.map((b, i) => {
              const color = `#0b1c${(16 + i).toString(16)}`;
              const style = b.attributes.style || {};
              // As the inspector does: a custom value replaces the block's preset.
              dispatch('core/block-editor').updateBlockAttributes(b.clientId, { textColor: undefined, fontSize: undefined, style: { ...style, color: { ...style.color, text: color }, typography: { ...style.typography, fontSize: '37px' }, spacing: { ...style.spacing, padding: { ...style.spacing?.padding, top: '23px' } } } });
              return { name: b.name, className: b.attributes.className || '', color, rgb: `rgb(11, 28, ${16 + i})` };
            });
          }, { per: BLOCK_CONTROL_PROBES });
          if (!picked.length) return;
          await page.evaluate(async () => { await window.wp.data.dispatch('core/editor').savePost(); });
          const front = await browser.newPage({ viewport: { width: 1440, height: 900 } });
          try {
            await front.goto(url);
            const failing = await front.evaluate((picked) => picked.map((p) => {
              const rgb = (c) => { const m = c.match(/color\(srgb ([\d.]+) ([\d.]+) ([\d.]+)/); return m ? `rgb(${m.slice(1, 4).map((v) => Math.round(v * 255)).join(', ')})` : c.replace(/rgba\((\d+), (\d+), (\d+), 1\)/, 'rgb($1, $2, $3)'); };
              // Find the block by the color it was given, as written or as a
              // script reserializes it (el.style rewrites hex as rgb()).
              const el = [...document.querySelectorAll('[style]')].find((n) => { const style = n.getAttribute('style').toLowerCase(); return style.includes(p.color) || style.includes(p.rgb); });
              if (!el) return { ...p, missing: ['rendered'] };
              const cs = getComputedStyle(el);
              const missing = [];
              if (rgb(cs.color) !== p.rgb) missing.push(`color ${cs.color}`);
              if (cs.fontSize !== '37px') missing.push(`font-size ${cs.fontSize}`);
              if (cs.paddingTop !== '23px') missing.push(`padding-top ${cs.paddingTop}`);
              const owned = (n) => /\bhas-[\w-]*color\b/.test(n.className || '') || /(^|;)\s*color\s*:/i.test(n.getAttribute('style') || '');
              const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
              for (let t = walker.nextNode(); t; t = walker.nextNode()) {
                const host = t.parentElement;
                if (!t.textContent.trim() || !host.getClientRects().length) continue;
                let own = false;
                for (let n = host; n && n !== el; n = n.parentElement) if (owned(n)) { own = true; break; }
                if (!own && rgb(getComputedStyle(host).color) !== p.rgb) { missing.push(`text color ${getComputedStyle(host).color} on <${host.tagName.toLowerCase()} class="${host.className}">`); break; }
              }
              return missing.length ? { ...p, missing } : null;
            }).filter(Boolean), picked);
            if (failing.length) throw Object.assign(new Error(`${failing.length} of ${picked.length} blocks ignore their own style controls`), { evidence: { count: failing.length, probed: picked.length, failing: failing.map(({ name, className, missing }) => ({ name, className, missing })) } });
          } finally { await front.close(); }
        });
      } finally {
        const file = `${site}/.import-loop-restore-${ID}.html`;
        const { writeFileSync, unlinkSync } = await import('node:fs');
        writeFileSync(file, original);
        try { wp('post', 'update', String(ID), file); } finally { unlinkSync(file); }
      }
      return { id: ID, route, blocks, duplicates };
    };
    let next = 0;
    const tab = async () => {
      const own = await context.newPage();
      own.setDefaultTimeout(10_000);
      try { while (next < todo.length) { const at = next++; result[at] = await checkPage(own, todo[at]); } } finally { await own.close(); }
    };
    await Promise.all(Array.from({ length: Math.min(todo.length, Number(process.env.IMPORT_LOOP_EDITOR_TABS) || 4) }, tab));
    try { await themeChecks(page, wpUrl, result.map((p) => p.route), report); }
    catch (error) { report({ stage: 'theme', check: 'theme_checks_unverified', owner_repo: 'static-site-importer', subject: 'site_editor', evidence: String(error.message || error) }); }
    return result;
  } finally {
    await browser.close();
  }
}
