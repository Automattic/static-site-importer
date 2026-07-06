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
  DEFAULT_EDITOR_VALIDATION_TARGET,
} from '../shared/constants.mjs';
import { firstPresent, fixtureStepMetadata } from './shared.mjs';

export function editorBlockValidationStep(input = {}) {
  const fixture = input.fixture || {};
  const surface = input.surface || null;

  const postId = firstPresent([input.postId, input.post_id, surface?.postId, surface?.post_id, fixture.editor_post_id, fixture.editorPostId, fixture.post_id, fixture.postId]);
  const url = firstPresent([input.url, input.editorValidationUrl, input.editor_validation_url, surface?.url, fixture.editor_url, fixture.editorUrl]);
  const target = firstPresent([input.target, surface?.target, fixture.editor_target, fixture.editorTarget, fixture.target]);
  const postType = firstPresent([input.postType, input.post_type, surface?.postType, surface?.post_type]);
  const postSlug = firstPresent([input.postSlug, input.post_slug, surface?.postSlug, surface?.post_slug]);

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

  return {
    command: EDITOR_VALIDATE_BLOCKS_COMMAND,
    allowFailure: true,
    args,
    metadata: fixtureStepMetadata(fixture, 'editor', {
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
