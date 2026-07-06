// Editor-open recipe step for the Static Site Importer fixture matrix.
// Captures real block-editor canvas evidence for an imported page surface.
/**
 * Internal dependencies
 */
import {
  EDITOR_OPEN_COMMAND,
  DEFAULT_EDITOR_VALIDATION_TARGET,
} from '../shared/constants.mjs';
import { firstPresent, fixtureStepMetadata } from './shared.mjs';

export function editorOpenStep(input = {}) {
  const fixture = input.fixture || {};
  const surface = input.surface || null;

  const postId = firstPresent([input.postId, input.post_id, surface?.postId, surface?.post_id, fixture.editor_post_id, fixture.editorPostId, fixture.post_id, fixture.postId]);
  const url = firstPresent([input.url, input.editorOpenUrl, input.editor_open_url, surface?.url, fixture.editor_url, fixture.editorUrl]);
  const target = firstPresent([input.target, input.editorOpenTarget, input.editor_open_target, surface?.target, fixture.editor_target, fixture.editorTarget, fixture.target]);
  const postType = firstPresent([input.postType, input.post_type, surface?.postType, surface?.post_type]);
  const postSlug = firstPresent([input.postSlug, input.post_slug, surface?.postSlug, surface?.post_slug]);
  const artifactPrefix = firstPresent([input.artifactPrefix, input.artifact_prefix, surface?.artifact_prefix]) || `files/browser/editor-open/${fixture.id}`;

  const args = [];
  if (postId !== undefined) {
    args.push(`post-id=${postId}`);
  } else if (postSlug !== undefined) {
    if (postType !== undefined) {
      args.push(`post-type=${postType}`);
    }
    args.push(`post-slug=${postSlug}`);
  } else if (url !== undefined) {
    args.push(`url=${url}`);
  } else if (target !== undefined) {
    args.push(`target=${target}`);
  } else {
    args.push(`target=${DEFAULT_EDITOR_VALIDATION_TARGET}`);
  }

  const waitSelector = firstPresent([input.waitSelector, input.wait_selector, fixture.editor_wait_selector, fixture.editorWaitSelector]);
  if (waitSelector !== undefined) {
    args.push(`wait-selector=${waitSelector}`);
  }
  const waitTimeout = firstPresent([input.waitTimeout, input.wait_timeout, fixture.editor_wait_timeout, fixture.editorWaitTimeout]);
  if (waitTimeout !== undefined) {
    args.push(`wait-timeout=${waitTimeout}`);
  }

  args.push('capture=screenshot,editor-state,editor-validity');
  args.push(`artifact-prefix=${artifactPrefix}`);

  return {
    command: EDITOR_OPEN_COMMAND,
    allowFailure: true,
    args,
    metadata: fixtureStepMetadata(fixture, 'editor-open', {
      artifact_prefix: artifactPrefix,
      ...(surface?.id ? { surface_id: surface.id } : {}),
      ...(postId !== undefined ? { post_id: postId } : {}),
      ...(postSlug !== undefined ? { post_slug: postSlug } : {}),
      ...(postType !== undefined ? { post_type: postType } : {}),
      ...(url !== undefined ? { url } : {}),
      ...(target !== undefined ? { target } : { target: DEFAULT_EDITOR_VALIDATION_TARGET }),
      ...(surface?.editor_target_source ? { editor_target_source: surface.editor_target_source } : {}),
    }),
  };
}
