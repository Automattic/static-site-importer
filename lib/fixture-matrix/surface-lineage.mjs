/**
 * External dependencies
 */
import { createHash } from 'node:crypto';

/**
 * Internal dependencies
 */
import { selectFixtureSurfaces } from './steps/surfaces.mjs';
import { normalizeArray, objectValue, firstString, compactObject, artifactRef } from './shared/utils.mjs';

export const SURFACE_LINEAGE_SCHEMA = 'static-site-importer/fixture-surface-lineage/v1';

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
    imported_post: compactObject({
      id: firstString([runtime.post_id]),
      type: firstString([runtime.post_type, surface.post_type]),
      slug: firstString([runtime.post_slug, surface.post_slug]),
      editor_target: firstString([runtime.target, surface.target]),
    }),
    artifacts: refs,
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
  return artifactRef(`surface-lineage--${slug}`, `${outputDirectory}/${fixtureId}/surface-lineage--${slug}.json`, 'surface-lineage');
}

// Keep logical surface IDs lossless in the JSON while preventing a runner-provided
// route name from affecting artifact paths. The hash disambiguates equivalent slugs.
export function surfaceArtifactSlug(surfaceId) {
  const logicalId = String(surfaceId || 'surface');
  const stem = logicalId.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 48) || 'surface';
  return `${stem}-${createHash('sha256').update(logicalId).digest('hex').slice(0, 12)}`;
}
