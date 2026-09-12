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
  EDITOR_VISIBLE_PLACEHOLDER_SELECTOR_GROUP,
  EDITOR_VISIBLE_PLACEHOLDER_SELECTORS,
} from '../shared/constants.mjs';
import { firstPresent, fixtureStepMetadata } from './shared.mjs';

export function editorBlockValidationStep(input = {}) {
  const fixture = input.fixture || {};
  const surface = input.surface || null;

  const postId = firstPresent([input.postId, input.post_id, fixture.editor_post_id, fixture.editorPostId, fixture.post_id, fixture.postId]);
  const postSlug = firstPresent([input.postSlug, input.post_slug, surface?.post_slug, fixture.editor_post_slug, fixture.editorPostSlug]);
  const postType = firstPresent([input.postType, input.post_type, surface?.post_type, fixture.editor_post_type, fixture.editorPostType]) || 'page';
  const url = firstPresent([input.url, input.editorValidationUrl, input.editor_validation_url, surface?.url, fixture.editor_url, fixture.editorUrl]);
  const target = firstPresent([input.target, surface?.target, fixture.editor_target, fixture.editorTarget, fixture.target]);

  const args = [];
  if (postId !== undefined) {
    args.push(`post-id=${postId}`);
  } else if (postSlug !== undefined) {
    args.push(`post-type=${postType}`, `post-slug=${postSlug}`);
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
      ...(surface?.source_entry ? { source_entry: surface.source_entry } : {}),
      ...(surface?.target ? { route: surface.target } : {}),
      ...(postId !== undefined ? { post_id: postId } : {}),
      ...(postSlug !== undefined ? { post_type: postType, post_slug: postSlug } : {}),
      ...(url !== undefined ? { url } : {}),
      ...(target !== undefined ? { target } : { target: DEFAULT_EDITOR_VALIDATION_TARGET }),
    }),
  };
}

export function editorChromeValidationStep(input = {}) {
  const validation = editorBlockValidationStep(input);
  return {
    ...validation,
    command: 'wordpress.editor-canvas-probe',
    args: [
      ...validation.args,
      `selector-groups-json=${JSON.stringify([{
        name: EDITOR_VISIBLE_PLACEHOLDER_SELECTOR_GROUP,
        selectors: EDITOR_VISIBLE_PLACEHOLDER_SELECTORS,
      }])}`,
    ],
    metadata: {
      ...validation.metadata,
      phase: 'editor-chrome',
    },
  };
}
