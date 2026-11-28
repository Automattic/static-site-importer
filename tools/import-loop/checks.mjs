// One check implementation for live evaluation and candidate acceptance.
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { editorPages } from './editor.mjs';
import { authoringParity } from './authoring-parity.mjs';
import { blockShape, blockSubject } from './shape.mjs';
import { coreHtmlFindings } from './core-html.mjs';
import { compareImports, excess, parityFailureClass, UNCHECKED } from './progress.mjs';
import { wrapperWeight, repeatedStructures, QUALITY } from './quality.mjs';

import { WIDTHS, compareInvocation, visualCells } from './visual.mjs';
export { WIDTHS } from './visual.mjs';
const compareClient = JSON.parse(fs.readFileSync(new URL('./compare-client.json', import.meta.url), 'utf8'));
const slugStem = (slug) => slug.replace(/-[0-9a-f]{64}$/, '');
const advisory = new Set(['restyle_coverage_low', 'quality_status', 'visual_parity_unsampled', 'block_repeated_structure', 'block_duplicated_content_unverified']);
const findReport = (dir) => {
  for (const entry of fs.existsSync(dir) ? fs.readdirSync(dir, { withFileTypes: true }) : []) {
    const full = path.join(dir, entry.name);
    if (entry.isFile() && entry.name === 'import-report.json') return full;
    if (entry.isDirectory()) { const found = findReport(full); if (found) return found; }
  }
  return null;
};

// One per-class report per cell: deduplicated failure strings are the count,
// so a fix that clears some instances moves the finding before the class is
// fully gone. A cell with failures but no measured state keeps its classes; a
// cell that failed without any failure string (pending or missing evidence)
// stays one unverified finding.
export const parityCellReports = (cell) => {
  const classes = new Map();
  for (const score of cell.scores) {
    for (const failure of score.failures || []) {
      const cls = parityFailureClass(failure);
      const entry = classes.get(cls.subject) || { ...cls, failures: [] };
      if (!entry.failures.includes(failure)) entry.failures.push(failure);
      classes.set(cls.subject, entry);
    }
  }
  // A route the imported site answers with 404 was never published: which
  // pages exist is the importer's plan, so it is SSI's finding, not an
  // unmeasured parity cell for the materializer.
  const missing = (cell.pending || []).filter((p) => /\bHTTP 404\b/.test(String(p.reason || '')));
  if (!classes.size && missing.length) return [{ owner_repo: 'static-site-importer', subject: 'route_missing', evidence: { route: cell.route, reasons: [...new Set(missing.map((p) => p.reason))] } }];
  // A cell whose source side was never frozen (the capture's reference holds
  // no single observation for it) is unmeasured on the source, which the
  // capture owns; the materializer cannot prove a cell against nothing.
  const unobserved = (cell.pending || []).filter((p) => /\bSource observation missing or ambiguous\b/.test(String(p.reason || '')));
  if (!classes.size && unobserved.length) return [{ owner_repo: compareClient.owner_repo, subject: 'source_unobserved', evidence: { route: cell.route, reasons: [...new Set(unobserved.map((p) => p.reason))] } }];
  if (!classes.size && (!cell.verified || cell.scores.some((s) => !s.pass))) return [{ owner_repo: 'blocks-engine', subject: 'unverified', evidence: cell }];
  return [...classes.values()].map(({ owner_repo, subject, failures }) => ({ owner_repo, subject, evidence: { count: failures.length, failures } }));
};

// The routes a capture holds, as the paths compare scores them: the capture
// receipt lists every captured page, whether the source serves a directory
// (`website/about/index.html` → `/about/`) or a file (`website/about.html` →
// `/about.html`).
const receiptRoute = (file) => '/' + file.replace(/^website\//, '').replace(/(?:^|\/)index\.html$/, (m) => (m.startsWith('/') ? '/' : ''));
export const captureRoutes = (captureRoot) => {
  const receipt = JSON.parse(fs.readFileSync(path.join(captureRoot, 'capture-receipt.json'), 'utf8'));
  const routes = (receipt.routes || []).map(({ path: file }) => receiptRoute(file));
  return [...new Set(routes)].sort();
};

// Routes the compare declares (its capture's reference) that the capture's
// portable site does not hold. Those pages were never imported, so scoring the
// import would silently shrink the site instead of measuring it.
const routeKey = (route, url) => new URL(route, url).pathname.replace(/(.)\/$/, '$1');
export const unexportedRoutes = (held, parity, url) => {
  const keys = new Set(held.map((route) => routeKey(route, url)));
  const declared = [...(parity?.scores || []), ...(parity?.pending || [])].map((s) => s.route);
  return [...new Set(declared.filter((route) => !keys.has(routeKey(route, url))))].sort();
};

// Routes whose source serves phones a different document than desktops
// (`responsiveVariants`). A route that ships both documents (`variants > 1`)
// holds two copies of its content, so editing one leaves the other stale: a
// blocker. A route reconciled into one document that still ships elements or
// components per viewport is advisory. A route with no mobile document, or one
// that collapsed equivalent, reports nothing.
export const sourceDeviceDocuments = (captureRoot) => {
  const receipt = JSON.parse(fs.readFileSync(path.join(captureRoot, 'capture-receipt.json'), 'utf8'));
  const found = [];
  for (const { path: file, responsiveVariants: rv } of receipt.routes || []) {
    if (!rv || typeof rv !== 'object' || !file) continue;
    const evidence = { outcome: rv.outcome ?? null, variants: Number(rv.variants) || 1, matchedNodes: Number(rv.matchedNodes) || 0, desktopOnlyElements: Number(rv.desktopOnlyElements) || 0, mobileOnlyElements: Number(rv.mobileOnlyElements) || 0, divergedComponents: Number(rv.divergedComponents) || 0, css: rv.css ?? null };
    const route = receiptRoute(file);
    if (evidence.variants > 1) found.push({ route, severity: 'blocker', subject: 'dual_document', evidence });
    else if (evidence.divergedComponents || evidence.desktopOnlyElements || evidence.mobileOnlyElements) found.push({ route, severity: 'advisory', subject: 'viewport_divergence', evidence });
  }
  return found.sort((a, b) => a.route.localeCompare(b.route));
};

// Routes the capture folded into another page although they render different
// text: a folded route never reaches `website/`, so the import cannot make it
// and no other check sees it. Query renditions fold by design and are skipped.
const pageText = (file) => fs.readFileSync(file, 'utf8').replace(/<(script|style|noscript|template)\b[\s\S]*?<\/\1>/gi, ' ').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
export const foldedRoutes = (captureRoot) => {
  const receipt = JSON.parse(fs.readFileSync(path.join(captureRoot, 'capture-receipt.json'), 'utf8'));
  const manifestPath = path.join(captureRoot, 'screenshots', 'manifest.json');
  const entries = fs.existsSync(manifestPath) ? JSON.parse(fs.readFileSync(manifestPath, 'utf8')).entries || {} : {};
  const text = (url) => entries[url]?.html && fs.existsSync(path.join(captureRoot, entries[url].html)) ? pageText(path.join(captureRoot, entries[url].html)) : null;
  return (receipt.duplicateRoutes || []).filter(({ url, canonicalUrl }) => {
    if (new URL(url).search) return false;
    const alias = text(url), canonical = text(canonicalUrl);
    return alias !== null && canonical !== null && alias !== canonical;
  }).map(({ url, canonicalUrl }) => ({ route: new URL(url).pathname, canonical: new URL(canonicalUrl).pathname }));
};

// Content saved twice and shown at disjoint widths (duplicated-content.mjs):
// one blocker per route, counted by duplicate. A route whose source shipped
// separate device documents carries that finding's signature, so triage can
// route the duplicate to capture.
export const duplicatedContentFindings = (duplicates, route, findings = []) => {
  if (duplicates?.error) return [{ stage: 'editability', check: 'block_duplicated_content_unverified', owner_repo: 'static-site-importer', subject: 'duplicated_content', evidence: duplicates.error }];
  if (!duplicates?.length) return [];
  const device = findings.filter((f) => f.check === 'source_device_documents' && f.occurrences.some((o) => o.route == null || o.route === route)).map((f) => f.signature);
  return [{ stage: 'editability', check: 'block_duplicated_content', owner_repo: 'blocks-engine', subject: 'duplicated_content', evidence: { count: duplicates.length, duplicates, ...(device.length ? { source_device_documents: device } : {}) } }];
};

export async function checkImport(args) {
  for (const key of ['url', 'wp-url', 'site', 'source', 'out', 'wp-cli']) if (!args[key]) throw new Error(`evaluate_argument_missing: --${key}`);
  fs.mkdirSync(args.out, { recursive: true });
  const captureRoot = path.join(args.source, new URL(args.url).hostname);
  // The release the capture was taken with (import.sh): compare with the same one.
  const capturedWith = JSON.parse(fs.readFileSync(path.join(captureRoot, '.capture-client.json'), 'utf8')).package;
  const scope = args['check-scope'];
  const scopedCell = (route, width) => !scope || scope.some((s) => s.cells.some((c) => c.route === route && (c.width == null || width == null || c.width === width)));
  const routes = captureRoutes(captureRoot).filter((route) => !scope || scope.some((s) => s.cells.some((c) => c.route == null || c.route === route)));
  if (!routes.length) throw new Error('capture_has_no_routes');
  const findings = new Map();
  // Wall time per stage, so a slow evaluation names its stage.
  const timings = {};
  let mark = Date.now();
  const report = ({ stage, check, owner_repo, subject, evidence, route = null, width = null, severity = null }) => {
    const signature = createHash('sha1').update(JSON.stringify([stage, check, owner_repo, subject])).digest('hex').slice(0, 12);
    if (scope && !UNCHECKED.has(check) && !scope.some((s) => s.signature === signature)) return;
    if (scope && route != null && !scopedCell(route, width)) return;
    const finding = findings.get(signature) || { signature, severity: severity || (advisory.has(check) ? 'advisory' : 'blocker'), stage, check, owner_repo, subject, evidence, occurrences: [] };
    finding.occurrences.push({ route, width, evidence });
    findings.set(signature, finding);
  };
  for (const folded of foldedRoutes(captureRoot)) report({ stage: 'capture', check: 'route_folded', owner_repo: compareClient.owner_repo, subject: 'route_folded', evidence: folded, route: folded.route });
  for (const { route, severity, subject, evidence } of sourceDeviceDocuments(captureRoot)) report({ stage: 'capture', check: 'source_device_documents', owner_repo: compareClient.owner_repo, subject, evidence, route, severity });

  // Compare writes artifacts into the capture: never mutate a gate input or
  // reuse stale evidence from another run. The same pinned release checks both.
  const capture = path.join(args.out, 'parity-capture');
  fs.rmSync(capture, { recursive: true, force: true });
  fs.cpSync(captureRoot, capture, { recursive: true, dereference: true });
  // A client candidate (gates/client-candidate.sh) compares with its own package.
  const { command, reportPath } = compareInvocation(capture, args['wp-url'], process.env.IMPORT_LOOP_CLIENT_PACKAGE || capturedWith);
  fs.rmSync(reportPath, { force: true });
  let compareCommand = command;
  let compareEnv = process.env;
  if (scope) {
    const scopeFile = path.resolve(args.out, 'check-scope.json');
    fs.writeFileSync(scopeFile, JSON.stringify(scope));
    compareCommand = ['--yes', `--package=${process.env.IMPORT_LOOP_CLIENT_PACKAGE || capturedWith}`, '-c', `node "$IMPORT_LOOP_BISECT_COMPARE" "$IMPORT_LOOP_COMPARE_EXECUTABLE" "$IMPORT_LOOP_COMPARE_CAPTURE" "$IMPORT_LOOP_COMPARE_CANDIDATE" "$IMPORT_LOOP_COMPARE_SCOPE"`];
    compareEnv = { ...process.env,
      IMPORT_LOOP_BISECT_COMPARE: new URL('./bisect-compare.mjs', import.meta.url).pathname,
      IMPORT_LOOP_COMPARE_EXECUTABLE: compareClient.executable,
      IMPORT_LOOP_COMPARE_CAPTURE: path.resolve(capture),
      IMPORT_LOOP_COMPARE_CANDIDATE: args['wp-url'],
      IMPORT_LOOP_COMPARE_SCOPE: scopeFile,
    };
  }
  timings.setup = Date.now() - mark; mark = Date.now();
  const run = spawnSync('npx', compareCommand, { env: compareEnv, encoding: 'utf8', maxBuffer: 64 << 20, timeout: 30 * 60_000 });
  fs.writeFileSync(path.join(args.out, 'compare.log'), `${run.stdout || ''}\n${run.stderr || ''}`);
  let parity;
  try {
    if (![0, 1].includes(run.status)) throw new Error(run.error?.message || `compare_exit_${run.status}`);
    parity = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
  } catch (error) {
    report({ stage: 'materialization', check: 'visual_parity_unverified', owner_repo: compareClient.owner_repo, subject: 'compare', evidence: { command: ['npx', ...command], status: run.status, error: error.message } });
  }
  const unexported = unexportedRoutes(captureRoutes(captureRoot), parity, args.url);
  if (unexported.length) report({ stage: 'capture', check: 'capture_route_unexported', owner_repo: compareClient.owner_repo, subject: 'portable_site', evidence: { routes: unexported, held: captureRoutes(captureRoot).length } });
  // A capture freezes reference evidence for a sample of its routes (the
  // capture's --reference-sample; default 1, the homepage). An unsampled route
  // is uncompared scope by the capture's contract, not a parity failure. A
  // sampled route without scores still fails.
  const measured = visualCells(captureRoot, routes, parity, args.url);
  const visual = measured.cells.filter(c => scopedCell(c.route, c.width));
  for (const { route, width } of measured.unsampled) {
    if (scopedCell(route, width)) report({ stage: 'materialization', check: 'visual_parity_unsampled', owner_repo: 'static-site-importer', subject: 'reference_sample', evidence: { route, width, reference_sample: measured.reference.scope.referenceSample }, route, width });
  }
  for (const cell of visual) {
    for (const partial of parityCellReports(cell)) report({ stage: 'materialization', check: 'visual_parity', ...partial, route: cell.route, width: cell.width });
  }

  timings.visual_parity = Date.now() - mark; mark = Date.now();
  const reportFile = findReport(path.join(args.site, 'wp-content', 'static-site-importer'));
  if (!reportFile) report({ stage: 'import', check: 'import_report_missing', owner_repo: 'static-site-importer', subject: 'import-report.json', evidence: args.site });
  else {
    const imported = JSON.parse(fs.readFileSync(reportFile, 'utf8'));
    for (const finding of coreHtmlFindings(imported, routes)) report(finding);
    const metrics = imported.design_system?.metrics || {};
    if (metrics.restyle_coverage < 0.5) report({ stage: 'theme', check: 'restyle_coverage_low', owner_repo: 'blocks-engine', subject: 'restyle_coverage', evidence: metrics });
    // Each diagnostic is one actual occurrence; do not multiply a site-wide
    // count by every route when the report has no route attribution.
    for (const d of imported.diagnostics || []) if (d.type === 'raw_html_companion_block') report({ stage: 'editability', check: 'raw_html_companion_block_count', owner_repo: 'blocks-engine', subject: slugStem(String(d.context?.block_name || '').split('/').pop() || 'unknown'), evidence: { document: d.source_path, selector: d.selector } });
  }

  timings.import_report = Date.now() - mark; mark = Date.now();
  const rawSlugs = new Set([...findings.values()].filter((f) => f.check === 'raw_html_companion_block_count').map((f) => f.subject));
  try {
    const pages = await editorPages(args['wp-url'], args.site, args['wp-cli'], report, scope?.flatMap((s) => s.cells), WIDTHS, captureRoot);
    const tally = (list, key) => list.reduce((m, b) => m.set(key(b), (m.get(key(b)) || 0) + 1), new Map());
    for (const { route, blocks, duplicates } of pages) {
      const cell = { route, width: 1440 };
      for (const [name, count] of tally(blocks.filter((b) => !b.valid), (b) => b.name)) report({ stage: 'editability', check: 'editor_invalid_block', owner_repo: 'blocks-engine', subject: name, evidence: { count }, ...cell });
      for (const [subject, count] of tally(blocks.filter((b) => /^(ssi-[a-z0-9-]+|custom)\//.test(b.name) && !rawSlugs.has(slugStem(b.name.split('/').pop()))), (b) => blockSubject(slugStem(b.name.split('/').pop()), blockShape(b)))) report({ stage: 'editability', check: 'non_core_block', owner_repo: 'blocks-engine', subject, evidence: { count, of: blocks.length }, ...cell });
      const depth = Math.max(0, ...blocks.map((b) => b.depth));
      if (depth > QUALITY.depth) report({ stage: 'editability', check: 'block_nesting_deep', owner_repo: 'blocks-engine', subject: 'block_depth', evidence: { depth, threshold: QUALITY.depth, measure: { value: depth, limit: QUALITY.depth } }, ...cell });
      const weight = wrapperWeight(blocks);
      if (weight.heavy) report({ stage: 'editability', check: 'block_wrapper_heavy', owner_repo: 'blocks-engine', subject: 'block_wrappers', evidence: { ...weight, threshold: QUALITY.groups_per_leaf, measure: { value: weight.groups / Math.max(1, weight.leaves), limit: QUALITY.groups_per_leaf } }, ...cell });
      const repeated = repeatedStructures(blocks);
      if (repeated.length) report({ stage: 'editability', check: 'block_repeated_structure', owner_repo: 'blocks-engine', subject: 'repeated_structure', evidence: repeated, ...cell });
      for (const finding of duplicatedContentFindings(duplicates, route, [...findings.values()])) report({ ...finding, ...cell });
    }
  } catch (error) { report({ stage: 'editability', check: 'editor_unreachable', owner_repo: 'static-site-importer', subject: 'block_editor', evidence: error.message }); }

  timings.editor = Date.now() - mark; mark = Date.now();
  let authoring = [];
  try {
    authoring = await authoringParity(args.site, args['wp-cli']);
    if (!authoring.length) throw new Error('No imported layout clusters');
    for (const row of authoring.filter(row => !row.pass)) report({ stage: 'editability', check: 'authoring_parity', owner_repo: 'blocks-engine', subject: row.cluster, evidence: row });
  } catch (error) { report({ stage: 'editability', check: 'authoring_parity_unverified', owner_repo: 'static-site-importer', subject: 'authoring_parity', evidence: error.message }); }
  timings.authoring_parity = Date.now() - mark; mark = Date.now();

  const list = [...findings.values()].sort((a, b) => a.signature.localeCompare(b.signature));

  const result = { timings_ms: timings, authoring_parity_evidence: authoring, schema: 'import-loop/findings/v1', url: args.url, site: args.site, wp_url: args['wp-url'], routes, widths: WIDTHS, import_report: reportFile, verdict: list.some((f) => f.severity === 'blocker') ? 'findings' : 'accepted', findings: list, visual, parity_command: ['npx', ...command] };
  const previous = args.before ? JSON.parse(fs.readFileSync(args.before, 'utf8')) : { findings: [], visual: [] };
  result.progress = compareImports(previous, result).blockers;
  const findingsPath = path.join(args.out, 'findings.json');
  fs.writeFileSync(findingsPath, JSON.stringify(result, null, 2));
  const output = { verdict: result.verdict, finding_count: list.length, blocker_count: list.filter((f) => f.severity === 'blocker').length, blocker_signatures: list.filter((f) => f.severity === 'blocker').map((f) => `${f.signature}:${f.occurrences.reduce((n, o) => n + (o.evidence?.count ?? 1), 0)}${excess(f) ? `:${excess(f)}` : ''}`), findings_path: findingsPath, progress: result.progress };
  return { result, output };
}
