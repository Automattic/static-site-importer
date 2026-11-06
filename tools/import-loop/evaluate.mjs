#!/usr/bin/env node
// Loop step: review one import and emit typed findings.
//
// Usage: node evaluate.mjs --url <source url> --wp-url <imported site url>
//          --site <site path> --source <kept source dir> --out <dir>
//
// Writes <out>/findings.json and, when $HOMEBOY_STEP_OUTPUT is set, the step
// output {verdict, finding_count, blocker_count, findings_path}.
// A finding is {signature, severity, stage, check, owner_repo, subject, evidence, occurrences[]};
// the signature hashes everything except route and width so one defect seen on
// several routes or widths is one finding. Materialization and editability
// findings are blockers; theme metrics are advisory and never block acceptance.
//
// Each route is rendered three ways: the live source, the kept capture, and the
// import. A defect the capture already shows belongs to the capture stage; one
// that only the import shows belongs to materialization. repos.json names the
// repository that owns each stage.

import { createHash } from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';

const args = Object.fromEntries(process.argv.slice(2).reduce((pairs, value, index, all) => (value.startsWith('--') ? [...pairs, [value.slice(2), all[index + 1]]] : pairs), []));
for (const required of ['url', 'wp-url', 'site', 'source', 'out']) if (!args[required]) throw new Error(`evaluate_argument_missing: --${required}`);
import { chromium } from 'playwright';
import { fitViewport, measure } from './measure.mjs';
import { editorPages } from './editor.mjs';
import { blockShape, blockSubject } from './shape.mjs';

const stageOwners = Object.fromEntries(Object.entries(JSON.parse(fs.readFileSync(new URL('./repos.json', import.meta.url), 'utf8'))).map(([id, repo]) => [repo.stage, id]));

const WIDTHS = [390, 768, 1440];
const THRESHOLDS = { visible_text_ratio: 0.7, restyle_coverage: 0.5, css_ratio: 2, cover_share: 0.5, aspect_change: 0.2, block_depth: 8 };
const sourceUrl = new URL(args.url);
const wpUrl = new URL(args['wp-url']);
fs.mkdirSync(args.out, { recursive: true });

const findings = new Map();
// Generated block slugs may end in a content hash (block-<sha256>): one per
// carried fragment. They are one defect kind, so a finding names the stem.
const slugStem = (slug) => slug.replace(/-[0-9a-f]{64}$/, '');
const ADVISORY = new Set(['restyle_coverage_low', 'theme_css_bloat', 'quality_status', 'block_nesting_deep']);
function report({ stage, check, owner_repo, subject, evidence, route = null, width = null }) {
  const severity = ADVISORY.has(check) ? 'advisory' : 'blocker';
  const signature = createHash('sha1').update(JSON.stringify([stage, check, owner_repo, subject])).digest('hex').slice(0, 12);
  const finding = findings.get(signature) || { signature, severity, stage, check, owner_repo, subject, evidence, occurrences: [] };
  finding.occurrences.push({ route, width, evidence });
  findings.set(signature, finding);
}

// Routes are the pages the capture kept: <source>/<host>/website/**/index.html.
function capturedRoutes() {
  const website = path.join(args.source, sourceUrl.hostname, 'website');
  const routes = [];
  const walk = (dir) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      if (entry.isDirectory() && !['assets', 'media'].includes(entry.name)) walk(path.join(dir, entry.name));
      else if (entry.name === 'index.html') routes.push('/' + path.relative(website, dir).split(path.sep).filter(Boolean).map((p) => `${p}/`).join(''));
    }
  };
  walk(website);
  return routes.sort();
}

// What the capture removed on purpose (ads, platform credits): the plain
// selector rules of its cleanup policy, optionally limited to link hosts. Rules
// with further conditions (promotion text, builder chrome) are not replayable
// by selector alone and are left out.
function cleanupExclusions() {
  const file = path.join(args.source, sourceUrl.hostname, 'cleanup-evidence.json');
  if (!fs.existsSync(file)) return [];
  const plain = new Set(['id', 'category', 'selector', 'hosts', 'credit', 'reclaimVariables']);
  const rules = new Map();
  for (const page of Object.values(JSON.parse(fs.readFileSync(file, 'utf8')).pages || {})) {
    for (const rule of page.policy?.rules || []) if (rule.selector && Object.keys(rule).every((k) => plain.has(k))) rules.set(rule.id, { selector: rule.selector, hosts: rule.hosts || null });
  }
  return [...rules.values()];
}

// Reveal lazy and scroll-triggered content, then grow the viewport to the
// whole page so hit-testing covers every element, not only the first screen.
async function settle(page, url, width) {
  await page.setViewportSize({ width, height: 900 });
  await page.goto(url, { waitUntil: 'networkidle', timeout: 90_000 }).catch(() => page.waitForLoadState('load'));
  for (let y = 0, h = await page.evaluate(() => document.documentElement.scrollHeight); y < h; y += 600) {
    await page.evaluate((top) => window.scrollTo(0, top), y);
    await page.waitForTimeout(150);
  }
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(1500);
  await fitViewport(page, width);
}


// The capture's website directory, served as a site so absolute asset paths resolve.
const website = path.join(args.source, sourceUrl.hostname, 'website');
const types = { '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.avif': 'image/avif', '.gif': 'image/gif', '.woff2': 'font/woff2', '.woff': 'font/woff' };
const server = http.createServer((req, res) => {
  let file = path.join(website, decodeURIComponent(new URL(req.url, 'http://x').pathname));
  if (!file.startsWith(website)) return res.writeHead(403).end();
  if (fs.existsSync(file) && fs.statSync(file).isDirectory()) file = path.join(file, 'index.html');
  if (!fs.existsSync(file)) return res.writeHead(404).end();
  res.writeHead(200, { 'content-type': types[path.extname(file).toLowerCase()] || 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const captureUrl = new URL(`http://127.0.0.1:${server.address().port}/`);

// Defects of a candidate render against the source render, keyed by check and subject.
function compare(source, candidate) {
  const defects = [];
  if (candidate.covering && !source.covering) defects.push({ check: 'covering_element', owner: stageOwners.materialization, subject: `${candidate.covering.tag}:${candidate.covering.text}`, evidence: candidate.covering });
  const ratio = source.visibleText ? candidate.visibleText / source.visibleText : 1;
  if (ratio < THRESHOLDS.visible_text_ratio) defects.push({ check: 'visible_text_lost', owner: 'static-site-importer', subject: 'visible_text', evidence: { ratio: Number(ratio.toFixed(2)), source: source.visibleText, candidate: candidate.visibleText } });
  const rendered = new Map();
  for (const m of candidate.media) if (!rendered.has(m.key)) rendered.set(m.key, m);
  const missing = new Map();
  // Materialization renames media (an attachment takes its title or alt as
  // its file name), so an image without a key match is matched by rendered
  // size (2px) among the candidate's images that matched no source key.
  const sourceKeys = new Set(source.media.map((s) => s.key));
  const unmatched = [...rendered.values()].filter((m) => !sourceKeys.has(m.key));
  for (const m of new Map(source.media.map((s) => [s.key, s])).values()) {
    const match = rendered.get(m.key);
    if (!match) {
      const at = unmatched.findIndex((c) => Math.abs(c.w - m.w) <= 2 && Math.abs(c.h - m.h) <= 2);
      if (at >= 0) { unmatched.splice(at, 1); continue; }
      const subject = m.group || m.key;
      missing.set(subject, [...(missing.get(subject) || []), { key: m.key, source: [m.w, m.h] }]);
    } else if (Math.min(m.w, m.h, match.w, match.h) >= 8 && Math.abs((match.w / match.h) / (m.w / m.h) - 1) > THRESHOLDS.aspect_change) {
      defects.push({ check: 'image_distorted', owner: stageOwners.materialization, subject: m.key, evidence: { source: [m.w, m.h], candidate: [match.w, match.h], style: match.style } });
    }
  }
  for (const [subject, items] of missing) defects.push({ check: 'image_missing', owner: stageOwners.materialization, subject, evidence: { missing: items } });
  return defects;
}

const browser = await chromium.launch();
const page = await browser.newPage();
// --routes narrows a run to some captured routes, e.g. to re-check one finding.
const routes = capturedRoutes().filter((route) => !args.routes || args.routes.split(',').includes(route));
const exclusions = cleanupExclusions();
const shots = path.join(args.out, 'shots');
fs.mkdirSync(shots, { recursive: true });
const pairs = [];
for (const route of routes) {
  for (const width of WIDTHS) {
    const pair = { route, width };
    for (const [side, base] of [['source', sourceUrl], ['capture', captureUrl], ['wp', wpUrl]]) {
      const url = new URL(route.replace(/^\//, ''), base.href.endsWith('/') ? base.href : `${base.href}/`).href;
      try {
        await settle(page, url, width);
        // The capture is static: scroll-reveal content stays transparent without
        // its scripts, so it is measured by geometry, not by what is on top.
        pair[side] = await page.evaluate(measure, { coverShare: THRESHOLDS.cover_share, exclusions: side === 'source' ? exclusions : [], geometryOnly: side === 'capture' });
        const file = path.join(shots, `${side}${route.replace(/\//g, '_')}${width}.png`);
        await page.screenshot({ path: file, fullPage: false });
        pair[side].screenshot = file;
      } catch (error) {
        pair[side] = { error: String(error.message || error).slice(0, 300) };
      }
    }
    pairs.push(pair);
    const { source, capture, wp } = pair;
    if (wp.error) { report({ stage: 'materialization', check: 'route_unreachable', owner_repo: stageOwners.import, subject: 'route', evidence: wp.error, route, width }); continue; }
    if (source.error) continue;
    // A subject the capture already gets wrong, in any way, is a capture defect.
    const captured = capture.error ? new Map() : new Map(compare(source, capture).map((d) => [d.subject, d]));
    for (const defect of compare(source, wp)) {
      const upstream = captured.get(defect.subject);
      if (upstream) report({ stage: 'capture', check: defect.check, owner_repo: stageOwners.capture, subject: defect.subject, evidence: { capture: { check: upstream.check, ...upstream.evidence }, wp: defect.evidence }, route, width });
      else report({ stage: 'materialization', check: defect.check, owner_repo: defect.owner, subject: defect.subject, evidence: defect.evidence, route, width });
    }
    if (width === 1440 && source.title && wp.title && !wp.title.includes(source.title.split(/\s[|–-]\s/).pop())) {
      report({ stage: 'theme', check: 'site_title_not_imported', owner_repo: stageOwners.import, subject: 'document_title', evidence: { source: source.title, wp: wp.title }, route, width });
    }
  }
}
await browser.close();
server.close();

// Theme and editability findings come from the importer's own report.
const reportRoot = path.join(args.site, 'wp-content', 'static-site-importer');
const reportFile = (function find(dir) {
  for (const entry of fs.existsSync(dir) ? fs.readdirSync(dir, { withFileTypes: true }) : []) {
    const full = path.join(dir, entry.name);
    if (entry.isFile() && entry.name === 'import-report.json') return full;
    if (entry.isDirectory()) { const found = find(full); if (found) return found; }
  }
  return null;
})(reportRoot);
if (!reportFile) {
  report({ stage: 'import', check: 'import_report_missing', owner_repo: stageOwners.import, subject: 'import-report.json', evidence: reportRoot });
} else {
  const importReport = JSON.parse(fs.readFileSync(reportFile, 'utf8'));
  const metrics = importReport.design_system?.metrics || {};
  const quality = importReport.quality || {};
  if (quality.status && quality.status !== 'pass' && quality.status !== 'passed') report({ stage: 'editability', check: 'quality_status', owner_repo: stageOwners.import, subject: quality.status, evidence: quality.status });
  if (quality.invalid_block_count > 0) report({ stage: 'editability', check: 'invalid_block_count', owner_repo: 'blocks-engine', subject: 'invalid_block_count', evidence: quality.invalid_block_count });
  // One finding per raw-HTML companion block, keyed by its slug (the prefix is
  // per site). The report does not map documents to routes, so each finding
  // lists every route; triage keeps the cells its predicate reproduces at trunk.
  if (quality.raw_html_companion_block_count > 0) {
    const bySlug = new Map();
    for (const d of importReport.diagnostics || []) {
      if (d.type !== 'raw_html_companion_block') continue;
      const slug = slugStem(String(d.context?.block_name || '').split('/').pop() || 'unknown');
      const entry = bySlug.get(slug) || { count: 0, documents: new Set(), selectors: new Set() };
      entry.count += 1; entry.documents.add(d.source_path); entry.selectors.add(d.selector);
      bySlug.set(slug, entry);
    }
    for (const [slug, entry] of bySlug) {
      const evidence = { count: entry.count, documents: [...entry.documents].sort(), selectors: [...entry.selectors].slice(0, 8) };
      for (const route of routes) report({ stage: 'editability', check: 'raw_html_companion_block_count', owner_repo: 'blocks-engine', subject: slug, evidence, route });
    }
  }
  if (metrics.restyle_coverage < THRESHOLDS.restyle_coverage) report({ stage: 'theme', check: 'restyle_coverage_low', owner_repo: 'blocks-engine', subject: 'restyle_coverage', evidence: { restyle_coverage: metrics.restyle_coverage, refused_value_count: metrics.refused_value_count, threshold: THRESHOLDS.restyle_coverage } });
  if (metrics.generated_to_source_css_ratio > THRESHOLDS.css_ratio) report({ stage: 'theme', check: 'theme_css_bloat', owner_repo: 'blocks-engine', subject: 'generated_to_source_css_ratio', evidence: { ratio: metrics.generated_to_source_css_ratio, theme_css_bytes: metrics.theme_css_bytes, unique_source_css_bytes: metrics.unique_source_css_bytes, threshold: THRESHOLDS.css_ratio } });
}

// Editability as an editor meets it: each page opened in the block editor.
// A block the editor cannot validate is a blocker. So is each block outside
// WordPress core: the importer's generated blocks keep source structure an
// editor cannot change with core tools. Raw HTML companions are already
// reported by slug above. Deep nesting is advisory.
const rawSlugs = new Set([...findings.values()].filter((f) => f.check === 'raw_html_companion_block_count').map((f) => f.subject));
if (args['wp-cli']) {
  let pages = [];
  try {
    pages = await editorPages(args['wp-url'], args.site, args['wp-cli']);
  } catch (error) {
    report({ stage: 'editability', check: 'editor_unreachable', owner_repo: stageOwners.import, subject: 'block_editor', evidence: String(error.message || error).slice(0, 300) });
  }
  const tally = (list, key) => list.reduce((m, b) => m.set(key(b), (m.get(key(b)) || 0) + 1), new Map());
  for (const { route, blocks } of pages) {
    const cell = routes.includes(route) ? { route, width: 1440 } : { route: null };
    for (const [name, count] of tally(blocks.filter((b) => !b.valid), (b) => b.name)) report({ stage: 'editability', check: 'editor_invalid_block', owner_repo: 'blocks-engine', subject: name, evidence: { route, count }, ...cell });
    // One finding per generated block slug and source shape (shape.mjs).
    for (const [subject, count] of tally(blocks.filter((b) => !b.name.startsWith('core/') && !rawSlugs.has(slugStem(b.name.split('/').pop()))), (b) => blockSubject(slugStem(b.name.split('/').pop()), blockShape(b)))) {
      report({ stage: 'editability', check: 'non_core_block', owner_repo: 'blocks-engine', subject, evidence: { route, count, of: blocks.length }, ...cell });
    }
    const depth = Math.max(0, ...blocks.map((b) => b.depth));
    if (depth > THRESHOLDS.block_depth) report({ stage: 'editability', check: 'block_nesting_deep', owner_repo: 'blocks-engine', subject: 'block_depth', evidence: { route, depth, threshold: THRESHOLDS.block_depth }, ...cell });
  }
}

const list = [...findings.values()].sort((a, b) => a.signature.localeCompare(b.signature));
const result = {
  schema: 'import-loop/findings/v1',
  url: args.url,
  site: args.site,
  wp_url: args['wp-url'],
  routes,
  widths: WIDTHS,
  thresholds: THRESHOLDS,
  import_report: reportFile,
  verdict: list.some((f) => f.severity === 'blocker') ? 'findings' : 'accepted',
  findings: list,
  pairs,
};
const findingsPath = path.join(args.out, 'findings.json');
fs.writeFileSync(findingsPath, JSON.stringify(result, null, 2));
const output = { verdict: result.verdict, finding_count: list.length, blocker_count: list.filter((f) => f.severity === 'blocker').length, findings_path: findingsPath };
fs.writeFileSync(process.env.HOMEBOY_STEP_OUTPUT || '/dev/stdout', JSON.stringify(output));
for (const f of list) console.error(`${f.signature} ${f.severity} ${f.stage}/${f.check} ${f.owner_repo} ${f.subject} x${f.occurrences.length}`);
