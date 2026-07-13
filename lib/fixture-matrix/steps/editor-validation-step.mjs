// Editor-validation recipe step for the Static Site Importer fixture matrix.
//
// Emits a WP Codebox validateBlock browser step for the fixture matrix.
// The rig declares this runner capability up front so unavailable validation fails
// before evidence runs instead of silently degrading to an editor-open smoke test.
/**
 * Internal dependencies
 */
import {
  EDITOR_VALIDATE_BLOCKS_COMMAND,
} from '../shared/constants.mjs';
import { editorStepTarget, fixtureStepMetadata } from './shared.mjs';

export function editorBlockValidationStep(input = {}) {
  const fixture = input.fixture || {};
  const surface = input.surface || null;
  const resolvedTarget = editorStepTarget(input, fixture, surface, {
    urlAliases: ['editorValidationUrl', 'editor_validation_url'],
  });

  return {
    command: EDITOR_VALIDATE_BLOCKS_COMMAND,
    allowFailure: true,
    args: resolvedTarget.args,
    metadata: fixtureStepMetadata(fixture, 'editor', {
      ...(surface?.id ? { surface_id: surface.id } : {}),
      ...resolvedTarget.metadata,
    }),
  };
}
