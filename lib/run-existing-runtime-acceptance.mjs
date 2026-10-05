import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { acceptanceScope, aggregateAcceptance, fidelityGate, editorGate, verifyReference, fileReference, writeEvidence, hash, bindFidelityReport } from './existing-runtime-acceptance.mjs';
import { reviewAcceptanceEditor } from '../tools/run-existing-runtime-review.mjs';
import { consumeAcceptanceHandoff } from './fixture-matrix/acceptance-handoff.mjs';
import { consumeOwnerHandoffEvidence } from './fixture-matrix/owner-handoff-evidence.mjs';

const read = file => JSON.parse(fs.readFileSync(file, 'utf8'));
const pending = reason => ({ status: 'pending', reason });
function origin(value) {
  const url = new URL(value);
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.pathname !== '/' || url.search || url.hash) throw new Error('Credential-free HTTP(S) origin required.');
  return url.origin;
}
// Hooks and auth configuration are deliberately never part of serialized evidence.
function safe(value) {
  if (Array.isArray(value)) return value.map(safe);
  if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).filter(([key]) => !/password|cookie|authorization|token|secret|credential/i.test(key)).map(([key, entry]) => [key, safe(entry)]));
  if (typeof value === 'string') return value.replace(/https?:\/\/[^\s/@]+(?::[^\s/@]*)?@/gi, 'https://').replace(/([?&](?:token|password|key|nonce|auth)[^=]*=)[^&\s]+/gi, '$1[redacted]');
  return value;
}

export async function runExistingRuntimeAcceptance(config) {
  const root = path.resolve(config.outputDirectory);
  fs.mkdirSync(root, { recursive: true });
  const directory = path.resolve(config.directory);
  const manifest = read(config.manifest);
  const scope = acceptanceScope(manifest);
  const candidateOrigin = origin(config.candidateOrigin);
  const authenticate = config.authenticate ?? (config.authEndpoint ? async ({ page, candidateOrigin, postId }) => {
    const endpoint = typeof config.authEndpoint === 'function' ? await config.authEndpoint({ candidateOrigin, postId }) : config.authEndpoint;
    await page.goto(endpoint, { waitUntil: 'domcontentloaded' });
  } : undefined);
  const artifacts = {};
  const prerequisites = [];
  const identity = { candidate_origin: candidateOrigin, candidate: config.candidateIdentity, capture: config.reference, manifest_sha256: hash(fs.readFileSync(config.manifest)), runtime: config.runtimeIdentity, import_report: config.importReport, import_run_id: config.importRunId };
  artifacts.manifest = writeEvidence(root, 'artifacts/manifest.json', { routes: scope.routes, widths: scope.widths, states: scope.states });
  let candidateIdentityValid = verifyReference(config.evidenceRoot, config.candidateIdentity);
  if (candidateIdentityValid) try {
    const candidate = read(path.resolve(config.evidenceRoot, config.candidateIdentity.path));
    candidateIdentityValid = candidate.candidate_origin === candidateOrigin && candidate.import_run_id === config.importRunId && candidate.reference_sha256 === config.reference?.sha256 && candidate.manifest_sha256 === identity.manifest_sha256;
    if (candidateIdentityValid) artifacts.candidate_identity = writeEvidence(root, 'artifacts/candidate-identity.json', safe(candidate));
  } catch { candidateIdentityValid = false; }
  if (!candidateIdentityValid || !config.runtimeIdentity) prerequisites.push(pending('candidate_runtime_identity_required'));
  let referenceValid = config.reference?.path === 'fidelity-reference.json' && verifyReference(directory, config.reference);
  if (referenceValid) try { artifacts.capture_reference = writeEvidence(root, 'artifacts/fidelity-reference.json', safe(read(path.resolve(directory, config.reference.path)))); } catch { referenceValid = false; }
  if (!referenceValid) prerequisites.push(pending('frozen_reference_missing_or_hash_mismatch'));
  let runtime;
  try {
    // Package name uses only its public exports; file module must be an explicit public entry.
    if (Boolean(config.runtimeModule) === Boolean(config.runtimePackage)) throw new Error('one runtime input required');
    const entry = config.runtimeModule ? pathToFileURL(path.resolve(config.runtimeModule)).href : import.meta.resolve(config.runtimePackage);
    runtime = await import(entry);
    if (typeof runtime.checkFidelity !== 'function' || !config.runtimeValidation || !verifyReference(config.evidenceRoot, config.runtimeValidation)) runtime = null;
    if (runtime) {
      const validation = read(path.resolve(config.evidenceRoot, config.runtimeValidation.path));
      if (validation.schema !== 'static-site-importer/dla-runtime-validation/v1' || validation.runtime_identity !== config.runtimeIdentity || validation.public_entry_sha256 !== hash(fs.readFileSync(fileURLToPath(entry))) || validation.status !== 'passed' || validation.frozen_capture !== true || validation.materialization_no_origin_visits !== true) runtime = null;
      else artifacts.runtime_validation = writeEvidence(root, 'artifacts/runtime-validation.json', safe(validation));
    }
  } catch { runtime = null; }
  if (!runtime) prerequisites.push(pending('validated_dla_fidelity_runtime_unavailable'));
  const gates = {};
  for (const stage of ['capture', 'materialization']) {
    let report;
    let valid = false;
    if (runtime && referenceValid) {
      try {
        // A report left by an earlier invocation is not evidence of this run.
        const file = path.join(directory, 'compare', stage, 'report.json');
        fs.rmSync(file, { force: true });
        const publicReport = await runtime.checkFidelity({ directory, stage, ...(stage === 'materialization' ? { candidateUrl: candidateOrigin } : {}), routes: scope.routes, widths: scope.widths, states: scope.states, screenshots: true });
        const persisted = read(file);
        if (JSON.stringify(publicReport) !== JSON.stringify(persisted)) throw new Error('DLA persisted report does not match returned evidence');
        const stageArtifacts = [fileReference(directory, file)];
        const retain = dir => {
          for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            const target = path.join(dir, entry.name);
            if (entry.isDirectory()) retain(target);
            else if (entry.isFile() && target !== file) stageArtifacts.push(fileReference(directory, target));
          }
        };
        retain(path.dirname(file));
        report = bindFidelityReport(stage, scope, publicReport, config.reference.sha256, candidateOrigin, stageArtifacts);
        valid = verifyReference(directory, config.reference) && stageArtifacts.every(ref => verifyReference(directory, ref));
        artifacts[stage] = writeEvidence(root, `artifacts/${stage}.json`, safe(report));
        for (const [index, ref] of (report.artifacts ?? []).entries()) {
          if (!verifyReference(directory, ref)) continue;
          const target = path.join(root, 'artifacts', stage, `${index}-${path.basename(ref.path)}`);
          fs.mkdirSync(path.dirname(target), { recursive: true });
          fs.copyFileSync(path.resolve(directory, ref.path), target);
          artifacts[`${stage}-${index}`] = fileReference(root, target);
        }
      } catch { report = { stage, status: 'unproven', pending: [{ stage, reason: 'dla_execution_or_evidence_unavailable' }] }; }
    }
    gates[stage] = fidelityGate(stage, scope, report, valid);
    gates[stage].evidence_valid = valid;
  }
  let browser;
  const editorRows = [];
  const health = { status: 'pending', routes: [] };
  const cleanup = { status: 'passed' };
  let inventoryVerified = false;
  try {
    browser = await chromium.launch({ headless: true });
    const page = await browser.newPage();
    try {
      // Independent HTTP/fatal health is never promoted by a visual score.
      for (const { route, width } of scope.cells.filter(cell => cell.state === scope.states[0])) {
        const probe = await browser.newPage();
        const fatals = [];
        probe.on('pageerror', () => fatals.push('uncaught_page_error'));
        try {
          await probe.setViewportSize({ width, height: 1000 });
          const response = await probe.goto(new URL(route, candidateOrigin).href, { waitUntil: 'load', timeout: 30_000 });
          const fatal = await probe.locator('body').innerText();
          health.routes.push({ route, width, status: !response || response.status() >= 400 || fatals.length || /Fatal error:|There has been a critical error/i.test(fatal) ? 'failed' : 'passed', http_status: response?.status(), fatals });
        } catch { health.routes.push({ route, width, status: fatals.length ? 'failed' : 'pending', fatals, reason: 'runtime_health_evidence_unavailable' }); }
        finally { try { await probe.close(); } catch { cleanup.status = 'failed'; cleanup.reason = 'health_probe_cleanup_failed'; } }
      }
      health.status = health.routes.some(row => row.status === 'failed') ? 'failed' : health.routes.every(row => row.status === 'passed') ? 'passed' : 'pending';
      if (typeof authenticate === 'function') await authenticate({ page, candidateOrigin });
      else if (config.authProvider === 'studio-auto-login') await page.goto(new URL('/studio-auto-login', candidateOrigin).href);
      else throw new Error('caller_authentication_required');
      if (typeof config.candidateInventory !== 'function') throw new Error('candidate_route_inventory_required');
      const inventory = await config.candidateInventory({ page, candidateOrigin });
      const mapping = config.routeToPost;
      inventoryVerified = Array.isArray(inventory) && Array.isArray(mapping) && inventory.length === scope.routes.length && mapping.length === scope.routes.length && scope.routes.every(route => {
        const supplied = mapping.filter(row => row.route === route), actual = inventory.filter(row => row.route === route);
        return supplied.length === 1 && actual.length === 1 && Number.isInteger(supplied[0].postId) && supplied[0].postId > 0 && supplied[0].postId === actual[0].postId && supplied[0].postType === actual[0].postType;
      });
      artifacts.inventory = writeEvidence(root, 'artifacts/candidate-inventory.json', safe(inventory));
      if (inventoryVerified) for (const mapping of config.routeToPost) {
        const row = await reviewAcceptanceEditor(browser, { candidate_origin: candidateOrigin, post_id: mapping.postId, post_type: mapping.postType, editor_id: config.editorId, authenticate, portable_origin: config.portableOrigin ? origin(config.portableOrigin) : null, output_directory: path.join(root, `editor-${mapping.postId}`) }, scope, mapping);
        editorRows.push(row);
        for (const [index, presentation] of row.presentations.entries()) for (const [artifactIndex, artifact] of (presentation.artifacts ?? []).entries()) {
          const file = path.join(root, `editor-${mapping.postId}`, artifact.file);
          try { artifacts[`editor-${mapping.postId}-${index}-${artifactIndex}`] = fileReference(root, file); }
          catch { if (row.status !== 'failed') row.status = 'pending'; row.reason = 'editor_artifact_unavailable'; }
        }
      }
    } finally { try { await page.close(); } catch { cleanup.status = 'failed'; cleanup.reason = 'inventory_page_cleanup_failed'; } }
  } catch { prerequisites.push(pending('browser_auth_or_inventory_evidence_unavailable')); }
  finally {
    if (browser) try { await browser.close(); } catch { cleanup.status = 'failed'; cleanup.reason = 'browser_cleanup_failed'; }
  }
  if (!inventoryVerified) prerequisites.push(pending('complete_route_post_identity_not_verified'));
  gates.health = health;
  gates.editor = editorGate(scope, editorRows);
  // Presentation currently measures resting baseline only. Named states still need real editor evidence.
  if (scope.states.some(state => state !== 'baseline')) prerequisites.push(pending('named_editor_state_evidence_unavailable'));
  prerequisites.push(cleanup);
  let importReport;
  let importReportVerified = verifyReference(config.evidenceRoot, config.importReport);
  if (importReportVerified) try { importReport = read(path.resolve(config.evidenceRoot, config.importReport.path)); artifacts.import_report = writeEvidence(root, 'artifacts/import-report.json', safe(importReport)); } catch { importReportVerified = false; }
  if (config.importRunId !== importReport?.import_run_id || !config.importRunId) prerequisites.push(pending('import_run_identity_not_bound'));
  // Existing workflow contracts can accompany this scoped review. Consume their
  // predicates rather than relabeling a fidelity pass as an owner/built pass.
  for (const [name, consume] of [['acceptanceHandoff', value => consumeAcceptanceHandoff(config.evidenceRoot, value)], ['ownerHandoff', consumeOwnerHandoffEvidence]]) {
    if (!config[name]) continue;
    if (!verifyReference(config.evidenceRoot, config[name])) { prerequisites.push(pending(`${name}_artifact_invalid`)); continue; }
    const document = read(path.resolve(config.evidenceRoot, config[name].path));
    const consumed = consume(document);
    artifacts[name] = writeEvidence(root, `artifacts/${name}.json`, safe(document));
    prerequisites.push({ status: consumed.disposition === 'failed' ? 'failed' : consumed.disposition === 'passed' ? 'passed' : 'pending', reason: `${name}_${consumed.disposition}` });
  }
  artifacts.editor = writeEvidence(root, 'artifacts/editor.json', safe(gates.editor));
  artifacts.health = writeEvidence(root, 'artifacts/health.json', health);
  const result = safe({ ...aggregateAcceptance({ identity, scope, gates, importReport, importReportVerified, prerequisites }), artifacts });
  writeEvidence(root, 'existing-runtime-acceptance.json', result);
  return result;
}
