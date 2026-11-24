// Structure and weight of an imported corpus site, the noise floor over
// repeated replays, and the trend ledger the daily trunk cadence keeps.
//
// Structure is WordPress's own count (structure.php: parse_blocks over
// published pages, templates and template parts). Weight is the theme CSS
// each captured route loads (quality.mjs themeSheets), its largest route and
// the sum over routes.
import { execFile } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { themeSheets } from './quality.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
export const STRUCTURE_PHP = path.join(here, 'structure.php');

// structure.php through wp eval-file on an imported site.
export function siteStructure(wpCliPhar, site) {
  return new Promise((resolve, reject) => {
    execFile('php', [wpCliPhar, `--path=${site}`, 'eval-file', STRUCTURE_PHP], { maxBuffer: 64 << 20, timeout: 10 * 60_000 }, (error, stdout, stderr) => {
      if (error) return reject(new Error(`structure.php: ${(stderr || error.message).trim().slice(-500)}`));
      try { resolve(JSON.parse(stdout.trim().split('\n').at(-1))); } catch { reject(new Error(`structure.php printed no JSON: ${stdout.trim().slice(-300)}`)); }
    });
  });
}

const attr = (tag, name) => {
  const m = tag.match(new RegExp(`\\s${name}\\s*=\\s*(?:"([^"]*)"|'([^']*)'|([^\\s>]+))`, 'i'));
  return m ? (m[1] ?? m[2] ?? m[3]).replace(/&amp;/g, '&').replace(/&#0?38;/g, '&') : null;
};

// The stylesheets a rendered page loads: linked (`{ href }`) and inline (`{ id, bytes }`).
export function pageSheets(html, base) {
  const sheets = [];
  for (const [tag] of html.matchAll(/<link\b[^>]*>/gi)) {
    const rel = (attr(tag, 'rel') || '').toLowerCase().split(/\s+/);
    const href = attr(tag, 'href');
    if (rel.includes('stylesheet') && href) sheets.push({ href: new URL(href, base).href });
  }
  for (const [, open, body] of html.matchAll(/<style\b([^>]*)>([\s\S]*?)<\/style>/gi)) sheets.push({ id: attr(open, 'id') || '', bytes: Buffer.byteLength(body) });
  return sheets;
}

const fetchText = async (url) => {
  const response = await fetch(url, { redirect: 'follow', signal: AbortSignal.timeout(60_000) });
  return response.ok ? response.text() : null;
};

// Theme CSS bytes per route of the served site: the largest route and the total over routes.
// A route the site does not serve is null, and left out of both.
export async function routeCss(url, routes, get = fetchText) {
  const files = new Map();
  const bytesOf = async (href) => {
    const key = href.replace(/[?#].*$/, '');
    if (!files.has(key)) files.set(key, get(href).then((text) => (text == null ? 0 : Buffer.byteLength(text))));
    return files.get(key);
  };
  const perRoute = {};
  for (const route of routes) {
    const html = await get(new URL(route, url).href);
    if (html == null) { perRoute[route] = null; continue; }
    const sheets = await Promise.all(pageSheets(html, url).map(async (s) => (s.href ? { ...s, bytes: await bytesOf(s.href) } : s)));
    perRoute[route] = themeSheets(sheets).reduce((n, s) => n + s.bytes, 0);
  }
  const measured = Object.entries(perRoute).filter(([, b]) => b != null);
  const [maxRoute, maxBytes] = measured.reduce((best, entry) => (entry[1] > best[1] ? entry : best), [null, null]);
  return { max_route: maxRoute, max_route_bytes: maxBytes, total_bytes: measured.length ? measured.reduce((n, [, b]) => n + b, 0) : null, routes: perRoute };
}

const cellKey = (c) => `${c.route}@${c.width}`;

// The noise floor: replays of one site merged into one result. A cell passes
// only when every replay measured it and passed it; a measured cell the
// replays disagree on is flaky, counted rather than read as a change.
export function mergeRuns(runs) {
  if (runs.length === 1) return { ...runs[0], replays: 1, flaky_cells: 0 };
  const [first] = runs;
  const byKey = runs.map((r) => new Map(r.cells.map((c) => [cellKey(c), c])));
  let flaky = 0;
  const cells = first.cells.map((cell) => {
    const all = byKey.map((m) => m.get(cellKey(cell)));
    const verified = all.every((c) => c?.verified);
    const passes = all.map((c) => Boolean(c?.pass));
    const disagree = verified && passes.some((p) => p !== passes[0]);
    if (disagree) flaky++;
    return { ...cell, verified, pass: verified && passes.every(Boolean), ...(disagree ? { flaky: true } : {}) };
  });
  // Structure is read from the import, not the screenshots: any replay that measured it.
  const measured = runs.find((r) => r.metrics);
  return {
    ...first,
    cells,
    metrics: measured?.metrics ?? null,
    metrics_error: measured ? undefined : first.metrics_error,
    unmeasured: [...new Set(runs.flatMap((r) => r.unmeasured || []))],
    seconds: runs.reduce((n, r) => n + (r.seconds || 0), 0),
    replays: runs.length,
    flaky_cells: flaky,
  };
}

export const TREND_FIELDS = ['passing', 'verified', 'flaky_cells', 'groups', 'single_child_groups', 'max_depth', 'css_max_route_bytes', 'css_total_bytes'];

// One ledger row for a site's measurement.
export function trendRow({ date, trunk, release }, site) {
  const m = site.metrics || {};
  return {
    date, trunk, release, site: site.site,
    passing: site.cells?.filter((c) => c.pass).length ?? null,
    verified: site.cells?.filter((c) => c.verified).length ?? null,
    flaky_cells: site.flaky_cells ?? null,
    groups: m.groups ?? null,
    single_child_groups: m.single_child_groups ?? null,
    max_depth: m.max_depth ?? null,
    css_max_route: m.css?.max_route ?? null,
    css_max_route_bytes: m.css?.max_route_bytes ?? null,
    css_total_bytes: m.css?.total_bytes ?? null,
    unmeasured: [...(site.unmeasured || []), ...(site.metrics_error ? [site.metrics_error] : [])],
  };
}

export const readTrend = (file) => (fs.existsSync(file) ? fs.readFileSync(file, 'utf8').split('\n').filter(Boolean).map((line) => JSON.parse(line)) : []);
export const appendTrend = (file, rows) => fs.appendFileSync(file, rows.map((r) => JSON.stringify(r) + '\n').join(''));

// Each site's latest row in the ledger.
export const latestRows = (trend) => new Map(trend.map((r) => [r.site, r]));

const show = (v) => (v == null ? '-' : String(v));
const cellText = (r) => (r ? `${show(r.passing)}/${show(r.verified)}` : '-');

// Today against each site's previous row; a changed value shows `was→now*`.
export function deltaTable(previous, rows) {
  const columns = [['site', (r) => r.site], ['cells', cellText], ['flaky', (r) => show(r.flaky_cells)], ['groups', (r) => show(r.groups)], ['1-child', (r) => show(r.single_child_groups)], ['depth', (r) => show(r.max_depth)], ['css max', (r) => show(r.css_max_route_bytes)], ['css total', (r) => show(r.css_total_bytes)]];
  let changed = 0;
  const body = rows.map((row) => {
    const was = previous.get(row.site);
    return columns.map(([, text], i) => {
      if (i === 0) return text(row);
      const now = text(row), before = was ? text(was) : null;
      if (before == null || before === now) return now;
      changed++;
      return `${before}→${now}*`;
    });
  });
  const table = [columns.map(([name]) => name), ...body];
  const widths = table[0].map((_, i) => Math.max(...table.map((r) => r[i].length)));
  const lines = table.map((r) => r.map((v, i) => v.padEnd(widths[i])).join('  ').trimEnd());
  const head = rows[0] ? `corpus ${rows[0].date} trunk ${String(rows[0].trunk).slice(0, 8)} ${rows[0].release}: ${changed ? `${changed} changed (*)` : 'no change'}` : 'corpus: no rows';
  return [head, lines[0], ...lines.slice(1)].join('\n');
}

// A head's structure and weight against its baseline, per changed site:
// `{ site, changes: { single_child_groups: [576, 540], ... } }`.
const METRIC_KEYS = [['groups', (m) => m?.groups], ['single_child_groups', (m) => m?.single_child_groups], ['max_depth', (m) => m?.max_depth], ['css_max_route_bytes', (m) => m?.css?.max_route_bytes], ['css_total_bytes', (m) => m?.css?.total_bytes]];
export function metricChanges(sites) {
  const out = [];
  for (const { site, before, after } of sites) {
    if (!before || !after) continue;
    const changes = {};
    for (const [key, get] of METRIC_KEYS) if (get(before) != null && get(after) != null && get(before) !== get(after)) changes[key] = [get(before), get(after)];
    if (Object.keys(changes).length) out.push({ site, changes });
  }
  return out;
}
const SHORT = { groups: 'groups', single_child_groups: '1-child', max_depth: 'depth', css_max_route_bytes: 'css max', css_total_bytes: 'css' };
export const metricSummary = (changes) => changes.map(({ site, changes: c }) => `${site} ${Object.entries(c).map(([k, [a, b]]) => `${SHORT[k]} ${a}→${b}`).join(' ')}`).join('; ');
