import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { appendTrend, deltaTable, latestRows, mergeRuns, metricChanges, pageSheets, readTrend, routeCss, trendRow } from '../tools/import-loop/corpus-metrics.mjs';
import { scoreboard } from '../tools/import-loop/corpus-trunk.mjs';
import { corpusStatus } from '../tools/import-loop/corpus-pr.mjs';
import { corpusOutcome } from '../tools/import-loop/corpus-check.mjs';

const fixture = path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures', 'corpus-structure');

test('structure.php counts groups, single-child groups and depth through WordPress parse_blocks', () => {
  const out = JSON.parse(execFileSync('php', [path.join(fixture, 'harness.php'), path.join(fixture, 'site.json')], { encoding: 'utf8' }).trim().split('\n').at(-1));
  // page:1 nests two single-child groups; page:2 has a two-child group; the
  // index template's main group and its column group each wrap one block
  // (depth 5: main > columns > column > group > image); the header part's
  // group is empty, so it is a group but not a single-child one.
  assert.deepEqual(out, { groups: 6, single_child_groups: 4, max_depth: 5, documents: 4 });
});

test('theme CSS per route counts theme files and non-core inline styles only', async () => {
  const url = 'http://127.0.0.1:9/';
  const html = (extra) => `<link rel='stylesheet' id='theme-css' href='http://127.0.0.1:9/wp-content/themes/t/style.css?ver=1' media='all' />
<link rel="stylesheet" href="/wp-includes/css/dist/block-library/style.min.css" />
<style id="global-styles-inline-css">body{color:red}</style><style id="t-inline-css">${extra}</style>`;
  assert.equal(pageSheets(html(''), url).length, 4);
  const pages = { '/': html('.a{}'), '/about/': html('.about{color:blue}') };
  const files = { '/wp-content/themes/t/style.css': 'x'.repeat(100), '/wp-includes/css/dist/block-library/style.min.css': 'y'.repeat(5000) };
  const get = async (href) => { const p = new URL(href).pathname; return pages[p] ?? files[p] ?? null; };
  const css = await routeCss(url, ['/', '/about/', '/missing/'], get);
  assert.deepEqual(css, { max_route: '/about/', max_route_bytes: 118, total_bytes: 222, routes: { '/': 104, '/about/': 118, '/missing/': null } });
});

const cell = (route, pass, verified = true) => ({ route, width: 390, verified, pass: verified && pass, scores: [] });

test('the noise floor passes a cell only when both replays agree, and counts disagreements as flaky', () => {
  const one = { site: 's', cells: [cell('/', true), cell('/a/', true), cell('/b/', false), cell('/c/', true)], unmeasured: [], metrics: { groups: 3 }, seconds: 1 };
  const two = { site: 's', cells: [cell('/', true), cell('/a/', false), cell('/b/', true), cell('/c/', true, false)], unmeasured: ['/c/@390'], metrics: { groups: 3 }, seconds: 2 };
  const merged = mergeRuns([one, two]);
  assert.deepEqual(merged.cells.map((c) => [c.route, c.verified, c.pass, Boolean(c.flaky)]), [['/', true, true, false], ['/a/', true, false, true], ['/b/', true, false, true], ['/c/', false, false, false]]);
  assert.equal(merged.flaky_cells, 2);
  assert.equal(merged.replays, 2);
  assert.deepEqual(merged.unmeasured, ['/c/@390']);
  assert.deepEqual(mergeRuns([{ ...one, metrics: null, metrics_error: 'x' }, two]).metrics, { groups: 3 });
  assert.equal(mergeRuns([one]).flaky_cells, 0);
});

const metrics = (groups, scg, depth, max, total) => ({ groups, single_child_groups: scg, max_depth: depth, css: { max_route: '/', max_route_bytes: max, total_bytes: total } });

test('the trend ledger appends one row per site and the delta table marks what changed', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-trend-'));
  const file = path.join(dir, '.trend.jsonl');
  const day1 = scoreboard([], { sites: [{ site: 'squarespace-bedford', cells: [cell('/', true), cell('/a/', false)], flaky_cells: 0, unmeasured: [], metrics: metrics(906, 576, 16, 9000, 30000) }] }, { date: '2026-01-01', trunk: '87654ca2deadbeef', release: 'v1.38.29', sites: ['squarespace-bedford', 'agency'] });
  assert.deepEqual(day1.rows[0], { date: '2026-01-01', trunk: '87654ca2deadbeef', release: 'v1.38.29', site: 'squarespace-bedford', passing: 1, verified: 2, flaky_cells: 0, groups: 906, single_child_groups: 576, max_depth: 16, css_max_route: '/', css_max_route_bytes: 9000, css_total_bytes: 30000, unmeasured: [] });
  // A site the replay never reached still has its row, with the reason.
  assert.deepEqual(day1.rows[1].unmeasured, ['not replayed']);
  assert.equal(day1.rows[1].groups, null);
  appendTrend(file, day1.rows);
  const day2 = scoreboard(readTrend(file), { sites: [
    { site: 'squarespace-bedford', cells: [cell('/', true), cell('/a/', false)], flaky_cells: 1, unmeasured: [], metrics: metrics(900, 540, 16, 9000, 29000) },
    { site: 'agency', cells: [cell('/', true)], flaky_cells: 0, unmeasured: [], metrics: metrics(10, 2, 5, 100, 300) },
  ] }, { date: '2026-01-02', trunk: '11112222', release: 'v1.38.30', sites: ['squarespace-bedford', 'agency'] });
  appendTrend(file, day2.rows);
  assert.equal(readTrend(file).length, 4);
  const lines = day2.table.split('\n');
  assert.match(lines[0], /^corpus 2026-01-02 trunk 11112222 v1\.38\.30: \d+ changed/);
  assert.match(lines[1], /^site\s+cells\s+flaky\s+groups\s+1-child\s+depth\s+css max\s+css total$/);
  const bedford = lines.find((l) => l.startsWith('squarespace-bedford'));
  assert.match(bedford, /1\/2 /);
  assert.match(bedford, /0→1\*/);
  assert.match(bedford, /906→900\*/);
  assert.match(bedford, /576→540\*/);
  assert.match(bedford, / 16 /);
  assert.match(bedford, /30000→29000\*/);
  assert.match(lines.find((l) => l.startsWith('agency')), /-\/-→1\/1\*/);
  assert.equal(latestRows(readTrend(file)).get('agency').groups, 10);
  // No previous row: nothing is marked.
  assert.doesNotMatch(deltaTable(new Map(), day2.rows), /\*/);
  assert.equal(trendRow({ date: 'd', trunk: 't', release: 'r' }, { site: 'x', cells: [], metrics_error: 'metrics: boom' }).unmeasured[0], 'metrics: boom');
});

test('a pull request head reports its structure against the trunk baseline next to its cell verdict', () => {
  const replay = { sites: [
    { site: 'squarespace-bedford', regressions: [], improvements: [], unmeasured: [], baseline_metrics: metrics(906, 576, 16, 9000, 30000), metrics: metrics(906, 540, 16, 9000, 30000) },
    { site: 'agency', regressions: [], improvements: [], unmeasured: [], baseline_metrics: metrics(10, 2, 5, 100, 300), metrics: metrics(10, 2, 5, 100, 300) },
  ] };
  const outcome = corpusOutcome(replay);
  assert.deepEqual(outcome.structure, [{ site: 'squarespace-bedford', changes: { single_child_groups: [576, 540] } }]);
  assert.equal(outcome.metrics.length, 2);
  assert.deepEqual(metricChanges([{ site: 'x', before: null, after: metrics(1, 1, 1, 1, 1) }]), []);
  assert.deepEqual(corpusStatus({ failing: [], reasons: [], structure: outcome.structure }, ['squarespace-bedford', 'agency']), { state: 'success', description: 'no regression on 2 corpus sites; squarespace-bedford 1-child 576→540' });
  assert.equal(corpusStatus({ failing: [{ corpus: [{ site: 'agency', cells: ['/@390'] }] }], reasons: [], structure: outcome.structure }, []).description, 'worse on agency (1); squarespace-bedford 1-child 576→540');
});
