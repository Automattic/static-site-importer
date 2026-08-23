import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

export const ACCEPTANCE_HANDOFF_SCHEMA = 'static-site-importer/acceptance-handoff/v1';
export const NOT_PROVEN_REASONS = Object.freeze([
  'input_identity_missing',
  'compiler_identity_missing',
  'site_plan_missing',
  'site_plan_invalid',
  'materialization_receipt_missing',
  'materialization_failed',
  'route_entity_mapping_missing',
  'visual_evidence_missing',
  'editor_evidence_missing',
  'quality_projection_missing',
  'artifact_missing',
  'hash_mismatch',
]);

export function produceAcceptanceHandoff(input = {}) {
  const directory = path.resolve(requiredString(input.outputDirectory || input.output_directory, 'outputDirectory'));
  const fixtureId = requiredString(input.fixtureId || input.fixture_id, 'fixtureId');
  fs.mkdirSync(directory, { recursive: true });
  const reasons = [];
  const artifact = (name, value, required = true) => {
    if (!value) {
      if (required) reasons.push(`${name}_missing`);
      return null;
    }
    const target = path.join(directory, 'artifacts', `${slug(name)}.json`);
    if (typeof value === 'string') {
      const source = path.resolve(value);
      if (!isFile(source)) {
        reasons.push('artifact_missing');
        return null;
      }
      fs.mkdirSync(path.dirname(target), { recursive: true });
      fs.copyFileSync(source, target);
    } else {
      writeJson(target, value);
    }
    return reference(directory, target);
  };
  const inputIdentity = artifact('input_identity', input.inputIdentity || input.input_identity);
  const compilerIdentity = artifact('compiler_identity', input.compilerIdentity || input.compiler_identity);
  const planValue = readValue(input.sitePlan || input.site_plan);
  const sitePlan = artifact('site_plan', input.sitePlan || input.site_plan);
  if (sitePlan && planValue?.schema !== 'blocks-engine/wordpress-site-plan/v2') reasons.push('site_plan_invalid');
  const receiptValue = readValue(input.materializationReceipt || input.materialization_receipt);
  const materializationReceipt = artifact('materialization_receipt', input.materializationReceipt || input.materialization_receipt);
  if (materializationReceipt && receiptValue?.status === 'failed') reasons.push('materialization_failed');
  if (materializationReceipt && receiptValue?.status !== 'completed' && receiptValue?.status !== 'failed') reasons.push('materialization_receipt_missing');
  const routeEntityMapping = artifact('route_entity_mapping', input.routeEntityMapping || input.route_entity_mapping);
  const visualEvidence = visualEvidenceReferences(directory, input.visualEvidence || input.visual_evidence, reasons);
  const editorEvidence = editorEvidenceReferences(directory, input.editorEvidence || input.editor_evidence, reasons);
  const qualityValue = readValue(input.qualityProjection || input.quality_projection);
  const qualityProjection = artifact('quality_projection', input.qualityProjection || input.quality_projection);
  const uniqueReasons = [...new Set(reasons)];
  const disposition = uniqueReasons.includes('materialization_failed') || qualityValue?.production_status === 'failed' || qualityValue?.disposition === 'failed'
    ? 'failed'
    : uniqueReasons.length === 0 && ['passed', 'production'].includes(qualityValue?.production_status || qualityValue?.disposition)
      ? 'passed'
      : 'not_proven';
  const document = {
    schema: ACCEPTANCE_HANDOFF_SCHEMA,
    fixture_id: fixtureId,
    input_identity: inputIdentity,
    compiler_identity: compilerIdentity,
    wordpress_site_plan: sitePlan,
    terminal_materialization_receipt: materializationReceipt,
    route_entity_mapping: routeEntityMapping,
    visual_evidence: visualEvidence,
    editor_evidence: editorEvidence,
    quality_projection: qualityProjection,
    disposition,
    ...(uniqueReasons.length > 0 ? { not_proven: uniqueReasons.map((code) => ({ code })) } : {}),
  };
  writeJson(path.join(directory, 'acceptance-handoff.json'), document);
  return document;
}

export function validateAcceptanceHandoff(root, document) {
  const reasons = [];
  if (!document || document.schema !== ACCEPTANCE_HANDOFF_SCHEMA) return { valid: false, disposition: 'not_proven', reasons: [{ code: 'artifact_missing' }] };
  const refs = [document.input_identity, document.compiler_identity, document.wordpress_site_plan, document.terminal_materialization_receipt, document.route_entity_mapping, document.quality_projection, ...array(document.visual_evidence).flatMap((entry) => [entry?.source, entry?.candidate, entry?.diff]), ...array(document.editor_evidence).map((entry) => entry?.evidence)].filter(Boolean);
  for (const ref of refs) {
    if (!validReference(ref)) {
      reasons.push('artifact_missing');
      continue;
    }
    const file = path.resolve(root, ref.path);
    if (!file.startsWith(`${path.resolve(root)}${path.sep}`) || !isFile(file)) reasons.push('artifact_missing');
    else if (hash(file) !== ref.sha256) reasons.push('hash_mismatch');
  }
  const plan = readReference(root, document.wordpress_site_plan);
  const receipt = readReference(root, document.terminal_materialization_receipt);
  const mapping = readReference(root, document.route_entity_mapping);
  const quality = readReference(root, document.quality_projection);
  if (!document.input_identity) reasons.push('input_identity_missing');
  if (!document.compiler_identity) reasons.push('compiler_identity_missing');
  if (!plan) reasons.push('site_plan_missing');
  else if (plan.schema !== 'blocks-engine/wordpress-site-plan/v2') reasons.push('site_plan_invalid');
  if (!receipt) reasons.push('materialization_receipt_missing');
  else if (receipt.status === 'failed') reasons.push('materialization_failed');
  else if (receipt.status !== 'completed') reasons.push('materialization_receipt_missing');
  const mapped = new Set(array(mapping).map((entry) => `${entry?.route}\u0000${entry?.entity}`));
  if (mapped.size === 0 || [...array(document.visual_evidence), ...array(document.editor_evidence)].some((entry) => !mapped.has(`${entry?.route}\u0000${entry?.entity}`))) reasons.push('route_entity_mapping_missing');
  if (array(document.visual_evidence).length === 0) reasons.push('visual_evidence_missing');
  if (array(document.editor_evidence).length === 0) reasons.push('editor_evidence_missing');
  if (!quality) reasons.push('quality_projection_missing');
  const declared = array(document.not_proven).map((entry) => entry?.code).filter((code) => NOT_PROVEN_REASONS.includes(code));
  const allReasons = [...new Set([...declared, ...reasons])];
  const valid = reasons.length === 0;
  const disposition = !valid || allReasons.length > 0 ? (receipt?.status === 'failed' || quality?.production_status === 'failed' || document.disposition === 'failed' ? 'failed' : 'not_proven') : document.disposition;
  return { valid, disposition, reasons: allReasons.map((code) => ({ code })) };
}

export function consumeAcceptanceHandoff(root, document) {
  const validation = validateAcceptanceHandoff(root, document);
  return { schema: ACCEPTANCE_HANDOFF_SCHEMA, disposition: validation.valid && validation.disposition === 'passed' ? 'passed' : validation.disposition, reasons: validation.reasons };
}

// The fixture matrix is a generic workflow boundary. It only projects facts it
// already owns, and leaves unavailable evidence as typed not-proven reasons.
export function emitFixtureMatrixAcceptanceHandoffs({ outputDirectory, result }) {
  const root = path.resolve(outputDirectory);
  const handoffs = [];
  for (const fixture of array(result?.fixtures)) {
    const evidence = object(fixture.matrix_evidence);
    const receipt = object(evidence.materialization_receipt);
    const compiler = object(evidence.transformer);
    const mapping = array(fixture.surface_lineage).map((surface) => ({ route: surface?.surface?.source_entry, entity: surface?.materialized_document?.post_id, surface_id: surface?.surface?.id })).filter((entry) => entry.route && entry.entity);
    const visual = array(fixture.surface_lineage).flatMap((surface) => {
      const refs = Object.fromEntries(array(surface?.visual_data?.refs).map((ref) => [ref.artifact_id, resolveArtifact(root, ref.path || ref.file)]));
      return [{ source_file: refs.source_screenshot, candidate_file: refs.imported_screenshot, diff_file: refs.diff_screenshot || refs.visual_diff, route: surface?.surface?.source_entry, viewport: surface?.capture?.viewport, entity: surface?.materialized_document?.post_id }];
    });
    const editor = fixture.editor_validation && mapping.length > 0 ? mapping.map((entry) => ({ route: entry.route, entity: entry.entity, validation: fixture.editor_validation })) : [];
    const handoffRoot = path.join(root, fixture.fixture_id, 'acceptance-handoff');
    const document = produceAcceptanceHandoff({
      outputDirectory: handoffRoot,
      fixtureId: fixture.fixture_id,
      inputIdentity: path.join(root, fixture.fixture_id, 'artifact.json'),
      compilerIdentity: Object.keys(compiler).length > 0 ? compiler : null,
      sitePlan: fixture.import_report?.blocks_engine?.wordpress_site_plan,
      materializationReceipt: Object.keys(receipt).length > 0 ? receipt : null,
      routeEntityMapping: mapping.length > 0 ? mapping : null,
      visualEvidence: visual,
      editorEvidence: editor,
      qualityProjection: receipt.quality_budget_admission || fixture.import_report?.quality_budget_admission,
    });
    handoffs.push({ fixture_id: fixture.fixture_id, path: path.relative(root, path.join(handoffRoot, 'acceptance-handoff.json')).split(path.sep).join('/'), disposition: document.disposition });
  }
  return { schema: ACCEPTANCE_HANDOFF_SCHEMA, handoffs };
}

function visualEvidenceReferences(root, items, reasons) {
  const records = [];
  for (const [index, item] of array(items).entries()) {
    const row = object(item);
    if (!row.source_file || !row.candidate_file || !row.diff_file || !row.route || !row.entity || !row.viewport || !isFile(path.resolve(row.source_file)) || !isFile(path.resolve(row.candidate_file)) || !isFile(path.resolve(row.diff_file))) continue;
    records.push({ route: row.route, entity: row.entity, viewport: row.viewport, source: copyReference(root, row.source_file, `evidence/visual-source-${index}${path.extname(row.source_file) || '.bin'}`), candidate: copyReference(root, row.candidate_file, `evidence/visual-candidate-${index}${path.extname(row.candidate_file) || '.bin'}`), diff: copyReference(root, row.diff_file, `evidence/visual-diff-${index}${path.extname(row.diff_file) || '.bin'}`) });
  }
  if (records.length === 0) reasons.push('visual_evidence_missing');
  return records;
}

function editorEvidenceReferences(root, items, reasons) {
  const records = [];
  for (const [index, item] of array(items).entries()) {
    const row = object(item);
    if (!row.route || !row.entity || !row.validation) continue;
    records.push({ route: row.route, entity: row.entity, evidence: artifactReference(root, `evidence/editor-${index}.json`, row.validation) });
  }
  if (records.length === 0) reasons.push('editor_evidence_missing');
  return records;
}

function artifactReference(root, name, value) { const target = path.join(root, 'artifacts', name); writeJson(target, value); return reference(root, target); }
function copyReference(root, source, name) { const target = path.join(root, 'artifacts', name); fs.mkdirSync(path.dirname(target), { recursive: true }); fs.copyFileSync(path.resolve(source), target); return reference(root, target); }
function reference(root, file) { return { path: path.relative(root, file).split(path.sep).join('/'), sha256: hash(file) }; }
function readReference(root, ref) { if (!validReference(ref)) return null; const file = path.resolve(root, ref.path); return isFile(file) && hash(file) === ref.sha256 ? readJson(file) : null; }
function readValue(value) { if (!value) return null; return typeof value === 'string' ? (isFile(path.resolve(value)) ? readJson(path.resolve(value)) : null) : object(value); }
function resolveArtifact(root, value) { return value ? (path.isAbsolute(value) ? value : path.join(root, value)) : ''; }
function validReference(ref) { return Boolean(ref && typeof ref.path === 'string' && !path.isAbsolute(ref.path) && !ref.path.split('/').includes('..') && /^[a-f0-9]{64}$/.test(ref.sha256 || '')); }
function object(value) { return value && typeof value === 'object' && !Array.isArray(value) ? value : {}; }
function array(value) { return Array.isArray(value) ? value : []; }
function hash(file) { return createHash('sha256').update(fs.readFileSync(file)).digest('hex'); }
function isFile(file) { try { return fs.statSync(file).isFile(); } catch { return false; } }
function readJson(file) { try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch { return null; } }
function writeJson(file, value) { fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`); }
function requiredString(value, label) { if (typeof value !== 'string' || !value.trim()) throw new Error(`SSI acceptance handoff: ${label} is required`); return value; }
function slug(value) { return value.replace(/[^a-z0-9]+/gi, '-'); }
