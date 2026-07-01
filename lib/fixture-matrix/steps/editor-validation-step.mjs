// Editor-validation recipe step for the Static Site Importer fixture matrix.
//
// Emits a WP Codebox schema-supported editor browser step for the fixture matrix.
// The runner-side schema currently used by Homeboy Lab accepts `wordpress.editor-open`
// but rejects the newer `wordpress.editor-validate-blocks` command before imports
// can run. SSI's imported-content gate remains the PHP/native-rate validation; this
// step captures generic editor runtime evidence without depending on a newer binary.

import {
  EDITOR_OPEN_COMMAND,
  DEFAULT_EDITOR_VALIDATION_TARGET,
} from '../shared/constants.mjs';

function present(value) {
  return value !== undefined && value !== null && String(value).trim() !== '';
}

export function editorBlockValidationStep(input = {}) {
  const fixture = input.fixture || {};

  const postId = firstPresent([input.postId, input.post_id, fixture.editor_post_id, fixture.editorPostId, fixture.post_id, fixture.postId]);
  const url = firstPresent([input.url, input.editorValidationUrl, input.editor_validation_url, fixture.editor_url, fixture.editorUrl]);
  const target = firstPresent([input.target, fixture.editor_target, fixture.editorTarget, fixture.target]);

  const args = [];
  if (postId !== undefined) {
    args.push(`post-id=${postId}`);
  } else if (url !== undefined) {
    args.push(`url=${url}`);
  } else if (target !== undefined) {
    args.push(`target=${target}`);
  } else {
    args.push(`target=${DEFAULT_EDITOR_VALIDATION_TARGET}`);
  }

  args.push('capture=editor-state');

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
    args,
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
