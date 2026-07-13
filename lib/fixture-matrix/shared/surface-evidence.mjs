// Shared parsing helpers for fixture-matrix surface evidence payloads.

import { compactObject, normalizeArray, objectValue } from './utils.mjs';

export function surfaceFields(payload, options = {}) {
  const metadata = objectValue(payload.metadata || payload.recipeStepMetadata || payload.recipe_step_metadata);
  const includeGenericAliases = options.includeGenericAliases === true;
  return compactObject({
    surface_id: payload.surface_id || payload.surfaceId || (includeGenericAliases ? payload.id : undefined) || metadata.surface_id || metadata.surfaceId,
    surface_label: payload.surface_label || payload.surfaceLabel || (includeGenericAliases ? payload.label : undefined) || metadata.surface_label || metadata.surfaceLabel,
    source_entry: payload.source_entry || payload.sourceEntry || metadata.source_entry || metadata.sourceEntry,
    target: payload.target || metadata.target,
    url: payload.url || payload.candidate_url || payload.candidateUrl || metadata.url || metadata.candidate_url || metadata.candidateUrl,
  });
}

export function surfaceArtifactPaths(surface) {
  return normalizeArray(surface.artifact_refs || surface.artifactRefs)
    .map((ref) => objectValue(ref).path || objectValue(ref).file || objectValue(ref).href || '')
    .filter(Boolean);
}

export function hasVisualSurfaceEvidence(surface) {
  const artifacts = objectValue(surface.visual_parity_artifacts || surface.visualParityArtifacts);
  return Object.keys(objectValue(artifacts.artifacts)).length > 0
    || Object.keys(objectValue(artifacts.metrics || artifacts.comparison)).length > 0
    || surfaceArtifactPaths(surface).some((artifactPath) => /visual|screenshot|diff|\.png$/i.test(artifactPath));
}

export function hasEditorSurfaceEvidence(surface) {
  return Boolean(surface.editor_validation || surface.editorValidation || surface.editor_canvas || surface.editorCanvas || surface.editor_open || surface.editorOpen)
    || surfaceArtifactPaths(surface).some((artifactPath) => /editor/i.test(artifactPath));
}
