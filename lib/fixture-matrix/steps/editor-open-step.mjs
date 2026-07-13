// Editor-open recipe step for the Static Site Importer fixture matrix.
// Captures real block-editor canvas evidence for an imported page surface.
/**
 * Internal dependencies
 */
import {
  EDITOR_OPEN_COMMAND,
} from '../shared/constants.mjs';
import { editorStepTarget, firstPresent, fixtureStepMetadata } from './shared.mjs';

export function editorOpenStep(input = {}) {
  const fixture = input.fixture || {};
  const surface = input.surface || null;
  const artifactPrefix = firstPresent([input.artifactPrefix, input.artifact_prefix, surface?.artifact_prefix]) || `files/browser/editor-open/${fixture.id}`;
  const resolvedTarget = editorStepTarget(input, fixture, surface, {
    urlAliases: ['editorOpenUrl', 'editor_open_url'],
    targetAliases: ['editorOpenTarget', 'editor_open_target'],
  });

  const args = [...resolvedTarget.args];
  args.push('capture=screenshot,editor-state,editor-validity');
  args.push(`artifact-prefix=${artifactPrefix}`);

  return {
    command: EDITOR_OPEN_COMMAND,
    allowFailure: true,
    args,
    metadata: fixtureStepMetadata(fixture, 'editor-open', {
      artifact_prefix: artifactPrefix,
      ...(surface?.id ? { surface_id: surface.id } : {}),
      ...resolvedTarget.metadata,
    }),
  };
}
