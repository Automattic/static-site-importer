/**
 * External dependencies
 */
import { createHash } from 'node:crypto';

/**
 * Internal dependencies
 */
import { selectFixtureSurfaces } from './steps/surfaces.mjs';
import { normalizeArray, objectValue, firstString, compactObject, artifactRef } from './shared/utils.mjs';

export const SURFACE_LINEAGE_SCHEMA = 'static-site-importer/fixture-surface-lineage/v2';
const VISUAL_REF_LIMIT = 25;

// A surface bundle is deliberately a small, transportable index. The referenced
// artifacts stay first-class files rather than being embedded in the receipt.
export function buildFixtureSurfaceLineage(fixture, result = {}, options = {}) {
  const selected = selectFixtureSurfaces(fixture, options);
  const payloads = normalizeArray(result.surface_records);
  const observedIds = [
    ...normalizeArray(result.visual_parity_comparisons).map((comparison) => comparison.surface_id),
    ...payloads.map((payload) => payload.surface_id),
  ].filter(Boolean);
  const surfaceById = new Map(selected.map((surface) => [surface.id, surface]));
  for (const id of observedIds) {
    if (!surfaceById.has(id)) surfaceById.set(id, { id, source_entry: '', candidate_url: '' });
  }
  const surfaces = [...surfaceById.values()];
  return surfaces.map((surface) => buildSurfaceLineage({ fixture, result, surface, payloads }));
}

export function buildSurfaceLineageArtifact(result = {}) {
  return {
    schema: SURFACE_LINEAGE_SCHEMA,
    matrix_id: result.matrix_id || '',
    fixtures: normalizeArray(result.fixtures).map((fixture) => ({
      fixture_id: fixture.fixture_id,
      surfaces: normalizeArray(fixture.surface_lineage),
    })),
  };
}

function buildSurfaceLineage({ fixture, result, surface, payloads }) {
  const scoped = payloads.filter((payload) => surfaceId(payload) === surface.id);
  const comparison = normalizeArray(result.visual_parity_comparisons).find((row) => row.surface_id === surface.id) || {};
  const artifacts = objectValue(comparison.visual_parity_artifacts);
  const visual = objectValue(preferredSurfaceRecord(scoped, 'visual'));
  const editor = objectValue(preferredSurfaceRecord(scoped, 'editor'));
  const runtime = Object.keys(editor).length > 0 ? editor : Object.keys(visual).length > 0 ? visual : objectValue(scoped.at(-1));
  const sourceUrl = firstString([comparison.source_url, visual.source_url, runtime.source_url, sourceUrlFor(fixture, surface)]);
  const candidateUrl = firstString([comparison.candidate_url, visual.candidate_url, runtime.candidate_url, surface.candidate_url]);
  const refs = refsForSurface({ result, scoped, artifacts });
  const matrixEvidence = objectValue(result.matrix_evidence);
  const receipt = objectValue(matrixEvidence.materialization_receipt);
  const sidecar = objectValue(matrixEvidence.materialization_sidecar);
  const editorRecord = preferredSurfaceRecord(scoped, 'editor');
  const visualRecord = preferredSurfaceRecord(scoped, 'visual');
  const document = materializedDocument({ sidecar, receipt, runtime, surface });
  const selectorEvidence = objectValue(artifacts.visual_explanation || artifacts.visualExplanation);
  const domCaptured = refs.some((ref) => /(?:source|candidate)[-_]dom/i.test(`${ref.artifact_id} ${ref.kind}`));
  const selectorCaptured = Boolean(selectorEvidence.selector || selectorEvidence.selectors || selectorEvidence.regions || selectorEvidence.elements);
  return {
    schema: SURFACE_LINEAGE_SCHEMA,
    surface_id: `${fixture.id}:${surface.id}`,
    fixture_id: fixture.id,
    surface: {
      id: surface.id,
      artifact_slug: surfaceArtifactSlug(surface.id),
      source_entry: surface.source_entry,
      source_url: sourceUrl,
      candidate_url: candidateUrl,
    },
    fixture: compactObject({
      corpus: fixture.fixture_corpus || result.fixture_corpus,
      path: fixture.fixture_path || result.fixture_path,
      entrypoint: fixture.entrypoint,
    }),
    lineage: {
      matrix_run_id: firstString([sidecar.matrix_run_id]),
      attempt_id: firstString([sidecar.attempt_id]),
      fixture_id: fixture.id,
      surface_id: surface.id,
      source_artifact_sha256: firstString([sidecar.source_artifact_sha256]),
      plan_hash: firstString([receipt.plan_hash]),
    },
    lanes: {
      transform: laneStatus(firstString([sidecar.source_artifact_sha256]) ? 'available' : 'unavailable'),
      materialization: laneStatus(materializationStatus(receipt, sidecar)),
      editor: laneStatus(recordStatus(editorRecord)),
      visual: laneStatus(visualStatus({ comparison, visualRecord, artifacts })),
    },
    imported_post: compactObject({
      id: firstString([runtime.post_id]),
      type: firstString([runtime.post_type, surface.post_type]),
      slug: firstString([runtime.post_slug, surface.post_slug]),
      editor_target: firstString([runtime.target, surface.target]),
    }),
    materialized_document: document,
    artifacts: refs.slice(0, VISUAL_REF_LIMIT),
    visual_data: {
      refs: refs.slice(0, VISUAL_REF_LIMIT),
      truncation: {
        retained_count: Math.min(refs.length, VISUAL_REF_LIMIT),
        truncated_count: Math.max(0, refs.length - VISUAL_REF_LIMIT),
      },
    },
    materialization: compactObject({
      receipt: objectValue(result.matrix_evidence).materialization_receipt,
      refs: normalizeArray(result.artifact_refs).filter((ref) => /materialization|receipt|sidecar/i.test(`${ref.artifact_id} ${ref.kind}`)),
    }),
    capture: compactObject({
      viewport: artifacts.metrics?.viewport || artifacts.viewport,
      full_page: artifacts.metrics?.full_page ?? artifacts.metrics?.fullPage,
      wait_for: artifacts.metrics?.wait_for || artifacts.metrics?.waitFor,
      duration_ms: artifacts.metrics?.duration_ms ?? artifacts.metrics?.durationMs,
      block_external_requests: artifacts.metrics?.block_external_requests ?? artifacts.metrics?.blockExternalRequests,
      threshold: artifacts.metrics?.threshold,
    }),
    replay_inputs: {
      matrix: { artifact_id: 'matrix', kind: 'matrix', path: 'matrix.json' },
      recipe: { artifact_id: 'fixture-matrix-recipe', kind: 'recipe', path: 'wp-codebox-static-site-fixture-matrix-recipe.json' },
      fixture: { artifact_id: `fixture-${fixture.id}`, kind: 'fixture', path: `${fixture.id}/artifact.json` },
      source: { artifact_id: `fixture-source-${fixture.id}-${surface.artifact_slug}`, kind: 'fixture-source', path: `${fixture.id}/source/${surface.source_entry}` },
    },
    blind_spots: [
      ...(!domCaptured ? [{ kind: 'dom_attribution_absent', message: 'No source/candidate DOM snapshot is registered for this surface.' }] : []),
      ...(!selectorCaptured ? [{ kind: 'css_selector_attribution_absent', message: 'No bounded CSS selector or region attribution is registered for this surface.' }] : []),
    ],
  };
}

function preferredSurfaceRecord(records, role) {
  return [...records].reverse().find((record) => record.role === role) || null;
}

function refsForSurface({ result, scoped, artifacts }) {
  const refs = [
    ...normalizeArray(result.artifact_refs).filter((ref) => isSurfaceVisualRef(ref, artifacts)),
    ...scoped.flatMap((payload) => normalizeArray(payload.artifact_refs || payload.artifactRefs)),
    ...Object.entries(objectValue(artifacts.artifacts)).map(([id, slot]) => ({ artifact_id: id, kind: slot?.kind || 'visual-parity', ...(objectValue(slot?.ref)) })),
  ].filter((ref) => ref && (ref.path || ref.file || ref.href));
  const seen = new Set();
  return refs.filter((ref) => {
    const key = `${ref.artifact_id || ''}\u0000${ref.path || ref.file || ref.href || ''}`;
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  }).sort((left, right) => `${left.artifact_id || ''}\u0000${left.path || left.file || left.href || ''}`.localeCompare(`${right.artifact_id || ''}\u0000${right.path || right.file || right.href || ''}`));
}

function laneStatus(status) {
  return { status };
}

function materializationStatus(receipt, sidecar) {
  if (sidecar.status === 'missing') return 'missing';
  if (sidecar.status && sidecar.status !== 'verified') return 'unavailable';
  if (receipt.status === 'completed') return 'available';
  if (receipt.status === 'failed') return 'failed';
  return 'unavailable';
}

function recordStatus(record) {
  const row = objectValue(record);
  if (Object.keys(row).length === 0) return 'unavailable';
  if (row.status === 'disabled') return 'disabled';
  if (row.status === 'failed' || row.success === false) return 'failed';
  return 'available';
}

function visualStatus({ comparison, visualRecord, artifacts }) {
  if (Object.keys(objectValue(artifacts)).length > 0) return 'available';
  if (Object.keys(objectValue(visualRecord)).length > 0) return recordStatus(visualRecord);
  if (Object.keys(objectValue(comparison)).length > 0) return 'missing';
  return 'unavailable';
}

function materializedDocument({ sidecar, receipt, runtime, surface }) {
  const postId = firstString([runtime.post_id]);
  const documents = normalizeArray(sidecar.documents);
  const document = documents.find((entry) => firstString([entry.post_id]) === postId) || {};
  const receiptStatus = materializationStatus(receipt, sidecar);
  if (receiptStatus !== 'available') return { status: receiptStatus };
  if (!postId) return { status: 'unavailable' };
  if (Object.keys(document).length === 0) {
    return sidecar.documents_truncated
      ? compactObject({ status: 'indeterminate', post_id: postId, truncated: true, documents_total: sidecar.documents_total })
      : { status: 'missing', post_id: postId };
  }
  return compactObject({
    status: 'available',
    post_id: firstString([document.post_id]),
    post_type: firstString([document.post_type, surface.post_type]),
    post_slug: firstString([document.post_slug, surface.post_slug]),
    serialized_content_sha256: firstString([document.serialized_content_sha256]),
  });
}

function isSurfaceVisualRef(ref, artifacts) {
  const slots = Object.values(objectValue(artifacts.artifacts));
  return slots.some((slot) => (slot?.ref?.path || slot?.ref?.file || slot?.ref?.href) === (ref.path || ref.file || ref.href));
}

function surfaceId(payload) {
  return firstString([payload?.surface_id, payload?.surfaceId]) || 'front-page';
}

function sourceUrlFor(fixture, surface) {
  return `source/${fixture.id}/${surface.source_entry}`;
}

export function surfaceLineageArtifactRef(outputDirectory, fixtureId, surfaceId) {
  const slug = surfaceArtifactSlug(surfaceId);
  // Surface IDs repeat across fixtures (most commonly `front-page`), so the
  // artifact identity must include the fixture to remain resolvable globally.
  return artifactRef(`surface_lineage_${surfaceArtifactSlug(fixtureId)}_${slug}`, `${outputDirectory}/${fixtureId}/surface-lineage--${slug}.json`, 'surface-lineage');
}

// Keep logical surface IDs lossless in the JSON while preventing a runner-provided
// route name from affecting artifact paths. The hash disambiguates equivalent slugs.
export function surfaceArtifactSlug(surfaceId) {
  const logicalId = String(surfaceId || 'surface');
  const stem = logicalId.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 48) || 'surface';
  return `${stem}-${createHash('sha256').update(logicalId).digest('hex').slice(0, 12)}`;
}
