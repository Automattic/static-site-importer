import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { presentationEvidencePassed } from './editor-presentation.mjs';

export const ACCEPTANCE_SCHEMA = 'static-site-importer/existing-runtime-acceptance/v1';
export const hash = value => createHash('sha256').update(value).digest('hex');
export const cellKey = cell => JSON.stringify([cell.route, cell.width, cell.state]);
export function acceptanceScope(manifest) {
  const routes = manifest.routes;
  const widths = manifest.widths ?? [390, 768, 1440];
  const states = manifest.states ?? ['baseline'];
  if (!Array.isArray(routes) || !routes.length || routes.some(route => typeof route !== 'string' || !route.startsWith('/') || /[\\?#\x00-\x1f]/.test(route) || route.startsWith('//'))) throw new Error('Manifest requires absolute route paths.');
  if (!Array.isArray(widths) || !widths.length || widths.some(width => !Number.isInteger(width) || width < 1) || !Array.isArray(states) || !states.length || states.some(state => typeof state !== 'string' || !state.trim())) throw new Error('Invalid width/state scope.');
  for (const values of [routes, widths, states]) if (new Set(values).size !== values.length) throw new Error('Duplicate acceptance scope.');
  return { routes, widths, states, cells: routes.flatMap(route => widths.flatMap(width => states.map(state => ({ route, width, state })))) };
}

// Same path/sha256 artifact contract used by acceptance and owner handoffs.
export function verifyReference(root, reference) {
  try {
    const file = fs.realpathSync(path.resolve(root, reference.path));
    const base = fs.realpathSync(root);
    return file.startsWith(`${base}${path.sep}`) && /^[a-f0-9]{64}$/.test(reference.sha256) && hash(fs.readFileSync(file)) === reference.sha256;
  } catch { return false; }
}
export function fileReference(root, file) {
  return { path: path.relative(root, file).split(path.sep).join('/'), sha256: hash(fs.readFileSync(file)) };
}
export function writeEvidence(root, name, value) {
  const file = path.join(root, name);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`);
  return fileReference(root, file);
}

/** Adapt the public DLA FidelityReport, binding it to this exact invocation.
 * DLA owns scoring; SSI only proves requested cell coverage and retains bytes.
 */
export function bindFidelityReport(stage, scope, report, referenceSha256, candidateUrl, artifacts) {
  if (report?.stage !== stage || !Array.isArray(report.scores)
      || !Number.isInteger(report.coverage?.required) || !Number.isInteger(report.coverage?.measured)) {
    throw new Error('Incompatible DLA FidelityReport');
  }
  const measured = report.scores.map(score => ({
    route: score.route, width: score.viewport, state: score.state,
    status: typeof score.pass !== 'boolean' ? 'unproven' : score.pass ? 'proven' : 'failed',
    pass: score.pass, scores: score,
  }));
  const complete = report.coverage.required === scope.cells.length
    && report.coverage.measured === measured.length
    && measured.length === scope.cells.length
    && scope.cells.every(cell => measured.filter(row => cellKey(row) === cellKey(cell)).length === 1);
  return {
    ...report,
    reference_sha256: referenceSha256,
    ...(stage === 'materialization' ? { candidate_url: candidateUrl } : {}),
    coverage: { required: complete ? scope.cells : [], measured, unknowns: report.coverage.unknowns ?? [] },
    artifacts,
    public_coverage: report.coverage,
  };
}

/** Consume measured DLA cells; never calculate browser scores here. */
export function fidelityGate(stage, scope, report, artifactsValid) {
  const pending = [];
  const failed = [];
  const identityGap = report?.errors?.some(error => ['source_hash_mismatch', 'reference_hash_mismatch', 'hash_mismatch'].includes(typeof error === 'string' ? error : error?.code ?? error?.reason));
  if (identityGap) { artifactsValid = false; pending.push({ stage, reason: 'source_or_reference_identity_unproven' }); }
  const measured = Array.isArray(report?.coverage?.measured) ? report.coverage.measured : [];
  const required = Array.isArray(report?.coverage?.required) ? report.coverage.required : [];
  if (required.length !== scope.cells.length || scope.cells.some(cell => required.filter(row => cellKey(row) === cellKey(cell)).length !== 1)) pending.push({ stage, reason: 'fidelity_scope_not_bound' });
  for (const cell of scope.cells) {
    const matches = measured.filter(row => cellKey(row) === cellKey(cell));
    const row = matches[0];
    if (report?.stage !== stage || matches.length !== 1 || !artifactsValid || !['proven', 'failed'].includes(row?.status) || typeof row?.pass !== 'boolean' || (row.status === 'proven' && row.pass && (!row.scores || typeof row.scores !== 'object' || Object.keys(row.scores).length === 0))) pending.push({ stage, ...cell, reason: 'missing_stale_or_ambiguous_fidelity_evidence' });
    else if (row.status === 'failed' || row.pass === false) failed.push({ stage, ...cell, reason: 'fidelity_failed', scores: row.scores });
  }
  // A report-level failure/error retains its owner even with incomplete cells.
  if (artifactsValid && report?.stage === stage && (report.status === 'failed' || report.errors?.length)) failed.push({ stage, reason: 'fidelity_report_failed', errors: report.errors ?? [] });
  if (report?.pending?.length) pending.push(...report.pending);
  if (report?.status !== 'proven' || report?.pass !== true) pending.push({ stage, reason: 'fidelity_not_proven' });
  return { stage, status: failed.length ? 'failed' : pending.length ? 'pending' : 'passed', pending, failed, coverage: { required: scope.cells, measured } };
}

export function aggregateAcceptance({ identity, scope, gates, importReport, importReportVerified, prerequisites = [] }) {
  const count = importReport?.quality?.fallback_count;
  const measured = importReportVerified && importReport?.schema === 'static-site-importer/import-report/v1' && Number.isInteger(count) && count >= 0;
  const conversion = { status: !measured ? 'pending' : count === 0 ? 'passed' : 'failed', fallback_count: measured ? count : null, reason: !measured ? 'measured_import_report_required' : null };
  const all = [...['capture', 'materialization', 'editor', 'health'].map(stage => gates[stage] ?? { status: 'pending', reason: `${stage}_evidence_required` }), conversion, ...prerequisites];
  return { schema: ACCEPTANCE_SCHEMA, identity, scope, gates, conversion_quality: conversion, prerequisites, status: all.some(row => row.status === 'failed') ? 'failed' : all.every(row => row.status === 'passed') ? 'accepted' : 'pending', pending: all.flatMap(row => row.pending ?? (row.status === 'pending' ? [{ reason: row.reason ?? 'evidence_unavailable' }] : [])) };
}

export function editorGate(scope, rows) {
  const complete = scope.routes.every(route => {
    const matches = rows.filter(row => row.route === route);
    const row = matches[0];
    return matches.length === 1 && row.status === 'passed' && draftEditEvidencePassed(row) && scope.widths.every(width => {
      const presentations = row.presentations?.filter(value => value.viewport?.width === width) ?? [];
      return presentations.length === 1 && presentationEvidencePassed(presentations[0]);
    });
  });
  return { stage: 'editor', status: rows.some(row => row.status === 'failed' || row.cleanup?.status === 'failed') ? 'failed' : complete && scope.states.every(state => state === 'baseline') ? 'passed' : 'pending', routes: rows };
}

export function draftEditEvidencePassed(row) {
  const isolation = row?.isolation;
  return row?.cleanup?.status === 'passed' && row.edit?.status === 'passed' && row.persisted?.text === true && row.persisted?.image === true && row.frontend?.text === true && row.frontend?.image === true && [row.original_validation, row.reloaded_validation].every(validation => validation?.total_blocks > 0 && validation.invalid_blocks === 0 && /^[a-f0-9]{64}$/.test(validation.content_sha256)) && row.reloaded_validation.marker_present === true && row.original_validation.content_sha256 !== row.reloaded_validation.content_sha256 && /^[a-f0-9]{64}$/.test(isolation?.original_before_sha256) && isolation.original_after_sha256 === isolation.original_before_sha256 && isolation.media?.length > 0 && isolation.media.every(media => Number.isInteger(media.attachment_id) && /^[a-f0-9]{64}$/.test(media.before_sha256) && media.before_sha256 === media.after_sha256);
}

/** Portable consumption verifies all retained bytes and re-evaluates requirements. */
export function consumeExistingRuntimeAcceptance(root, report) {
  if (report?.schema !== ACCEPTANCE_SCHEMA || !report.artifacts || !Object.values(report.artifacts).every(ref => verifyReference(root, ref))) return { status: 'pending', reason: 'aggregate_artifact_missing_or_hash_mismatch' };
  try {
    const read = name => report.artifacts[name] ? JSON.parse(fs.readFileSync(path.resolve(root, report.artifacts[name].path), 'utf8')) : undefined;
    const scope = acceptanceScope(read('manifest'));
    const gates = { ...report.gates, editor: editorGate(scope, read('editor')?.routes ?? []), health: read('health') ?? { status: 'pending' } };
    for (const stage of ['capture', 'materialization']) {
      const evidence = read(stage);
      const retained = Object.values(report.artifacts);
      gates[stage] = fidelityGate(stage, scope, evidence, report.gates[stage]?.evidence_valid === true && evidence?.reference_sha256 === report.identity.capture?.sha256 && (stage === 'capture' || evidence?.candidate_url === report.identity.candidate_origin) && Array.isArray(evidence?.artifacts) && evidence.artifacts.length > 0 && evidence.artifacts.every(ref => retained.some(copy => copy.sha256 === ref.sha256)));
    }
    return aggregateAcceptance({ identity: report.identity, scope, gates, importReport: read('import_report'), importReportVerified: Boolean(report.artifacts.import_report), prerequisites: report.prerequisites });
  } catch { return { status: 'pending', reason: 'aggregate_evidence_unavailable' }; }
}
