// Editor-fidelity recipe step for the Static Site Importer fixture matrix.
// Opens the real imported page in the block editor by post id and captures a
// replayable full-page editor screenshot via WP Codebox.

/**
 * Internal dependencies
 */
import { EDITOR_OPEN_COMMAND } from '../shared/constants.mjs';

function present(value) {
  return value !== undefined && value !== null && String(value).trim() !== '';
}

export function editorCanvasCaptureStep(input = {}) {
  const fixture = input.fixture || {};
  const postId = firstPresent([input.postId, input.post_id, fixture.editor_post_id, fixture.editorPostId, fixture.post_id, fixture.postId]);
  if (!present(postId)) {
    return null;
  }

  const args = [
    `post-id=${postId}`,
    `post-type=${firstPresent([input.postType, input.post_type, fixture.editor_post_type, fixture.editorPostType, fixture.post_type, fixture.postType]) || 'page'}`,
    'capture=steps,console,errors,html,screenshot,editor-state',
  ];
  const waitSelector = firstPresent([input.waitSelector, input.wait_selector, fixture.editor_wait_selector, fixture.editorWaitSelector]);
  if (waitSelector !== undefined) {
    args.push(`wait-selector=${waitSelector}`);
  }
  const waitTimeout = firstPresent([input.waitTimeout, input.wait_timeout, fixture.editor_wait_timeout, fixture.editorWaitTimeout]);
  if (waitTimeout !== undefined) {
    args.push(`wait-timeout=${waitTimeout}`);
  }

  return {
    command: EDITOR_OPEN_COMMAND,
    allowFailure: true,
    args,
    metadata: {
      fixture_id: fixture.id,
      fixture_path: fixture.fixture_path || fixture.directory,
      phase: 'editor-fidelity',
      post_id: postId,
    },
  };
}

function firstPresent(values) {
  for (const value of values) {
    if (present(value)) {
      return value;
    }
  }
  return undefined;
}
