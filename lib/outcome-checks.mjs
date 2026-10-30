import { createHash } from 'node:crypto';
import { documentOutcomes } from './document-outcomes.mjs';
import { interactiveOutcomes } from './interactive-outcomes.mjs';
import { validateOutcomeDocuments } from './outcome-editor-validation.mjs';
const REQUIRED = ['link_graph', 'block_validity', 'head_singletons', 'control_inventory', 'forms', 'readability', 'full_document', 'theme_independence'];
const TYPES = ['post', 'page', 'wp_template', 'wp_template_part', 'wp_navigation'];
const CHROME_TYPES = ['wp_navigation', 'wp_template_part'];
const CHROME_TARGET_BOUND = 25;
const CSS_IMPORT_DEPTH_BOUND = 5;
export const OUTCOME_SCHEMA = 'static-site-importer/outcome-checks/v1';
export const THEME_OWNER_SNAPSHOT_SCHEMA = 'static-site-importer/theme-owner-snapshot/v1';
export function unavailableOutcomes(candidateOrigin, routes, importRunId) {
  return { schema: OUTCOME_SCHEMA, candidate_origin: candidateOrigin, routes, import_run_id: importRunId, status: 'pending', checks: Object.fromEntries(REQUIRED.map(name => [name, unsupported('runtime_evidence_unavailable')])) };
}
const unsupported = reason => ({ status: 'unsupported', evidence: [{ reason }] });
const result = (evidence, failures) => ({ status: failures.length ? 'fail' : 'pass', evidence, failures });

/** Canonical JSON so caller-built snapshots compare and hash identically. */
const stable = value => {
  if (Array.isArray(value)) return `[${value.map(stable).join(',')}]`;
  if (value && typeof value === 'object') return `{${Object.keys(value).sort().map(key => `${JSON.stringify(key)}:${stable(value[key])}`).join(',')}}`;
  return JSON.stringify(value ?? null);
};
const snapshotHash = snapshot => createHash('sha256').update(stable(snapshot)).digest('hex');

/** Difference paths between two snapshots; the active theme is the one fact
 * allowed to change under the probe theme, so it never counts as a difference. */
function snapshotDifferences(baseline, candidate, prefix = '') {
  if (stable(baseline) === stable(candidate)) return [];
  if (!baseline || !candidate || typeof baseline !== 'object' || typeof candidate !== 'object' || Array.isArray(baseline) !== Array.isArray(candidate)) return [prefix || '$'];
  const keys = [...new Set([...Object.keys(baseline), ...Object.keys(candidate)])].filter(key => !(prefix === 'settings' && key === 'active_theme'));
  const differences = [];
  for (const key of keys) {
    const path = prefix ? `${prefix}.${key}` : key;
    if (stable(baseline[key]) !== stable(candidate[key])) {
      if (baseline[key] && candidate[key] && typeof baseline[key] === 'object' && typeof candidate[key] === 'object' && !Array.isArray(baseline[key]) && !Array.isArray(candidate[key])) differences.push(...snapshotDifferences(baseline[key], candidate[key], path));
      else differences.push(path);
    }
    if (differences.length >= 20) return differences;
  }
  return differences;
}

/** Only measured failures fail acceptance; unsupported checks keep it pending. */
export function outcomeGate(report) {
  const checks = REQUIRED.map(name => report?.checks?.[name]);
  const schemes = report?.colour_schemes;
  const documentCoverage = Array.isArray(schemes) && schemes.length > 0 && schemes.every(scheme => ['light', 'dark'].includes(scheme))
    && report?.routes?.length > 0 && report?.widths?.length > 0
    && ['readability', 'full_document'].every(name => report.routes.every(route => report.widths.every(width => schemes.every(scheme => report.checks?.[name]?.evidence?.some(row => row.route === route && row.width === width && row.colour_scheme === scheme && row.status === 'pass')))));
  const themeCoverage = report?.routes?.length > 0 && report.routes.every(route => report.checks?.theme_independence?.evidence?.some(row => row?.phase === 'switched' && row?.kind === 'route' && row?.route === route && row?.status === 'pass'));
  return { status: checks.some(check => check?.status === 'fail') ? 'failed' : report?.schema === OUTCOME_SCHEMA && documentCoverage && themeCoverage && checks.every(check => check?.status === 'pass' && check.evidence?.length) ? 'passed' : 'pending', reason: 'outcome_checks_required' };
}

/** Caller executes the shipped PHP inventory collector in its own authenticated runtime.
 * Browser probes are independent, logged-out contexts, never the editor auth context.
 */
export async function runOutcomeChecks({ browser, page, candidateOrigin, routes, config = {}, widths = [390, 768, 1440], importRunId }) {
  const report = { schema: OUTCOME_SCHEMA, import_run_id: importRunId, candidate_origin: candidateOrigin, routes, widths, source_capture: config.capture ?? null, colour_schemes: config.colourSchemes ?? [], checks: {} };
  let inventory;
  try { inventory = await config.collectInventory?.({ page, candidateOrigin }); } catch { /* unavailable is not success */ }
  const complete = inventory?.schema === 'static-site-importer/outcome-inventory/v1' && TYPES.every(type => inventory.types?.includes(type)) && Array.isArray(inventory.documents) && inventory.documents.length > 0;
  const documents = complete ? inventory.documents : [];
  report.checks.block_validity = !complete || documents.some(doc => doc.parse?.parser !== 'parse_blocks' || typeof doc.parse.valid !== 'boolean')
    ? unsupported('complete_server_parse_inventory_required')
    : result(documents.map(({ id, type, parse }) => ({ id, type, ...parse })), documents.filter(doc => !doc.parse.valid).map(({ id, type, parse }) => ({ id, type, errors: parse.errors })));
  let editorRows;
  try {
    if (complete) editorRows = await validateOutcomeDocuments({ page, candidateOrigin, documents });
  } catch { /* Authentication or an unavailable editor is not a validity pass. */ }
  const editorComplete = editorRows?.length === documents.length && documents.length > 0;
  if (editorComplete) {
    report.checks.block_validity.evidence.push(...editorRows);
    const failures = editorRows.filter(row => row.validation?.invalid_blocks > 0 || row.diagnostics.length > 0);
    if (failures.length) {
      report.checks.block_validity.status = 'fail';
      report.checks.block_validity.failures = [...(report.checks.block_validity.failures ?? []), ...failures];
    }
  } else if (report.checks.block_validity.status !== 'fail') {
    report.checks.block_validity = unsupported('installed_editor_validation_required');
  }
  report.capabilities = { client_editor_validation: editorComplete ? 'available' : 'unsupported' };
  const linkEvidence = [], linkFailures = [], headEvidence = [], headFailures = [];
  const sourceHosts = config.sourceHosts;
  const hostsValid = Array.isArray(sourceHosts) && sourceHosts.length > 0 && sourceHosts.every(host => typeof host === 'string' && /^[a-z0-9.-]+(?::\d+)?$/i.test(host));
  const scan = (text, surface) => {
    const decoded = text.replace(/&amp;|&#0*38;|&#x0*26;/gi, '&').replace(/\\\//g, '/');
    const violations = [];
    if (/[?&]page_id(?:=|%3d)/i.test(decoded)) violations.push('page_id_url');
    for (const value of decoded.match(/(?:https?:)?\/\/[^\s<>"'()]+/gi) ?? []) {
      try { if (sourceHosts?.some(host => host.toLowerCase() === new URL(value, candidateOrigin).host.toLowerCase())) violations.push('source_host_url'); } catch { /* non URLs aren't links */ }
    }
    linkEvidence.push({ surface, violations });
    if (violations.length) linkFailures.push({ surface, violations });
  };
  for (const doc of documents) scan(doc.content, `${doc.type}:${doc.id}`);
  // Shared chrome (navigation posts and template parts) carries the site-wide
  // anchors. Each must resolve on every probed route: bare fragments against
  // the rendering route itself, path-qualified fragments against their target.
  const chromeAnchors = [];
  let chromeComplete = complete;
  if (complete) {
    for (const doc of documents) {
      if (!CHROME_TYPES.includes(doc.type)) continue;
      for (const match of doc.content?.matchAll?.(/href\s*=\s*(?:"([^"]*)"|'([^']*)')/gi) ?? []) {
        const href = match[1] ?? match[2];
        if (href?.includes('#')) chromeAnchors.push({ doc: `${doc.type}:${doc.id}`, href });
      }
    }
  }
  let available = true;
  const chromePages = new Map();
  const chromeFragmentCache = new Map();
  const chromePageFor = async targetPath => {
    if (!chromePages.has(targetPath)) {
      if (chromePages.size >= CHROME_TARGET_BOUND) return null;
      const target = await browser.newPage();
      try { await target.goto(new URL(targetPath, candidateOrigin).href, { waitUntil: 'load', timeout: 30000 }); } catch { await target.close(); return null; }
      chromePages.set(targetPath, target);
    }
    return chromePages.get(targetPath);
  };
  const chromeAnchorResolves = async (route, anchor, probe) => {
    let targetPath, fragment;
    try {
      const url = new URL(anchor.href, new URL(route, candidateOrigin));
      targetPath = url.pathname + url.search;
      fragment = decodeURIComponent(url.hash.slice(1));
    } catch { return { status: 'fail', target: anchor.href, reason: 'chrome_anchor_unresolvable' }; }
    if (!fragment) return { status: 'fail', target: anchor.href, reason: 'chrome_anchor_fragment_required' };
    const cacheKey = `${targetPath}#${fragment}`;
    if (chromeFragmentCache.has(cacheKey)) return { status: chromeFragmentCache.get(cacheKey) ? 'pass' : 'fail', target: cacheKey };
    const routePath = new URL(route, candidateOrigin).pathname + new URL(route, candidateOrigin).search;
    const page = targetPath === routePath ? probe : await chromePageFor(targetPath);
    if (!page) return { status: 'unsupported', target: cacheKey, reason: 'chrome_anchor_target_bound_exceeded' };
    try {
      const resolves = await page.evaluate(fragment => Boolean(document.getElementById(fragment)), fragment);
      chromeFragmentCache.set(cacheKey, resolves);
      return { status: resolves ? 'pass' : 'fail', target: cacheKey };
    } catch { return { status: 'unsupported', target: cacheKey, reason: 'chrome_anchor_target_unavailable' }; }
  };
  /** Imported CSS is part of the link graph: scan every same-origin stylesheet
   * the route renders, following same-origin @import chains depth-first. */
  const scannedCss = new Set();
  const scanCssSheet = async (probe, route, href, depth = 0) => {
    let url;
    try { url = new URL(href, candidateOrigin); } catch { return; }
    if (url.origin !== candidateOrigin || scannedCss.has(url.href) || depth > CSS_IMPORT_DEPTH_BOUND) return;
    scannedCss.add(url.href);
    let css;
    try {
      const response = await probe.request.get(url.href);
      if (response.status() !== 200) throw new Error(String(response.status()));
      css = await response.text();
    } catch {
      available = false;
      linkEvidence.push({ surface: `css:${route}:${url.pathname}`, reason: 'stylesheet_fetch_unavailable' });
      return;
    }
    scan(css, `css:${route}:${url.pathname}`);
    for (const match of css.matchAll(/@import\s+(?:url\(\s*)?["']?([^"'()\s;]+)/gi)) {
      try { await scanCssSheet(probe, route, new URL(match[1], url).href, depth + 1); } catch { /* malformed import target is already retained text */ }
    }
  };
  for (const route of routes) {
    const probe = await browser.newPage();
    try {
      const response = await probe.goto(new URL(route, candidateOrigin).href, { waitUntil: 'load', timeout: 30000 });
      if (!response || response.status() !== 200 || new URL(probe.url()).origin !== candidateOrigin) throw new Error('route_unavailable');
      const head = await probe.locator('head').innerHTML();
      scan(head, `head:${route}`);
      const sheets = await probe.evaluate(() => ({
        links: [...document.querySelectorAll('link[rel~="stylesheet" i][href]')].map(node => node.href),
        inline: [...document.querySelectorAll('style')].map(node => node.textContent ?? ''),
      }));
      for (const css of sheets.inline) scan(css, `style:${route}`);
      for (const href of sheets.links) await scanCssSheet(probe, route, href);
      for (const anchor of chromeAnchors) {
        const row = { surface: `chrome:${anchor.doc}`, anchor: anchor.href, route, ...(await chromeAnchorResolves(route, anchor, probe)) };
        linkEvidence.push(row);
        if (row.status === 'fail') linkFailures.push(row);
        if (row.status === 'unsupported') chromeComplete = false;
      }
      const tags = await probe.locator('head').evaluate(head => {
        const select = selector => [...head.querySelectorAll(selector)].map(node => node.getAttribute('href') ?? node.getAttribute('content') ?? node.getAttribute('charset'));
        return Object.fromEntries(Object.entries({ charset: 'meta[charset]', viewport: 'meta[name="viewport" i]', canonical: 'link[rel~="canonical" i]', description: 'meta[name="description" i]', 'og:title': 'meta[property="og:title" i]', 'og:url': 'meta[property="og:url" i]', 'og:image': 'meta[property="og:image" i]', prohibited: 'meta[name="robots" i],meta[name="generator" i],script[src],link[rel="pingback"]' }).map(([key, selector]) => [key, select(selector)]));
      });
      const failures = [];
      for (const [name, values] of Object.entries(tags)) {
        if (name === 'prohibited') continue;
        if (values.length !== 1 || !values[0]?.trim()) failures.push(`${name}:singleton_required`);
        if (['canonical', 'og:url', 'og:image'].includes(name)) for (const value of values) {
          try { if (new URL(value).origin !== candidateOrigin) failures.push(`${name}:destination_required`); } catch { failures.push(`${name}:absolute_url_required`); }
        }
      }
      // Robots/generator are prohibited conservatively: provenance cannot be inferred
      // from rendered bytes. Telemetry patterns are explicitly caller supplied.
      if (await probe.locator('head meta[name="robots" i],head meta[name="generator" i],head link[rel="pingback"]').count()) failures.push('prohibited_metadata');
      for (const pattern of config.telemetryMarkers ?? []) if (head.includes(pattern)) failures.push('source_telemetry');
      headEvidence.push({ route, tags, failures });
      if (failures.length) headFailures.push({ route, failures });
    } catch { available = false; headEvidence.push({ route, reason: 'route_probe_unavailable' }); }
    finally { await probe.close(); }
  }
  for (const target of chromePages.values()) try { await target.close(); } catch { /* already gone */ }
  let aliasesAvailable = Array.isArray(config.aliases);
  for (const alias of config.aliases ?? []) {
    try {
      const evidence = await aliasProbe(browser, candidateOrigin, alias);
      linkEvidence.push(evidence);
      if (!evidence.passed) linkFailures.push(evidence);
    } catch { aliasesAvailable = false; }
  }
  report.checks.link_graph = linkFailures.length ? result(linkEvidence, linkFailures) : !chromeComplete || !complete || !hostsValid || !available || !aliasesAvailable ? { ...unsupported('inventory_source_hosts_aliases_and_route_probes_required'), evidence: [...linkEvidence, { reason: 'inventory_source_hosts_aliases_and_route_probes_required' }] } : result(linkEvidence, []);
  report.checks.head_singletons = headFailures.length ? result(headEvidence, headFailures) : !available || !Array.isArray(config.telemetryMarkers) ? { ...unsupported('route_probes_and_source_telemetry_markers_required'), evidence: headEvidence } : result(headEvidence, []);
  Object.assign(report.checks, await interactiveOutcomes({ browser, candidateOrigin, routes, widths, config }));
  Object.assign(report.checks, await documentOutcomes({ browser, candidateOrigin, routes, widths, config }));
  report.checks.theme_independence = await themeIndependenceOutcome({ browser, candidateOrigin, routes, aliases: config.aliases, config });
  report.status = outcomeGate(report).status;
  return report;
}

/**
 * Theme independence (#2430): switching to a default core theme and restoring
 * the imported one must leave content, routes, redirects, menus, media, site
 * title, tagline, language and SEO fields unchanged. Styling is explicitly
 * never compared. The caller supplies the switch/restore executors and the
 * native-owner snapshot (execute lib/theme-independence.php in its runtime);
 * this check probes logged-out routes and aliases under the switched theme and
 * deep-compares owner snapshots across baseline, switched and restored phases.
 */
async function themeIndependenceOutcome({ browser, candidateOrigin, routes, aliases, config }) {
  const seam = config.themeIndependence;
  const themeValid = typeof seam?.theme === 'string' && /^[a-z0-9_-]+$/i.test(seam.theme);
  if (!themeValid || typeof seam.snapshot !== 'function' || typeof seam.activate !== 'function' || typeof seam.restore !== 'function') {
    return { ...unsupported('theme_switch_snapshot_and_restore_executors_required'), evidence: [{ reason: 'theme_switch_snapshot_and_restore_executors_required' }] };
  }
  const evidence = [], failures = [];
  let baseline;
  try { baseline = await seam.snapshot(); } catch { return unsupported('theme_owner_snapshot_unavailable'); }
  const snapshotValid = baseline && typeof baseline === 'object' && !Array.isArray(baseline) && baseline.schema === THEME_OWNER_SNAPSHOT_SCHEMA
    && Array.isArray(baseline.pages) && baseline.pages.length > 0
    && baseline.pages.every(row => row && Number.isInteger(row.id) && typeof row.url === 'string' && typeof row.content === 'string')
    && baseline.menus && typeof baseline.menus === 'object'
    && baseline.media && typeof baseline.media === 'object'
    && baseline.redirects && typeof baseline.redirects === 'object'
    && baseline.settings && typeof baseline.settings === 'object';
  if (!snapshotValid) return { ...unsupported('complete_theme_owner_snapshot_required'), evidence: [{ reason: 'complete_theme_owner_snapshot_required' }] };
  const baselineJson = stable(baseline);
  const record = (phase, kind, row) => {
    const entry = { phase, kind, ...row };
    evidence.push(entry);
    if (entry.status === 'fail' || entry.reason) failures.push(entry);
    return entry;
  };
  const probePhase = async phase => {
    for (const route of routes) record(phase, 'route', await routeProbe(browser, candidateOrigin, route));
    for (const alias of aliases ?? []) {
      try {
        const probe = await aliasProbe(browser, candidateOrigin, alias);
        record(phase, 'alias', { alias, status: probe.passed ? 'pass' : 'fail', http_status: probe.status, target_status: probe.target_status, location: probe.location });
      } catch { record(phase, 'alias', { alias, status: 'fail', reason: 'alias_probe_unavailable' }); }
    }
  };
  evidence.push({ phase: 'baseline', kind: 'snapshot', snapshot_sha256: snapshotHash(baseline) });
  await probePhase('baseline');
  let activated = false;
  try {
    try { await seam.activate(); activated = true; } catch { failures.push({ phase: 'switched', kind: 'theme_switch', reason: 'theme_switch_failed' }); }
    if (activated) {
      let switched = null;
      try { switched = await seam.snapshot(); } catch { failures.push({ phase: 'switched', kind: 'snapshot', reason: 'theme_owner_snapshot_unavailable' }); }
      if (switched) {
        // The active theme itself is presentation and may change; every owner must not.
        const matches = stable({ ...baseline, settings: { ...baseline.settings, active_theme: null } }) === stable({ ...switched, settings: { ...switched.settings, active_theme: null } });
        evidence.push({ phase: 'switched', kind: 'snapshot', theme: seam.theme, snapshot_sha256: snapshotHash(switched), matches_baseline: matches });
        if (!matches) failures.push({ phase: 'switched', kind: 'snapshot', reason: 'native_owner_changed_under_switch', differences: snapshotDifferences(baseline, switched) });
      }
      await probePhase('switched');
    }
  } finally {
    try { await seam.restore(); } catch { failures.push({ phase: 'restored', kind: 'theme_restore', reason: 'theme_restore_failed' }); }
    try {
      const restored = await seam.snapshot();
      // Restore must return every owner, including the active theme, exactly.
      const matches = stable(restored) === baselineJson;
      evidence.push({ phase: 'restored', kind: 'snapshot', snapshot_sha256: snapshotHash(restored), matches_baseline: matches });
      if (!matches) failures.push({ phase: 'restored', kind: 'snapshot', reason: 'native_owner_not_restored', differences: snapshotDifferences(baseline, restored) });
    } catch { failures.push({ phase: 'restored', kind: 'snapshot', reason: 'theme_owner_snapshot_unavailable' }); }
  }
  return result(evidence, failures);
}

async function routeProbe(browser, candidateOrigin, route) {
  const probe = await browser.newPage();
  try {
    const response = await probe.goto(new URL(route, candidateOrigin).href, { waitUntil: 'load', timeout: 30000 });
    const ok = Boolean(response) && response.status() === 200 && new URL(probe.url()).origin === candidateOrigin;
    return { route, status: ok ? 'pass' : 'fail', http_status: ok ? 200 : response?.status() ?? null, ...(ok ? {} : { reason: 'route_probe_unavailable' }) };
  } catch { return { route, status: 'fail', reason: 'route_probe_unavailable' }; }
  finally { await probe.close(); }
}

async function aliasProbe(browser, candidateOrigin, alias) {
  const probe = await browser.newPage();
  try {
    const base = new URL(alias, candidateOrigin);
    const response = await probe.request.get(base.href, { maxRedirects: 0 });
    const location = response.headers().location;
    const target = location && new URL(location, base);
    let passed = response.status() >= 300 && response.status() < 400 && target?.origin === candidateOrigin && target.href !== base.href;
    let targetStatus;
    if (passed) { const final = await probe.request.get(target.href, { maxRedirects: 0 }); targetStatus = final.status(); passed = targetStatus === 200; }
    return { alias, location, status: response.status(), target_status: targetStatus, passed };
  } finally { await probe.close(); }
}
