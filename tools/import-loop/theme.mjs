// Authenticated Playwright checks; REST requests run in the owner's browser
// with its nonce, not from unauthenticated external report counters.
import { styleTokens, themeCss, QUALITY } from './quality.mjs';
export async function themeChecks(page, wpUrl, routes, report) {
  await page.goto(new URL('/wp-admin/site-editor.php', wpUrl).href, { waitUntil: 'load' });
  await page.waitForFunction(() => window.wp?.apiFetch, null, { timeout: 120_000 });
  // apiFetch rejects with a plain REST error object, which Playwright reduces
  // to "Object": surface its path, code and message instead.
  const api = async (pathname, method = 'GET', data) => {
    const result = await page.evaluate(async ({ pathname, method, data }) => {
      try { return { ok: await window.wp.apiFetch({ path: pathname, method, data }) }; } catch (error) { return { error: { path: pathname, method, code: error?.code, status: error?.data?.status, message: error?.message } }; }
    }, { pathname, method, data });
    if (result.error) throw new Error(`apiFetch ${JSON.stringify(result.error)}`);
    return result.ok;
  };
  const fail = (check, evidence) => report({ stage: 'theme', check, owner_repo: 'blocks-engine', subject: check, evidence });
  const templates = await api('/wp/v2/templates?context=edit&per_page=100');
  const parts = await api('/wp/v2/template-parts?context=edit&per_page=100');
  const pages = await api('/wp/v2/pages?context=edit&per_page=100');
  const navigation = await api('/wp/v2/navigation?context=edit&per_page=100');
  if (!templates.some((t) => ['page', 'index'].includes(t.slug))) fail('theme_templates', 'No page or fallback index template');
  for (const area of ['header', 'footer']) {
    const shared = parts.filter((p) => p.area === area || p.slug === area);
    if (shared.length !== 1 || templates.some((t) => !t.content.raw.includes(`"slug":"${shared[0]?.slug}"`))) fail('theme_shared_shell', { area, parts: shared.map((p) => p.slug) });
    if (pages.some((p) => new RegExp(`<${area}[ >]`, 'i').test(p.content.raw))) fail('theme_page_shell_copy', area);
  }
  const menus = [...templates, ...parts, ...pages].flatMap((p) => [...p.content.raw.matchAll(/<!-- wp:navigation\b([^]*?)-->/g)].map((m) => m[1]));
  if (!menus.length || menus.some((m) => !navigation.some((n) => m.includes(`"ref":${n.id}`)))) fail('theme_saved_navigation', { menus, saved: navigation.map((n) => n.id) });
  const tokens = styleTokens([...templates, ...parts, ...pages].map((p) => p.content.raw).join('\n'));
  if (tokens.share > QUALITY.literal_share) fail('theme_literal_style', { ...tokens, threshold: QUALITY.literal_share, measure: { value: tokens.share, limit: QUALITY.literal_share } });
  const themes = await api('/wp/v2/themes?status=active');
  const theme = themes[0]?.stylesheet;
  if (!theme) throw new Error('Active theme unavailable');
  const current = await api(`/wp/v2/global-styles/themes/${encodeURIComponent(theme)}`);
  const settings = current.settings || {};
  const presets = [settings.color?.palette, settings.typography?.fontFamilies, settings.typography?.fontSizes, settings.spacing?.spacingSizes];
  if (presets.some((p) => !p || !Object.keys(p).length)) fail('theme_presets', settings);
  if (['link', 'button', 'heading'].some((name) => !current.styles?.elements?.[name])) fail('theme_element_styles', current.styles?.elements);
  // Core exposes the user's Global Styles post only as a link on the active
  // theme; /wp/v2/global-styles has no collection route.
  const href = themes[0]?._links?.['wp:user-global-styles']?.[0]?.href;
  const entity = { id: href && decodeURIComponent(href).match(/global-styles\/(\d+)/)?.[1] };
  if (!entity.id) { fail('theme_live_restyle', 'No editable Global Styles entity'); return; }
  const original = await api(`/wp/v2/global-styles/${entity.id}?context=edit`);
  // The editor page owns a browser.newPage() context, which cannot open more
  // pages: observe the front end in a sibling context with the same session.
  const frontContext = await page.context().browser().newContext({ storageState: await page.context().storageState() });
  const front = await frontContext.newPage();
  const observe = async () => {
    const snapshots = [];
    for (const route of routes) {
      await front.goto(new URL(route, wpUrl).href);
      snapshots.push(await front.evaluate(() => {
        const nodes = [document.body, ...document.querySelectorAll('main p, main h1, main a, main button, .wp-block-button__link')];
        return nodes.slice(0, 60).map((n) => { const s = getComputedStyle(n); return [s.backgroundColor, s.color, s.fontFamily]; });
      }));
    }
    return snapshots;
  };
  try {
    // The CSS each route loads, before the restyle below changes Global Styles.
    for (const route of routes) {
      await front.goto(new URL(route, wpUrl).href);
      const css = themeCss(await front.evaluate(async () => [
        ...await Promise.all([...document.querySelectorAll('link[rel=stylesheet]')].map(async (l) => ({ href: l.href, bytes: (await (await fetch(l.href)).text()).length }))),
        ...[...document.querySelectorAll('style')].map((s) => ({ id: s.id, bytes: s.textContent.length })),
      ]));
      if (css.kb > QUALITY.theme_css_kb) report({ stage: 'theme', check: 'theme_css_weight', owner_repo: 'blocks-engine', subject: 'theme_css_weight', route, evidence: { ...css, threshold_kb: QUALITY.theme_css_kb, measure: { value: css.kb, limit: QUALITY.theme_css_kb } } });
    }
    const before = await observe();
    const styles = structuredClone(original.styles || {});
    styles.color = { ...styles.color, background: '#132435', text: '#e1d2c3' };
    styles.typography = { ...styles.typography, fontFamily: 'monospace' };
    styles.elements = { ...styles.elements, link: { color: { text: '#bada55' } }, button: { color: { background: '#bada55', text: '#132435' } } };
    await api(`/wp/v2/global-styles/${entity.id}`, 'POST', { styles });
    const after = await observe();
    for (let i = 0; i < after.length; i++) {
      if (after[i][0]?.[0] !== 'rgb(19, 36, 53)' || after[i][0]?.[1] !== 'rgb(225, 210, 195)' || !after[i][0]?.[2].includes('monospace') || JSON.stringify(before[i]) === JSON.stringify(after[i])) fail('theme_live_restyle', { route: routes[i], before: before[i], after: after[i] });
    }
  } finally {
    await api(`/wp/v2/global-styles/${entity.id}`, 'POST', { styles: original.styles, settings: original.settings });
    await frontContext.close();
  }
}
