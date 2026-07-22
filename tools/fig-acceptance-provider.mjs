#!/usr/bin/env node

/**
 * Adapt versioned Blocks Engine transform and SSI matrix records to Blocks
 * Engine's acceptance provider contract.  It never treats aggregate status as
 * evidence: every stage is backed by an artifact or a named provider input.
 */
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

export const STAGES = ['decode', 'normalize', 'emit', 'figma_html_desktop_parity', 'figma_html_mobile_parity', 'import', 'editor_validity', 'fallback', 'html_wordpress_desktop_parity', 'html_wordpress_mobile_parity', 'figma_wordpress_desktop_parity', 'figma_wordpress_mobile_parity', 'responsive_selection'];
const PARITY = { figma_html_desktop_parity: 'figma_html', figma_html_mobile_parity: 'figma_html', html_wordpress_desktop_parity: 'html_wordpress', html_wordpress_mobile_parity: 'html_wordpress', figma_wordpress_desktop_parity: 'figma_wordpress', figma_wordpress_mobile_parity: 'figma_wordpress' };
const FIGMA_STAGES = new Set(['decode', 'normalize', 'emit', 'figma_html_desktop_parity', 'figma_html_mobile_parity', 'responsive_selection']);
const EXTERNAL_PARITY = new Set(['html_wordpress_mobile_parity', 'figma_wordpress_desktop_parity', 'figma_wordpress_mobile_parity']);

if (import.meta.url === `file://${process.argv[1]}`) {
  try { process.stdout.write(`${JSON.stringify(buildFigAcceptanceProvider(parseArgs(process.argv.slice(2))), null, 2)}\n`); } catch (error) { process.stderr.write(`${error.message}\n`); process.exitCode = 1; }
}

export function buildFigAcceptanceProvider(input = {}) {
  const fig = requiredFile(input.fig, '--fig');
  const fixtureId = requiredSlug(input.fixtureId || input.fixture_id, '--fixture-id');
  const fixtureOutput = path.resolve(requiredString(input.fixtureOutput || input.fixture_output, '--fixture-output'));
  const matrixOutput = requiredDirectory(input.matrixOutput || input.matrix_output, '--matrix-output');
  const transformSummary = readJson(requiredFile(input.transformSummary || input.transform_summary, '--transform-summary'));
  const transform = fixtureRecord(transformSummary, fixtureId, 'transform');
  const matrix = fixtureRecord(readJson(requiredFile(input.matrixResult || input.matrix_result, '--matrix-result')), fixtureId, 'matrix');
  if (transform.status !== 'completed') fail('transform fixture is not completed');
  if (matrix.status !== 'passed') fail('matrix fixture is not passed');
  const root = acceptanceRoot(fixtureOutput);
  const directory = path.join(fixtureOutput, 'ssi-acceptance-provider');
  const transformRoot = requiredDirectory(transformSummary.output_dir, 'transform output_dir');
  requiredFile(transform.result_path, 'transform result_path');
  requiredDirectory(transform.artifact_dir, 'transform artifact_dir');
  const sourceHash = sha256(fig);
  const source = copyEvidence(fig, directory, root, 'source-artifact.fig');
  const emitted = copyEvidence(transform.result_path, directory, root, 'transform-result.json');
  const sitePlanValue = readJson(requiredFile(input.sitePlan || input.site_plan, '--site-plan'));
  validateSitePlan(sitePlanValue);
  const sitePlan = writeArtifact(sitePlanValue, directory, root, 'site-plan.json');
  const importReport = writeArtifact(matrix, directory, root, 'matrix-fixture-result.json');
  const editorReport = writeArtifact(requiredObject(matrix.editor_validation, 'matrix editor_validation'), directory, root, 'editor-report.json');
  const figmaRecords = figmaStageRecords(transform, transformRoot, directory, root, fixtureId, sourceHash);
  const composition = requiredObject(matrix.block_composition, 'matrix block_composition');
  const editor = requiredObject(matrix.editor_validation, 'matrix editor_validation');
  const imported = { imported_route_count: positiveInteger(matrix.matrix_evidence?.materialization_receipt?.page_count, 'imported route count') };
  const editorMetrics = { parsed_block_count: positiveInteger(editor.total_blocks, 'editor total_blocks'), native_editable_block_count: positiveInteger(composition.native_block_count, 'native block count'), invalid_block_count: integer(editor.invalid_blocks, 'editor invalid_blocks') };
  zeroMetrics(editorMetrics, ['invalid_block_count'], 'editor validity');
  const fallbackCount = fallbackCountFrom(matrix); if (fallbackCount !== 0) fail('fallback count must be zero');
  const providerIdentity = requiredString(input.providerIdentity || input.provider_identity, '--provider-identity');
  const runtimeIdentity = requiredString(input.runtimeIdentity || input.runtime_identity, '--runtime-identity');
  const records = {
    import: { metrics: imported, references: [importReport, sitePlan], isolated_fresh_wordpress_import: true, provider_identity: providerIdentity, runtime_identity: runtimeIdentity },
    editor_validity: { metrics: editorMetrics, references: [editorReport] }, fallback: { fallback_count: fallbackCount, references: [importReport] },
  };
  for (const stage of Object.keys(PARITY)) if (!FIGMA_STAGES.has(stage)) records[stage] = parityRecord(stage, matrix, input, directory, root, matrixOutput);
  const stages = {};
  for (const stage of STAGES) {
    const file = path.join(directory, 'stages', `${stage}.json`);
    writeJson(file, figmaRecords[stage] || { schema: 'blocks-engine/figma-wordpress-stage-evidence/v1', fixture_id: fixtureId, stage, source_sha256: sourceHash, status: 'passed', ...records[stage] });
    stages[stage] = file;
  }
  const manifest = { id: fixtureId, fig, evidence: stages, site_plan: path.join(root, sitePlan), provider_artifacts: { source, emitted, importReport, editorReport, sitePlan } };
  writeJson(path.join(directory, 'manifest-fragment.json'), manifest);
  return manifest;
}

function figmaStageRecords(transform, transformRoot, directory, root, fixtureId, sourceHash) {
  const readiness = requiredObject(transform.acceptance_readiness, 'transform acceptance_readiness');
  if (readiness.schema !== 'blocks-engine/figma-transformer/acceptance-readiness/v1') fail('transform acceptance_readiness has an invalid schema');
  const paths = requiredObject(readiness.stage_paths, 'transform acceptance_readiness stage_paths');
  const records = {};
  for (const stage of FIGMA_STAGES) {
    const evidencePath = resolveArtifact(paths[stage], transformRoot, `transform ${stage} stage path`);
    const evidence = readJson(evidencePath);
    if (evidence.schema !== 'blocks-engine/figma-wordpress-stage-evidence/v1' || evidence.fixture_id !== fixtureId || evidence.stage !== stage) fail(`transform ${stage} evidence identity is invalid`);
    if (evidence.source_sha256 !== sourceHash) fail(`transform ${stage} evidence does not match --fig`);
    if (evidence.status !== 'passed') fail(`transform ${stage} evidence is not passed`);
    const references = Array.isArray(evidence.references) ? evidence.references : fail(`transform ${stage} evidence references are required`);
    const rewritten = new Map();
    for (const [index, reference] of references.entries()) {
      const sourceFile = resolveArtifact(reference, transformRoot, `transform ${stage} reference`);
      rewritten.set(reference, copyEvidence(sourceFile, directory, root, path.join('figma', stage, `${index}-${path.basename(sourceFile)}`)));
    }
    records[stage] = { ...evidence, references: references.map((reference) => rewritten.get(reference)) };
    for (const key of ['source_screenshot', 'rendered_screenshot', 'diff_report']) if (typeof evidence[key] === 'string') records[stage][key] = rewritten.get(evidence[key]) || fail(`transform ${stage} ${key} is not a declared reference`);
  }
  return records;
}

function parityRecord(stage, matrix, input, directory, root, matrixOutput) {
  const supplied = input[camel(stage)] || input[stage];
  const item = EXTERNAL_PARITY.has(stage) ? readExternalParity(supplied, stage) : htmlWordpressParity(matrix, matrixOutput);
  const source = copyEvidence(item.source_screenshot, directory, root, `${stage}-source.png`);
  const rendered = copyEvidence(item.rendered_screenshot, directory, root, `${stage}-rendered.png`);
  const report = writeArtifact(item.diff_report, directory, root, `${stage}-diff.json`);
  const checked = requiredMetrics(item.diff_report.metrics || item.diff_report, ['pixel_difference_count', 'geometry_difference_count'], stage);
  zeroMetrics(checked, ['pixel_difference_count', 'geometry_difference_count'], stage);
  return { comparison: PARITY[stage], metrics: checked, source_screenshot: source, rendered_screenshot: rendered, diff_report: report, references: [source, rendered, report] };
}
function htmlWordpressParity(matrix, root) {
  const visual = requiredObject(matrix.visual_parity_artifacts, 'matrix visual_parity_artifacts');
  const slots = requiredObject(visual.artifacts, 'matrix visual parity artifacts');
  const source = artifactPath(slots.source_screenshot, root, 'html_wordpress_desktop_parity source screenshot');
  const rendered = artifactPath(slots.imported_screenshot, root, 'html_wordpress_desktop_parity rendered screenshot');
  artifactPath(slots.visual_diff, root, 'html_wordpress_desktop_parity diff report');
  const metrics = requiredObject(visual.metrics, 'matrix visual parity metrics');
  return { source_screenshot: source, rendered_screenshot: rendered, diff_report: { metrics: { pixel_difference_count: integer(metrics.mismatch_pixels, 'html_wordpress_desktop_parity mismatch_pixels'), geometry_difference_count: metrics.dimension_mismatch ? 1 : 0 } } };
}
function readExternalParity(value, stage) {
  if (!value) fail(`${stage} is unavailable from current workflows; supply --${stage.replaceAll('_', '-')}`);
  const item = readJson(requiredFile(value, `${stage} provider input`));
  if (item.schema !== 'static-site-importer/fig-acceptance-parity-input/v1' || item.stage !== stage) fail(`${stage} provider input has an invalid schema or stage`);
  requiredFile(item.source_screenshot, `${stage} source screenshot`); requiredFile(item.rendered_screenshot, `${stage} rendered screenshot`);
  return { source_screenshot: item.source_screenshot, rendered_screenshot: item.rendered_screenshot, diff_report: requiredObject(item.diff_report, `${stage} diff_report`) };
}
function validateSitePlan(plan) { if (plan.schema !== 'blocks-engine/wordpress-site-plan/v2' || !Array.isArray(plan.pages) || !plan.pages.some((page) => page && typeof page === 'object') || !Array.isArray(plan.routes) || !plan.routes.some((route) => route && typeof route === 'object')) fail('site plan is not a structurally valid non-empty blocks-engine/wordpress-site-plan/v2 artifact'); }
function fallbackCountFrom(matrix) { const quality = requiredObject(matrix.quality_metrics, 'matrix quality_metrics'); const count = integer(quality.fallback_count, 'matrix fallback_count'); if (integer(matrix.block_composition?.core_html_block_count || 0, 'core html block count') !== 0) fail('core/html fallback count must be zero'); return count; }
function fixtureRecord(document, id, label) { const record = (Array.isArray(document.fixtures) ? document.fixtures : []).find((item) => item?.id === id || item?.fixture_id === id); if (!record) fail(`${label} evidence has no fixture ${id}`); return record; }
function artifactPath(slot, root, label) { const value = slot?.ref?.path || slot?.path; const file = path.resolve(root, requiredString(value, label)); return requiredFile(file, label); }
function resolveArtifact(value, root, label) { const reference = requiredString(value, label); const file = path.isAbsolute(reference) ? reference : path.join(root, reference); return requiredFile(file, label); }
function copyEvidence(value, directory, root, name) { const target = path.join(directory, 'artifacts', name); fs.mkdirSync(path.dirname(target), { recursive: true }); fs.copyFileSync(requiredFile(value, 'evidence file'), target); return relativeReference(root, target); }
function writeArtifact(value, directory, root, name) { const target = path.join(directory, 'artifacts', name); writeJson(target, value); return relativeReference(root, target); }
function requiredMetrics(value, keys, label) { const metrics = requiredObject(value, `${label} metrics`); for (const key of keys) integer(metrics[key], `${label} ${key}`); return Object.fromEntries(keys.map((key) => [key, metrics[key]])); }
function zeroMetrics(metrics, keys, label) { for (const key of keys) if (integer(metrics[key], `${label} ${key}`) !== 0) fail(`${label} ${key} must be zero`); }
function acceptanceRoot(output) { const root = path.dirname(path.dirname(output)); if (path.basename(path.dirname(output)) !== 'fixtures') fail('fixture output must be under an acceptance fixtures/<id> directory'); return root; }
function relativeReference(root, file) { const ref = path.relative(root, file); if (!ref || ref.startsWith('..') || path.isAbsolute(ref)) fail('evidence cannot be resolved relative to the acceptance output'); return ref.split(path.sep).join('/'); }
function requiredFile(value, label) { const file = path.resolve(requiredString(value, label)); if (!fs.existsSync(file) || !fs.statSync(file).isFile()) fail(`${label} is missing or unreadable: ${file}`); return file; }
function requiredDirectory(value, label) { const directory = path.resolve(requiredString(value, label)); if (!fs.existsSync(directory) || !fs.statSync(directory).isDirectory()) fail(`${label} is missing or unreadable: ${directory}`); return directory; }
function readJson(file) { try { const value = JSON.parse(fs.readFileSync(file, 'utf8')); return requiredObject(value, 'JSON evidence'); } catch { fail(`invalid JSON evidence: ${file}`); } }
function writeJson(file, value) { fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`); }
function requiredObject(value, label) { if (!value || typeof value !== 'object' || Array.isArray(value)) fail(`${label} is required`); return value; }
function integer(value, label) { if (!Number.isInteger(value)) fail(`${label} must be an integer`); return value; }
function positiveInteger(value, label) { const number = integer(value, label); if (number <= 0) fail(`${label} must be positive`); return number; }
function requiredString(value, label) { if (typeof value !== 'string' || value.trim() === '') fail(`${label} is required`); return value; }
function requiredSlug(value, label) { const id = requiredString(value, label); if (!/^[a-z0-9][a-z0-9-]*$/.test(id)) fail(`${label} must be a lowercase slug`); return id; }
function sha256(file) { return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex'); }
function camel(value) { return value.replace(/_([a-z])/g, (_, letter) => letter.toUpperCase()); }
function fail(message) { throw new Error(`SSI acceptance provider: ${message}`); }
function parseArgs(args) { const options = {}; for (let i = 0; i < args.length; i += 1) { const [key, inline] = args[i].replace(/^--/, '').split(/=(.*)/s, 2); if (!key || args[i][0] !== '-') fail(`unknown argument: ${args[i]}`); const value = inline === undefined ? args[++i] : inline; if (value === undefined) fail(`missing value for --${key}`); options[key.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())] = value; } return options; }
