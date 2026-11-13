// One check implementation for live evaluation and candidate acceptance.
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { editorPages } from './editor.mjs';
import { blockShape, blockSubject } from './shape.mjs';
import { coreHtmlFindings } from './core-html.mjs';
import { compareImports } from './progress.mjs';

export const WIDTHS = [390, 768, 1440];
const compareClient = JSON.parse(fs.readFileSync(new URL('./compare-client.json', import.meta.url), 'utf8'));
const slugStem = (slug) => slug.replace(/-[0-9a-f]{64}$/, '');
const advisory = new Set(['restyle_coverage_low', 'theme_css_bloat', 'quality_status', 'block_nesting_deep', 'visual_parity_unsampled']);
const findReport = (dir) => {
  for (const entry of fs.existsSync(dir) ? fs.readdirSync(dir, { withFileTypes: true }) : []) {
    const full = path.join(dir, entry.name);
    if (entry.isFile() && entry.name === 'import-report.json') return full;
    if (entry.isDirectory()) { const found = findReport(full); if (found) return found; }
  }
  return null;
};

// The scorer emits each defect as one failure string whose leading words name
// its class. One finding per class gives a fix to one class its own signature,
// owner and count, instead of an all-defects bundle that only clears when every
// failure is gone. Title is the imported site's identity, produced by SSI, not
// the materializer; every other class belongs to Blocks Engine until a class
// proves another owner.
export const parityFailureClass = (failure) => {
  const text = String(failure);
  const known = [
    [/^title /, 'title', 'static-site-importer'],
    [/^nav /, 'link', 'blocks-engine'],
    [/^viewport animation/, 'motion', 'blocks-engine'],
    [/^text position/, 'text_position', 'blocks-engine'],
    [/^text \d/, 'text_content', 'blocks-engine'],
    [/^image geometry/, 'image_geometry', 'blocks-engine'],
    [/^background geometry/, 'background_geometry', 'blocks-engine'],
    [/^page height/, 'page_height', 'blocks-engine'],
    [/^images /, 'image_decoding', 'blocks-engine'],
  ].find(([pattern]) => pattern.test(text));
  if (known) return { subject: known[1], owner_repo: known[2] };
  // A class this table has not seen: its leading words still give a stable
  // subject, so the scorer's new class is measured on its own, not bundled.
  const words = text.toLowerCase().match(/[a-z]+/g)?.slice(0, 3).join('_');
  return { subject: words || 'other', owner_repo: 'blocks-engine' };
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
  if (!classes.size && (!cell.verified || cell.scores.some((s) => !s.pass))) return [{ owner_repo: 'blocks-engine', subject: 'unverified', evidence: cell }];
  return [...classes.values()].map(({ owner_repo, subject, failures }) => ({ owner_repo, subject, evidence: { count: failures.length, failures } }));
};

// The routes a capture holds, as the paths compare scores them: the capture
// receipt lists every captured page, whether the source serves a directory
// (`website/about/index.html` → `/about/`) or a file (`website/about.html` →
// `/about.html`).
export const captureRoutes = (captureRoot) => {
  const receipt = JSON.parse(fs.readFileSync(path.join(captureRoot, 'capture-receipt.json'), 'utf8'));
  const routes = (receipt.routes || []).map(({ path: file }) => '/' + file.replace(/^website\//, '').replace(/(?:^|\/)index\.html$/, (m) => (m.startsWith('/') ? '/' : '')));
  return [...new Set(routes)].sort();
};

export async function checkImport(args) {
  for (const key of ['url', 'wp-url', 'site', 'source', 'out', 'wp-cli']) if (!args[key]) throw new Error(`evaluate_argument_missing: --${key}`);
  fs.mkdirSync(args.out, { recursive: true });
  const captureRoot = path.join(args.source, new URL(args.url).hostname);
  const routes = captureRoutes(captureRoot);
  if (!routes.length) throw new Error('capture_has_no_routes');
  const findings = new Map();
  const report = ({ stage, check, owner_repo, subject, evidence, route = null, width = null }) => {
    const signature = createHash('sha1').update(JSON.stringify([stage, check, owner_repo, subject])).digest('hex').slice(0, 12);
    const finding = findings.get(signature) || { signature, severity: advisory.has(check) ? 'advisory' : 'blocker', stage, check, owner_repo, subject, evidence, occurrences: [] };
    finding.occurrences.push({ route, width, evidence });
    findings.set(signature, finding);
  };

  // Compare writes artifacts into the capture: never mutate a gate input or
  // reuse stale evidence from another run. The same pinned release checks both.
  const capture = path.join(args.out, 'parity-capture');
  fs.rmSync(capture, { recursive: true, force: true });
  fs.cpSync(captureRoot, capture, { recursive: true });
  const command = ['--yes', `--package=${compareClient.package}`, compareClient.executable, 'compare', capture, '--candidate', args['wp-url']];
  const reportPath = path.join(capture, 'compare', 'materialization', 'report.json');
  fs.rmSync(reportPath, { force: true });
  const run = spawnSync('npx', command, { encoding: 'utf8', maxBuffer: 64 << 20, timeout: 30 * 60_000 });
  fs.writeFileSync(path.join(args.out, 'compare.log'), `${run.stdout || ''}\n${run.stderr || ''}`);
  let parity;
  try {
    if (![0, 1].includes(run.status)) throw new Error(run.error?.message || `compare_exit_${run.status}`);
    parity = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
  } catch (error) {
    report({ stage: 'materialization', check: 'visual_parity_unverified', owner_repo: compareClient.owner_repo, subject: 'compare', evidence: { command: ['npx', ...command], status: run.status, error: error.message } });
  }
  // A capture freezes reference evidence for a sample of its routes (the
  // capture's --reference-sample; default 1, the homepage). An unsampled route
  // is uncompared scope by the capture's contract, not a parity failure. A
  // sampled route without scores still fails.
  const reference = JSON.parse(fs.readFileSync(path.join(captureRoot, 'fidelity-reference.json'), 'utf8'));
  const sampled = reference.scope?.referenceSample === undefined ? null : new Set((reference.entries || []).map((e) => e.route));
  const visual = [];
  const sameRoute = (value, route) => new URL(value, args.url).pathname === route;
  for (const route of routes) for (const width of WIDTHS) {
    const scores = (parity?.scores || []).filter((s) => sameRoute(s.route, route) && s.viewport === width);
    const pending = (parity?.pending || []).filter((s) => sameRoute(s.route, route) && s.viewport === width);
    const cell = { route, width, scores, pending, verified: scores.length > 0 && pending.length === 0 };
    if (sampled && !sampled.has(route) && !scores.length && !pending.length) {
      report({ stage: 'materialization', check: 'visual_parity_unsampled', owner_repo: 'static-site-importer', subject: 'reference_sample', evidence: { route, width, reference_sample: reference.scope.referenceSample }, route, width });
      continue;
    }
    visual.push(cell);
    for (const partial of parityCellReports(cell)) report({ stage: 'materialization', check: 'visual_parity', ...partial, route, width });
  }

  const reportFile = findReport(path.join(args.site, 'wp-content', 'static-site-importer'));
  if (!reportFile) report({ stage: 'import', check: 'import_report_missing', owner_repo: 'static-site-importer', subject: 'import-report.json', evidence: args.site });
  else {
    const imported = JSON.parse(fs.readFileSync(reportFile, 'utf8'));
    for (const finding of coreHtmlFindings(imported, routes)) report(finding);
    const metrics = imported.design_system?.metrics || {};
    if (metrics.restyle_coverage < 0.5) report({ stage: 'theme', check: 'restyle_coverage_low', owner_repo: 'blocks-engine', subject: 'restyle_coverage', evidence: metrics });
    if (metrics.generated_to_source_css_ratio > 2) report({ stage: 'theme', check: 'theme_css_bloat', owner_repo: 'blocks-engine', subject: 'generated_to_source_css_ratio', evidence: metrics });
    // Each diagnostic is one actual occurrence; do not multiply a site-wide
    // count by every route when the report has no route attribution.
    for (const d of imported.diagnostics || []) if (d.type === 'raw_html_companion_block') report({ stage: 'editability', check: 'raw_html_companion_block_count', owner_repo: 'blocks-engine', subject: slugStem(String(d.context?.block_name || '').split('/').pop() || 'unknown'), evidence: { document: d.source_path, selector: d.selector } });
  }

  const rawSlugs = new Set([...findings.values()].filter((f) => f.check === 'raw_html_companion_block_count').map((f) => f.subject));
  try {
    const pages = await editorPages(args['wp-url'], args.site, args['wp-cli'], report);
    const tally = (list, key) => list.reduce((m, b) => m.set(key(b), (m.get(key(b)) || 0) + 1), new Map());
    for (const { route, blocks } of pages) {
      const cell = { route, width: 1440 };
      for (const [name, count] of tally(blocks.filter((b) => !b.valid), (b) => b.name)) report({ stage: 'editability', check: 'editor_invalid_block', owner_repo: 'blocks-engine', subject: name, evidence: { count }, ...cell });
      for (const [subject, count] of tally(blocks.filter((b) => /^(ssi-[a-z0-9-]+|custom)\//.test(b.name) && !rawSlugs.has(slugStem(b.name.split('/').pop()))), (b) => blockSubject(slugStem(b.name.split('/').pop()), blockShape(b)))) report({ stage: 'editability', check: 'non_core_block', owner_repo: 'blocks-engine', subject, evidence: { count, of: blocks.length }, ...cell });
      const depth = Math.max(0, ...blocks.map((b) => b.depth));
      if (depth > 8) report({ stage: 'editability', check: 'block_nesting_deep', owner_repo: 'blocks-engine', subject: 'block_depth', evidence: { depth, threshold: 8 }, ...cell });
    }
  } catch (error) { report({ stage: 'editability', check: 'editor_unreachable', owner_repo: 'static-site-importer', subject: 'block_editor', evidence: error.message }); }

  const list = [...findings.values()].sort((a, b) => a.signature.localeCompare(b.signature));
  const result = { schema: 'import-loop/findings/v1', url: args.url, site: args.site, wp_url: args['wp-url'], routes, widths: WIDTHS, import_report: reportFile, verdict: list.some((f) => f.severity === 'blocker') ? 'findings' : 'accepted', findings: list, visual, parity_command: ['npx', ...command] };
  const previous = args.before ? JSON.parse(fs.readFileSync(args.before, 'utf8')) : { findings: [], visual: [] };
  result.progress = compareImports(previous, result).blockers;
  const findingsPath = path.join(args.out, 'findings.json');
  fs.writeFileSync(findingsPath, JSON.stringify(result, null, 2));
  const output = { verdict: result.verdict, finding_count: list.length, blocker_count: list.filter((f) => f.severity === 'blocker').length, blocker_signatures: list.filter((f) => f.severity === 'blocker').map((f) => `${f.signature}:${f.occurrences.reduce((n, o) => n + (o.evidence?.count ?? 1), 0)}`), findings_path: findingsPath, progress: result.progress };
  return { result, output };
}
