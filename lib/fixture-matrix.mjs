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
  LOW_NATIVE_CONVERSION_KIND,
} from './fixture-matrix/shared/constants.mjs';

export {
  discoverFixtures,
  createFixtureMatrix,
  classifyFixture,
} from './fixture-matrix/fixtures.mjs';

export {
  buildFixtureArtifact,
  buildFixtureMatrixRecipe,
  stageFixtureSource,
  wordpressServedPath,
  normalizeStaticSiteImporterPlugin,
} from './fixture-matrix/steps/recipe-builder.mjs';

export { editorBlockValidationStep } from './fixture-matrix/steps/editor-validation-step.mjs';

export { visualParityCompareStep } from './fixture-matrix/steps/visual-parity-step.mjs';

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

export { collectFixtureMatrixRunResults } from './fixture-matrix/collectors/run-intake.mjs';

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
  normalizeVisualParityGateOptions,
} from './fixture-matrix/collectors/visual-parity.mjs';

export {
  runLiveWpParity,
  normalizeLiveWpParityReport,
  collectLiveWpParity,
  normalizeLiveWpParityCollectorOptions,
} from './fixture-matrix/collectors/live-wp-parity.mjs';
