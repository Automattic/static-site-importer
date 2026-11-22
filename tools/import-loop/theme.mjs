// Authenticated Playwright checks; REST requests run in the owner's browser
// with its nonce, not from unauthenticated external report counters.
import { styleTokens, themeCss, QUALITY } from './quality.mjs';
import { sourceCss, themeCssWeight } from './source-css.mjs';
// Each area's chrome is template-owned: every template references one of the
// area's parts, and every part of the area serves some template. A route
// that presents the site's header or footer its own way (a landing page's
// call to action) renders its own variant part from its own template, so an
// area may have several parts. No page carries a header or footer copy.
export function shellFindings(templates, parts, pages) {
  const findings = [];
  const references = (t) => [...t.content.raw.matchAll(/<!-- wp:template-part (\{[^]*?\}) \/?-->/g)].map((m) => { try { return JSON.parse(m[1]).slug; } catch { return null; } });
  for (const area of ['header', 'footer']) {
    const shared = parts.filter((p) => p.area === area || p.slug === area).map((p) => p.slug);
    const missing = templates.filter((t) => !references(t).some((slug) => shared.includes(slug))).map((t) => t.slug);
    const unreferenced = shared.filter((slug) => !templates.some((t) => references(t).includes(slug)));
    if (!shared.length || missing.length || unreferenced.length) findings.push(['theme_shared_shell', { area, parts: shared, templates_missing: missing, unreferenced }]);
    if (pages.some((p) => new RegExp(`<${area}[ >]`, 'i').test(p.content.raw))) findings.push(['theme_page_shell_copy', area]);
  }
  return findings;
}
export async function themeChecks(page, wpUrl, routes, report, captureRoot) {
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
  for (const [check, evidence] of shellFindings(templates, parts, pages)) fail(check, evidence);
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
      const evidence = themeCssWeight(css, sourceCss(captureRoot, route));
      if (evidence.kb > evidence.limit_kb) report({ stage: 'theme', check: 'theme_css_weight', owner_repo: 'blocks-engine', subject: 'theme_css_weight', route, evidence });
    }
    const before = await observe();
    const styles = structuredClone(original.styles || {});
    styles.color = { ...styles.color, background: '#132435', text: '#e1d2c3' };
    styles.typography = { ...styles.typography, fontFamily: 'monospace' };
    styles.elements = { ...styles.elements, link: { color: { text: '#bada55' } }, button: { color: { background: '#bada55', text: '#132435' } }, heading: { ...styles.elements?.heading, color: { text: '#f0c419' }, typography: { ...styles.elements?.heading?.typography, fontFamily: 'monospace' } } };
    await api(`/wp/v2/global-styles/${entity.id}`, 'POST', { styles });
    const after = await observe();
    // Style ownership: after the restyle, each visible text element's color
    // and font must come from WordPress, either following Global Styles
    // (text, heading, link and button elements) or set by a block's own
    // settings (a has-*-color / has-*-font-family class or inline style on it
    // or a block around it). What is left is styled by imported CSS that
    // Global Styles cannot reach. The unowned share is measured against a
    // limit, so a fix that moves part of the design into WordPress shows as
    // progress.
    for (const route of routes) {
      await front.goto(new URL(route, wpUrl).href);
      const owned = await front.evaluate(styleOwnership);
      if (owned.total && owned.unowned / owned.total > QUALITY.unowned_style_share) report({ stage: 'theme', check: 'theme_style_ownership', owner_repo: 'blocks-engine', subject: 'theme_style_ownership', route, evidence: { ...owned, measure: { value: Math.round((owned.unowned / owned.total) * 1000) / 1000, limit: QUALITY.unowned_style_share } } });
    }
    for (let i = 0; i < after.length; i++) {
      if (after[i][0]?.[0] !== 'rgb(19, 36, 53)' || after[i][0]?.[1] !== 'rgb(225, 210, 195)' || !after[i][0]?.[2].includes('monospace') || JSON.stringify(before[i]) === JSON.stringify(after[i])) fail('theme_live_restyle', { route: routes[i], before: before[i], after: after[i] });
    }
  } finally {
    await api(`/wp/v2/global-styles/${entity.id}`, 'POST', { styles: original.styles, settings: original.settings });
    await frontContext.close();
  }
}

// Runs in the page after the restyle above. Counts each visible text
// element's color and font as owned when it shows the restyled value or a
// block setting explains it, and samples the unowned ones by tag and class so
// a Cook can find the CSS that holds them.
export function styleOwnership() {
  const expected = { text: 'rgb(225, 210, 195)', heading: 'rgb(240, 196, 25)', link: 'rgb(186, 218, 85)', button: 'rgb(19, 36, 53)' };
  const root = document.querySelector('.wp-site-blocks') || document.body;
  const set = (n, prop) => {
    for (let at = n; at && at !== root.parentElement; at = at.parentElement) {
      const cls = typeof at.className === 'string' ? at.className : '';
      const style = at.getAttribute?.('style') || '';
      if (prop === 'color' && (/\bhas-[\w-]*(text|link)-color\b|\bhas-text-color\b/.test(cls) || /(^|;)\s*color\s*:/i.test(style))) return true;
      if (prop === 'font' && (/\bhas-[\w-]*font-family\b/.test(cls) || /font-family\s*:/i.test(style))) return true;
    }
    return false;
  };
  const rgb = (c) => { const m = c.match(/color\(srgb ([\d.]+) ([\d.]+) ([\d.]+)/); return m ? `rgb(${m.slice(1, 4).map((v) => Math.round(v * 255)).join(', ')})` : c.replace(/rgba\((\d+), (\d+), (\d+), 1\)/, 'rgb($1, $2, $3)'); };
  const kind = (n) => (n.matches('.wp-block-button__link, .wp-element-button') ? 'button' : n.matches('a') ? 'link' : n.matches('h1,h2,h3,h4,h5,h6') ? 'heading' : 'text');
  const nodes = [...root.querySelectorAll('h1,h2,h3,h4,h5,h6,p,li,a,blockquote,figcaption,.wp-block-button__link')]
    .filter((n) => n.getClientRects().length && [...n.childNodes].some((c) => c.nodeType === 3 && c.textContent.trim()))
    .slice(0, 400);
  let total = 0, unowned = 0;
  const byKind = {}, sample = [];
  for (const n of nodes) {
    const k = kind(n), cs = getComputedStyle(n);
    for (const [prop, ok] of [['color', rgb(cs.color) === expected[k]], ['font', /monospace/i.test(cs.fontFamily)]]) {
      total++;
      if (ok || set(n, prop)) continue;
      unowned++;
      byKind[`${k}.${prop}`] = (byKind[`${k}.${prop}`] || 0) + 1;
      if (sample.length < 12) sample.push({ tag: n.tagName.toLowerCase(), class: String(n.className).slice(0, 160), prop, value: prop === 'color' ? cs.color : cs.fontFamily.slice(0, 60) });
    }
  }
  return { total, unowned, by_kind: byKind, sample };
}
