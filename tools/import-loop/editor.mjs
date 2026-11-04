// What an editor gets: every imported page opened in the block editor, as the
// editor parsed it. Each page lists its blocks with name, nesting depth, and
// whether the editor validated it (an invalid block shows "This block contains
// unexpected or invalid content" and cannot be edited until recovered).
//
// The site is a disposable import: its first administrator gets a fresh
// password for the session. `wpCli` runs WP-CLI against `site` by path.
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { chromium } from 'playwright';

export async function editorPages(wpUrl, site, wpCli) {
  const [command, ...prefix] = wpCli.split(/\s+/);
  const wp = (...argv) => execFileSync(command, [...prefix, `--path=${site}`, ...argv], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
  const user = wp('user', 'list', '--role=administrator', '--field=user_login').split('\n')[0];
  const pass = randomBytes(12).toString('hex');
  wp('user', 'update', user, `--user_pass=${pass}`, '--skip-email');
  const pages = JSON.parse(wp('post', 'list', '--post_type=page', '--post_status=publish', '--fields=ID,url', '--format=json'));

  const browser = await chromium.launch();
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.goto(new URL('/wp-login.php', wpUrl).href);
    await page.fill('#user_login', user);
    await page.fill('#user_pass', pass);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    const result = [];
    for (const { ID, url } of pages) {
      await page.goto(new URL(`/wp-admin/post.php?post=${ID}&action=edit`, wpUrl).href, { waitUntil: 'load', timeout: 120_000 });
      await page.waitForFunction(() => window.wp?.data?.select('core/editor')?.getEditorBlocks().length > 0, null, { timeout: 120_000 });
      const blocks = await page.evaluate(() => {
        const all = [];
        const walk = (list, depth) => list.forEach((b) => { all.push({ name: b.name, valid: b.isValid, depth }); walk(b.innerBlocks, depth + 1); });
        walk(window.wp.data.select('core/editor').getEditorBlocks(), 1);
        return all;
      });
      result.push({ id: ID, route: new URL(url).pathname, blocks });
    }
    return result;
  } finally {
    await browser.close();
  }
}
