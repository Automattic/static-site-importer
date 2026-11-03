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

import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

const args = Object.fromEntries(process.argv.slice(2).reduce((pairs, value, index, all) => (value.startsWith('--') ? [...pairs, [value.slice(2), all[index + 1]]] : pairs), []));
for (const required of ['url', 'wp-url', 'site', 'source', 'out']) if (!args[required]) throw new Error(`evaluate_argument_missing: --${required}`);
import { chromium } from 'playwright';

const WIDTHS = [390, 768, 1440];
const THRESHOLDS = { visible_text_ratio: 0.7, restyle_coverage: 0.5, css_ratio: 2, cover_share: 0.5 };
const sourceUrl = new URL(args.url);
const wpUrl = new URL(args['wp-url']);
fs.mkdirSync(args.out, { recursive: true });

const findings = new Map();
const ADVISORY = new Set(['restyle_coverage_low', 'theme_css_bloat', 'quality_status']);
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
  const height = Math.min(await page.evaluate(() => document.documentElement.scrollHeight), 12_000);
  await page.setViewportSize({ width, height: Math.max(height, 900) });
  await page.waitForTimeout(500);
}

// What a visitor can see: text whose box is on top at its own centre, images
// likewise, and the element that is on top across most of a grid of points.
const measure = (coverShare) => {
  const onTop = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return false;
    const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    return !!hit && (hit === el || el.contains(hit) || hit.contains(el));
  };
  const style = (el) => getComputedStyle(el);
  const visibleText = [...document.querySelectorAll('h1,h2,h3,h4,h5,h6,p,li,a,button,span,figcaption,blockquote,td,th,label')]
    .filter((el) => ![...el.children].some((c) => c.textContent.trim()) && el.textContent.trim() && style(el).visibility !== 'hidden' && Number(style(el).opacity) > 0.05 && onTop(el))
    .reduce((n, el) => n + el.textContent.replace(/\s+/g, ' ').trim().length, 0);
  const visibleImages = [...document.querySelectorAll('img,svg,video,picture')].filter((el) => onTop(el)).length;
  const counts = new Map();
  const { innerWidth: w, innerHeight: h } = window;
  let points = 0;
  for (let x = 0.1; x < 1; x += 0.2) for (let y = 0.05; y < 1; y += 0.1) {
    points++;
    const link = document.elementFromPoint(w * x, h * y)?.closest('a,button');
    if (link) counts.set(link, (counts.get(link) || 0) + 1);
  }
  const [cover] = [...counts].filter(([, n]) => n / points >= coverShare).sort((a, b) => b[1] - a[1]);
  const covering = cover && {
    tag: cover[0].tagName.toLowerCase(),
    text: cover[0].textContent.replace(/\s+/g, ' ').trim().slice(0, 60),
    classes: (cover[0].closest('[class*="wp-block-"]') || cover[0]).className.toString().split(/\s+/).filter((c) => !/^blocks-engine-|^be-/.test(c)).slice(0, 6).join(' '),
    share: cover[1] / points,
    height: Math.round(cover[0].getBoundingClientRect().height),
  };
  return { visibleText, visibleImages, covering, height: document.documentElement.scrollHeight, title: document.title };
};

const browser = await chromium.launch();
const page = await browser.newPage();
const routes = capturedRoutes();
const shots = path.join(args.out, 'shots');
fs.mkdirSync(shots, { recursive: true });
const pairs = [];
for (const route of routes) {
  for (const width of WIDTHS) {
    const pair = { route, width };
    for (const [side, base] of [['source', sourceUrl], ['wp', wpUrl]]) {
      const url = new URL(route.replace(/^\//, ''), base.href.endsWith('/') ? base.href : `${base.href}/`).href;
      try {
        await settle(page, url, width);
        pair[side] = await page.evaluate(measure, THRESHOLDS.cover_share);
        const file = path.join(shots, `${side}${route.replace(/\//g, '_')}${width}.png`);
        await page.screenshot({ path: file, fullPage: false });
        pair[side].screenshot = file;
      } catch (error) {
        pair[side] = { error: String(error.message || error).slice(0, 300) };
      }
    }
    pairs.push(pair);
    const { source, wp } = pair;
    if (wp.error) { report({ stage: 'materialization', check: 'route_unreachable', owner_repo: 'static-site-importer', subject: 'route', evidence: wp.error, route, width }); continue; }
    if (source.error) continue;
    if (wp.covering && !source.covering) {
      report({ stage: 'materialization', check: 'covering_element', owner_repo: 'blocks-engine', subject: `${wp.covering.tag}:${wp.covering.text}`, evidence: wp.covering, route, width });
    }
    const ratio = source.visibleText ? wp.visibleText / source.visibleText : 1;
    if (ratio < THRESHOLDS.visible_text_ratio) {
      report({ stage: 'materialization', check: 'visible_text_lost', owner_repo: 'static-site-importer', subject: 'visible_text', evidence: { ratio: Number(ratio.toFixed(2)), source: source.visibleText, wp: wp.visibleText }, route, width });
    }
    if (source.visibleImages > wp.visibleImages + 1) {
      report({ stage: 'materialization', check: 'visible_images_lost', owner_repo: 'static-site-importer', subject: 'visible_images', evidence: { source: source.visibleImages, wp: wp.visibleImages }, route, width });
    }
    if (width === 1440 && source.title && wp.title && !wp.title.includes(source.title.split(/\s[|–-]\s/).pop())) {
      report({ stage: 'theme', check: 'site_title_not_imported', owner_repo: 'static-site-importer', subject: 'document_title', evidence: { source: source.title, wp: wp.title }, route, width });
    }
  }
}
await browser.close();

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
  report({ stage: 'import', check: 'import_report_missing', owner_repo: 'static-site-importer', subject: 'import-report.json', evidence: reportRoot });
} else {
  const importReport = JSON.parse(fs.readFileSync(reportFile, 'utf8'));
  const metrics = importReport.design_system?.metrics || {};
  const quality = importReport.quality || {};
  if (quality.status && quality.status !== 'pass' && quality.status !== 'passed') report({ stage: 'editability', check: 'quality_status', owner_repo: 'static-site-importer', subject: quality.status, evidence: quality.status });
  for (const key of ['raw_html_companion_block_count', 'invalid_block_count']) if (quality[key] > 0) report({ stage: 'editability', check: key, owner_repo: 'blocks-engine', subject: key, evidence: quality[key] });
  if (metrics.restyle_coverage < THRESHOLDS.restyle_coverage) report({ stage: 'theme', check: 'restyle_coverage_low', owner_repo: 'blocks-engine', subject: 'restyle_coverage', evidence: { restyle_coverage: metrics.restyle_coverage, refused_value_count: metrics.refused_value_count, threshold: THRESHOLDS.restyle_coverage } });
  if (metrics.generated_to_source_css_ratio > THRESHOLDS.css_ratio) report({ stage: 'theme', check: 'theme_css_bloat', owner_repo: 'blocks-engine', subject: 'generated_to_source_css_ratio', evidence: { ratio: metrics.generated_to_source_css_ratio, theme_css_bytes: metrics.theme_css_bytes, unique_source_css_bytes: metrics.unique_source_css_bytes, threshold: THRESHOLDS.css_ratio } });
}

const list = [...findings.values()].sort((a, b) => a.signature.localeCompare(b.signature));
const result = {
  schema: 'import-loop/findings/v1',
  url: args.url,
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
