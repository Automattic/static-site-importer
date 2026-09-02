// Static Site Importer fixture matrix — thin composer.
//
// The accreted concerns (fixture discovery/taxonomy, recipe building, the
// editor-validation #537 / visual-parity #538 / editor-quality #541 collectors,
// finding classification + honest loss-acceptance #535, and the summary/aggregate
// rollups) now live as composable modules under `./fixture-matrix/`. This file is
// a behavior-preserving facade that re-exports the same public surface from the
// same path, so `tools/`, `bench/`, and tests import it unchanged.
//
// Modularized as workstream 3 of the maintainability/parallel-safe-swarm epic
// (Refs #242).

export {
  FIXTURE_MATRIX_SCHEMA,
  FIXTURE_MATRIX_RESULT_SCHEMA,
  WEBSITE_ARTIFACT_SCHEMA,
  EDITOR_BLOCK_INVALID_KIND,
  EDITOR_INVALID_BLOCK_SELECTOR_GROUP,
  EDITOR_INVALID_BLOCK_SELECTORS,
  EDITOR_OPEN_COMMAND,
  EDITOR_VALIDATE_BLOCKS_COMMAND,
  EDITOR_VALIDATE_BLOCKS_SCHEMA,
  EDITOR_VALIDATION_METHOD,
  EDITOR_VALIDATION_PROVIDER,
  DEFAULT_EDITOR_VALIDATION_POST_TYPE,
  VISUAL_PARITY_MISMATCH_KIND,
  VISUAL_PARITY_DETERMINISTIC_CSS,
  LOW_NATIVE_CONVERSION_KIND,
} from './fixture-matrix/shared/constants.mjs';

export {
  discoverFixtures,
  inspectFixtureDirectories,
  buildFixtureCoverage,
  isExecutableFixtureDirectory,
  resolveFixtureSearchRoots,
  createFixtureMatrix,
  classifyFixture,
  fixtureManifestCoverage,
  collectFixtureArtifactFiles,
  pathinfoExtension,
} from './fixture-matrix/fixtures.mjs';

export {
  buildFixtureArtifact,
  buildFixturePolicyArtifact,
  buildFixtureMatrixRecipe,
  normalizeFixtureMatrixDependencyOverlays,
  stageFixtureSource,
  wordpressServedPath,
  normalizeStaticSiteImporterPlugin,
  hostStageDependencyPlan,
} from './fixture-matrix/steps/recipe-builder.mjs';

export {
  DEFAULT_EXTRA_SURFACE_COUNT,
  MAX_EXTRA_SURFACE_COUNT,
  normalizeSurfaceCoverageOptions,
  selectFixtureSurfaces,
  summarizeSurfaceCoverage,
} from './fixture-matrix/steps/surfaces.mjs';

export {
  FIXTURE_MATRIX_RUN_FIELDS,
  normalizeFixtureMatrixRunConfig,
  fixtureMatrixRunConfigFromEnv,
  fixtureMatrixHomeboySettings,
  fixtureMatrixBenchOptions,
  fixtureMatrixRecipeInput,
  fixtureMatrixGateConfig,
} from './fixture-matrix/run-config.mjs';

export {
  SURFACE_LINEAGE_SCHEMA,
  buildFixtureSurfaceLineage,
  buildSurfaceLineageArtifact,
} from './fixture-matrix/surface-lineage.mjs';

export {
  ACCEPTANCE_HANDOFF_SCHEMA,
  NOT_PROVEN_REASONS,
  produceAcceptanceHandoff,
  validateAcceptanceHandoff,
  consumeAcceptanceHandoff,
  emitFixtureMatrixAcceptanceHandoffs,
} from './fixture-matrix/acceptance-handoff.mjs';

export {
  OWNER_HANDOFF_EVIDENCE_SCHEMA,
  OWNER_TASK_SCHEMA,
  DIMENSION_IDS,
  OWNER_TASK_IDS,
  produceOwnerHandoffEvidence,
  validateOwnerHandoffEvidence,
  consumeOwnerHandoffEvidence,
  renderOwnerHandoffReportCard,
  emitFixtureMatrixOwnerHandoffs,
} from './fixture-matrix/owner-handoff-evidence.mjs';

export { editorBlockValidationStep } from './fixture-matrix/steps/editor-validation-step.mjs';

export {
  visualParityCompareStep,
  normalizeAnimatedMediaPolicy,
  normalizeVisualAttributionOptions,
} from './fixture-matrix/steps/visual-parity-step.mjs';

export {
  liveWpParityCaptureStep,
  liveWpParityEnabled,
  normalizeLiveWpParityRecipeOptions,
} from './fixture-matrix/steps/live-wp-parity-step.mjs';

export {
  normalizeFixtureMatrixResult,
  writeFixtureMatrixArtifacts,
  writeFixtureMatrixResultArtifacts,
} from './fixture-matrix/result.mjs';

export {
  GUTENBERG_INCOMPATIBILITY_REGISTRY_SCHEMA,
  DEFAULT_CUSTOM_BLOCK_CANDIDATE_FIXTURE_THRESHOLD,
  buildGutenbergIncompatibilityRegistry,
  renderGutenbergIncompatibilityRegistryMarkdown,
} from './fixture-matrix/gutenberg-incompatibility-registry.mjs';

export {
  VISUAL_PARITY_EVIDENCE_REPORT_SCHEMA,
  buildVisualParityEvidenceReport,
  renderVisualParityEvidenceReportMarkdown,
} from './fixture-matrix/visual-evidence-report.mjs';

export { collectEditorInteraction, collectEditorPresentation, collectFixtureMatrixRunResults, collectMatrixEvidence } from './fixture-matrix/collectors/run-intake.mjs';

export { RUNTIME_PRESENTATION_EVIDENCE_SCHEMA, RUNTIME_PRESENTATION_EVIDENCE_UNAVAILABLE, runtimePresentationEvidenceArtifactPath, runtimePresentationEvidenceEnabled, runtimePresentationEvidenceProbeStep, runtimePresentationEvidenceMergeStep, collectRuntimePresentationEvidence } from './fixture-matrix/runtime-presentation-evidence.mjs';

export { classifyStaticSiteFinding, normalizeLossClass } from './fixture-matrix/findings.mjs';

export {
  collectBlockComposition,
  collectBlockCompositionFromBlockDocuments,
  collectBlockCompositionFromSerializedBlocks,
  computeFixtureEditorQuality,
  parseSerializedBlockNames,
} from './fixture-matrix/collectors/quality-metrics.mjs';

export {
  collectEditorValidationDiagnostics,
  collectEditorValidation,
  isEditorValidateBlocksPayload,
} from './fixture-matrix/collectors/editor-validation.mjs';

export {
  collectVisualParityDiagnostics,
  classifyVisualDiffRegions,
  findBestVisualParityOffset,
  normalizeVisualParityGateOptions,
} from './fixture-matrix/collectors/visual-parity.mjs';

export {
  runLiveWpParity,
  normalizeLiveWpParityReport,
  collectLiveWpParity,
  normalizeLiveWpParityCollectorOptions,
} from './fixture-matrix/collectors/live-wp-parity.mjs';
